<?php
/**
 * @filesource Gcms/Chat/Channels/LineAdapter.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat\Channels;

use Gcms\Chat\ChannelAdapterInterface;
use Gcms\Chat\Message;
use Gcms\Chat\Response;
use Kotchasan\Language;

/**
 * LINE adapter for normalized chat handling.
 *
 * @since 1.0
 */
class LineAdapter implements ChannelAdapterInterface
{
    /**
     * @return string
     */
    public function name()
    {
        return 'line';
    }

    /**
     * @return string
     */
    public function description()
    {
        return 'LINE Messaging API channel adapter';
    }

    /**
     * @param array       $payload
     * @param object|null $user
     *
     * @return Message
     */
    public function normalize(array $payload, $user = null)
    {
        $event = isset($payload['events'][0]) && is_array($payload['events'][0]) ? $payload['events'][0] : $payload;
        $source = isset($event['source']) && is_array($event['source']) ? $event['source'] : [];
        $conversationId = (string) ($source['userId'] ?? $source['groupId'] ?? $source['roomId'] ?? '');

        return Message::fromArray([
            'channel' => 'line',
            'message' => $event['message']['text'] ?? $event['text'] ?? '',
            'conversation_id' => $conversationId,
            'metadata' => [
                'reply_token' => $event['replyToken'] ?? '',
                'source' => $source,
                'raw' => $event
            ]
        ], $user);
    }

    /**
     * @param Response $response
     *
     * @return array
     */
    public function format(Response $response)
    {
        $text = ChannelResponseHelper::primaryText($response);
        $message = [
            'type' => 'text',
            'text' => ChannelResponseHelper::truncate($text, 5000)
        ];

        $quickReplies = $this->formatQuickReplies($response->actions);
        if (!empty($quickReplies)) {
            $message['quickReply'] = [
                'items' => $quickReplies
            ];
        }

        $messages = [$message];

        $carousel = $this->formatCarousel($response->cards, $text);
        if ($carousel !== null) {
            $messages[] = $carousel;
        }

        return [
            'messages' => $messages,
            'conversation_id' => $response->conversationId
        ];
    }

    /**
     * @param array $actions
     *
     * @return array
     */
    private function formatQuickReplies(array $actions)
    {
        $items = [];
        foreach (array_slice($actions, 0, 13) as $action) {
            if (!is_array($action)) {
                continue;
            }

            $label = trim((string) ($action['label'] ?? $action['value'] ?? ''));
            $type = trim((string) ($action['type'] ?? 'prompt'));

            if ($type === 'link' && !empty($action['url'])) {
                $items[] = [
                    'type' => 'action',
                    'action' => [
                        'type' => 'uri',
                        'label' => ChannelResponseHelper::truncate($label !== '' ? $label : Language::get('AI chat open link', 'Open'), 20),
                        'uri' => (string) $action['url']
                    ]
                ];
                continue;
            }

            // ปุ่มชนิด callback เก็บค่าไว้ใน data ไม่ใช่ value
            //
            // อ่านแค่ value แปลว่าปุ่มทุกปุ่มที่เครื่องมือสร้าง (เมนู ตัวช่วยลงนัด
            // ปุ่มจัดการบนข้อความเตือน) หายไปเงียบ ๆ เฉพาะบน LINE ทั้งที่ฝั่ง
            // Telegram แสดงครบ · quick reply ชนิด message ส่งข้อความกลับมาหาบอต
            // ซึ่งเป็นกลไกเดียวกับ callback_data ทุกประการ
            $value = trim((string) ($action['value'] ?? ''));
            if ($value === '' && $type === 'callback') {
                $value = trim((string) ($action['data'] ?? ''));
            }
            if ($value === '') {
                continue;
            }

            $items[] = [
                'type' => 'action',
                'action' => [
                    'type' => 'message',
                    'label' => ChannelResponseHelper::truncate($label !== '' ? $label : $value, 20),
                    'text' => ChannelResponseHelper::truncate($value, 300)
                ]
            ];
        }

        return $items;
    }

    /**
     * @param array  $cards
     * @param string $altText
     *
     * @return array|null
     */
    private function formatCarousel(array $cards, $altText)
    {
        if (empty($cards)) {
            return null;
        }

        $columns = [];
        foreach (array_slice($cards, 0, 10) as $card) {
            if (!is_array($card)) {
                continue;
            }

            $action = $this->cardAction($card);
            if ($action === null) {
                continue;
            }

            $title = trim((string) ($card['title'] ?? ''));
            if ($title === '') {
                $title = 'Result';
            }

            $description = trim((string) ($card['description'] ?? ''));
            if ($description === '') {
                $description = trim((string) ($card['module'] ?? ''));
            }
            if ($description === '' && !empty($card['date'])) {
                $description = trim((string) $card['date']);
            }
            if ($description === '') {
                $description = $title;
            }

            $columns[] = [
                'title' => ChannelResponseHelper::truncate($title, 40),
                'text' => ChannelResponseHelper::truncate(preg_replace('/\s+/u', ' ', $description), 60),
                'actions' => [$action]
            ];
        }

        if (empty($columns)) {
            return null;
        }

        return [
            'type' => 'template',
            'altText' => ChannelResponseHelper::truncate(
                $altText !== '' ? $altText : Language::get('AI chat card default title', 'Results'),
                400
            ),
            'template' => [
                'type' => 'carousel',
                'columns' => $columns
            ]
        ];
    }

    /**
     * @param array $card
     *
     * @return array|null
     */
    private function cardAction(array $card)
    {
        $url = trim((string) ($card['url'] ?? ''));
        if ($url !== '') {
            return [
                'type' => 'uri',
                'label' => ChannelResponseHelper::truncate(Language::get('AI chat open link', 'Open'), 20),
                'uri' => $url
            ];
        }

        $title = trim((string) ($card['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        return [
            'type' => 'message',
            'label' => 'Select',
            'text' => ChannelResponseHelper::truncate($title, 300)
        ];
    }
}