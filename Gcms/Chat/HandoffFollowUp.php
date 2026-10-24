<?php
/**
 * @filesource Gcms/Chat/HandoffFollowUp.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

use Kotchasan\Language;

/**
 * Append requester messages to an active handoff.
 *
 * @since 1.0
 */
class HandoffFollowUp extends \Kotchasan\KBase
{
    /**
     * @param array|null $handoff
     *
     * @return bool
     */
    public static function isActive(?array $handoff): bool
    {
        if (!is_array($handoff) || empty($handoff['id'])) {
            return false;
        }

        $status = strtolower(trim((string) ($handoff['status'] ?? '')));

        return $status === 'open' || $status === 'accepted';
    }

    /**
     * @param Message      $message
     * @param HandoffStore $store
     *
     * @return array|null
     */
    public static function activeHandoff(Message $message, HandoffStore $store): ?array
    {
        $conversationId = trim((string) $message->conversationId);
        if ($conversationId === '') {
            return null;
        }

        $handoff = $store->latestByConversation($conversationId);

        return self::isActive($handoff) ? $handoff : null;
    }

    /**
     * @param Message      $message
     * @param array        $handoff
     * @param HandoffStore $store
     *
     * @return array|null
     */
    public static function append(Message $message, array $handoff, HandoffStore $store): ?array
    {
        $note = trim((string) $message->text);
        if ($note === '' || empty($handoff['id'])) {
            return null;
        }

        return $store->appendFollowUp((int) $handoff['id'], $note, $message->history);
    }

    /**
     * @param Message      $message
     * @param array        $handoff
     * @param HandoffStore $store
     *
     * @return Response
     */
    public static function respond(Message $message, array $handoff, HandoffStore $store): Response
    {
        $updated = self::append($message, $handoff, $store);
        if ($updated === null) {
            return Response::text(
                Language::get(
                    'AI chat handoff append failed',
                    'Could not save your message. Please try again.'
                ),
                $message,
                'handoff-follow-up',
                [],
                [],
                []
            );
        }

        $id = (int) ($updated['id'] ?? 0);
        $text = str_replace(
            '{ID}',
            (string) $id,
            Language::get(
                'AI chat handoff note saved',
                'Your note to staff was saved (request #{ID}). They can see it immediately. Type more below if needed.'
            )
        );

        return Response::text(
            $text,
            $message,
            'handoff-follow-up',
            [
                'handoff' => [
                    'id' => $id,
                    'status' => $updated['status'] ?? 'open'
                ]
            ],
            [],
            []
        );
    }
}
