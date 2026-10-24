<?php
/**
 * @filesource Gcms/Chat/Tools/AppointmentTool.php
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
use Gcms\Timeline\ChatLink;
use Gcms\Timeline\LocalProvider;
use Gcms\Timeline\ThaiDateTime;

/**
 * บันทึกนัดหมายจากห้องแชต แบบถามทีละข้อ
 *
 * เดิมรับเป็นประโยคเดียวแล้วให้ตัวอ่านแยกเอง ซึ่งพังเมื่อผู้ใช้พิมพ์คนละแบบกับที่
 * ตัวอ่านรู้จัก — ข้อความจะตกไปถึง AI แล้วมันถามวนไม่จบจนลงนัดไม่ได้สักที
 *
 * ตอนนี้ถามทีละช่องพร้อมปุ่มให้กด **และยึดบทสนทนาไว้จนกว่าจะจบหรือยกเลิก** —
 * ข้อความถัดไปในห้องนี้เป็นคำตอบของคำถามที่ค้างอยู่เสมอ ไม่หลุดไปหาเครื่องมืออื่น
 * ไม่ต้องเดา ไม่ต้องพึ่ง AI
 *
 * ทางลัดยังอยู่ — พิมพ์ครบในบรรทัดเดียว (`/นัด หมอฟัน พรุ่งนี้ 10:00`) แล้วข้าม
 * ไปหน้ายืนยันเลย สำหรับคนที่จำรูปแบบได้
 *
 * @since 1.0
 */
class AppointmentTool implements ToolInterface
{
    /**
     * ลำดับของช่องที่ถาม
     */
    const STEPS = ['title', 'date', 'time', 'place'];

    /**
     * บทสนทนาที่ค้างไว้นานกว่านี้ถือว่าเลิกทำแล้ว
     */
    const TTL_MINUTES = 30;

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function name()
    {
        return 'appointment';
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function description()
    {
        return 'บันทึกนัดหมาย — พิมพ์ /นัด แล้วตอบทีละข้อ หรือพิมพ์ครบในบรรทัดเดียวก็ได้';
    }

    /**
     * {@inheritdoc}
     *
     * มีบทสนทนาค้างอยู่เมื่อไร เครื่องมือนี้รับข้อความทุกอย่างในห้องนั้นไว้เอง
     * ไม่งั้นคำตอบสั้น ๆ อย่าง "หมอฟัน" จะถูกเครื่องมืออื่นหรือ AI คว้าไป
     *
     * @param Message $message
     *
     * @return bool
     */
    public function supports(Message $message)
    {
        if ($this->parse($message->text) !== null) {
            return true;
        }

        // มีตัวช่วยค้างอยู่ = ข้อความถัดไปเป็นคำตอบของขั้นตอน — ยกเว้นคำสั่ง
        //
        // เดิมคว้าทุกอย่างที่ตามมา ผลคือเริ่มลงนัดค้างไว้ครั้งเดียวแล้ว /menu
        // /today และปุ่มทุกปุ่มกลายเป็นคำตอบของ "วันไหน?" ไปหมด จนกว่าจะครบ
        // สามสิบนาที · อ่านออกมาเหมือนบอตพัง ทั้งที่มันทำตามที่สั่งเป๊ะ ๆ
        return $this->load($message) !== null && !self::isCommand($message->text);
    }

    /**
     * ข้อความนี้เป็นคำสั่งของระบบ ไม่ใช่คำตอบของตัวช่วยหรือเปล่า
     *
     * @param string $text
     *
     * @return bool
     */
    private static function isCommand($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }

        // ปุ่มของตัวช่วยเองขึ้นต้นด้วย tl:aw: ส่วน tl: อื่น ๆ เป็นของเครื่องมืออื่น
        if (strpos($text, 'tl:') === 0) {
            return strpos($text, 'tl:aw:') !== 0;
        }

        return isset($text[0]) && $text[0] === '/';
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
        // ผูกห้องไว้ไม่พอ — บัญชีที่ผูกต้องยังใช้งานอยู่และมีสิทธิ์ด้วย
        // นัดหมายเก็บชื่อคน สถานที่ และเวลาจริง ไม่ใช่ข้อมูลที่ให้ใครก็ได้เขียน
        $memberId = $this->memberId($message);
        if (!\Gcms\Timeline\Access::allowMember($memberId)) {
            return $this->say($message, 'ต้องผูกบัญชีก่อนจึงจะใช้งานได้ — เปิดหน้า "บัญชีแชต" ในระบบเพื่อรับรหัส แล้วพิมพ์ /link ตามด้วยรหัสนั้น');
        }

        $intent = $this->parse($message->text);
        $state = $this->load($message);

        // ยกเลิกได้ทุกเมื่อ ไม่ต้องรอให้จบ
        if ($intent !== null && $intent['action'] === 'cancel') {
            $this->clear($message);

            return $this->say($message, 'ยกเลิกแล้ว ไม่ได้บันทึกอะไร');
        }

        if ($intent !== null && $intent['action'] === 'start') {
            return $this->start($message, $memberId, $intent['text']);
        }

        if ($state === null) {
            return $this->start($message, $memberId, '');
        }

        if ($intent !== null && $intent['action'] === 'save') {
            return $this->save($message, $memberId, $state);
        }

        // ปุ่มพกค่ามาเอง ส่วนข้อความที่พิมพ์คือคำตอบของช่องที่ค้างอยู่
        $answer = $intent !== null && $intent['action'] === 'fill'
            ? $intent['value']
            : trim((string) $message->text);

        return $this->fill($message, $memberId, $state, $answer);
    }

    /**
     * เริ่มบทสนทนาใหม่
     *
     * @param Message $message
     * @param int     $memberId
     * @param string  $text ข้อความที่พิมพ์มาพร้อมคำสั่ง
     *
     * @return Response
     */
    private function start(Message $message, $memberId, $text)
    {
        $state = ['title' => '', 'date' => '', 'time' => '', 'place' => ''];

        // ทางลัด: พิมพ์ครบมาแล้วก็ไม่ต้องถามซ้ำ
        if ($text !== '') {
            $parsed = ThaiDateTime::parse($text);
            $state['title'] = $parsed['title'];
            $state['date'] = (string) $parsed['date'];
            $state['time'] = $parsed['all_day'] ? 'ทั้งวัน' : (string) $parsed['time'];
            $state['place'] = $parsed['location'];
        }

        $this->store($message, $memberId, $state);

        return $this->ask($message, $memberId, $state);
    }

    /**
     * รับคำตอบของช่องที่ค้างอยู่ แล้วถามช่องถัดไป
     *
     * @param Message $message
     * @param int     $memberId
     * @param array   $state
     * @param string  $answer
     *
     * @return Response
     */
    private function fill(Message $message, $memberId, array $state, $answer)
    {
        $step = $this->nextStep($state);
        if ($step === null) {
            return $this->ask($message, $memberId, $state);
        }
        if ($answer === '') {
            return $this->ask($message, $memberId, $state);
        }

        if ($step === 'date') {
            $date = ThaiDateTime::date($answer);
            if ($date === null) {
                // อ่านไม่ออกก็บอกตรง ๆ ไม่เดา — นัดผิดวันแย่กว่าถามซ้ำ
                //
                // และต้องบอกด้วยว่ารูปแบบไหนใช้ได้ · "อ่านไม่ออก" เฉย ๆ ทำให้คน
                // เดาสุ่มไปเรื่อยจนเลิกใช้ ทั้งที่ตัวอ่านรับได้หลายแบบมาก
                return $this->ask(
                    $message,
                    $memberId,
                    $state,
                    'อ่านวันที่ไม่ออก · รูปแบบที่ใช้ได้ เช่น '.ThaiDateTime::FORMATS
                );
            }
            $state['date'] = $date;
        } elseif ($step === 'time') {
            if ($this->isSkip($answer)) {
                $state['time'] = 'ทั้งวัน';
            } else {
                $time = ThaiDateTime::time($answer);
                if ($time === null) {
                    return $this->ask(
                        $message,
                        $memberId,
                        $state,
                        'อ่านเวลาไม่ออก · รูปแบบที่ใช้ได้ เช่น 10:00 · 14.30 น. · บ่าย 2 โมง · 2 ทุ่ม · เที่ยง'
                    );
                }
                $state['time'] = $time;
            }
        } elseif ($step === 'place') {
            $state['place'] = $this->isSkip($answer) ? '-' : mb_substr($answer, 0, 255);
        } else {
            $state['title'] = mb_substr($answer, 0, 255);
        }

        $this->store($message, $memberId, $state);

        return $this->ask($message, $memberId, $state);
    }

    /**
     * ถามช่องที่ยังว่างอยู่ หรือสรุปให้ยืนยันเมื่อครบแล้ว
     *
     * @param Message $message
     * @param int     $memberId
     * @param array   $state
     * @param string  $note ข้อความเตือนเมื่อคำตอบก่อนหน้าใช้ไม่ได้
     *
     * @return Response
     */
    private function ask(Message $message, $memberId, array $state, $note = '')
    {
        $step = $this->nextStep($state);
        $prefix = $note === '' ? '' : '⚠️ '.$note."\n\n";

        if ($step === null) {
            $lines = [
                $prefix.'ยืนยันนัดหมายนี้ไหม',
                '',
                '📌 '.$state['title'],
                '🗓 '.\Kotchasan\Date::format($state['date'], 'l d M Y')
                    .($state['time'] === 'ทั้งวัน' ? ' (ทั้งวัน)' : ' '.$state['time'].' น.')
            ];
            if ($state['place'] !== '-' && $state['place'] !== '') {
                $lines[] = '📍 '.$state['place'];
            }

            return $this->say($message, implode("\n", $lines), [
                ['type' => 'callback', 'label' => '✅ บันทึก', 'data' => 'tl:aw:save'],
                ['type' => 'callback', 'label' => '✖ ยกเลิก', 'data' => 'tl:aw:cancel']
            ]);
        }

        $questions = [
            'title' => ['ขั้นที่ 1/4 — นัดเรื่องอะไร?', []],
            'date' => ['ขั้นที่ 2/4 — วันไหน?', [
                ['วันนี้', 'วันนี้'], ['พรุ่งนี้', 'พรุ่งนี้'], ['มะรืน', 'มะรืน'],
                ['สัปดาห์หน้า', 'สัปดาห์หน้า'], ['สิ้นเดือน', 'สิ้นเดือน']
            ]],
            'time' => ['ขั้นที่ 3/4 — กี่โมง?', [
                ['09:00', '09:00'], ['13:00', '13:00'], ['ทั้งวัน', 'ข้าม']
            ]],
            'place' => ['ขั้นที่ 4/4 — ที่ไหน?', [['ไม่ระบุ', 'ข้าม']]]
        ];
        list($question, $choices) = $questions[$step];

        $buttons = [];
        foreach ($choices as $choice) {
            $buttons[] = ['type' => 'callback', 'label' => $choice[0], 'data' => 'tl:aw:v:'.$choice[1]];
        }
        $buttons[] = ['type' => 'callback', 'label' => '✖ ยกเลิก', 'data' => 'tl:aw:cancel'];

        $hint = "\nพิมพ์เองก็ได้";
        if ($step === 'title') {
            $hint = "\nพิมพ์ตอบได้เลย";
        } elseif ($step === 'date') {
            // บอกตัวอย่างตั้งแต่ตอนถาม ไม่ใช่รอให้พิมพ์ผิดก่อน
            $hint = "\nเช่น ".ThaiDateTime::FORMATS;
        }

        return $this->say($message, $prefix.$question.$hint, $buttons);
    }

    /**
     * บันทึกจริง
     *
     * @param Message $message
     * @param int     $memberId
     * @param array   $state
     *
     * @return Response
     */
    private function save(Message $message, $memberId, array $state)
    {
        if ($this->nextStep($state) !== null) {
            return $this->ask($message, $memberId, $state, 'ยังกรอกไม่ครบ');
        }

        $allDay = $state['time'] === 'ทั้งวัน';
        try {
            $id = LocalProvider::save([
                'title' => $state['title'],
                'start_at' => $state['date'].' '.($allDay ? '00:00' : $state['time']).':00',
                'all_day' => $allDay,
                'location' => $state['place'] === '-' ? '' : $state['place']
            ], $memberId);
        } catch (\Throwable $e) {
            return $this->say($message, '⚠️ '.$e->getMessage());
        }

        $this->clear($message);

        return $this->say(
            $message,
            '✅ บันทึกนัดหมายแล้ว (#'.$id.')'."\n".$state['title']."\n"
                .\Kotchasan\Date::format($state['date'], 'l d M Y').($allDay ? ' (ทั้งวัน)' : ' '.$state['time'].' น.')
        );
    }

    /**
     * ช่องแรกที่ยังว่าง · null = ครบแล้ว
     *
     * @param array $state
     *
     * @return string|null
     */
    private function nextStep(array $state)
    {
        foreach (self::STEPS as $step) {
            if (($state[$step] ?? '') === '') {
                return $step;
            }
        }

        return null;
    }

    /**
     * @param string $text
     *
     * @return bool
     */
    private function isSkip($text)
    {
        return in_array(trim(mb_strtolower($text)), ['ข้าม', 'skip', '-', 'ไม่มี', 'ไม่ระบุ'], true);
    }

    /**
     * @param string $text
     *
     * @return array|null
     */
    private function parse($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        if ($text === 'tl:aw:save') {
            return ['action' => 'save'];
        }
        if ($text === 'tl:aw:cancel') {
            return ['action' => 'cancel'];
        }
        if (strpos($text, 'tl:aw:v:') === 0) {
            return ['action' => 'fill', 'value' => substr($text, 8)];
        }
        // ปุ่มจากเมนูของ TimelineTool — ในช่องไม่มีเมนู "/" ให้กด
        if ($text === 'tl:cmd:appt') {
            return ['action' => 'start', 'text' => ''];
        }
        if (preg_match('/^\/?(?:ยกเลิก|cancel)$/iu', $text)) {
            return ['action' => 'cancel'];
        }
        // `appt` คือชื่อที่ลงทะเบียนไว้กับ Telegram (เมนู / รับได้แต่ a-z)
        // ส่วน `นัด` ไว้ให้พิมพ์เองซึ่งเป็นทางที่คนไทยพิมพ์จริง
        if (preg_match('/^\/?(?:นัด|appointment|appt)\b\s*(.*)$/u', $text, $m)) {
            return ['action' => 'start', 'text' => trim($m[1])];
        }

        return null;
    }

    /**
     * กุญแจของบทสนทนา — หนึ่งห้องมีบทสนทนาค้างได้ครั้งละหนึ่งเท่านั้น
     *
     * @param Message $message
     *
     * @return string
     */
    private function key(Message $message)
    {
        return 'wz'.substr(sha1($message->channel.':'.$message->conversationId), 0, 28);
    }

    /**
     * @param Message $message
     *
     * @return array|null
     */
    private function load(Message $message)
    {
        $row = \Kotchasan\DB::create()->first('chat_pending', [
            ['token', $this->key($message)],
            ['kind', 'appt_wizard'],
            ['expires_at', '>', date('Y-m-d H:i:s')]
        ]);
        if ($row === null) {
            return null;
        }
        $data = json_decode((string) $row->payload_json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param Message $message
     * @param int     $memberId
     * @param array   $state
     */
    private function store(Message $message, $memberId, array $state)
    {
        $db = \Kotchasan\DB::create();
        $db->delete('chat_pending', [['expires_at', '<', date('Y-m-d H:i:s')]], 0);

        $row = [
            'member_id' => (int) $memberId,
            'kind' => 'appt_wizard',
            'payload_json' => json_encode($state, JSON_UNESCAPED_UNICODE),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+'.self::TTL_MINUTES.' minutes'))
        ];

        if ($db->exists('chat_pending', [['token', $this->key($message)]])) {
            $db->update('chat_pending', ['token', $this->key($message)], $row);

            return;
        }
        $row['token'] = $this->key($message);
        $row['created_at'] = date('Y-m-d H:i:s');
        $db->insert('chat_pending', $row);
    }

    /**
     * @param Message $message
     */
    private function clear(Message $message)
    {
        \Kotchasan\DB::create()->delete('chat_pending', [['token', $this->key($message)]], 0);
    }

    /**
     * @param Message $message
     *
     * @return int
     */
    private function memberId(Message $message)
    {
        $channels = ChatLink::CHANNELS;
        if (!isset($channels[$message->channel])) {
            return $message->channel === 'web' && !empty($message->user) ? (int) $message->user->id : 0;
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
     * @param array   $buttons
     *
     * @return Response
     */
    private function say(Message $message, $text, array $buttons = [])
    {
        return Response::text($text, $message, $this->name(), [], [], $buttons);
    }
}
