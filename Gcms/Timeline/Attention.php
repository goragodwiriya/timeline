<?php
/**
 * @filesource Gcms/Timeline/Attention.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

use Kotchasan\Language;

/**
 * ตอบคำถามเดียวที่ทำให้ระบบนี้เกิดขึ้น — **ตอนนี้ต้องสนใจอะไรบ้าง**
 *
 * การจัดกลุ่มเป็นของ Hub ไม่ใช่ของต้นทาง ต้นทางส่ง due_at กับ priority มาเป็น
 * คำใบ้เท่านั้น ทำแบบนี้ระบบใหม่ที่เสียบเข้ามาได้หน้า dashboard ที่ถูกต้อง
 * ทันทีโดยไม่ต้องแก้โค้ดของ Hub เลย
 *
 * @since 1.0
 */
class Attention extends \Kotchasan\Model
{
    /**
     * ภายในกี่วันถึงเรียกว่า "ใกล้ถึง"
     */
    const SOON_DAYS = 7;

    /**
     * ลำดับความสำคัญ ใช้เรียงภายในกลุ่ม
     */
    const RANK = ['critical' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];

    /**
     * รายการที่ต้องสนใจ แยกเป็นกลุ่มพร้อมแสดงผล
     *
     * @param array $filter source_id, kind, bucket
     *
     * @return array ['overdue' => [...], 'today' => [...], 'soon' => [...], 'upcoming' => [...]]
     */
    public static function buckets(array $filter = [])
    {
        $groups = ['overdue' => [], 'today' => [], 'soon' => [], 'upcoming' => []];
        foreach (self::items($filter) as $item) {
            $groups[$item['bucket']][] = $item;
        }

        foreach ($groups as &$group) {
            usort($group, function ($a, $b) {
                $ra = self::RANK[$a['priority']] ?? 9;
                $rb = self::RANK[$b['priority']] ?? 9;

                // priority เรียงภายในกลุ่มเท่านั้น ไม่ข้ามกลุ่ม — หนี้ critical
                // ที่ครบกำหนดเดือนหน้า ต้องไม่ขึ้นก่อนนัดหมอ normal ของวันนี้
                return $ra === $rb ? strcmp((string) $a['at'], (string) $b['at']) : $ra - $rb;
            });
        }

        return $groups;
    }

    /**
     * จำนวนในแต่ละกลุ่ม สำหรับหัวหน้าจอ
     *
     * @param array $filter
     *
     * @return array
     */
    public static function counts(array $filter = [])
    {
        $counts = ['overdue' => 0, 'today' => 0, 'soon' => 0, 'upcoming' => 0, 'total' => 0];
        foreach (self::items($filter) as $item) {
            ++$counts[$item['bucket']];
            ++$counts['total'];
        }

        return $counts;
    }

    /**
     * item ที่ยังต้องสนใจทั้งหมด พร้อมกลุ่มที่คำนวณแล้ว
     *
     * @param array $filter
     *
     * @return array
     */
    public static function items(array $filter = [])
    {
        $now = time();
        $todayEnd = strtotime('tomorrow') - 1;
        $soonEnd = strtotime('+'.self::SOON_DAYS.' days 23:59:59');

        $where = [
            ['I.vanished_at', null],
            ['I.status', 'active']
        ];
        if (!empty($filter['source_id'])) {
            $where[] = ['I.source_id', (int) $filter['source_id']];
        }
        if (!empty($filter['kind'])) {
            $where[] = ['I.kind', $filter['kind']];
        }
        $query = static::createQuery()
            ->select(
                'I.id', 'I.uid', 'I.source_id', 'I.kind', 'I.origin', 'I.title', 'I.subtitle', 'I.body',
                'I.start_at', 'I.end_at', 'I.due_at', 'I.all_day', 'I.status', 'I.priority',
                'I.entity_type', 'I.entity_id', 'I.entity_label',
                'I.contact_json', 'I.links_json', 'I.actions_json', 'I.facts_json', 'I.meta_json', 'I.hint_json',
                'Src.slug', 'Src.name AS source_name', 'Src.last_ok_at',
                'S.state', 'S.snooze_until', 'S.note', 'S.pinned'
            )
            ->from('items I')
            ->join('sources Src', [['Src.id', 'I.source_id']], 'INNER')
            ->join('item_state S', [['S.item_id', 'I.id']], 'LEFT')
            ->where($where);

        // ค้นด้วยคำ — ชื่อเรื่อง บรรทัดรอง ป้ายของสิ่งที่อ้างถึง และชื่อผู้ติดต่อ
        //
        // ชื่อผู้ติดต่อฝังอยู่ใน contact_json จึงค้นบนข้อความ JSON ตรง ๆ · ไม่สวย
        // แต่ถูกต้อง และเลี่ยงการเพิ่มคอลัมน์ซ้ำที่ต้องคอยดูแลให้ตรงกัน
        if (isset($filter['q']) && trim((string) $filter['q']) !== '') {
            $q = '%'.trim((string) $filter['q']).'%';
            $query->where([
                ['I.title', 'LIKE', $q],
                ['I.subtitle', 'LIKE', $q],
                ['I.entity_label', 'LIKE', $q],
                ['I.contact_json', 'LIKE', $q]
            ], 'OR');
        }

        $rows = $query->fetchAll(true);

        // ชนิดที่ถูกสั่งไม่ให้ขึ้นกระดาน
        //
        // ข้ามการซ่อนเมื่อผู้ใช้ถามถึงมันตรง ๆ — ระบุ kind มา หรือค้นด้วยคำ
        // ซ่อนคือ "อย่าเอามาแย่งที่หน้าแรก" ไม่ใช่ "ลบทิ้ง" ของที่หาไม่เจอ
        // ทั้งที่ยังอยู่ในฐานข้อมูล แย่กว่าของที่รก เพราะไม่มีใครรู้ว่ามันหายไป
        $explicit = !empty($filter['kind']) || (isset($filter['q']) && trim((string) $filter['q']) !== '');
        $board = $explicit ? [] : ReminderEngine::boardRules();

        $items = [];
        foreach ($rows as $row) {
            if (!$explicit && !ReminderEngine::isVisible($board, (string) $row['kind'], (int) $row['source_id'])) {
                continue;
            }

            // สถานะที่ผู้ใช้กำหนดเองอยู่คนละตาราง sync จึงเขียนทับไม่ได้
            //
            // กรองที่นี่ไม่ใช่ใน SQL เพราะ LEFT JOIN ให้ NULL กับ item ที่ผู้ใช้
            // ยังไม่เคยแตะ และ `NULL IN (...)` ไม่เป็นจริงใน SQL — เขียนเป็น
            // เงื่อนไข SQL ตรง ๆ จะกรอง item ที่ปกติดีทิ้งทั้งหมดแบบเงียบ ๆ
            if (!in_array($row['state'], ['none', 'snoozed', null], true)) {
                continue;
            }

            // เลื่อนไว้แล้วและยังไม่ถึงเวลา — ยังไม่ต้องเห็น
            if ($row['state'] === 'snoozed' && !empty($row['snooze_until'])
                && strtotime($row['snooze_until']) > $now) {
                continue;
            }

            $at = $row['due_at'] ?: $row['start_at'];
            if ($at === null) {
                continue;
            }
            $ts = strtotime($at);

            if ($ts < strtotime('today')) {
                $bucket = 'overdue';
            } elseif ($ts <= $todayEnd) {
                $bucket = 'today';
            } elseif ($ts <= $soonEnd) {
                $bucket = 'soon';
            } else {
                $bucket = 'upcoming';
            }

            $row['at'] = $at;
            $row['bucket'] = $bucket;
            $row['days'] = (int) floor(($ts - strtotime('today')) / 86400);
            $row['contact'] = self::decode($row['contact_json']);
            $row['links'] = self::decode($row['links_json']) ?: [];
            $row['actions'] = self::decode($row['actions_json']) ?: [];
            $row['facts'] = self::decode($row['facts_json']) ?: [];
            $row['meta'] = self::decode($row['meta_json']) ?: [];
            $row['hint'] = self::decode($row['hint_json']) ?: [];
            unset($row['contact_json'], $row['links_json'], $row['actions_json'], $row['facts_json'], $row['meta_json'], $row['hint_json']);

            $items[] = $row;
        }

        return $items;
    }

    /**
     * ข้อความบอกเวลา — **ต้องมีวันที่จริงเสมอ ไม่ใช่แค่ระยะห่าง**
     *
     * "อีก 353 วัน" ตอบไม่ได้ว่าวันไหน คนอ่านต้องนับปฏิทินเอง และถ้าอยู่ในแชต
     * ก็ต้องพิมพ์ถามกลับอีกรอบ · ทุกที่ที่แสดงเวลาของ item เรียกฟังก์ชันนี้
     * ตัวเดียว การรวมวันที่ไว้ที่นี่จึงทำให้ "ทุกจอบอกวันที่ครบ" โดยไม่ต้องไล่แก้
     * ทีละที่ และไม่มีทางลืมเมื่อเพิ่มจอใหม่
     *
     * **วันที่คาดการณ์ต้องอ่านออกว่าเป็นการคาดการณ์** (`hint.estimated`)
     * โฮสติ้งหมดอายุคือวันที่แน่นอน แต่ "งวดถัดไปของลูกหนี้" เป็นการเดาจากงวด
     * ที่ชำระล่าสุด · เขียนเหมือนกันทั้งสองอย่างแปลว่าชวนให้เชื่อวันที่เดามา
     * เท่ากับวันที่จริง แล้วเสียเวลาไปกับการทวงในวันที่ไม่มีใครสัญญาไว้
     *
     * @param int    $days
     * @param bool   $allDay
     * @param string $at
     * @param bool   $estimated ต้นทางบอกว่าวันนี้เป็นการคาดการณ์ ไม่ใช่กำหนดจริง
     *
     * @return string
     */
    public static function whenText($days, $allDay, $at, $estimated = false)
    {
        if ($days < 0) {
            $relative = Language::sprintf($estimated ? '%d days past the estimated date' : '%d days overdue', abs($days));
        } elseif ($days === 0) {
            $relative = Language::get($estimated ? 'Expected today' : 'Today');
        } elseif ($days === 1) {
            $relative = Language::get($estimated ? 'Expected tomorrow' : 'Tomorrow');
        } else {
            $relative = Language::sprintf($estimated ? 'Expected in %d days' : 'In %d days', $days);
        }

        return $relative.' · '.self::stamp($at, $allDay);
    }

    /**
     * ต้นทางบอกไหมว่าวันของ item ใบนี้เป็นการคาดการณ์
     *
     * @param array $item รูปที่ Attention::items() คืนมา (hint ถอดแล้ว)
     *
     * @return bool
     */
    public static function isEstimated(array $item)
    {
        return !empty($item['hint']['estimated']);
    }

    /**
     * วัน (และเวลา ถ้ามีความหมาย) ในรูปที่คนไทยอ่านออกทันที
     *
     * ไม่แสดง "00:00" — ต้นทางที่ส่งมาแต่วันที่ไม่ได้ตั้ง all_day เสมอไป
     * (โฮสติ้งหมดอายุส่ง `2027-08-25 00:00:00` มาโดยที่ all_day เป็น 0)
     * การพิมพ์เที่ยงคืนออกมาจึงเป็นการเดาแทนต้นทาง ไม่ใช่การรายงานสิ่งที่มันบอก
     *
     * @param string $at
     * @param bool   $allDay
     *
     * @return string
     */
    public static function stamp($at, $allDay = false)
    {
        $time = strtotime($at);
        $date = \Kotchasan\Date::format($at, 'j M Y');

        return $allDay || date('H:i', $time) === '00:00'
            ? $date
            : $date.' '.date(Language::get('TIME_FORMAT'), $time);
    }

    /**
     * ทุกอย่างที่ต้นทางส่งมาให้คนอ่าน — facts บวกช่องทางติดต่อ
     *
     * ที่เดียวสำหรับทุกช่องทาง (แชต · ข้อความเตือน · อีเมล) เพราะกติกาของระบบนี้
     * คือ "ส่งทุกช่องทางด้วยข้อความเดียวกัน" ถ้าแต่ละที่ประกอบเอง สักพักจะเพี้ยน
     * กันจนตอบไม่ได้ว่าใครเห็นอะไร
     *
     * ไม่รวม `meta` — เป็นข้อมูลสำหรับเครื่องอ่าน ไม่ใช่สำหรับคน
     * (`TIMELINE-PROTOCOL.md` §6.6) ต้นทางที่อยากให้คนเห็นอะไรต้องใส่ใน `facts`
     *
     * @param array $facts   จาก facts_json
     * @param array $contact จาก contact_json
     *
     * @return array บรรทัดพร้อมแสดง
     */
    public static function details(array $facts, array $contact)
    {
        $out = [];
        $shown = '';

        foreach ($facts as $fact) {
            $label = trim((string) ($fact['label'] ?? ''));
            $value = trim((string) ($fact['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                $out[] = '• '.$label.': '.$value;
                $shown .= ' '.$value;
            }
        }

        // ต้นทางหลายที่ใส่เบอร์กับอีเมลไว้ทั้งใน facts และ contact — พิมพ์ทั้งสอง
        // ที่จะได้บรรทัดซ้ำติดกัน ซึ่งอ่านแล้วเหมือนระบบส่งข้อมูลผิด
        $who = [];
        foreach (['name' => '', 'phone' => '📞 ', 'email' => '✉️ '] as $key => $icon) {
            $value = trim((string) ($contact[$key] ?? ''));
            if ($value !== '' && mb_strpos($shown, $value) === false) {
                $who[] = $icon.$value;
            }
        }
        if (!empty($who)) {
            $out[] = implode(' · ', $who);
        }

        return $out;
    }

    /**
     * @param string|null $json
     *
     * @return array|null
     */
    private static function decode($json)
    {
        if (empty($json)) {
            return null;
        }
        $value = json_decode($json, true);

        return is_array($value) ? $value : null;
    }
}
