<?php
/**
 * @filesource Gcms/Chat/ResponsePresenter.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

/**
 * Single place to normalize tool responses before any channel adapter formats them.
 *
 * Keeps Web, LINE, Telegram behavior aligned: same tools, same rules, channel adapters
 * only translate transport shape.
 *
 * @since 1.0
 */
class ResponsePresenter
{
    /**
     * Apply cross-channel presentation rules to a response in place.
     *
     * @param Response $response
     * @param string   $channel web|line|telegram
     */
    public static function finalize(Response $response, $channel): void
    {
        $channel = strtolower(trim((string) $channel));

        if (self::cardsHaveUrls($response->cards)) {
            $response->actions = self::withoutReadPromptActions($response->actions);
        }

        if ($channel !== 'web') {
            $response->actions = self::normalizeForMessagingChannels($response->actions);
        }
    }

    /**
     * @param array $cards
     *
     * @return bool
     */
    public static function cardsHaveUrls(array $cards): bool
    {
        foreach ($cards as $card) {
            if (is_array($card) && trim((string) ($card['url'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop /read shortcuts when cards already expose open/read links.
     *
     * @param array $actions
     *
     * @return array
     */
    public static function withoutReadPromptActions(array $actions): array
    {
        $out = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $value = trim((string) ($action['value'] ?? ''));
            if (($action['type'] ?? '') === 'prompt'
                && $value !== ''
                && preg_match('#^/read(\s|$)#iu', $value) === 1) {
                continue;
            }
            $out[] = $action;
        }

        return $out;
    }

    /**
     * Messaging channels send prompt taps as messages (no compose box).
     *
     * @param array $actions
     *
     * @return array
     */
    public static function normalizeForMessagingChannels(array $actions): array
    {
        $out = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = trim((string) ($action['type'] ?? ''));
            if ($type === 'handoff_note') {
                continue;
            }
            if ($type !== 'prompt') {
                $out[] = $action;
                continue;
            }
            $value = trim((string) ($action['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            unset($action['behavior']);
            $out[] = $action;
        }

        return $out;
    }
}
