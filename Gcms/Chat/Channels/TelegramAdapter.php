<?php
/**
 * @filesource Gcms/Chat/Channels/TelegramAdapter.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat\Channels;

use Gcms\Chat\ChannelAdapterInterface;
use Gcms\Chat\Message;
use Gcms\Chat\Response;

/**
 * Telegram adapter for normalized chat handling.
 *
 * @since 1.0
 */
class TelegramAdapter implements ChannelAdapterInterface
{
    /**
     * @return string
     */
    public function name()
    {
        return 'telegram';
    }

    /**
     * @return string
     */
    public function description()
    {
        return 'Telegram bot channel adapter';
    }

    /**
     * @param array       $payload
     * @param object|null $user
     *
     * @return Message
     */
    public function normalize(array $payload, $user = null)
    {
        if (isset($payload['callback_query']) && is_array($payload['callback_query'])) {
            $callback = $payload['callback_query'];
            $callbackMessage = isset($callback['message']) && is_array($callback['message']) ? $callback['message'] : [];
            $chat = isset($callbackMessage['chat']) && is_array($callbackMessage['chat']) ? $callbackMessage['chat'] : [];
            $conversationId = isset($chat['id']) ? (string) $chat['id'] : '';

            return Message::fromArray([
                'channel' => 'telegram',
                'message' => $callback['data'] ?? '',
                'conversation_id' => $conversationId,
                'metadata' => [
                    'chat_id' => $conversationId,
                    'message_id' => $callbackMessage['message_id'] ?? '',
                    'chat' => $chat,
                    'callback_query_id' => $callback['id'] ?? '',
                    'raw' => $callback
                ]
            ], $user);
        }

        $update = self::updateBody($payload);

        $chat = isset($update['chat']) && is_array($update['chat']) ? $update['chat'] : [];
        $conversationId = isset($chat['id']) ? (string) $chat['id'] : '';

        return Message::fromArray([
            'channel' => 'telegram',
            'message' => $update['text'] ?? $update['caption'] ?? $update['message'] ?? '',
            'conversation_id' => $conversationId,
            'metadata' => [
                'chat_id' => $conversationId,
                'message_id' => $update['message_id'] ?? '',
                'chat' => $chat,
                'raw' => $update
            ]
        ], $user);
    }

    /**
     * ส่วนของ update ที่ถือข้อความจริง
     *
     * Telegram ใช้คีย์คนละชื่อตามที่ที่ข้อความถูกส่ง — ในแชตส่วนตัวและกลุ่มเป็น
     * `message` ส่วนในช่องเป็น `channel_post` · อ่านแค่ `message` แปลว่าข้อความ
     * ที่พิมพ์ในช่องจะไม่ถูกมองเห็นเลย ทั้งที่ปุ่มบนข้อความยังทำงาน (callback_query
     * ใช้คีย์เดียวกันทุกที่) ซึ่งทำให้อาการดูเหมือน "บางอย่างใช้ได้ บางอย่างไม่ได้"
     * โดยไม่มีอะไรฟ้อง
     *
     * @param array $payload
     *
     * @return array
     */
    private static function updateBody(array $payload)
    {
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return $payload;
    }

    /**
     * @param Response $response
     *
     * @return array
     */
    public function format(Response $response)
    {
        $text = ChannelResponseHelper::primaryText($response);
        $messages = [[
            'text' => ChannelResponseHelper::truncate($text !== '' ? $text : $response->message, 4096),
            'disable_web_page_preview' => true
        ]];

        // ปุ่มทั้งหมดรวมอยู่บนข้อความเดียว
        //
        // เดิมปุ่มชนิด prompt ถูกส่งเป็นข้อความใบที่สองพร้อม ReplyKeyboardMarkup
        // ซึ่ง Telegram ยอมรับเฉพาะในแชตส่วนตัวกับกลุ่ม · ในช่องมันตีกลับเป็น
        // error ทำให้ webhook ตอบไม่ใช่ 2xx แล้ว Telegram ส่ง update เดิมซ้ำ
        // ไม่หยุด · แถมยังพ่วงข้อความ "แตะปุ่มด้านล่าง" มารกห้องทุกครั้ง
        $inlineKeyboard = $this->inlineKeyboard($response->cards, $response->actions);
        if (!empty($inlineKeyboard)) {
            $messages[0]['reply_markup'] = [
                'inline_keyboard' => $inlineKeyboard
            ];
        }

        return [
            'messages' => $messages,
            'text' => $messages[0]['text'],
            'conversation_id' => $response->conversationId
        ];
    }

    /**
     * @param array $cards
     * @param array $actions
     *
     * @return array
     */
    private function inlineKeyboard(array $cards, array $actions)
    {
        $rows = [];
        $prompts = [];

        foreach (array_slice($cards, 0, 6) as $card) {
            if (!is_array($card) || empty($card['url'])) {
                continue;
            }
            $rows[] = [[
                'text' => ChannelResponseHelper::truncateButton((string) ($card['title'] ?? '')),
                'url' => (string) $card['url']
            ]];
        }

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = $action['type'] ?? '';

            if ($type === 'link' && !empty($action['url'])) {
                $rows[] = [[
                    'text' => ChannelResponseHelper::truncateButton((string) ($action['label'] ?? '')),
                    'url' => (string) $action['url']
                ]];
                continue;
            }

            // ปุ่มที่ส่งข้อความกลับมาหาบอตแทนการเปิดลิงก์
            //
            // ต่างจาก url ตรงที่ผู้ใช้ทำอะไรบางอย่างได้จบในแชต ไม่ต้องย้ายไป
            // เบราว์เซอร์ · callback_data ของ Telegram จำกัด 64 ไบต์ ยาวกว่านั้น
            // Telegram ตีกลับทั้งข้อความ ทำให้คำตอบหายไปทั้งฉบับเพราะปุ่มเดียว
            if ($type === 'callback' && !empty($action['data'])) {
                $data = (string) $action['data'];
                if (strlen($data) > 64) {
                    continue;
                }
                $prompts[] = [
                    'text' => ChannelResponseHelper::truncateButton((string) ($action['label'] ?? '')),
                    'callback_data' => $data
                ];
                continue;
            }

            // ปุ่มชนิด prompt — บนเว็บมันเติมข้อความลงช่องพิมพ์ให้แก้ก่อนส่ง
            // แต่ Telegram ไม่มีท่านั้นบนปุ่ม inline · ส่งค่าเดิมกลับมาเป็น
            // callback แทน ผลคือแตะแล้วคำสั่งทำงานจริง ไม่ใช่ได้คำตอบเดิมซ้ำ
            if ($type === 'prompt') {
                $value = trim((string) ($action['value'] ?? ''));
                if ($value === '' || strlen($value) > 64) {
                    continue;
                }
                $prompts[] = [
                    'text' => ChannelResponseHelper::truncateButton((string) ($action['label'] ?? $value)),
                    'callback_data' => $value
                ];
            }
        }

        return array_merge($rows, self::pack($prompts));
    }

    /**
     * จัดปุ่มลงแถว — ปุ่มป้ายสั้นอยู่ร่วมแถวได้ ปุ่มป้ายยาวขึ้นแถวของตัวเอง
     *
     * วางแถวละปุ่มเสมอทำให้ /help กลายเป็นกำแพงปุ่มสิบแถวที่ดันข้อความจริงหาย
     * ไปจากจอ · แต่ยัดสามปุ่มต่อแถวเสมอก็ทำให้ป้ายยาวอย่าง "🔌 สถานะการเชื่อมต่อ"
     * ถูกตัดจนอ่านไม่รู้เรื่อง — จึงตัดสินจากความยาวป้ายจริง
     *
     * @param array $buttons
     *
     * @return array
     */
    private static function pack(array $buttons)
    {
        $rows = [];
        $row = [];
        $width = 0;

        foreach ($buttons as $button) {
            $length = mb_strlen((string) $button['text']);
            if (!empty($row) && (count($row) >= 3 || $width + $length > 28)) {
                $rows[] = $row;
                $row = [];
                $width = 0;
            }
            $row[] = $button;
            $width += $length;
        }
        if (!empty($row)) {
            $rows[] = $row;
        }

        return $rows;
    }

}