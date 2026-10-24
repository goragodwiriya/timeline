<?php
/**
 * @filesource Gcms/Timeline/Reconciler.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

/**
 * ลบ item ที่หายไปจาก snapshot
 *
 * protocol ไม่มี endpoint สำหรับ "ลบ" และไม่มี tombstone — item ที่ไม่อยู่ใน
 * snapshot อีกต่อไปคือ item ที่หายไป (TIMELINE-PROTOCOL.md §8)
 *
 * เลือกวิธีนี้เพราะตารางต้นทางส่วนใหญ่ไม่มี updated_at การทำ incremental จึงต้อง
 * ไปแก้ schema ของทุกแอปพร้อมกับดูแล cursor และ tombstone retention ให้ถูกต้อง
 * ตลอดไป ขณะที่ปริมาณจริงอยู่ในหลักร้อย
 *
 * @since 1.0
 */
class Reconciler extends \Kotchasan\Model
{
    /**
     * เก็บ item ที่หายไปไว้กี่วันก่อนลบจริง
     */
    const GRACE_DAYS = 30;

    /**
     * ทำเครื่องหมาย item ที่หายไปจาก snapshot ของรอบนี้
     *
     * เรียกได้ต่อเมื่อ snapshot ครบและสมบูรณ์เท่านั้น ผู้เรียกเป็นคนตัดสิน
     *
     * @param int $sourceId
     * @param int $runId
     *
     * @return int จำนวน item ที่หายไป
     */
    public static function vanish($sourceId, $runId)
    {
        $db = \Kotchasan\DB::create();

        // origin = 'sync' เท่านั้น — item ที่มาจาก push และ provider local
        // ไม่เคยอยู่ใน snapshot ลืมเงื่อนไขนี้เมื่อไร sync รอบถัดไปจะลบ alert
        // ของเซิร์ฟเวอร์และนัดหมายทั้งหมดทิ้ง
        $rows = static::createQuery()
            ->select('id')
            ->from('items')
            ->where([
                ['source_id', (int) $sourceId],
                ['origin', 'sync'],
                ['last_seen_run', '<', (int) $runId],
                ['vanished_at', null]
            ])
            ->fetchAll(true);

        if (empty($rows)) {
            return 0;
        }

        $ids = array_map(function ($row) {
            return (int) $row['id'];
        }, $rows);

        // ไม่ลบทันที — ต้นทางส่งข้อมูลผิดพลาดหนึ่งรอบต้องกู้คืนได้ และประวัติ
        // การเตือนที่ส่งไปแล้วต้องไม่หายไปพร้อมกัน
        $db->update('items', [['id', $ids]], ['vanished_at' => date('Y-m-d H:i:s')]);

        return count($ids);
    }

    /**
     * ลบ item ที่หายไปนานกว่าช่วงผ่อนผันออกจริง พร้อมของที่ผูกอยู่
     *
     * @return int จำนวนแถวที่ลบ
     */
    public static function purge()
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-'.self::GRACE_DAYS.' days'));

        $rows = static::createQuery()
            ->select('id')
            ->from('items')
            ->where([['vanished_at', '!=', null], ['vanished_at', '<', $cutoff]])
            ->fetchAll(true);

        if (empty($rows)) {
            return 0;
        }

        $ids = array_map(function ($row) {
            return (int) $row['id'];
        }, $rows);

        $db = \Kotchasan\DB::create();
        // ไม่มี foreign key เพราะ MyISAM/InnoDB ปนกันในระบบเดิม จึงเก็บกวาดเอง
        $db->delete('item_state', [['item_id', $ids]], 0);
        $db->delete('reminders', [['item_id', $ids]], 0);
        $db->delete('items', [['id', $ids]], 0);

        return count($ids);
    }
}
