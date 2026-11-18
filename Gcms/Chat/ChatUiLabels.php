<?php
/**
 * @filesource Gcms/Chat/ChatUiLabels.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

use Kotchasan\Language;

/**
 * Translated strings for the public web chat widget (loaded via capabilities).
 *
 * @since 1.0
 */
class ChatUiLabels
{
    /**
     * @return array<string, string>
     */
    public static function forCapabilities(): array
    {
        return [
            'toggle' => Language::get('AI chat ui toggle', 'Assistant'),
            'title' => Language::get('AI chat ui title', 'AI assistant'),
            'close' => Language::get('AI chat ui close', 'Close chat'),
            'composer_label' => Language::get('AI chat ui composer label', 'Message'),
            'composer_placeholder' => Language::get(
                'AI chat composer placeholder',
                'Type /help for commands — e.g. /search topic, /contact your note'
            ),
            'composer_placeholder_handoff' => Language::get(
                'AI chat composer placeholder handoff',
                'Type your message to staff…'
            ),
            'send' => Language::get('AI chat ui send', 'Send'),
            'starter_default' => Language::get(
                'AI chat starter default',
                'Hello! Type /help for commands. Use /search to find content, /read for one article, and /contact to message staff.'
            ),
            'typing' => Language::get('AI chat typing', 'Typing'),
            'open_link' => Language::get('AI chat open link', 'Open link'),
            'card_default_title' => Language::get('AI chat card default title', 'Result'),
            'error_send' => Language::get('AI chat error send', 'Could not send your message right now.'),
            'error_empty_reply' => Language::get('AI chat error empty reply', 'No reply was returned.'),
            'handoff_accepted' => Language::get(
                'AI chat handoff notice accepted',
                'A staff member has accepted your request. Please wait for a reply.'
            ),
            'handoff_closed' => Language::get(
                'AI chat handoff notice closed',
                'This request was closed. You can send a new message if you need more help.'
            )
        ];
    }
}
