<?php
/**
 * @filesource modules/timeline/models/reminders.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Reminders;

use Gcms\Timeline\Offsets;
use Gcms\Timeline\ReminderEngine;
use Kotchasan\ApiException;
use Kotchasan\Language;

/**
 * กฎประจำชนิด — ตัดสินว่าเรื่องชนิดไหน "ส่งเข้าแชต" และ "ขึ้นหน้ากระดาน"
 *
 * ต้องมีหน้าจอให้ปรับ ไม่ใช่ตั้งครั้งเดียวตอนติดตั้ง · ห้องแชตห้องเดียวรับทั้ง
 * นัดหมอ โดเมนใกล้หมดอายุ และหนี้ค้าง ถ้าเปิดหมดตั้งแต่แรกห้องจะกลายเป็นที่ที่
 * ไม่มีใครอ่าน แล้วนัดจริงจะพลาดไปพร้อมกับที่เหลือ
 *
 * **สองสวิตช์แยกกัน** เพราะเป็นคนละคำถาม
 *
 *   enabled  ส่งเข้าแชต/อีเมลไหม
 *   visible  ขึ้นหน้า "วันนี้" ไหม
 *
 * ตอนแรกมีแต่ `enabled` แล้วพบว่าไม่พอ — fail2ban ของ BluHost ส่ง alert.security
 * มาวันละหลายสิบรายการแยกตาม IP ผู้โจมตี ปิดการส่งแชตแล้วแชตเงียบจริง แต่หน้าแรก
 * ยังเป็นรายการโจมตี 47 ใบจาก 70 ใบ จนนัดหมอกับโดเมนใกล้หมดอายุจมหายไป
 * · ถ้ามีสวิตช์เดียวจะต้องเลือกระหว่าง "ท่วมจอ" กับ "ลบข้อมูลทิ้ง" ซึ่งไม่มีข้อไหนถูก
 *
 * ซ่อนแล้วยังค้นเจอ — Attention::items() ข้ามการซ่อนเมื่อระบุ kind หรือค้นด้วยคำ
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ชื่อไทยของแต่ละชนิด · ชนิดที่ไม่อยู่ในนี้แสดงชื่อดิบไปตรง ๆ
     */
    const LABELS = [
        'appointment' => 'Appointment',
        'expiry.domain' => 'Domain expiring',
        'expiry.hosting' => 'Hosting expiring',
        'expiry.ssl' => 'SSL certificate expiring',
        'expiry.contract' => 'Contract expiring',
        'expiry.license' => 'License expiring',
        'expiry.*' => 'Expiring (other kinds)',
        'payment.due' => 'Payment due',
        'payment.overdue' => 'Payment overdue',
        'task.deadline' => 'Task with a deadline',
        'renewal.subscription' => 'Service renewal',
        'followup' => 'Follow-up',
        'alert.*' => 'Alerts from source systems',
        '*' => 'All other kinds'
    ];

    /**
     * @return array
     */
    public static function listAll()
    {
        $rows = static::createQuery()
            ->select('id', 'source_id', 'kind', 'offsets_json', 'max_repeat', 'enabled', 'visible')
            ->from('reminder_rules')
            ->fetchAll(true);

        $counts = self::itemCounts();
        $sources = self::sourceNames();
        $out = [];

        foreach ($rows as $row) {
            $kind = (string) $row['kind'];
            $sourceId = (int) $row['source_id'];
            $enabled = !empty($row['enabled']);
            $visible = !empty($row['visible']);
            $out[] = [
                'id' => (int) $row['id'],
                'source_id' => $sourceId,
                'source_name' => $sourceId === 0 ? Language::get('All systems') : ($sources[$sourceId] ?? Language::get('A deleted system')),
                'kind' => $kind,
                'label' => isset(self::LABELS[$kind]) ? Language::get(self::LABELS[$kind]) : $kind,
                'offsets_text' => Offsets::describe($row['offsets_json'], (int) $row['max_repeat']),
                'items' => self::countFor($counts, $kind, $sourceId),
                'enabled' => $enabled,
                'state' => $enabled ? 'on' : 'off',
                'state_text' => Language::get($enabled ? 'Send' : 'Do not send'),
                'button_class' => $enabled ? 'btn-primary' : '',
                'button_text' => Language::get($enabled ? 'Off' : 'On'),
                'next' => $enabled ? '0' : '1',
                'visible' => $visible,
                'board_state' => $visible ? 'on' : 'off',
                'board_text' => Language::get($visible ? 'Show' : 'Hide'),
                'board_class' => $visible ? 'btn-primary' : '',
                'board_next' => $visible ? '0' : '1'
            ];
        }

        // ที่เปิดอยู่ขึ้นก่อน แล้วเรียงตามชื่อ — คนเปิดหน้านี้มาเพื่อดูว่า
        // "ตอนนี้อะไรส่งเข้าแชตบ้าง" ไม่ใช่เพื่อไล่อ่านชนิดที่ปิดอยู่สิบกว่าอัน
        usort($out, static function ($a, $b) {
            if ($a['enabled'] !== $b['enabled']) {
                return $a['enabled'] ? -1 : 1;
            }
            if ($a['kind'] !== $b['kind']) {
                return strcmp($a['kind'], $b['kind']);
            }

            // กฎที่เจาะจงระบบขึ้นก่อนกฎกลางของชนิดเดียวกัน — ตรงกับลำดับที่
            // matchRule() ใช้เลือกจริง หน้าจอจึงอ่านออกว่าอันไหนชนะ
            return $b['source_id'] <=> $a['source_id'];
        });

        return $out;
    }

    /**
     * เปิด/ปิดกฎหนึ่งข้อ
     *
     * @param int  $id
     * @param bool $enabled
     *
     * @throws ApiException 404 เมื่อไม่พบกฎ
     *
     * @return array
     */
    public static function toggle($id, $enabled, $field = 'enabled')
    {
        $id = (int) $id;
        // ชื่อคอลัมน์มาจากหน้าเว็บ ต้องผ่านบัญชีขาวก่อนเสมอ ไม่ใช่ต่อเข้าไปตรง ๆ
        if (!in_array($field, ['enabled', 'visible'], true)) {
            throw new ApiException(Language::get('Unknown switch'), 422);
        }
        $row = static::createQuery()->select('id', 'kind')->from('reminder_rules')->where(['id', $id])->first();
        // first() คืน null บ้าง false บ้างแล้วแต่เส้นทาง — เทียบกับค่าเดียวไม่พอ
        if (empty($row)) {
            throw new ApiException(Language::get('This reminder rule was not found'), 404);
        }

        \Kotchasan\DB::create()->update('reminder_rules', ['id', $id], [$field => $enabled ? 1 : 0]);

        // สวิตช์ "แสดงบนกระดาน" ไม่เกี่ยวกับคิวการเตือนเลย ไม่ต้องตั้งนัดใหม่
        if ($field === 'visible') {
            return self::listAll();
        }

        // เก็บกวาดแล้วตั้งใหม่ให้ตรงกับกฎชุดปัจจุบันทันที
        //
        // ปิดกฎเฉย ๆ ไม่พอ — นัดที่ arm() สร้างไว้ล่วงหน้ายังถูกส่งจนหมดคิว
        // ผู้ใช้จะเห็นชนิดที่เพิ่งปิดโผล่ต่ออีกหลายวันแล้วสรุปว่าสวิตช์เสีย
        // และเปิดกลับมาก็ต้องได้นัดทันที ไม่ใช่รอ cron รอบถัดไป
        \Gcms\Timeline\ReminderEngine::prune();
        \Gcms\Timeline\ReminderEngine::arm();

        return self::listAll();
    }

    /**
     * ฟอร์มเพิ่ม/แก้กฎ
     *
     * @param int $id
     *
     * @return array|null
     */
    public static function modalPayload($id)
    {
        $id = (int) $id;

        if ($id > 0) {
            $row = static::createQuery()->select()->from('reminder_rules')->where(['id', $id])->first();
            if (empty($row)) {
                return null;
            }
            $data = [
                'id' => $id,
                'source_id' => (int) $row->source_id,
                'kind' => (string) $row->kind,
                'offsets' => Offsets::format($row->offsets_json),
                'max_repeat' => (int) $row->max_repeat,
                'enabled' => (int) $row->enabled,
                'visible' => (int) $row->visible
            ];
            $title = '{LNG_Edit} '.(isset(self::LABELS[(string) $row->kind]) ? Language::get(self::LABELS[(string) $row->kind]) : (string) $row->kind);
        } else {
            $data = [
                'id' => 0,
                'source_id' => 0,
                'kind' => '',
                'offsets' => '7, 1',
                'max_repeat' => 0,
                'enabled' => 1,
                'visible' => 1
            ];
            $title = '{LNG_Add rule}';
        }

        // ตัวเลือกของ <select> ต้องอยู่ใน `options` ระดับบนสุด ไม่ใช่ใน `data`
        //
        // ResponseHandler ผูกเทมเพลตกับ `payload.data` เท่านั้น (`context.data.data
        // || context.data`) ส่วน `options` ถูกอ่านแยกด้วย extractModalOptions()
        // แล้วส่งให้ data-options-key — ซึ่งเติมตัวเลือก **ก่อน** data-attr ตั้งค่า
        // จึงเลือกค่าเดิมได้ถูก · วางไว้ระดับบนสุดแบบอื่นจะไม่มีอะไรมาอ่านเลย
        //
        // `kinds` อยู่ใน data เพราะเป็น <datalist> ที่วาดด้วย data-for ไม่ใช่ select
        $data['kinds'] = self::kindOptions();

        return [
            'data' => (object) $data,
            'options' => [
                'sources' => self::sourceOptions()
            ],
            'actions' => [
                [
                    'type' => 'modal',
                    'action' => 'show',
                    'template' => '/timeline/rule.html',
                    'title' => $title,
                    'titleClass' => 'icon-bell'
                ]
            ]
        ];
    }

    /**
     * เพิ่มหรือแก้กฎ
     *
     * @param array $data
     * @param int   $id
     *
     * @throws ApiException 422 เมื่อข้อมูลไม่ผ่าน
     *
     * @return int
     */
    public static function save(array $data, $id = 0)
    {
        $id = (int) $id;
        $kind = trim((string) ($data['kind'] ?? ''));
        if ($kind === '' || !preg_match('/^(\*|[a-z][a-z0-9_]*(\.[a-z0-9_]+|\.\*)?)$/', $kind)) {
            throw new ApiException(Language::get('Invalid kind — use e.g. appointment, expiry.hosting, expiry.* or *'), 422);
        }

        $sourceId = (int) ($data['source_id'] ?? 0);
        if ($sourceId > 0) {
            $exists = static::createQuery()->select('id')->from('sources')->where(['id', $sourceId])->first();
            if (empty($exists)) {
                throw new ApiException(Language::get('This source system was not found'), 422);
            }
        }

        $offsets = Offsets::parse($data['offsets'] ?? '');
        $maxRepeat = max(0, min(30, (int) ($data['max_repeat'] ?? 0)));

        // กฎซ้ำคู่ (ระบบ, ชนิด) ไม่ได้ — มีดัชนี UNIQUE กันไว้ แต่ต้องตอบให้อ่านออก
        // ไม่ใช่ปล่อยให้ฐานข้อมูลโยน error ดิบ ๆ ออกไปทางหน้าเว็บ
        $dup = static::createQuery()->select('id')->from('reminder_rules')
            ->where([['source_id', $sourceId], ['kind', $kind]])->first();
        if (!empty($dup) && (int) $dup->id !== $id) {
            throw new ApiException(Language::get('A rule for this kind and system already exists'), 422);
        }

        $values = [
            'source_id' => $sourceId,
            'kind' => $kind,
            'offsets_json' => json_encode($offsets),
            'max_repeat' => $maxRepeat,
            'enabled' => empty($data['enabled']) ? 0 : 1,
            'visible' => empty($data['visible']) ? 0 : 1
        ];

        $db = \Kotchasan\DB::create();
        if ($id > 0) {
            $db->update('reminder_rules', ['id', $id], $values);
        } else {
            $id = (int) $db->insert('reminder_rules', $values);
        }

        // ช่วงเวลาเปลี่ยน = นัดที่ตั้งไว้ล่วงหน้าผิดหมด ต้องตั้งใหม่ทันที
        ReminderEngine::prune();
        ReminderEngine::arm();

        return $id;
    }

    /**
     * ลบกฎ
     *
     * @param int $id
     *
     * @throws ApiException 404
     *
     * @return array
     */
    public static function remove($id)
    {
        $id = (int) $id;
        $row = static::createQuery()->select('id')->from('reminder_rules')->where(['id', $id])->first();
        if (empty($row)) {
            throw new ApiException(Language::get('This reminder rule was not found'), 404);
        }

        \Kotchasan\DB::create()->delete('reminder_rules', ['id', $id], 1);
        ReminderEngine::prune();
        ReminderEngine::arm();

        return self::listAll();
    }

    /**
     * ชนิดที่เลือกได้ในฟอร์ม — ที่รู้จัก บวกกับที่พบจริงในข้อมูล
     *
     * ต้นทางประกาศชนิดใหม่ได้ตลอดโดยที่ Hub ไม่รู้ล่วงหน้า (TIMELINE-PROTOCOL §7)
     * รายการตายตัวจึงตั้งกฎให้ชนิดใหม่ไม่ได้เลย
     *
     * @return array
     */
    private static function kindOptions()
    {
        $kinds = array_keys(self::LABELS);
        foreach (static::createQuery()->select('kind')->from('items')->groupBy('kind')->fetchAll(true) as $row) {
            $kinds[] = (string) $row['kind'];
        }
        $kinds = array_values(array_unique($kinds));
        sort($kinds);

        $out = [];
        foreach ($kinds as $kind) {
            $out[] = ['value' => $kind, 'text' => $kind.(isset(self::LABELS[$kind]) ? ' — '.Language::get(self::LABELS[$kind]) : '')];
        }

        return $out;
    }

    /**
     * ระบบต้นทางที่เลือกได้ในฟอร์ม
     *
     * @return array
     */
    private static function sourceOptions()
    {
        $out = [['value' => 0, 'text' => Language::get('All systems')]];
        foreach (self::sourceNames() as $sourceId => $name) {
            $out[] = ['value' => $sourceId, 'text' => $name];
        }

        return $out;
    }

    /**
     * จำนวนรายการที่ยังใช้งานอยู่ แยกตามชนิด
     *
     * @return array [kind => int]
     */
    private static function itemCounts()
    {
        $rows = static::createQuery()
            ->select('kind', 'source_id', \Kotchasan\Database\Sql::create('COUNT(*) AS `c`'))
            ->from('items')
            ->where([['vanished_at', null], ['status', 'active']])
            ->groupBy('kind')
            ->groupBy('source_id')
            ->fetchAll(true);

        $counts = [];
        foreach ($rows as $row) {
            $counts[] = [
                'kind' => (string) $row['kind'],
                'source_id' => (int) $row['source_id'],
                'n' => (int) $row['c']
            ];
        }

        return $counts;
    }

    /**
     * จำนวนรายการที่กฎข้อนี้ครอบ
     *
     * @param array  $counts
     * @param string $kind
     *
     * @return int
     */
    private static function countFor(array $counts, $kind, $sourceId)
    {
        $total = 0;
        foreach ($counts as $row) {
            if ($sourceId > 0 && $row['source_id'] !== $sourceId) {
                continue;
            }
            if ($kind === '*') {
                $total += $row['n'];
                continue;
            }
            if (substr($kind, -2) === '.*') {
                if (strpos($row['kind'], substr($kind, 0, -1)) === 0) {
                    $total += $row['n'];
                }
                continue;
            }
            if ($row['kind'] === $kind) {
                $total += $row['n'];
            }
        }

        return $total;
    }

    /**
     * ชื่อระบบต้นทางทั้งหมด
     *
     * @return array [id => name]
     */
    private static function sourceNames()
    {
        $out = [];
        foreach (static::createQuery()->select('id', 'name')->from('sources')->orderBy('sort')->fetchAll(true) as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }

        return $out;
    }
}
