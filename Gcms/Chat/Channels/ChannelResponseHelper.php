<?php
/**
 * @filesource Gcms/Chat/Channels/ChannelResponseHelper.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat\Channels;

use Gcms\Chat\Response;
use Kotchasan\Language;

/**
 * Shared formatting helpers for LINE / Telegram adapters.
 *
 * @since 1.0
 */
class ChannelResponseHelper
{
    /**
     * When cards carry URLs, omit duplicate URL lines from the main text body.
     *
     * @param Response $response
     *
     * @return string
     */
    public static function primaryText(Response $response)
    {
        $text = trim((string) $response->message);
        if (empty($response->cards) || $text === '') {
            return $text;
        }

        $lines = preg_split('/\R/u', $text);
        $filtered = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || preg_match('/https?:\/\//i', $line) === 1) {
                continue;
            }
            $filtered[] = $line;
        }

        return !empty($filtered) ? implode("\n", $filtered) : $text;
    }

    /**
     * @param string $text
     * @param int    $limit
     *
     * @return string
     */
    public static function truncate($text, $limit)
    {
        $text = trim((string) $text);
        if ($text === '' || mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(1, $limit - 3))).'...';
    }

    /**
     * @param string $text
     * @param int    $limit
     *
     * @return string
     */
    public static function truncateButton($text, $limit = 32)
    {
        $text = trim((string) $text);

        return self::truncate($text !== '' ? $text : Language::get('AI chat open link', 'Open'), $limit);
    }

    /**
     * @return string
     */
    public static function promptKeyboardHint()
    {
        return Language::get(
            'AI chat social prompt keyboard hint',
            'Tap a shortcut below to send that command.'
        );
    }

    /**
     * @return string
     */
    public static function promptKeyboardPlaceholder()
    {
        return Language::get(
            'AI chat social prompt keyboard placeholder',
            'Choose a shortcut'
        );
    }
}
