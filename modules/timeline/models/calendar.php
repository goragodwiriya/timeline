<?php
/**
 * @filesource modules/timeline/models/calendar.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Calendar;

use Gcms\Timeline\Attention;
use Gcms\Timeline\ReminderEngine;
use Timeline\Reminders\Model as Rules;
use Kotchasan\Language;

/**
 * ภาพรวมนัดหมายเป็นปฏิทิน
 *
 * ตอบคนละคำถามกับหน้า "วันนี้" — หน้านั้นตอบว่า *ตอนนี้ต้องทำอะไร* เรียงตาม
 * ความเร่งด่วน ส่วนหน้านี้ตอบว่า *เดือนนี้หน้าตาเป็นอย่างไร* ซึ่งเป็นคำถามที่
 * รายการเรียงบรรทัดตอบไม่ได้เลย: วันไหนว่าง วันไหนชนกัน สัปดาห์ไหนแน่นผิดปกติ
 *
 * ไม่ใช้ Attention::items() เพราะที่นี่ต้องเห็นของที่ "จัดการแล้ว" ด้วย
 * ปฏิทินที่ลบสิ่งที่ทำไปแล้วออกคือปฏิทินที่โกหกว่าสัปดาห์นั้นว่าง
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * สีตามชนิด · นัดหมายเด่นที่สุดเพราะเป็นเหตุผลที่หน้านี้มีอยู่
     * ที่เหลือใช้สีจางกว่า ให้เห็นว่ามีอะไรชนอยู่โดยไม่แย่งสายตา
     */
    const COLORS = [
        'appointment' => '#2563eb',
        'payment' => '#dc2626',
        'expiry' => '#d97706',
        'task' => '#7c3aed',
        'renewal' => '#0891b2',
        'followup' => '#65a30d'
    ];

    /**
     * สีของเรื่องที่จัดการไปแล้ว — ยังอยู่บนปฏิทินแต่ต้องไม่ดูเหมือนงานค้าง
     */
    const DONE_COLOR = '#9ca3af';

    /**
     * เหตุการณ์ในช่วงวันที่ที่ปฏิทินขอมา
     *
     * @param string $start YYYY-MM-DD
     * @param string $end   YYYY-MM-DD
     *
     * @return array
     */
    public static function events($start, $end)
    {
        $rows = static::createQuery()
            ->select(
                'I.id', 'I.kind', 'I.source_id', 'I.title', 'I.subtitle',
                'I.start_at', 'I.end_at', 'I.due_at', 'I.all_day', 'I.priority',
                'I.entity_label', 'I.facts_json', 'I.contact_json', 'I.hint_json', 'I.meta_json',
                'Src.name AS source_name',
                'S.state'
            )
            ->from('items I')
            ->join('sources Src', [['Src.id', 'I.source_id']], 'INNER')
            ->join('item_state S', [['S.item_id', 'I.id']], 'LEFT')
            ->where([
                ['I.vanished_at', null],
                ['I.status', 'active']
            ])
            ->cacheOff()
            ->fetchAll(true);

        // ชนิดที่ถูกสั่งซ่อนจากกระดาน ต้องซ่อนที่นี่ด้วย — ไม่งั้นสวิตช์เดียว
        // ให้ผลสองอย่าง แล้วคนตั้งค่าจะไม่มีทางรู้ว่าตกลงมันทำอะไร
        $board = ReminderEngine::boardRules();

        $from = strtotime($start.' 00:00:00');
        $to = strtotime($end.' 23:59:59');
        $events = [];

        foreach ($rows as $row) {
            if (!ReminderEngine::isVisible($board, (string) $row['kind'], (int) $row['source_id'])) {
                continue;
            }

            $at = $row['due_at'] ?: $row['start_at'];
            if ($at === null) {
                continue;
            }
            $begin = strtotime($at);
            // เรื่องที่มีเวลาแต่ไม่มีเวลาสิ้นสุด ให้กินพื้นที่หนึ่งชั่วโมง
            //
            // ส่ง end เท่ากับ start จะได้แท่งที่ยาวศูนย์นาที ซึ่งในมุมมองสัปดาห์
            // แทบมองไม่เห็นและกดไม่โดน · ของที่เป็นทั้งวันไม่มีปัญหานี้
            $finish = empty($row['end_at']) ? $begin : strtotime((string) $row['end_at']);
            $timed = empty($row['all_day']) && date('H:i', $begin) !== '00:00';
            if ($timed && $finish <= $begin) {
                $finish = $begin + 3600;
            }
            if ($finish < $from || $begin > $to) {
                continue;
            }

            $done = in_array($row['state'], ['done', 'dismissed'], true);
            $events[] = [
                'id' => 'tl-'.(int) $row['id'],
                'title' => ($done ? '✓ ' : '').self::label($row),
                'start' => date('Y-m-d H:i:s', $begin),
                'end' => date('Y-m-d H:i:s', max($finish, $begin)),
                'allDay' => !$timed,
                'color' => $done ? self::DONE_COLOR : self::color((string) $row['kind']),
                'description' => self::describe($row),
                'location' => self::location($row),
                // ตัวจริงที่หน้าจอใช้ตอนกดดูรายละเอียด
                'item_id' => (int) $row['id'],
                'kind' => (string) $row['kind'],
                'kind_label' => isset(Rules::LABELS[(string) $row['kind']]) ? Language::get(Rules::LABELS[(string) $row['kind']]) : (string) $row['kind'],
                'source_name' => (string) $row['source_name'],
                'state' => $done ? 'done' : 'open',
                'when' => Attention::whenText(
                    (int) floor((strtotime(date('Y-m-d', $begin)) - strtotime('today')) / 86400),
                    !empty($row['all_day']),
                    $at,
                    self::estimated($row)
                )
            ];
        }

        usort($events, static function ($a, $b) {
            return strcmp($a['start'], $b['start']);
        });

        return $events;
    }

    /**
     * ชื่อบนช่องปฏิทิน — สั้นพอจะอ่านออกในช่องแคบ ๆ
     *
     * ใส่ชื่อเจ้าของเรื่องต่อท้ายเมื่อไม่ซ้ำกับหัวข้อ · "หมอนัด" สามใบเรียงกัน
     * บอกอะไรไม่ได้เลยว่าใบไหนของใคร
     *
     * @param array $row
     *
     * @return string
     */
    private static function label(array $row)
    {
        $title = trim((string) $row['title']);
        $entity = trim((string) $row['entity_label']);
        if ($entity !== '' && mb_strpos($title, $entity) === false) {
            $title .= ' · '.$entity;
        }

        return $title;
    }

    /**
     * @param string $kind
     *
     * @return string
     */
    private static function color($kind)
    {
        return self::COLORS[$kind] ?? self::COLORS[explode('.', $kind)[0]] ?? '#6b7280';
    }

    /**
     * ทุกอย่างที่ต้นทางส่งมา ประกอบเป็นข้อความเดียวสำหรับหน้าต่างรายละเอียด
     *
     * ใช้ตัวประกอบเดียวกับแชตและข้อความเตือน จะได้ไม่มีจอไหนเห็นน้อยกว่าจออื่น
     *
     * @param array $row
     *
     * @return string
     */
    private static function describe(array $row)
    {
        $facts = json_decode((string) $row['facts_json'], true);
        $contact = json_decode((string) $row['contact_json'], true);

        $lines = [];
        if (trim((string) $row['subtitle']) !== '') {
            $lines[] = (string) $row['subtitle'];
        }
        foreach (Attention::details(is_array($facts) ? $facts : [], is_array($contact) ? $contact : []) as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array $row
     *
     * @return string
     */
    private static function location(array $row)
    {
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true);

        return is_array($meta) ? trim((string) ($meta['location'] ?? '')) : '';
    }

    /**
     * @param array $row
     *
     * @return bool
     */
    private static function estimated(array $row)
    {
        $hint = json_decode((string) $row['hint_json'], true);

        return is_array($hint) && !empty($hint['estimated']);
    }
}
