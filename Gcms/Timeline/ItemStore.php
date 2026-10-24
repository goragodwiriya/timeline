<?php
/**
 * @filesource Gcms/Timeline/ItemStore.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

use Kotchasan\Database\Sql;

/**
 * เขียน item ที่รับมาจากต้นทางลงฐานข้อมูลของ Hub
 *
 * แตะเฉพาะตาราง items — **ห้ามแตะ item_state เด็ดขาด** เพราะนั่นคือการตัดสินใจ
 * ของผู้ใช้ที่ Hub เป็นเจ้าของ ปัญหา "กดจัดการแล้วโดน sync ทับ" จึงเป็นไปไม่ได้
 * ตั้งแต่ระดับโครงสร้าง ไม่ต้องอาศัยว่าใครจะเขียนโค้ดถูก (HUB-DESIGN.md §4.1)
 *
 * @since 1.0
 */
class ItemStore extends \Kotchasan\Model
{
    /**
     * อ่าน uid + hash ของ item ทั้งหมดที่มีอยู่ของ source นี้มาไว้ในหน่วยความจำ
     *
     * ทำครั้งเดียวต่อรอบ sync แล้วตัดสินใจใน PHP — ไม่งั้นจะกลายเป็น SELECT
     * หนึ่งครั้งต่อ item ซึ่งกับหลายร้อย item ต่อรอบคือคอขวดทั้งหมดของงานนี้
     *
     * @param int $sourceId
     *
     * @return array [uid => ['id' => int, 'hash' => string]]
     */
    public static function preload($sourceId)
    {
        $rows = static::createQuery()
            ->select('id', 'uid', 'payload_hash')
            ->from('items')
            ->where(['source_id', (int) $sourceId])
            ->fetchAll(true);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['uid']] = ['id' => (int) $row['id'], 'hash' => $row['payload_hash']];
        }

        return $map;
    }

    /**
     * เขียน item หนึ่งใบ
     *
     * @param int    $sourceId
     * @param array  $item     item ที่ได้จากต้นทาง
     * @param int    $runId
     * @param array  $existing ผลจาก preload()
     * @param string $origin   sync | local
     *
     * @return string 'new' | 'changed' | 'same'
     */
    public static function store($sourceId, array $item, $runId, array $existing, $origin = 'sync')
    {
        $row = self::toRow($sourceId, $item, $origin);
        $hash = self::hash($row);
        $row['payload_hash'] = $hash;
        $row['last_seen_run'] = (int) $runId;
        // เคยหายไปแล้วกลับมา — ปลุกคืน ไม่สร้างใบใหม่ เพื่อไม่ให้ผู้ใช้เสีย
        // สถานะและประวัติการเตือนที่ผูกกับ item เดิม
        $row['vanished_at'] = null;

        $db = \Kotchasan\DB::create();
        $uid = $item['uid'];

        if (!isset($existing[$uid])) {
            $row['first_seen_at'] = date('Y-m-d H:i:s');
            $db->insert('items', $row);

            return 'new';
        }

        if ($existing[$uid]['hash'] === $hash) {
            // ไม่มีอะไรเปลี่ยน อัปเดตแค่ร่องรอยว่ายังเห็นอยู่ในรอบนี้
            $db->update('items', ['id', $existing[$uid]['id']], [
                'last_seen_run' => (int) $runId,
                'vanished_at' => null
            ]);

            return 'same';
        }

        $db->update('items', ['id', $existing[$uid]['id']], $row);

        return 'changed';
    }

    /**
     * แปลง item ของ protocol เป็นแถวในฐานข้อมูล
     *
     * @param int    $sourceId
     * @param array  $item
     * @param string $origin
     *
     * @return array
     */
    public static function toRow($sourceId, array $item, $origin = 'sync')
    {
        $allDay = !empty($item['all_day']);

        return [
            'source_id' => (int) $sourceId,
            'uid' => (string) $item['uid'],
            'kind' => (string) $item['kind'],
            'origin' => $origin,
            'title' => (string) $item['title'],
            'subtitle' => (string) ($item['subtitle'] ?? ''),
            'body' => $item['body'] ?? null,
            'start_at' => self::toLocal($item['start_at'] ?? null, $allDay),
            'end_at' => self::toLocal($item['end_at'] ?? null, $allDay),
            'due_at' => self::toLocal($item['due_at'] ?? null, $allDay),
            'all_day' => $allDay ? 1 : 0,
            'tz' => (string) ($item['tz'] ?? date_default_timezone_get()),
            'status' => (string) ($item['status'] ?? 'active'),
            'priority' => (string) ($item['priority'] ?? 'normal'),
            'entity_type' => (string) ($item['entity']['type'] ?? ''),
            'entity_id' => (string) ($item['entity']['id'] ?? ''),
            'entity_label' => (string) ($item['entity']['label'] ?? ''),
            'contact_json' => self::encode($item['contact'] ?? null),
            'links_json' => self::encode($item['links'] ?? null),
            'actions_json' => self::encode($item['actions'] ?? null),
            'facts_json' => self::encode($item['facts'] ?? null),
            'meta_json' => self::encode($item['meta'] ?? null),
            'hint_json' => self::encode($item['hint'] ?? null)
        ];
    }

    /**
     * แปลงเวลาจาก protocol เป็นเวลาท้องถิ่นของ Hub
     *
     * protocol ส่งมาเป็น RFC3339 พร้อม offset หรือวันที่ล้วนเมื่อ all_day
     * ส่วน Hub เก็บเป็นเวลาท้องถิ่นตาม config.timezone ให้ตรงกับ date() ที่
     * เฟรมเวิร์กใช้ทั้งระบบ จะได้ไม่ต้องแปลงไปมาทุกจุดที่แสดงผล
     *
     * @param string|null $value
     * @param bool        $allDay
     *
     * @return string|null
     */
    public static function toLocal($value, $allDay)
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = new \DateTime((string) $value);
        } catch (\Exception $e) {
            return null;
        }

        // วันที่ล้วนคือ "วัน" ไม่ใช่ "จุดเวลา" — แปลงเขตเวลาจะเลื่อนวันได้
        // ซึ่งทำให้ "โดเมนหมดอายุ 1 ม.ค." กลายเป็น 31 ธ.ค. โดยไม่มีใครสังเกต
        if (!$allDay) {
            $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        }

        return $allDay ? $date->format('Y-m-d').' 00:00:00' : $date->format('Y-m-d H:i:s');
    }

    /**
     * ลายนิ้วมือของ item — ตอบว่า "เปลี่ยนจริงไหม" โดยไม่ต้องเทียบทีละฟิลด์
     *
     * @param array $row
     *
     * @return string
     */
    public static function hash(array $row)
    {
        $subject = $row;
        // ฟิลด์ที่เป็นร่องรอยของการ sync ไม่ใช่เนื้อหาของ item
        unset($subject['last_seen_run'], $subject['vanished_at'], $subject['first_seen_at'], $subject['payload_hash']);
        ksort($subject);

        return sha1(json_encode($subject, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param mixed $value
     *
     * @return string|null
     */
    private static function encode($value)
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
