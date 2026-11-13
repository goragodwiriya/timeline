<?php
/**
 * @filesource Gcms/Timeline/SyncEngine.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

use Kotchasan\Language;

/**
 * ดึงข้อมูลจากระบบต้นทางทุกระบบแล้วเขียนลง Hub
 *
 * ยิงทุก source พร้อมกันเป็นรอบ ๆ (manifest ทั้งหมด แล้วค่อย items ทั้งหมด)
 * ไม่ใช่ทำทีละ source จนจบ — source เดียวที่ค้างจะกินเวลาจนตัวหลังไม่ได้ sync
 * และรอบ cron จะซ้อนกันเองจนพังทั้งกระดาน
 *
 * @since 1.0
 */
class SyncEngine extends \Kotchasan\Model
{
    /**
     * ล้มติดกันกี่รอบถึงพัก source นั้น
     */
    const CIRCUIT_FAILS = 5;

    /**
     * พักนานเท่าไรเมื่อวงจรตัด
     */
    const CIRCUIT_PAUSE = '+6 hours';

    /**
     * ระยะถอยหลังตามจำนวนครั้งที่ล้ม
     */
    const BACKOFF = ['+1 minute', '+5 minutes', '+30 minutes'];

    /**
     * นาฬิกาสองฝั่งต่างกันเกินกี่วินาทีถึงเตือน
     */
    const CLOCK_SKEW = 300;

    /**
     * ขอกี่ item ต่อหน้า — ระบุให้ชัด ไม่พึ่งค่าปริยายของปลายทางซึ่งต่างรุ่นกันได้
     */
    const PER_PAGE = 200;

    /**
     * sync ทุก source ที่ถึงรอบแล้ว
     *
     * @return array สรุปผลของแต่ละ source
     */
    public static function runDue()
    {
        $now = time();
        $due = [];
        foreach (self::sources(true) as $source) {
            // base_url ว่าง = provider ที่อยู่ในตัว Hub เอง ไม่มีอะไรให้ไปเรียก
            // (ตอนนี้คือนัดหมาย ดู LocalProvider) — มันเขียน item ของตัวเอง
            // ตอนข้อมูลเปลี่ยน และ cron เรียก publish() ให้อีกชั้นหนึ่ง
            if (trim((string) $source->base_url) === '') {
                continue;
            }
            if (!empty($source->paused_until) && strtotime($source->paused_until) > $now) {
                continue;
            }
            if (empty($source->last_sync_at)
                || strtotime($source->last_sync_at) + ((int) $source->interval_min * 60) <= $now) {
                $due[] = $source;
            }
        }

        return self::runAll($due);
    }

    /**
     * sync source เดียวทันที (ปุ่ม "Sync Now")
     *
     * @param object $source
     *
     * @return array
     */
    public static function runOne($source)
    {
        $result = self::runAll([$source]);

        return reset($result);
    }

    /**
     * @param bool $enabledOnly
     *
     * @return array
     */
    public static function sources($enabledOnly = false)
    {
        $query = static::createQuery()->select()->from('sources')->orderBy('sort')->orderBy('id');
        if ($enabledOnly) {
            $query->where(['enabled', 1]);
        }

        return $query->fetchAll(false);
    }

    /**
     * ทำงานทุก source พร้อมกัน
     *
     * @param array $sources
     *
     * @return array [slug => ['status' => ..., 'seen' => ..., ...]]
     */
    public static function runAll(array $sources)
    {
        $sources = array_filter($sources, static function ($source) {
            return trim((string) $source->base_url) !== '';
        });
        if (empty($sources)) {
            return [];
        }

        $state = [];
        foreach ($sources as $source) {
            $state[$source->slug] = [
                'source' => $source,
                'run_id' => self::openRun($source),
                'status' => 'running',
                'http' => 0,
                'error' => '',
                'items' => [],
                'complete' => true,
                'seen' => 0,
                'changed' => 0,
                'vanished' => 0,
                'warning' => ''
            ];
        }

        self::fetchManifests($state);
        self::fetchFirstPages($state);
        self::fetchRemainingPages($state);
        self::persist($state);

        $summary = [];
        foreach ($state as $slug => $one) {
            $summary[$slug] = [
                'status' => $one['status'],
                'http' => $one['http'],
                'error' => $one['error'],
                'warning' => $one['warning'],
                'seen' => $one['seen'],
                'changed' => $one['changed'],
                'vanished' => $one['vanished']
            ];
        }

        return $summary;
    }

    /**
     * รอบที่ 1 — ขอ manifest ของทุก source พร้อมกัน
     *
     * @param array $state
     */
    private static function fetchManifests(array &$state)
    {
        $requests = [];
        foreach ($state as $slug => $one) {
            $requests[$slug] = [
                'curl' => Client::curl($one['source'], $one['run_id']),
                'url' => Client::url($one['source'], 'timeline/manifest')
            ];
        }

        foreach (Client::multi($requests) as $slug => $result) {
            $state[$slug]['http'] = $result['status'];
            if (!$result['ok']) {
                self::fail($state[$slug], $result['error']);
                continue;
            }
            self::checkManifest($state[$slug], (array) $result['data']);
        }
    }

    /**
     * ตรวจว่า manifest ที่ได้มาใช้ได้จริง
     *
     * @param array $one
     * @param array $manifest
     */
    private static function checkManifest(array &$one, array $manifest)
    {
        $source = $one['source'];

        $major = (int) explode('.', (string) ($manifest['protocol'] ?? '0'))[0];
        if ($major !== 1) {
            self::fail($one, Language::sprintf('Protocol %s is not supported — update the provider at the source', $manifest['protocol'] ?? '?'));

            return;
        }

        $slug = (string) ($manifest['source']['slug'] ?? '');
        if ($slug !== $source->slug) {
            // ชี้ผิดระบบ ถ้าปล่อยผ่านจะเอาข้อมูลของอีกระบบมาทับทั้งชุด
            self::fail($one, Language::sprintf('The source says it is "%s" but is set up as "%s"', $slug, $source->slug));

            return;
        }

        // นาฬิกาไม่ตรงทำให้การเตือนผิดเวลาแบบเงียบ ๆ ซึ่งไม่มีใครสังเกต
        if (!empty($manifest['server_time'])) {
            $skew = abs(time() - strtotime((string) $manifest['server_time']));
            if ($skew > self::CLOCK_SKEW) {
                $one['warning'] = Language::sprintf('The source clock differs from the Hub by %d seconds', $skew);
            }
        }

        $one['manifest'] = $manifest;
    }

    /**
     * รอบที่ 2 — ขอ items หน้าแรกของทุก source ที่ manifest ผ่าน
     *
     * @param array $state
     */
    private static function fetchFirstPages(array &$state)
    {
        $requests = [];
        foreach ($state as $slug => $one) {
            if ($one['status'] !== 'running') {
                continue;
            }
            $requests[$slug] = [
                'curl' => Client::curl($one['source'], $one['run_id']),
                'url' => Client::url($one['source'], 'timeline/items', [
                    'past' => $one['source']->horizon_past,
                    'future' => $one['source']->horizon_future,
                    'page' => 1,
                    'per_page' => self::PER_PAGE
                ])
            ];
        }
        if (empty($requests)) {
            return;
        }

        foreach (Client::multi($requests) as $slug => $result) {
            $state[$slug]['http'] = $result['status'];
            if (!$result['ok']) {
                self::fail($state[$slug], $result['error']);
                continue;
            }
            self::collect($state[$slug], (array) $result['data'], 1);
        }
    }

    /**
     * รอบที่ 3 — หน้าที่เหลือของทุก source รวมกันเป็นชุดเดียว
     *
     * @param array $state
     */
    private static function fetchRemainingPages(array &$state)
    {
        $requests = [];
        foreach ($state as $slug => $one) {
            if ($one['status'] !== 'running' || empty($one['meta']['pages'])) {
                continue;
            }
            for ($page = 2; $page <= (int) $one['meta']['pages']; ++$page) {
                $requests[$slug.'#'.$page] = [
                    'curl' => Client::curl($one['source'], $one['run_id']),
                    'url' => Client::url($one['source'], 'timeline/items', [
                        'past' => $one['source']->horizon_past,
                        'future' => $one['source']->horizon_future,
                        'page' => $page,
                        'per_page' => self::PER_PAGE
                    ])
                ];
            }
        }
        if (empty($requests)) {
            return;
        }

        foreach (Client::multi($requests) as $key => $result) {
            list($slug, $page) = explode('#', $key);
            if ($state[$slug]['status'] !== 'running') {
                continue;
            }
            if (!$result['ok']) {
                // ดึงไม่ครบทุกหน้า = ไม่รู้ว่าอะไรหายจริง ห้ามลบอะไรในรอบนี้
                $state[$slug]['complete'] = false;
                $state[$slug]['error'] = $result['error'];
                continue;
            }
            self::collect($state[$slug], (array) $result['data'], (int) $page);
        }
    }

    /**
     * เก็บ item จากหน้าหนึ่ง
     *
     * @param array $one
     * @param array $data
     * @param int   $page
     */
    private static function collect(array &$one, array $data, $page)
    {
        $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];
        if ($page === 1) {
            $one['meta'] = $meta;
        }

        // ต้นทางบอกเองว่าดึงข้อมูลมาได้ไม่ครบ — เชื่อมัน
        if (array_key_exists('complete', $meta) && !$meta['complete']) {
            $one['complete'] = false;
        }
        // จำนวนรวมเปลี่ยนกลางคัน แปลว่า snapshot ขยับระหว่างที่ไล่อ่านหน้า
        if ($page > 1 && isset($meta['total'], $one['meta']['total'])
            && (int) $meta['total'] !== (int) $one['meta']['total']) {
            $one['complete'] = false;
        }

        foreach ((array) ($data['items'] ?? []) as $item) {
            if (!empty($item['uid'])) {
                $one['items'][$item['uid']] = $item;
            }
        }
    }

    /**
     * เขียนลงฐานข้อมูลและปิดรอบ
     *
     * @param array $state
     */
    private static function persist(array &$state)
    {
        foreach ($state as $slug => &$one) {
            if ($one['status'] !== 'running') {
                self::closeRun($one);
                continue;
            }

            $source = $one['source'];
            $existing = ItemStore::preload($source->id);

            foreach ($one['items'] as $item) {
                try {
                    $outcome = ItemStore::store($source->id, $item, $one['run_id'], $existing);
                } catch (\Throwable $e) {
                    // item ใบเดียวที่เพี้ยนต้องไม่ทำให้ทั้งรอบล้ม แต่ก็แปลว่า
                    // snapshot ไม่ครบ จึงห้าม reconcile ในรอบนี้
                    $one['complete'] = false;
                    $one['error'] = Language::sprintf('Saving an item failed: %s', $e->getMessage());
                    continue;
                }
                ++$one['seen'];
                if ($outcome !== 'same') {
                    ++$one['changed'];
                }
            }

            if ($one['complete']) {
                $one['vanished'] = Reconciler::vanish($source->id, $one['run_id']);
                $one['status'] = 'ok';
            } else {
                $one['status'] = 'partial';
            }

            self::closeRun($one);
        }
    }

    /**
     * @param object $source
     *
     * @return int
     */
    private static function openRun($source)
    {
        return (int) \Kotchasan\DB::create()->insert('sync_runs', [
            'source_id' => (int) $source->id,
            'started_at' => date('Y-m-d H:i:s'),
            'status' => 'running'
        ]);
    }

    /**
     * ปิดรอบและอัปเดตสถานะของ source
     *
     * @param array $one
     */
    private static function closeRun(array &$one)
    {
        $db = \Kotchasan\DB::create();
        $source = $one['source'];
        $now = date('Y-m-d H:i:s');

        $db->update('sync_runs', ['id', $one['run_id']], [
            'finished_at' => $now,
            'status' => $one['status'],
            'items_seen' => $one['seen'],
            'items_changed' => $one['changed'],
            'items_vanished' => $one['vanished'],
            'http_code' => $one['http'],
            'error' => mb_substr($one['error'], 0, 255)
        ]);

        $update = [
            'last_sync_at' => $now,
            'last_status' => $one['status'],
            'last_error' => mb_substr($one['status'] === 'ok' ? $one['warning'] : $one['error'], 0, 255)
        ];

        if ($one['status'] === 'failed') {
            $fails = (int) $source->fail_count + 1;
            $update['fail_count'] = $fails;

            if (Client::isFatal($one['http'])) {
                // token ผิดหรือไม่มี endpoint จะไม่หายเองด้วยการยิงซ้ำ
                // มีแต่จะโดนปลายทางแบน — ปิดแล้วบอกคนให้ไปแก้
                $update['enabled'] = 0;
                $update['paused_until'] = null;
            } elseif ($fails >= self::CIRCUIT_FAILS) {
                $update['paused_until'] = date('Y-m-d H:i:s', strtotime(self::CIRCUIT_PAUSE));
            } else {
                $step = self::BACKOFF[min($fails, count(self::BACKOFF)) - 1];
                $update['paused_until'] = date('Y-m-d H:i:s', strtotime($step));
            }
        } else {
            $update['fail_count'] = 0;
            $update['paused_until'] = null;
            // last_ok_at เป็นตัวที่บอกผู้ใช้ว่าข้อมูลบนจอเก่าแค่ไหน — ต้องขยับ
            // เฉพาะเมื่อได้ข้อมูลมาจริง ไม่ใช่ทุกครั้งที่ลอง
            if ($one['status'] === 'ok') {
                $update['last_ok_at'] = $now;
            }
            if (!empty($one['manifest'])) {
                $update['manifest_json'] = json_encode($one['manifest'], JSON_UNESCAPED_UNICODE);
                $update['protocol_version'] = (string) ($one['manifest']['protocol'] ?? '');
                $update['home_url'] = (string) ($one['manifest']['source']['url'] ?? $source->home_url);
                $update['timezone'] = (string) ($one['manifest']['timezone'] ?? $source->timezone);
            }
        }

        $db->update('sources', ['id', (int) $source->id], $update);
    }

    /**
     * @param array  $one
     * @param string $message
     */
    private static function fail(array &$one, $message)
    {
        $one['status'] = 'failed';
        $one['error'] = $message;
    }
}
