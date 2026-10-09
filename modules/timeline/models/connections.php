<?php
/**
 * @filesource modules/timeline/models/connections.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Connections;

use Kotchasan\ApiException;
use Kotchasan\Language;

/**
 * ข้อมูลของระบบต้นทางสำหรับหน้าสถานะการเชื่อมต่อ
 *
 * หน้านี้สำคัญกว่าที่คิด — เมื่อข้อมูลทุกอย่างบนจอมาจากสำเนา สิ่งที่อันตราย
 * ที่สุดคือสำเนาเก่าที่ดูเหมือนสด ต้องมีที่ที่ตอบได้ว่าแต่ละระบบอัปเดตล่าสุด
 * เมื่อไรและพลาดเพราะอะไร
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * @param int $id
     *
     * @return object|null
     */
    public static function get($id)
    {
        $row = static::createQuery()->select()->from('sources')->where(['id', (int) $id])->first();

        return $row === false ? null : $row;
    }

    /**
     * @return array
     */
    public static function listAll()
    {
        $rows = static::createQuery()
            ->select('id', 'slug', 'name', 'base_url', 'home_url', 'enabled', 'interval_min',
                'horizon_past', 'horizon_future',
                'protocol_version', 'timezone', 'last_sync_at', 'last_ok_at', 'last_status',
                'last_error', 'fail_count', 'paused_until')
            ->from('sources')
            ->orderBy('sort')
            ->orderBy('id')
            ->fetchAll(true);

        $counts = self::itemCounts();
        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $row['id'] = $id;
            $row['enabled'] = (bool) $row['enabled'];
            $row['items'] = $counts[$id] ?? 0;
            $row['last_ok_text'] = self::ago($row['last_ok_at']);
            $row['paused'] = !empty($row['paused_until']) && strtotime($row['paused_until']) > time();
            $row['stale'] = empty($row['last_ok_at']) || strtotime($row['last_ok_at']) < strtotime('-1 day');
            // ปิดเองกับถูกระบบปิดเพราะ token ผิด ต่างกันมากในสายตาผู้ใช้
            $row['disabled_by_error'] = !$row['enabled'] && $row['last_status'] === 'failed';
            // สรุปสถานะเป็นคำเดียวให้หน้าจอใช้ทั้งเป็นชื่อคลาสและเป็นข้อความ
            // — ตรรกะการตัดสินอยู่ที่เดียว ไม่ใช่กระจายอยู่ในเงื่อนไขของ template
            list($row['state'], $row['state_lng']) = self::state($row);
            // array ศูนย์หรือหนึ่งตัว — provider ที่อยู่ในตัว Hub ไม่มีอะไรให้กด sync
            // ใช้ data-for แทน data-if เพราะ data-if ที่เท็จถอดปุ่มทิ้งถาวร
            $row['sync_button'] = $row['base_url'] === '' ? [] : [$row['id']];
            // provider ที่อยู่ในตัว Hub แก้หรือลบไม่ได้ — ไม่มีที่อยู่ให้แก้
            // และการลบจะพานัดหมายทั้งหมดหายไปด้วย
            $row['edit_button'] = $row['base_url'] === '' ? [] : [$row['id']];
            $row['enabled_text'] = $row['enabled'] ? '{LNG_On}' : '{LNG_Off}';
            $out[] = $row;
        }

        return $out;
    }

    /**
     * ข้อมูลสำหรับเปิด Modal เพิ่ม/แก้ระบบต้นทาง
     *
     * ฟอร์มอยู่คนละเทมเพลตกับตาราง จึงต้องมี endpoint ของตัวเองที่ตอบทั้ง
     * "ค่าเริ่มต้นของฟอร์ม" และ "คำสั่งให้เปิด Modal" มาพร้อมกัน — หน้าจอไม่ต้อง
     * รู้ว่าฟอร์มอยู่ไฟล์ไหนหรือหัวข้อควรเขียนว่าอะไร
     *
     * @param int $id 0 = เพิ่มใหม่
     *
     * @throws ApiException
     *
     * @return array|null ไม่พบระบบที่ขอแก้คืนค่า null
     */
    public static function modalPayload($id)
    {
        $id = (int) $id;

        if ($id > 0) {
            $source = self::get($id);
            if ($source === null) {
                return null;
            }
            // provider ที่อยู่ในตัว Hub ไม่มีที่อยู่ให้แก้ ปุ่มแก้จึงไม่ควรมีตั้งแต่แรก
            // แต่ต้องกันไว้ที่นี่ด้วย เพราะปุ่มไม่ใช่สิ่งที่กันการเรียก API ได้
            if (trim((string) $source->base_url) === '') {
                throw new ApiException(Language::get('The Hub\'s built-in provider cannot be edited'), 422);
            }

            // ไม่ส่ง token กลับมา — ช่องที่เว้นว่างแปลว่า "ไม่เปลี่ยน"
            $data = [
                'id' => (int) $source->id,
                'slug' => $source->slug,
                'name' => $source->name,
                'base_url' => $source->base_url,
                'interval_min' => (int) $source->interval_min,
                'horizon_past' => $source->horizon_past,
                'horizon_future' => $source->horizon_future,
                'enabled' => (int) $source->enabled
            ];
            $title = '{LNG_Edit} '.$source->name;
        } else {
            $data = [
                'id' => 0,
                'slug' => '',
                'name' => '',
                'base_url' => '',
                'interval_min' => 360,
                'horizon_past' => 'P90D',
                'horizon_future' => 'P12M',
                'enabled' => 1
            ];
            $title = '{LNG_Add application}';
        }

        return [
            'data' => (object) $data,
            'actions' => [
                [
                    'type' => 'modal',
                    'action' => 'show',
                    'template' => '/timeline/source.html',
                    'title' => $title,
                    'titleClass' => 'icon-connection'
                ]
            ]
        ];
    }

    /**
     * เพิ่มหรือแก้ระบบต้นทาง
     *
     * @param array $data
     * @param int   $id   0 = เพิ่มใหม่
     *
     * @throws ApiException
     *
     * @return int
     */
    public static function save(array $data, $id = 0)
    {
        $slug = trim((string) $data['slug']);
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $slug)) {
            throw new ApiException(Language::get('The short name must be 2-32 characters of a-z 0-9 _'), 422);
        }
        if (trim((string) $data['name']) === '') {
            throw new ApiException(Language::get('Please fill in the display name'), 422);
        }

        $db = \Kotchasan\DB::create();
        $clash = $db->first('sources', [['slug', $slug]], ['id']);
        if ($clash !== null && (int) $clash->id !== (int) $id) {
            throw new ApiException(Language::get('This short name is already in use'), 422);
        }

        $baseUrl = rtrim(trim((string) $data['base_url']), '/');
        if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
            throw new ApiException(Language::get('The address must start with http:// or https://'), 422);
        }

        $row = [
            'slug' => $slug,
            'name' => mb_substr(trim((string) $data['name']), 0, 100),
            'base_url' => $baseUrl,
            'interval_min' => max(5, min((int) $data['interval_min'], 10080)),
            'horizon_past' => $data['horizon_past'] ?: 'P90D',
            'horizon_future' => $data['horizon_future'] ?: 'P12M',
            'enabled' => empty($data['enabled']) ? 0 : 1
        ];

        // ช่องรหัสที่เว้นว่างแปลว่า "ไม่เปลี่ยน" ไม่ใช่ "ลบทิ้ง" — หน้าจอไม่เคย
        // ส่งค่าเดิมกลับมาให้เห็น การเว้นว่างจึงเป็นสิ่งที่เกิดขึ้นทุกครั้งที่แก้
        // ชื่อหรือช่วงเวลา และการล้าง token ทิ้งตรงนั้นจะตัดการเชื่อมต่อโดยไม่ตั้งใจ
        if (trim((string) $data['token']) !== '') {
            $row['token'] = trim((string) $data['token']);
        }

        if ($id > 0) {
            if (!$db->exists('sources', [['id', (int) $id]])) {
                throw new ApiException(Language::get('This system was not found'), 404);
            }
            // แก้ค่าแล้วให้ลองใหม่ทันที ไม่ต้องรอให้ครบรอบพักของความล้มเหลวเดิม
            $row['fail_count'] = 0;
            $row['paused_until'] = null;
            $row['last_sync_at'] = null;
            $db->update('sources', ['id', (int) $id], $row);

            return (int) $id;
        }

        $row['token'] = $row['token'] ?? '';
        $row['timezone'] = 'Asia/Bangkok';
        $row['sort'] = 50;
        $row['created_at'] = date('Y-m-d H:i:s');

        return (int) $db->insert('sources', $row);
    }

    /**
     * ลบระบบต้นทางพร้อมทุกอย่างที่ดึงมาจากมัน
     *
     * @param int $id
     *
     * @throws ApiException
     */
    public static function remove($id)
    {
        $source = self::get($id);
        if ($source === null) {
            throw new ApiException(Language::get('This system was not found'), 404);
        }
        if (trim((string) $source->base_url) === '') {
            throw new ApiException(Language::get('The Hub\'s built-in provider cannot be deleted'), 422);
        }

        $db = \Kotchasan\DB::create();
        $rows = static::createQuery()->select('id')->from('items')->where(['source_id', (int) $id])->fetchAll(true);
        $ids = array_map(function ($row) {
            return (int) $row['id'];
        }, $rows);

        if (!empty($ids)) {
            $db->delete('item_state', [['item_id', $ids]], 0);
            $db->delete('reminders', [['item_id', $ids]], 0);
            $db->delete('items', [['id', $ids]], 0);
        }
        $db->delete('sync_runs', [['source_id', (int) $id]], 0);
        $db->delete('sources', [['id', (int) $id]], 0);
    }

    /**
     * ลองเรียก manifest ของปลายทางโดยไม่บันทึกอะไร
     *
     * ทุกช่องใช้กติกาเดียวกัน — กรอกมาก็ใช้ค่าที่กรอก เว้นว่างก็ใช้ค่าที่เก็บไว้
     * ของระบบนั้น · จำเป็นเพราะฟอร์มไม่เคยส่งรหัสเดิมกลับมาให้เห็น ช่องรหัสจึงว่าง
     * ทุกครั้งที่กดแก้ และการทดสอบด้วยรหัสว่างจะได้ 401 เสมอทั้งที่ไม่มีอะไรเสีย
     *
     * @param string $baseUrl
     * @param string $token
     * @param int    $id      ระบบที่กำลังแก้อยู่ · 0 = เพิ่มใหม่ ไม่มีค่าเดิมให้ใช้
     *
     * @return array
     */
    public static function test($baseUrl, $token, $id = 0)
    {
        $source = (int) $id > 0 ? self::get($id) : null;
        if ($source !== null) {
            $fields = ['base_url' => &$baseUrl, 'token' => &$token];
            foreach ($fields as $name => &$value) {
                if (trim((string) $value) === '') {
                    $value = (string) $source->$name;
                }
            }
            unset($value);
        }

        $probe = (object) ['base_url' => rtrim($baseUrl, '/'), 'token' => $token, 'id' => 0];
        $result = \Gcms\Timeline\Client::one(
            \Gcms\Timeline\Client::curl($probe),
            \Gcms\Timeline\Client::url($probe, 'timeline/manifest')
        );

        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['error']];
        }

        $data = (array) $result['data'];

        return [
            'ok' => true,
            'slug' => (string) ($data['source']['slug'] ?? ''),
            'name' => (string) ($data['source']['name'] ?? ''),
            'protocol' => (string) ($data['protocol'] ?? ''),
            'kinds' => (array) ($data['kinds'] ?? []),
            'message' => Language::sprintf('Connected · %s · protocol %s · %d kinds',
                $data['source']['name'] ?? '?',
                $data['protocol'] ?? '?',
                count((array) ($data['kinds'] ?? [])))
        ];
    }

    /**
     * สถานะโดยสรุปของระบบหนึ่ง
     *
     * @param array $row
     *
     * @return array [ชื่อสถานะ, คีย์ข้อความ]
     */
    private static function state(array $row)
    {
        if ($row['disabled_by_error']) {
            return ['failed', '{LNG_Disconnected by an error}'];
        }
        if (!$row['enabled']) {
            return ['off', '{LNG_Turned off}'];
        }
        if ($row['last_status'] === 'failed') {
            return ['failed', '{LNG_Last attempt failed}'];
        }
        if ($row['last_status'] === 'partial') {
            return ['partial', '{LNG_Incomplete data}'];
        }
        if ($row['stale']) {
            return ['stale', '{LNG_Data is out of date}'];
        }

        return ['ok', '{LNG_Working}'];
    }

    /**
     * จำนวน item ที่ยังไม่หายไป ของแต่ละระบบ
     *
     * @return array
     */
    private static function itemCounts()
    {
        $rows = static::createQuery()
            ->select('source_id', \Kotchasan\Database\Sql::create('COUNT(*) AS `c`'))
            ->from('items')
            ->where(['vanished_at', null])
            ->groupBy('source_id')
            ->fetchAll(true);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['source_id']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * @param string|null $datetime
     *
     * @return string
     */
    public static function ago($datetime)
    {
        if (empty($datetime)) {
            return Language::get('Never succeeded');
        }
        $diff = time() - strtotime($datetime);
        if ($diff < 60) {
            return Language::get('Just now');
        }
        if ($diff < 3600) {
            return Language::sprintf('%d minutes ago', (int) ($diff / 60));
        }
        if ($diff < 86400) {
            return Language::sprintf('%d hours ago', (int) ($diff / 3600));
        }

        return Language::sprintf('%d days ago', (int) ($diff / 86400));
    }
}
