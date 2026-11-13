<?php
/**
 * @filesource Gcms/Timeline/Notifier.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

use Kotchasan\Language;

/**
 * ส่งการเตือนออกไปยังช่องทางที่ตั้งไว้
 *
 * รู้จักแค่ "ข้อความหน้าตาแบบไหน" กับ "ส่งไปทางไหน" — ไม่ตัดสินว่าควรส่งหรือไม่
 * นั่นเป็นงานของ ReminderEngine ทั้งหมด
 *
 * ทุกข้อความมีปุ่มติดไปด้วยเสมอ เพราะการเตือนที่ทำอะไรต่อไม่ได้ นอกจากเปิด
 * เครื่องมาดูเอง ก็แทบไม่ต่างจากไม่เตือน — ยกเว้นอีเมลซึ่งกดปุ่มไม่ได้อยู่แล้ว
 * จึงแนบลิงก์เปิดหน้าเว็บไปแทน
 *
 * **ส่งทุกช่องทางที่ตั้งค่าไว้เสมอ ด้วยข้อความเดียวกัน** ไม่ได้เลือกตามกฎ —
 * กฎตอบแค่ว่า "เตือนเรื่องนี้ไหม และล่วงหน้าเท่าไร" ส่วนจะไปถึงที่ไหนขึ้นกับว่า
 * ตั้งค่าช่องทางไหนไว้ · แยกสองเรื่องนี้ออกจากกันทำให้เพิ่มช่องทางใหม่ไม่ต้อง
 * ไล่แก้กฎทุกข้อ และไม่มีกรณี "ตั้งค่า LINE ไว้แล้วแต่ไม่เคยได้อะไรเลย"
 * เพราะลืมติ๊กในกฎ
 *
 * @since 1.0
 */
class Notifier extends \Kotchasan\KBase
{
    /**
     * ยังไม่ได้ตั้งค่าช่องทางใดเลย — ผู้เรียกใช้ค่านี้แยกแยะจากการส่งที่ล้มเหลวจริง
     */
    const NO_CHANNEL = 'No notification channel is enabled';

    /**
     * sendTo(): the channel is not set up — not a failed delivery
     */
    const OFF = 'off';

    /**
     * ช่องทางที่ส่งสำเร็จจริงในการเรียก send() ครั้งล่าสุด
     *
     * มีไว้ให้ ReminderEngine บันทึกว่าไปถึงที่ไหนบ้าง — เดิมบันทึกรายชื่อ
     * ช่องทางที่ "ตั้งใจจะส่ง" ซึ่งอ่านย้อนหลังแล้วเข้าใจผิดว่าอีเมลถึงแล้ว
     * ทั้งที่ตีกลับ
     *
     * @var array<string>
     */
    private static $lastDelivered = [];

    /**
     * @return array<string>
     */
    public static function lastDelivered()
    {
        return self::$lastDelivered;
    }

    /**
     * ส่งการเตือนหนึ่งรายการ
     *
     * @param array $row     แถวที่ join item + source มาแล้ว
     * @param array $channels ช่องทางที่ต้องส่ง
     *
     * @return string ข้อความผิดพลาด · ค่าว่างคือสำเร็จทุกช่องทาง
     */
    public static function send(array $row, ?array $channels = null)
    {
        // ไม่ระบุมา = ทุกช่องทางที่ตั้งค่าไว้
        if ($channels === null) {
            $channels = self::channels();
        }

        $text = self::compose($row);
        $errors = [];
        self::$lastDelivered = [];

        foreach ($channels as $channel) {
            $error = self::sendTo($channel, $row, $text);
            if ($error === '') {
                self::$lastDelivered[] = $channel;
            } elseif ($error !== self::OFF) {
                $errors[] = $channel.': '.$error;
            }
        }

        $delivered = count(self::$lastDelivered);

        // ไม่มีช่องทางไหนเปิดใช้งานเลย — ไม่ใช่ความล้มเหลวของการส่ง แต่ต้อง
        // บอกไว้ในบันทึก ไม่งั้นจะดูเหมือนเตือนไปแล้วทั้งที่ไม่มีใครได้รับ
        if ($delivered === 0 && empty($errors)) {
            return Language::get(self::NO_CHANNEL);
        }

        return implode(' · ', $errors);
    }

    /**
     * @param string $channel
     * @param array  $row
     * @param string $text
     *
     * @return string
     */
    /**
     * ช่องทางที่ตั้งค่าไว้จริงและพร้อมส่ง
     *
     * "เปิดใช้ไว้" วัดจากค่าที่ตั้งจริง ไม่ใช่สวิตช์แยกอีกชั้น — สวิตช์ที่ต้อง
     * เปิดสองที่คือสวิตช์ที่วันหนึ่งจะมีคนลืมเปิดที่หนึ่ง แล้วหาสาเหตุไม่เจอ
     *
     * @return array<string>
     */
    public static function channels()
    {
        $out = [];

        if (!empty(self::$cfg->telegram_bot_token) && !empty(self::$cfg->telegram_chat_id)) {
            $out[] = 'telegram';
        }
        if (!empty(self::$cfg->line_channel_access_token) && self::lineUid() !== '') {
            $out[] = 'line';
        }
        if (self::notifyEmail() !== '') {
            $out[] = 'email';
        }

        return $out;
    }

    /**
     * ปลายทางอีเมลของการแจ้งเตือน
     *
     * ใช้ $cfg->timeline_notify_email ถ้าตั้งไว้ · ไม่งั้นใช้อีเมลของผู้ดูแล
     * ระบบคนแรก ซึ่งเป็นคนเดียวกับเจ้าของ Hub ในการใช้งานจริง
     *
     * @return string
     */
    private static function notifyEmail()
    {
        $configured = trim((string) (self::$cfg->timeline_notify_email ?? ''));
        if ($configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_EMAIL) ? $configured : '';
        }

        // ต้องมี SMTP ก่อน ไม่งั้นส่งไม่ออกอยู่ดีแล้วจะขึ้นเป็นความล้มเหลวทุกรอบ
        if (empty(self::$cfg->email_Host)) {
            return '';
        }

        // ตาราง user ของเฟรมเวิร์กนี้ไม่มีคอลัมน์ email — `username` คืออีเมล
        // ที่ใช้เข้าระบบ (ดู $cfg->login_fields) จึงต้องอ่านจากตรงนั้น
        $row = \Kotchasan\DB::create()->first('user', [['status', 1]], ['username']);
        $email = $row === null ? '' : trim((string) $row->username);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * เนื้อความอีเมล — ข้อความเดียวกับช่องทางอื่น บวกลิงก์แทนปุ่ม
     *
     * อีเมลเป็นแบบอ่านอย่างเดียว กดจัดการหรือเลื่อนจากในเมลไม่ได้ · ถ้าไม่แนบ
     * ลิงก์ไปด้วย ผู้อ่านจะไม่มีทางไปต่อนอกจากเปิดระบบแล้วไล่หาเอง
     *
     * @param array  $row
     * @param string $text
     *
     * @return string
     */
    private static function emailBody(array $row, $text)
    {
        $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

        $links = json_decode((string) ($row['links_json'] ?? ''), true);
        if (is_array($links)) {
            foreach ($links as $link) {
                if (empty($link['url'])) {
                    continue;
                }
                $url = htmlspecialchars((string) $link['url'], ENT_QUOTES, 'UTF-8');
                $label = htmlspecialchars((string) ($link['label'] ?? $link['url']), ENT_QUOTES, 'UTF-8');
                $html .= '<br><a href="'.$url.'">'.$label.'</a>';
            }
        }

        $home = rtrim((string) WEB_URL, '/');
        if ($home !== '') {
            $html .= '<br><br><a href="'.htmlspecialchars($home, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars(Language::get('Open the overview'), ENT_QUOTES, 'UTF-8').'</a>';
        }

        return $html;
    }

    private static function sendTo($channel, array $row, $text)
    {
        if ($channel === 'telegram') {
            if (empty(self::$cfg->telegram_bot_token) || empty(self::$cfg->telegram_chat_id)) {
                return self::OFF;
            }

            return (string) \Gcms\Telegram::sendPayload(self::$cfg->telegram_chat_id, [[
                'text' => $text,
                'disable_web_page_preview' => true,
                'reply_markup' => ['inline_keyboard' => self::keyboard($row)]
            ]]);
        }

        if ($channel === 'line') {
            if (empty(self::$cfg->line_channel_access_token)) {
                return self::OFF;
            }
            $uid = self::lineUid();
            if ($uid === '') {
                return self::OFF;
            }

            return (string) \Gcms\Line::sendTo($uid, $text);
        }

        if ($channel === 'email') {
            $to = self::notifyEmail();
            if ($to === '') {
                return self::OFF;
            }

            // Email::send() คืนอ็อบเจกต์ ไม่ใช่ bool — ต้องถาม error() ต่อ
            $mail = \Kotchasan\Email::send(
                $to,
                '',
                mb_substr(trim((string) $row['title']), 0, 120),
                self::emailBody($row, $text)
            );

            return $mail->error() ? str_replace("\n", ' · ', $mail->getErrorMessage()) : '';
        }

        return Language::sprintf('Unknown channel %s', $channel);
    }

    /**
     * ข้อความของการเตือน
     *
     * @param array $row
     *
     * @return string
     */
    public static function compose(array $row)
    {
        $at = $row['due_at'] ?: $row['start_at'];
        $days = (int) floor((strtotime($at) - strtotime('today')) / 86400);

        $lines = [
            // whenText มีวันที่จริงอยู่ในตัวแล้ว เคยต่อวันที่ซ้ำอีกชั้นตรงนี้
            self::mark($row['priority'], $days).' '.$row['title'],
            Attention::whenText($days, !empty($row['all_day']), $at, self::estimated($row))
        ];
        if (!empty($row['subtitle'])) {
            $lines[] = $row['subtitle'];
        }

        // ทุกอย่างที่ต้นทางส่งมาต้องอยู่ในข้อความเตือน ไม่ใช่ต้องเปิดเว็บดูต่อ
        //
        // การเตือนถูกอ่านตอนที่คนยังทำอย่างอื่นอยู่ ถ้ามันบอกแค่ "ครบกำหนดพรุ่งนี้"
        // แล้วต้องเปิดเว็บเพื่อดูยอดเงินกับเบอร์โทร ก็เท่ากับเลื่อนงานออกไป
        // ไม่ได้ช่วยให้ทำได้เลย · ต้นทางเลือกแล้วว่าอะไรสำคัญพอจะใส่มาใน facts
        foreach (self::facts($row) as $fact) {
            $lines[] = $fact;
        }

        $lines[] = '— '.$row['source_name'];

        return implode("\n", $lines);
    }

    /**
     * ต้นทางบอกไหมว่าวันของเรื่องนี้เป็นการคาดการณ์
     *
     * @param array $row
     *
     * @return bool
     */
    private static function estimated(array $row)
    {
        $hint = json_decode((string) ($row['hint_json'] ?? ''), true);

        return is_array($hint) && !empty($hint['estimated']);
    }

    /**
     * ข้อมูลประกอบที่ต้นทางส่งมา · ประกอบที่เดียวกับที่แชตใช้
     *
     * @param array $row
     *
     * @return array
     */
    private static function facts(array $row)
    {
        $facts = json_decode((string) ($row['facts_json'] ?? ''), true);
        $contact = json_decode((string) ($row['contact_json'] ?? ''), true);

        return Attention::details(
            is_array($facts) ? $facts : [],
            is_array($contact) ? $contact : []
        );
    }

    /**
     * สัญลักษณ์นำหน้า อ่านความเร่งด่วนได้ตั้งแต่ยังไม่เปิดข้อความ
     *
     * @param string $priority
     * @param int    $days
     *
     * @return string
     */
    private static function mark($priority, $days)
    {
        if ($days < 0 || $priority === 'critical') {
            return '🔴';
        }
        if ($days === 0 || $priority === 'high') {
            return '🟠';
        }

        return '🟡';
    }

    /**
     * ปุ่มใต้ข้อความ Telegram
     *
     * callback_data จำกัด 64 ไบต์ตามข้อกำหนดของ Telegram จึงใช้รูปสั้นที่สุด
     * ที่ยังบอกได้ครบว่าให้ทำอะไรกับ item ไหน
     *
     * @param array $row
     *
     * @return array
     */
    private static function keyboard(array $row)
    {
        // ไม่รู้ว่าเป็น item ใบไหนก็ทำปุ่มไม่ได้ — ปุ่มทุกปุ่มอ้างถึง id
        //
        // เกิดได้เมื่อผู้เรียกส่ง row ที่ประกอบเอง เช่นตอนทดสอบหรือตอนส่ง
        // ข้อความที่ไม่ได้มาจากคิวการเตือน · คืนข้อความเปล่า ๆ ดีกว่าพัง
        $itemId = (int) ($row['item_id'] ?? 0);
        if ($itemId <= 0) {
            return [];
        }

        $rows = [[
            ['text' => '✅ '.Language::get('Handled'), 'callback_data' => 'tl:done:'.$itemId],
            ['text' => '⏰ '.Language::get('Snooze 1 day'), 'callback_data' => 'tl:snooze:'.$itemId.':1'],
            ['text' => '⏰ '.Language::sprintf('%d days', 7), 'callback_data' => 'tl:snooze:'.$itemId.':7']
        ]];

        // ปุ่มที่ระบบต้นทางประกาศไว้กับ item ใบนี้ — Hub ไม่รู้ว่ามันทำอะไร
        // แค่พาคนไปยังขั้นถัดไปของสิ่งที่ต้นทางบอกว่าทำได้
        $actions = json_decode((string) ($row['actions_json'] ?? ''), true);
        if (is_array($actions)) {
            $buttons = [];
            foreach ($actions as $action) {
                if (empty($action['id'])) {
                    continue;
                }
                $buttons[] = [
                    'text' => mb_substr((string) ($action['label'] ?? $action['id']), 0, 24),
                    'callback_data' => 'tl:act:'.$itemId.':'.$action['id']
                ];
                if (count($buttons) >= 2) {
                    break;
                }
            }
            if (!empty($buttons)) {
                $rows[] = $buttons;
            }
        }

        $links = json_decode((string) ($row['links_json'] ?? ''), true);
        if (is_array($links)) {
            $open = [];
            foreach ($links as $link) {
                if (empty($link['url']) || !preg_match('#^https://#i', $link['url'])) {
                    // Telegram ปฏิเสธปุ่ม url ที่ไม่ใช่ https และตีกลับทั้งข้อความ
                    // ทำให้การเตือนหายไปทั้งฉบับเพราะลิงก์เดียว
                    continue;
                }
                $open[] = ['text' => mb_substr((string) $link['label'], 0, 24), 'url' => $link['url']];
                if (count($open) >= 2) {
                    break;
                }
            }
            if (!empty($open)) {
                $rows[] = $open;
            }
        }

        return $rows;
    }

    /**
     * LINE uid ของผู้ใช้ที่ผูกบัญชีไว้
     *
     * ใช้คอลัมน์ที่ระบบสมาชิกมีอยู่แล้ว ไม่ต้องมีตารางผูกบัญชีของตัวเอง
     *
     * @return string
     */
    private static function lineUid()
    {
        $row = \Kotchasan\DB::create()->first('user', [['line_uid', '!=', null]], ['line_uid']);

        return $row === null ? '' : (string) $row->line_uid;
    }
}
