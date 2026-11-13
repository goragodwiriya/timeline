<?php
/**
 * @filesource Gcms/Chat/Message.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

/**
 * Normalized chat request DTO.
 *
 * Every channel adapter converts incoming payloads into this shape
 * so the orchestrator can remain channel-agnostic.
 *
 * @since 1.0
 */
class Message
{
    /**
     * Channel identifier เช่น web, line, telegram.
     *
     * @var string
     */
    public $channel = 'web';

    /**
     * User message text.
     *
     * @var string
     */
    public $text = '';

    /**
     * Stable conversation identifier when available.
     *
     * @var string
     */
    public $conversationId = '';

    /**
     * Requested locale.
     *
     * @var string
     */
    public $locale = 'th';

    /**
     * Optional normalized history in OpenAI-like format.
     *
     * @var array
     */
    public $history = [];

    /**
     * Channel-specific metadata.
     *
     * @var array
     */
    public $metadata = [];

    /**
     * Authenticated user object when available.
     *
     * @var object|null
     */
    public $user;

    /**
     * Create a normalized message from arbitrary input.
     *
     * @param array       $data
     * @param object|null $user
     *
     * @return self
     */
    public static function fromArray(array $data, $user = null)
    {
        $message = new self();
        $channel = strtolower(trim((string) ($data['channel'] ?? 'web')));
        $message->channel = $channel !== '' ? $channel : 'web';
        $message->text = trim((string) ($data['message'] ?? $data['text'] ?? ''));
        $message->conversationId = trim((string) ($data['conversation_id'] ?? $data['conversationId'] ?? ''));
        $message->locale = trim((string) ($data['locale'] ?? 'th'));
        $message->history = self::normalizeHistory($data['history'] ?? []);
        $message->metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];
        $message->user = $user;

        return $message;
    }

    /**
     * Keep only valid role/content history items.
     *
     * @param mixed $history
     *
     * @return array
     */
    private static function normalizeHistory($history)
    {
        if (!is_array($history)) {
            return [];
        }

        $items = [];
        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = strtolower(trim((string) ($row['role'] ?? 'user')));
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '' || !in_array($role, ['system', 'user', 'assistant'], true)) {
                continue;
            }
            $items[] = [
                'role' => $role,
                'content' => $content
            ];
            if (count($items) >= 20) {
                break;
            }
        }

        return $items;
    }
}