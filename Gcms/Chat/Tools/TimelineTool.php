<?php
/**
 * @filesource Gcms/Chat/Tools/TimelineTool.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Chat\Tools;

use Gcms\Chat\Message;
use Gcms\Chat\Response;
use Gcms\Chat\ToolInterface;
use Gcms\Timeline\Attention;

/**
 * ถามและสั่งงาน timeline จากห้องแชต
 *
 * รับสองแบบ: คำสั่งที่คนพิมพ์ (`/today`, `/overdue`) และปุ่มที่กดจากข้อความเตือน
 * ซึ่งมาถึงเป็นข้อความ `tl:done:12` — Telegram ส่ง callback_data มาทางเดียวกับ
 * ข้อความปกติอยู่แล้ว จึงไม่ต้องมีทางเดินข้อมูลเส้นที่สอง
 *
 * **ทุกเส้นทางต้องผ่าน binding ก่อนเสมอ** — บอตอยู่บนอินเทอร์เน็ตที่ใครก็ทักได้
 * และสิ่งที่ตอบกลับคือหนี้สิน ลูกค้า และนัดหมอ (HUB-DESIGN.md §8.2)
 *
 * @since 1.0
 */
class TimelineTool extends \Kotchasan\KBase implements ToolInterface
{
    /**
     * จำนวนการ์ดสูงสุดต่อข้อความ — แชตที่ยาวเกินไปไม่มีใครอ่าน
     */
    const MAX_CARDS = 10;

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function name()
    {
        return 'timeline';
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function description()
    {
        return 'ดูและจัดการรายการที่ต้องสนใจ: /today /overdue /week /status และปุ่มบนข้อความเตือน';
    }

    /**
     * {@inheritdoc}
     *
     * @param Message $message
     *
     * @return bool
     */
    public function supports(Message $message)
    {
        return $this->parse($message->text) !== null;
    }

    /**
     * {@inheritdoc}
     *
     * @param Message $message
     *
     * @return Response
     */
    public function handle(Message $message)
    {
        $intent = $this->parse($message->text);
        if ($intent === null) {
            return $this->reply($message, 'ไม่เข้าใจคำสั่งนี้');
        }

        // ด่านเดียวที่กันไม่ให้คนแปลกหน้าอ่านข้อมูลได้ — ต้องอยู่ก่อนทุกอย่าง
        // และต้องไม่บอกด้วยซ้ำว่าระบบนี้คืออะไร
        if (!$this->isLinked($message)) {
            return $this->reply($message, 'ต้องผูกบัญชีก่อนจึงจะใช้งานได้ — เปิดหน้า "บัญชีแชต" ในระบบเพื่อรับรหัส แล้วพิมพ์ /link ตามด้วยรหัสนั้น');
        }

        switch ($intent['action']) {
            case 'today':
                return $this->listCards($message, ['overdue', 'today'], 'วันนี้และที่เกินกำหนด');
            case 'overdue':
                return $this->listCards($message, ['overdue'], 'เกินกำหนด');
            case 'week':
                return $this->listCards($message, ['today', 'soon'], 'ภายในเจ็ดวัน');
            case 'status':
                return $this->status($message);
            case 'menu':
                return $this->menu($message);
            case 'appts':
                return $this->appointments($message, true);
            case 'allappts':
                return $this->appointments($message, false);
            case 'free':
                return $this->freeBusy($message, $intent['date'] ?? null);
            case 'find':
                return $this->find($message, $intent['q']);
            case 'done':
                return $this->setState($message, $intent['id'], 'done', 0, 'ทำเครื่องหมายว่าจัดการแล้ว');
            case 'snooze':
                return $this->setState($message, $intent['id'], 'snoozed', $intent['days'], 'เลื่อนไป '.$intent['days'].' วัน');
            case 'ask':
                return $this->askAction($message, $intent['id'], $intent['act']);
            case 'run':
                return $this->runAction($message, $intent['id'], $intent['act'], $intent['value']);
        }

        return $this->reply($message, 'ไม่เข้าใจคำสั่งนี้');
    }

    /**
     * แปลข้อความเป็นคำสั่ง · null = ไม่ใช่งานของ tool นี้
     *
     * @param string $text
     *
     * @return array|null
     */
    private function parse($text)
    {
        $text = trim(mb_strtolower((string) $text));
        if ($text === '') {
            return null;
        }

        // ปุ่มจากข้อความเตือน
        if (preg_match('/^tl:done:(\d+)$/', $text, $m)) {
            return ['action' => 'done', 'id' => (int) $m[1]];
        }
        if (preg_match('/^tl:snooze:(\d+):(\d+)$/', $text, $m)) {
            return ['action' => 'snooze', 'id' => (int) $m[1], 'days' => max(1, min((int) $m[2], 365))];
        }
        // ถามว่าจะเลือกอะไร — ปุ่มชั้นแรกของ action ที่ต้นทางประกาศไว้
        if (preg_match('/^tl:act:(\d+):([a-z0-9_]+)$/', $text, $m)) {
            return ['action' => 'ask', 'id' => (int) $m[1], 'act' => $m[2]];
        }
        // ลงมือทำ — ปุ่มชั้นที่สองที่พกค่าที่เลือกมาด้วย
        if (preg_match('/^tl:run:(\d+):([a-z0-9_]+):([^:]*)$/', $text, $m)) {
            return ['action' => 'run', 'id' => (int) $m[1], 'act' => $m[2], 'value' => $m[3]];
        }

        // เมนูปุ่ม — ทางเดียวที่ใช้ได้ในช่อง เพราะ Telegram ไม่แสดงเมนู "/"
        // ที่นั่น (scope ของ setMyCommands ไม่ครอบคลุม channel)
        if ($text === '/menu' || $text === 'เมนู') {
            return ['action' => 'menu'];
        }
        if (preg_match('/^tl:cmd:(today|overdue|week|status|menu|appts|allappts|free)$/', $text, $m)) {
            return ['action' => $m[1]];
        }

        // ค้นหา — ตอบ "ขอดูสถานะล่าสุดของคนนี้" โดยไม่ต้องเข้าระบบ
        if (preg_match('/^(?:\/find|\/search|ค้นหา|หา)\s+(.{1,60})$/u', $text, $m)) {
            return ['action' => 'find', 'q' => trim($m[1])];
        }

        // ตรวจว่าวันไหนว่าง — ทั้งแบบสั่งตรงและแบบถามเป็นประโยค
        if (preg_match('/^\/free\s*(.{0,40})$/u', $text, $m)) {
            $arg = trim($m[1]);

            return ['action' => 'free', 'date' => $arg === '' ? null : \Gcms\Timeline\ThaiDateTime::date($arg)];
        }
        if (preg_match('/(ว่างไหม|ว่างมั้ย|ว่างรึเปล่า|มีนัดไหม|มีนัดมั้ย|ติดนัดไหม|ติดอะไรไหม)/u', $text)) {
            return ['action' => 'free', 'date' => \Gcms\Timeline\ThaiDateTime::date($text)];
        }

        // รายการนัดหมาย — แยกจาก /appt ที่เป็นการ "ลงนัดใหม่"
        if (preg_match('/^(?:\/allappts|นัดทั้งหมด|ดูนัดทั้งหมด)$/u', $text)) {
            return ['action' => 'allappts'];
        }
        if (preg_match('/^(?:\/appts|\/appointments|นัดหมาย|ดูนัด|นัดที่จะถึง)$/u', $text)) {
            return ['action' => 'appts'];
        }

        $map = [
            'today' => ['/today', 'วันนี้', 'มีอะไรบ้าง'],
            'overdue' => ['/overdue', 'ค้าง', 'เกินกำหนด'],
            'week' => ['/week', 'สัปดาห์นี้', 'อาทิตย์นี้'],
            'status' => ['/status', 'สถานะ', 'การเชื่อมต่อ']
        ];
        foreach ($map as $action => $words) {
            foreach ($words as $word) {
                if ($text === $word || $text === mb_substr($word, 1)) {
                    return ['action' => $action];
                }
            }
        }

        return null;
    }

    /**
     * ห้องแชตนี้ผูกกับผู้ใช้ในระบบแล้วหรือยัง
     *
     * ใช้คอลัมน์ที่ระบบสมาชิกมีอยู่แล้ว (line_uid / telegram_id) ไม่ต้องมีตาราง
     * ผูกบัญชีของตัวเอง — เป็นค่าเดียวกับที่การเข้าสู่ระบบด้วย social ใช้
     *
     * @param Message $message
     *
     * @return bool
     */
    private function isLinked(Message $message)
    {
        $id = trim((string) $message->conversationId);
        if ($id === '') {
            return false;
        }
        if ($message->channel === 'web') {
            // ช่องทางเว็บผ่านการล็อกอินมาแล้ว แต่ยังต้องมีสิทธิ์เหมือนกัน
            return \Gcms\Timeline\Access::allow($message->user);
        }

        // เดิมเช็กแค่ว่ามีแถวไหนสักแถวที่ผูกห้องนี้ไว้ — บัญชีที่ถูกระงับหรือ
        // ไม่มีสิทธิ์ก็ผ่าน และบัญชีที่ระบบสร้างให้อัตโนมัติตอนคุยกับบอตก็ผ่านด้วย
        return \Gcms\Timeline\Access::allowMember($this->memberId($message));
    }

    /**
     * @param Message $message
     * @param array   $buckets
     * @param string  $title
     *
     * @return Response
     */
    private function listCards(Message $message, array $buckets, $title)
    {
        $groups = Attention::buckets();
        $items = [];
        foreach ($buckets as $bucket) {
            $items = array_merge($items, $groups[$bucket]);
        }

        if (empty($items)) {
            return $this->reply($message, '✅ '.$title.' — ไม่มีอะไรต้องสนใจ');
        }

        $lines = [$title.' '.count($items).' รายการ', ''];
        foreach (array_slice($items, 0, self::MAX_CARDS) as $item) {
            $lines[] = $this->line($item);
        }
        if (count($items) > self::MAX_CARDS) {
            $lines[] = '';
            $lines[] = '… และอีก '.(count($items) - self::MAX_CARDS).' รายการ';
        }

        return $this->reply($message, implode("\n", $lines), array_slice($items, 0, self::MAX_CARDS));
    }

    /**
     * @param array $item
     *
     * @return string
     */
    private function line(array $item)
    {
        $at = $item['due_at'] ?: $item['start_at'];
        $mark = $item['days'] < 0 || $item['priority'] === 'critical' ? '🔴'
            : ($item['days'] === 0 || $item['priority'] === 'high' ? '🟠' : '🟡');

        $line = $mark.' '.$item['title']."\n   ".Attention::whenText($item['days'], !empty($item['all_day']), $at, Attention::isEstimated($item))
            .' · '.$item['source_name'].' · #'.$item['id'];

        // บรรทัดรองคือที่ที่ต้นทางใส่ตัวเลขสำคัญไว้ เช่นดอกเบี้ยค้างกับยอดไถ่ถอน
        // ตัดทิ้งแปลว่าในแชตเห็นแค่ชื่อกับวัน ซึ่งตอบไม่ได้ว่าตอนนี้สถานะเป็นอย่างไร
        // — และนั่นคือคำถามเดียวที่คนเปิดแชตมาถาม
        $subtitle = trim((string) ($item['subtitle'] ?? ''));
        if ($subtitle !== '') {
            $line .= "\n   ".mb_substr($subtitle, 0, 160);
        }

        // ทุกอย่างที่ต้นทางส่งมา ต้องอยู่ในรายการเลย ไม่ใช่ต้องกดดูต่อ
        //
        // แชตไม่มีพื้นที่ให้ "คลิกเพื่อดูเพิ่ม" ที่ได้ผล — คนอ่านบนมือถือระหว่างทำ
        // อย่างอื่นอยู่ ถ้าต้องพิมพ์คำสั่งถามซ้ำเพื่อรู้ยอดเงินหรือเบอร์โทร
        // ก็เท่ากับไม่ได้ตอบ · ต้นทางเป็นคนเลือกแล้วว่าอะไรสำคัญพอจะส่งมา
        // Hub มีหน้าที่แสดงให้ครบ ไม่ใช่คัดอีกชั้น
        foreach (self::details($item) as $detail) {
            $line .= "\n   ".$detail;
        }

        return $line;
    }

    /**
     * บรรทัดข้อมูลประกอบทั้งหมดที่ต้นทางส่งมากับ item ใบนี้
     *
     * ประกอบที่ Attention::details() ที่เดียว ให้ตรงกับข้อความเตือนเป๊ะ ๆ
     *
     * @param array $item
     *
     * @return array
     */
    private static function details(array $item)
    {
        return Attention::details(
            (array) ($item['facts'] ?? []),
            (array) ($item['contact'] ?? [])
        );
    }

    /**
     * รายการนัดหมาย
     *
     * แยกจาก /appt ที่เป็นการ "ลงนัดใหม่" — คนละเรื่องกันแต่ชื่อใกล้กันมาก
     * จึงตั้งชื่อคำสั่งให้ต่างกันชัด ๆ (`/appt` ลงนัด · `/appts` ดูนัด)
     *
     * @param Message $message
     * @param bool    $upcomingOnly true = เฉพาะที่ยังมาไม่ถึง
     *
     * @return Response
     */
    private function appointments(Message $message, $upcomingOnly)
    {
        $items = Attention::items(['kind' => 'appointment']);
        usort($items, static function ($a, $b) {
            return strcmp((string) $a['at'], (string) $b['at']);
        });

        if ($upcomingOnly) {
            $items = array_values(array_filter($items, static function ($item) {
                return $item['days'] >= 0;
            }));
        }

        $title = $upcomingOnly ? 'นัดที่ยังมาไม่ถึง' : 'นัดหมายทั้งหมด';
        if (empty($items)) {
            return $this->reply(
                $message,
                '📋 '.$title.' — ไม่มี'.($upcomingOnly ? "\n(พิมพ์ /allappts เพื่อดูที่ผ่านมาแล้วด้วย)" : ''),
                [],
                [['type' => 'callback', 'label' => '➕ ลงนัดใหม่', 'data' => 'tl:cmd:appt']]
            );
        }

        // ที่ผ่านมาแล้วเรียงจากใหม่ไปเก่า ที่ยังไม่ถึงเรียงจากใกล้ไปไกล —
        // ของที่อยู่ใกล้ "วันนี้" ที่สุดคือของที่คนเปิดดูอยากเห็นก่อน
        $past = [];
        $future = [];
        foreach ($items as $item) {
            if ($item['days'] < 0) {
                $past[] = $item;
            } else {
                $future[] = $item;
            }
        }
        $past = array_reverse($past);

        $lines = ['📋 '.$title.' '.count($items).' รายการ', ''];
        foreach (array_slice($future, 0, self::MAX_CARDS) as $item) {
            $lines[] = $this->line($item);
        }
        if (!$upcomingOnly && !empty($past)) {
            $lines[] = '';
            $lines[] = '— ผ่านมาแล้ว —';
            foreach (array_slice($past, 0, self::MAX_CARDS) as $item) {
                $lines[] = $this->line($item);
            }
        }

        return $this->reply(
            $message,
            implode("\n", $lines),
            array_slice($future, 0, self::MAX_CARDS),
            [['type' => 'callback', 'label' => '➕ ลงนัดใหม่', 'data' => 'tl:cmd:appt']]
        );
    }

    /**
     * ว่างไหม — วันเจาะจง หรือภาพรวมเจ็ดวันข้างหน้า
     *
     * ไม่ระบุวันแล้วตอบว่า "ระบุวันด้วย" เป็นคำตอบที่ไร้ประโยชน์ · ตอบเป็น
     * ภาพรวมเจ็ดวันไปเลย ซึ่งเป็นสิ่งที่คนถามอยากรู้อยู่แล้วในกรณีส่วนใหญ่
     *
     * @param Message     $message
     * @param string|null $date Y-m-d
     *
     * @return Response
     */
    private function freeBusy(Message $message, $date)
    {
        $byDate = [];
        foreach (Attention::items(['kind' => 'appointment']) as $item) {
            $byDate[substr((string) $item['at'], 0, 10)][] = $item;
        }

        if ($date !== null) {
            $on = $byDate[$date] ?? [];
            $when = \Kotchasan\Date::format($date, 'j M Y');
            if (empty($on)) {
                return $this->reply($message, '🟢 '.$when." — ว่าง ไม่มีนัด");
            }

            usort($on, static function ($a, $b) {
                return strcmp((string) $a['at'], (string) $b['at']);
            });
            $lines = ['🔴 '.$when.' — มีนัด '.count($on).' รายการ', ''];
            foreach ($on as $item) {
                $lines[] = ($item['all_day'] ? 'ทั้งวัน' : substr((string) $item['at'], 11, 5).' น.')
                    .' · '.$item['title']
                    .($item['subtitle'] === '' ? '' : "\n   ".$item['subtitle']);
            }

            return $this->reply($message, implode("\n", $lines), $on);
        }

        $lines = ['🗓 เจ็ดวันข้างหน้า', ''];
        for ($i = 0; $i < 7; ++$i) {
            $day = date('Y-m-d', strtotime('+'.$i.' days'));
            $on = $byDate[$day] ?? [];
            $label = \Kotchasan\Date::format($day, 'D j M');
            if (empty($on)) {
                $lines[] = '🟢 '.$label.' — ว่าง';
                continue;
            }
            $names = [];
            foreach ($on as $item) {
                $names[] = ($item['all_day'] ? '' : substr((string) $item['at'], 11, 5).' ').$item['title'];
            }
            $lines[] = '🔴 '.$label.' — '.implode(' · ', $names);
        }
        $lines[] = '';
        $lines[] = 'ตรวจวันอื่นพิมพ์ /free ตามด้วยวันที่ เช่น /free 10 หรือ /free 1 ตค';

        return $this->reply($message, implode("\n", $lines));
    }

    /**
     * ค้นหาข้ามทุกระบบด้วยชื่อ
     *
     * แสดง `facts` ที่ต้นทางส่งมาด้วย เพราะจุดประสงค์ของการค้นคือ "ขอดูสถานะ
     * ล่าสุดของรายนี้" ไม่ใช่แค่ยืนยันว่ามีอยู่ · แสดงเต็มเมื่อเจอรายเดียว
     * ถ้าเจอหลายรายให้เลือกก่อน ไม่งั้นข้อความยาวจนหาไม่เจอว่าอันไหนเป็นอันไหน
     *
     * @param Message $message
     * @param string  $query
     *
     * @return Response
     */
    private function find(Message $message, $query)
    {
        $items = Attention::items(['q' => $query]);
        if (empty($items)) {
            return $this->reply($message, '🔍 ไม่พบ "'.$query.'" ในระบบไหนเลย');
        }

        usort($items, static function ($a, $b) {
            return strcmp((string) $a['at'], (string) $b['at']);
        });

        if (count($items) > 1) {
            $lines = ['🔍 พบ '.count($items).' รายการที่ตรงกับ "'.$query.'"', ''];
            foreach (array_slice($items, 0, self::MAX_CARDS) as $item) {
                $lines[] = $this->line($item);
            }
            if (count($items) > self::MAX_CARDS) {
                $lines[] = '';
                $lines[] = '… และอีก '.(count($items) - self::MAX_CARDS).' รายการ — พิมพ์คำค้นให้เจาะจงกว่านี้';
            }

            return $this->reply($message, implode("\n", $lines), array_slice($items, 0, self::MAX_CARDS));
        }

        return $this->reply($message, $this->detail($items[0]), $items);
    }

    /**
     * รายละเอียดเต็มของรายการเดียว รวมข้อมูลสรุปที่ต้นทางส่งมา
     *
     * @param array $item
     *
     * @return string
     */
    private function detail(array $item)
    {
        $at = $item['due_at'] ?: $item['start_at'];
        $lines = [
            '📌 '.$item['title'],
            Attention::whenText($item['days'], !empty($item['all_day']), $at, Attention::isEstimated($item))
                .' · '.$item['source_name'].' · #'.$item['id']
        ];

        if (trim((string) $item['subtitle']) !== '') {
            $lines[] = $item['subtitle'];
        }

        $details = self::details($item);
        if (!empty($details)) {
            $lines[] = '';
            foreach ($details as $detail) {
                $lines[] = $detail;
            }
        }

        // ลิงก์ที่ต้นทางส่งมา — จอเว็บมีปุ่มให้กด แชตไม่มี ต้องพิมพ์ URL ออกมาตรง ๆ
        // ไม่งั้นข้อมูลชิ้นนี้หายไปเฉพาะในแชต ทั้งที่ต้นทางตั้งใจส่งมาให้
        $links = [];
        foreach ((array) ($item['links'] ?? []) as $link) {
            $url = trim((string) ($link['url'] ?? ''));
            if ($url !== '') {
                $links[] = trim((string) ($link['label'] ?? 'เปิด')).': '.$url;
            }
        }
        if (!empty($links)) {
            $lines[] = '';
            foreach ($links as $link) {
                $lines[] = '🔗 '.$link;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param Message $message
     *
     * @return Response
     */
    private function status(Message $message)
    {
        $rows = \Timeline\Connections\Model::listAll();
        if (empty($rows)) {
            return $this->reply($message, 'ยังไม่ได้เชื่อมต่อระบบต้นทางใด');
        }

        $lines = ['สถานะการเชื่อมต่อ', ''];
        foreach ($rows as $row) {
            $mark = !$row['enabled'] ? '⛔' : ($row['last_status'] === 'ok' && !$row['stale'] ? '✅' : '⚠️');
            $lines[] = $mark.' '.$row['name'].' · '.$row['items'].' รายการ · '.$row['last_ok_text'];
            if (!empty($row['last_error'])) {
                $lines[] = '   '.$row['last_error'];
            }
        }

        return $this->reply($message, implode("\n", $lines));
    }

    /**
     * @param Message $message
     * @param int     $itemId
     * @param string  $state
     * @param int     $days
     * @param string  $done
     *
     * @return Response
     */
    private function setState(Message $message, $itemId, $state, $days, $done)
    {
        try {
            \Timeline\Card\Model::setState($itemId, $state, $days);
        } catch (\Throwable $e) {
            return $this->reply($message, '⚠️ '.$e->getMessage());
        }

        $item = \Kotchasan\DB::create()->first('items', [['id', (int) $itemId]], ['title']);
        $title = $item === null ? '#'.$itemId : $item->title;

        return $this->reply($message, '✅ '.$title.' — '.$done);
    }

    /**
     * เมนูปุ่มสำหรับห้องที่พิมพ์ "/" แล้วไม่มีรายการคำสั่งขึ้นมาให้
     *
     * ในช่อง (channel) Telegram ไม่แสดงเมนูคำสั่งเลย เพราะการโพสต์ทำในนามช่อง
     * ไม่ใช่ในนามผู้ใช้ — ปุ่มบนข้อความจึงเป็นทางเดียวที่กดสั่งงานได้ที่นั่น
     * ปักหมุดข้อความนี้ไว้ในช่องแล้วใช้เป็นแผงควบคุมได้เลย
     *
     * @param Message $message
     *
     * @return Response
     */
    private function menu(Message $message)
    {
        return Response::text(
            "เลือกสิ่งที่ต้องการ\n"
            ."ค้นหาพิมพ์ `หา ตามด้วยชื่อ` · ตรวจวันเจาะจงพิมพ์ `/free 10`\n"
            ."(ปักหมุดข้อความนี้ไว้ใช้เป็นแผงควบคุมได้)",
            $message,
            $this->name(),
            [],
            [],
            [
                ['type' => 'callback', 'label' => '📅 วันนี้', 'data' => 'tl:cmd:today'],
                ['type' => 'callback', 'label' => '🔴 เกินกำหนด', 'data' => 'tl:cmd:overdue'],
                ['type' => 'callback', 'label' => '🗓 ภายใน 7 วัน', 'data' => 'tl:cmd:week'],
                ['type' => 'callback', 'label' => '➕ ลงนัดใหม่', 'data' => 'tl:cmd:appt'],
                ['type' => 'callback', 'label' => '📋 นัดที่จะถึง', 'data' => 'tl:cmd:appts'],
                ['type' => 'callback', 'label' => '🗂 นัดทั้งหมด', 'data' => 'tl:cmd:allappts'],
                ['type' => 'callback', 'label' => '🟢 ตรวจเวลาว่าง', 'data' => 'tl:cmd:free'],
                ['type' => 'callback', 'label' => '🔌 สถานะการเชื่อมต่อ', 'data' => 'tl:cmd:status']
            ]
        );
    }

    /**
     * ถามว่าจะเลือกค่าไหน ก่อนสั่งงานกลับไปยังระบบต้นทาง
     *
     * Hub ไม่รู้ว่า action ชื่อนี้แปลว่าอะไร — อ่านนิยามฟอร์มที่ต้นทางส่งมากับ
     * item แล้วทำปุ่มตามตัวเลือกที่ประกาศไว้ ไม่มีที่ไหนใน Hub รู้จักคำว่า
     * "ทวงหนี้" หรือรายการผลการติดตามของระบบลูกหนี้เลย
     *
     * ทำเป็นสองชั้นแทนการถามตอบหลายรอบ เพราะ Processor ไม่มีสถานะของบทสนทนา
     * ปุ่มชั้นที่สองจึงต้องพกทุกอย่างที่ต้องใช้มาเอง
     *
     * @param Message $message
     * @param int     $itemId
     * @param string  $actionId
     *
     * @return Response
     */
    private function askAction(Message $message, $itemId, $actionId)
    {
        $action = $this->findAction($itemId, $actionId);
        if ($action === null) {
            return $this->reply($message, '⚠️ รายการนี้ไม่มีปุ่มนั้นแล้ว');
        }

        $choices = $this->choices($action);
        if (empty($choices)) {
            // ไม่มีอะไรต้องเลือก ลงมือได้เลย
            return $this->runAction($message, $itemId, $actionId, '');
        }

        $buttons = [];
        foreach ($choices as $value => $label) {
            $buttons[] = [
                'type' => 'callback',
                'label' => $label,
                'data' => 'tl:run:'.((int) $itemId).':'.$actionId.':'.$value
            ];
        }

        return Response::text($action['label'], $message, $this->name(), [], [], $buttons);
    }

    /**
     * ส่ง action กลับไปให้ระบบต้นทางทำ
     *
     * @param Message $message
     * @param int     $itemId
     * @param string  $actionId
     * @param string  $value
     *
     * @return Response
     */
    private function runAction(Message $message, $itemId, $actionId, $value)
    {
        $action = $this->findAction($itemId, $actionId);
        if ($action === null) {
            return $this->reply($message, '⚠️ รายการนี้ไม่มีปุ่มนั้นแล้ว');
        }

        $params = [];
        foreach ($action['fields'] ?? [] as $field) {
            if ($field['type'] === 'select' && $value !== '') {
                $params[$field['name']] = is_numeric($value) ? (int) $value : $value;
            } elseif (!empty($field['required'])) {
                // ช่องบังคับที่ไม่ใช่ตัวเลือก กรอกจากปุ่มไม่ได้ — บอกให้ไปทำบนเว็บ
                // ดีกว่าส่งค่าว่างไปแล้วให้ต้นทางปฏิเสธด้วยข้อความที่อ่านไม่รู้เรื่อง
                return $this->reply($message, 'ปุ่มนี้ต้องกรอกข้อมูลเพิ่ม เปิดหน้าเว็บเพื่อบันทึกครับ');
            }
        }
        $params['detail'] = ($params['detail'] ?? '').' (บันทึกจากแชต)';

        try {
            $result = \Gcms\Timeline\ActionRunner::run(
                (int) $itemId,
                $actionId,
                $params,
                $this->memberId($message),
                $message->channel
            );
        } catch (\Throwable $e) {
            return $this->reply($message, '⚠️ '.$e->getMessage());
        }

        $item = \Kotchasan\DB::create()->first('items', [['id', (int) $itemId]], ['title', 'subtitle']);

        return $this->reply(
            $message,
            '✅ '.($item === null ? '' : $item->title."\n").$result['message']
                .($item === null ? '' : "\n".$item->subtitle)
        );
    }

    /**
     * นิยาม action ที่ต้นทางประกาศไว้กับ item ใบนี้
     *
     * @param int    $itemId
     * @param string $actionId
     *
     * @return array|null
     */
    private function findAction($itemId, $actionId)
    {
        $row = \Kotchasan\DB::create()->first('items', [['id', (int) $itemId]], ['actions_json']);
        if ($row === null) {
            return null;
        }
        foreach ((array) json_decode((string) $row->actions_json, true) as $action) {
            if (isset($action['id']) && $action['id'] === $actionId) {
                return $action;
            }
        }

        return null;
    }

    /**
     * ตัวเลือกของช่อง select ช่องแรก
     *
     * @param array $action
     *
     * @return array [value => label]
     */
    private function choices(array $action)
    {
        foreach ($action['fields'] ?? [] as $field) {
            if (($field['type'] ?? '') === 'select' && !empty($field['options'])) {
                $out = [];
                foreach ($field['options'] as $option) {
                    $out[(string) $option['value']] = (string) $option['label'];
                }

                return $out;
            }
        }

        return [];
    }

    /**
     * ผู้ใช้ที่ผูกกับห้องแชตนี้
     *
     * @param Message $message
     *
     * @return int
     */
    private function memberId(Message $message)
    {
        $channels = \Gcms\Timeline\ChatLink::CHANNELS;
        if (!isset($channels[$message->channel])) {
            return 0;
        }
        $row = \Kotchasan\DB::create()->first(
            'user',
            [[$channels[$message->channel], (string) $message->conversationId]],
            ['id']
        );

        return $row === null ? 0 : (int) $row->id;
    }

    /**
     * @param Message $message
     * @param string  $text
     * @param array   $cards
     *
     * @return Response
     */
    private function reply(Message $message, $text, array $cards = [], array $actions = [])
    {
        return Response::text($text, $message, $this->name(), [], $cards, $actions);
    }
}
