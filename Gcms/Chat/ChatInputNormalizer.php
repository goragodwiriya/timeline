<?php
/**
 * @filesource Gcms/Chat/ChatInputNormalizer.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

use Kotchasan\Language;

/**
 * Normalizes slash-style payloads before tools or handoff storage.
 *
 * @since 1.0
 */
class ChatInputNormalizer
{
    /**
     * Normalize incoming text for all channels before routing (slash commands, @bot suffix).
     *
     * @param Message $message
     */
    public static function normalizeIncoming(Message $message): void
    {
        $message->text = ChatCommandCatalog::normalizeText($message->text);
    }

    /**
     * Strip a leading `/contact` command so staff see only the visitor note (one message body).
     *
     * @param Message $message
     */
    public static function stripContactCommand(Message $message): void
    {
        if (!ChatCommandCatalog::messageMatchesCommand($message->text, 'contact')) {
            return;
        }

        $body = ChatCommandCatalog::extractArgument($message->text, 'contact');
        $message->text = $body !== '' ? $body : Language::get(
            'AI chat contact empty body',
            'Contact request (no additional message text)'
        );
    }
}
