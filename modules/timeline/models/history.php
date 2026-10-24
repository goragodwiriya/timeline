<?php
/**
 * @filesource modules/timeline/models/history.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\History;

use Gcms\Timeline\Offsets;
use Kotchasan\Database\Sql;
use Timeline\Reminders\Model as Rules;
use Kotchasan\Language;

/**
 * ประวัติการแจ้งเตือน — ทุกฉบับที่ระบบส่งออกไป และทุกฉบับที่รอส่ง
 *
 * ตาราง reminders เก็บครบอยู่แล้วตั้งแต่แรก แต่ไม่เคยมีหน้าจอให้ดู เวลามีข้อความ
 * โผล่มาเยอะผิดปกติจึงตอบไม่ได้ว่ามาจากกฎข้อไหน กี่ฉบับ และหยุดไปหรือยัง
 *
 * ลบได้ แต่ไม่ใช่ทุกแถว — ดู deletable()
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * คำอธิบายสถานะของแต่ละฉบับ
     */
    const STATES = [
        'sent' => 'Sent successfully',
        'partial' => 'Sent to some channels',
        'failed' => 'Sending failed',
        'skipped' => 'Skipped',
        'pending' => 'Waiting to be sent'
    ];

    /**
     * ชื่อช่องทางที่อ่านออก
     */
    const CHANNELS = [
        'telegram' => 'Telegram',
        'line' => 'LINE',
        'email' => 'Email'
    ];

    /**
     * ข้อมูลสำหรับ DataTable
     *
     * LEFT JOIN ทั้งสองตาราง — ประวัติต้องอยู่ต่อได้แม้ item ต้นเรื่องถูกลบไปแล้ว
     * ถ้าใช้ INNER แถวเก่าจะหายไปเงียบ ๆ พร้อมกับข้อมูลว่าเคยส่งอะไรไป
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params)
    {
        $where = [];
        if ($params['status'] !== '') {
            $where[] = ['R.status', $params['status']];
        }
        if ($params['kind'] !== '') {
            $where[] = ['I.kind', $params['kind']];
        }
        if ($params['source_id'] !== '') {
            $where[] = ['I.source_id', (int) $params['source_id']];
        }

        $query = static::createQuery()
            ->select(
                'R.id',
                'R.item_id',
                'R.offset_key',
                'R.fire_at',
                'R.sent_at',
                'R.channel',
                'R.status',
                'R.error',
                'I.kind',
                'I.title',
                'I.entity_label',
                'I.source_id',
                Sql::create('COALESCE(`S`.`name`, \'\') AS `source_name`')
            )
            ->from('reminders R')
            ->join('items I', [['I.id', 'R.item_id']], 'LEFT')
            ->join('sources S', [['S.id', 'I.source_id']], 'LEFT')
            ->where($where);

        if (!empty($params['search'])) {
            $search = '%'.$params['search'].'%';
            $query->where([
                ['I.title', 'LIKE', $search],
                ['I.entity_label', 'LIKE', $search],
                ['I.kind', 'LIKE', $search],
                ['R.error', 'LIKE', $search]
            ], 'OR');
        }

        // เรียงตั้งต้นเฉพาะตอนที่หน้าจอยังไม่ได้สั่งเรียง — Gcms\Table ต่อ orderBy
        // ของผู้ใช้ "ต่อท้าย" คิวรีนี้ ถ้าใส่ไว้ตายตัว R.id DESC จะเป็นคีย์แรกเสมอ
        // แล้วการกดหัวคอลัมน์จะไม่มีผลอะไรเลย
        if (empty($params['sort'])) {
            $query->orderBy('R.id', 'DESC');
        }

        return $query;
    }

    /**
     * เติมข้อความที่คนอ่านออกให้แต่ละแถว
     *
     * @param array $rows
     *
     * @return array
     */
    public static function format(array $rows)
    {
        $out = [];

        foreach ($rows as $row) {
            $status = (string) $row->status;
            $row->status_text = isset(self::STATES[$status]) ? Language::get(self::STATES[$status]) : $status;
            $row->kind_text = self::kindText((string) $row->kind);
            $row->offset_text = Offsets::human((string) $row->offset_key);
            $row->channel_text = self::channelText((string) $row->channel);
            $row->when = $row->sent_at ?: $row->fire_at;
            $row->title = (string) $row->title;
            if ($row->title === '') {
                // item ถูกลบไปแล้ว แต่ประวัติยังอยู่ ต้องบอกว่าทำไมช่องนี้ว่าง
                $row->title = Language::sprintf('The item has been deleted (#%d)', (int) $row->item_id);
            }
            // ชื่อเจ้าของเรื่องต่อท้ายเฉพาะเมื่อยังไม่ได้อยู่ในหัวข้ออยู่แล้ว —
            // ต้นทางหลายที่ใส่ชื่อไว้ทั้งสองช่อง ต่อท้ายดื้อ ๆ จะได้ "ไร หมอนัด · ไร หมอนัด"
            $label = (string) $row->entity_label;
            if ($label !== '' && mb_strpos($row->title, $label) === false) {
                $row->title .= ' · '.$label;
            }
            $row->source_name = $row->source_name ?: '—';
            $row->error = mb_substr((string) $row->error, 0, 120);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * ลบประวัติตาม id ที่เลือก
     *
     * @param array $ids
     *
     * @throws \Kotchasan\ApiException 422 เมื่อทุกแถวที่เลือกลบไม่ได้
     *
     * @return array ['deleted' => int, 'kept' => int]
     */
    public static function remove(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return ['deleted' => 0, 'kept' => 0];
        }

        $safe = self::deletable($ids);
        $kept = count($ids) - count($safe);

        if (empty($safe)) {
            throw new \Kotchasan\ApiException(
                Language::get('These cannot be deleted: they are unsent reminders of items that still exist — the queue of the future, not history · to stop this kind of reminder, turn its rule off on the connections page and the queue is cleared for you'),
                422
            );
        }

        \Kotchasan\DB::create()->delete('reminders', [['id', $safe]], 0);

        return ['deleted' => count($safe), 'kept' => $kept];
    }

    /**
     * ลบประวัติทั้งหมดที่ลบได้ (ตามตัวกรองที่เปิดอยู่)
     *
     * @param array $params
     *
     * @return array ['deleted' => int, 'kept' => int]
     */
    public static function clear(array $params)
    {
        $rows = self::toDataTable($params)->cacheOff()->fetchAll(true);
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }
        if (empty($ids)) {
            return ['deleted' => 0, 'kept' => 0];
        }

        $safe = self::deletable($ids);
        if (empty($safe)) {
            return ['deleted' => 0, 'kept' => count($ids)];
        }

        \Kotchasan\DB::create()->delete('reminders', [['id', $safe]], 0);

        return ['deleted' => count($safe), 'kept' => count($ids) - count($safe)];
    }

    /**
     * คัดเฉพาะแถวที่ลบได้ ตามกติกาสองข้อ
     *
     *   ส่งไปแล้ว   ลบได้เสมอ — เป็นบันทึกของเรื่องที่จบไปแล้ว เจ้าของระบบ
     *               ต้องเก็บกวาดจอตัวเองได้ ไม่ใช่ให้ระบบตัดสินแทน
     *   ยังไม่ส่ง   ลบได้เมื่อต้นทางไม่เหลืออยู่แล้ว (item ถูกลบ · vanished ·
     *               หรือเปลี่ยนสถานะไปจาก active) — ถ้าต้นทางยังอยู่ มันคือคิว
     *               ของอนาคต ไม่ใช่ประวัติ ลบไปเดี๋ยว arm() ก็สร้างกลับมา
     *               วิธีที่ถูกคือปิดกฎ แล้ว prune() เก็บกวาดให้เอง
     *
     * เดิมที่นี่หวงกว่านี้มาก — ห้ามลบแถวที่ส่งแล้วถ้า item ยังใช้งานอยู่และเวลา
     * ยิงยังอยู่ในหน้าต่างตามทัน เพราะกลัว arm() สร้างใหม่แล้วส่งซ้ำ · ผลคือ
     * การเตือนของนัดที่ผู้ใช้กดจัดการไปแล้วก็ยังลบไม่ได้ ซึ่งอธิบายให้ใครฟัง
     * ก็ไม่เข้าใจ · ความกลัวนั้นแก้ถูกที่แล้วที่ ReminderEngine::arm() ซึ่งไม่
     * สร้างนัดย้อนหลังให้เรื่องที่ผู้ใช้จัดการไปแล้วอีกต่อไป
     *
     * @param array $ids
     *
     * @return array id ที่ลบได้
     */
    private static function deletable(array $ids)
    {
        $rows = static::createQuery()
            ->select('R.id', 'R.sent_at', 'I.id AS item_id', 'I.status', 'I.vanished_at')
            ->from('reminders R')
            ->join('items I', [['I.id', 'R.item_id']], 'LEFT')
            ->where([['R.id', $ids]])
            ->cacheOff()
            ->fetchAll(true);

        $safe = [];
        foreach ($rows as $row) {
            if ($row['sent_at'] !== null) {
                $safe[] = (int) $row['id'];
                continue;
            }
            // LEFT JOIN ให้ item_id เป็น null เมื่อ item ถูกลบไปแล้วจริง ๆ
            $gone = $row['item_id'] === null || $row['vanished_at'] !== null || $row['status'] !== 'active';
            if ($gone) {
                $safe[] = (int) $row['id'];
            }
        }

        return $safe;
    }

    /**
     * ชื่อไทยของชนิด
     *
     * ชนิดที่ไม่มีชื่อตรงตัว ให้ใช้ชื่อของกฎกลุ่ม (`alert.*`) แทน — ต้นทางประกาศ
     * ชนิดใหม่ได้ตลอดโดย Hub ไม่รู้ล่วงหน้า ถ้าไม่มีทางถอยกลับ ตัวกรองจะเต็มไป
     * ด้วยคำอังกฤษดิบ ๆ อย่าง alert.security ปนกับชื่อไทย
     *
     * @param string $kind
     *
     * @return string
     */
    private static function kindText($kind)
    {
        if (isset(Rules::LABELS[$kind])) {
            return Language::get(Rules::LABELS[$kind]);
        }
        $dot = strrpos($kind, '.');
        if ($dot !== false && isset(Rules::LABELS[substr($kind, 0, $dot).'.*'])) {
            return Language::get(Rules::LABELS[substr($kind, 0, $dot).'.*']).' · '.substr($kind, $dot + 1);
        }

        return $kind;
    }

    /**
     * ชื่อช่องทางที่อ่านออก
     *
     * @param string $channel
     *
     * @return string
     */
    private static function channelText($channel)
    {
        $channel = trim($channel);
        if ($channel === '') {
            return '—';
        }

        $out = [];
        foreach (explode(',', $channel) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $out[] = isset(self::CHANNELS[$name]) ? Language::get(self::CHANNELS[$name]) : $name;
            }
        }

        return implode(' · ', $out);
    }

    /**
     * ตัวเลือกของตัวกรอง "ชนิด" — เฉพาะชนิดที่มีอยู่จริงในประวัติ
     *
     * @return array
     */
    public static function kindFilter()
    {
        $out = [];
        $rows = static::createQuery()
            ->select('I.kind')
            ->from('reminders R')
            ->join('items I', [['I.id', 'R.item_id']], 'INNER')
            ->groupBy('I.kind')
            ->orderBy('I.kind')
            ->fetchAll(true);

        foreach ($rows as $row) {
            $kind = (string) $row['kind'];
            $out[] = ['value' => $kind, 'text' => self::kindText($kind)];
        }

        return $out;
    }

    /**
     * ตัวเลือกของตัวกรอง "ระบบต้นทาง"
     *
     * @return array
     */
    public static function sourceFilter()
    {
        $out = [];
        foreach (static::createQuery()->select('id', 'name')->from('sources')->orderBy('sort')->fetchAll(true) as $row) {
            $out[] = ['value' => (string) (int) $row['id'], 'text' => (string) $row['name']];
        }

        return $out;
    }

    /**
     * ตัวเลือกของตัวกรอง "สถานะ"
     *
     * @return array
     */
    public static function statusFilter()
    {
        $out = [];
        foreach (self::STATES as $value => $text) {
            $out[] = ['value' => $value, 'text' => Language::get($text)];
        }

        return $out;
    }

    /**
     * สรุปหัวหน้าจอ
     *
     * @return array
     */
    public static function summary()
    {
        $rows = static::createQuery()
            ->select('status', Sql::create('COUNT(*) AS `c`'))
            ->from('reminders')
            ->groupBy('status')
            ->cacheOff()
            ->fetchAll(true);

        $out = ['total' => 0];
        foreach (array_keys(self::STATES) as $key) {
            $out[$key] = 0;
        }
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
            $out['total'] += (int) $row['c'];
        }

        return $out;
    }
}
