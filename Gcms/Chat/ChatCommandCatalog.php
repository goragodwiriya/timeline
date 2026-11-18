<?php
/**
 * @filesource Gcms/Chat/ChatCommandCatalog.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

use Kotchasan\Language;

/**
 * Single place to register user-visible /commands for /help and future UIs.
 *
 * Extend at runtime: {@see ChatCommandCatalog::register()}
 *
 * @since 1.0
 */
class ChatCommandCatalog extends \Kotchasan\KBase
{
    /**
     * @var array<int, array<string, string>>
     */
    private static $extra = [];

    /**
     * @param array<int, array<string, string>> $items
     */
    public static function register(array $items): void
    {
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['id'])) {
                self::$extra[] = $item;
            }
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function all(): array
    {
        return array_merge(self::core(), self::$extra);
    }

    /**
     * Normalize user text so slash commands match on every channel (web, LINE, Telegram).
     *
     * Handles Telegram-style `/help@BotName` and fullwidth slash.
     *
     * @param string $text
     *
     * @return string
     */
    public static function normalizeText($text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        // Normalize slash variants and strip invisible marks often introduced by social clients.
        $text = str_replace(["\xEF\xBC\x8F", "\xE2\x88\x95", "\xE2\x81\x84"], '/', $text);

        // Telegram and mobile keyboards may prepend bidi/format control chars before slash commands.
        $text = preg_replace('/^[\p{Cc}\p{Cf}\p{Z}]+/u', '', $text);
        $text = preg_replace('/[\x{FEFF}\x{200B}\x{200C}\x{200D}\x{200E}\x{200F}\x{2060}]/u', '', $text);
        if (!is_string($text)) {
            return '';
        }

        $text = preg_replace('#^(/[a-z0-9_-]+)@[\w.-]+#iu', '$1', $text);
        if (!is_string($text)) {
            return '';
        }

        return trim($text);
    }

    /**
     * @param string $commandId help|search|contact|read
     *
     * @return array<string, string>|null
     */
    public static function findById($commandId): ?array
    {
        $commandId = trim((string) $commandId);
        foreach (self::all() as $row) {
            if (($row['id'] ?? '') === $commandId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether normalized text starts with a catalog command (optional arguments).
     *
     * @param string $text
     * @param string $commandId
     *
     * @return bool
     */
    public static function messageMatchesCommand($text, $commandId): bool
    {
        $row = self::findById($commandId);
        if ($row === null) {
            return false;
        }

        $cmd = (string) ($row['command'] ?? '');
        if ($cmd === '') {
            return false;
        }

        $text = self::normalizeText($text);

        return preg_match('#^'.preg_quote($cmd, '#').'(\s|$)#iu', $text) === 1;
    }

    /**
     * Text after the command token (trimmed). Empty string if none.
     *
     * @param string $text
     * @param string $commandId
     *
     * @return string
     */
    public static function extractArgument($text, $commandId): string
    {
        $row = self::findById($commandId);
        if ($row === null) {
            return '';
        }

        $cmd = (string) ($row['command'] ?? '');
        if ($cmd === '') {
            return '';
        }

        $text = self::normalizeText($text);
        if (preg_match('#^'.preg_quote($cmd, '#').'\s*(.*)$#ius', $text, $matches) !== 1) {
            return '';
        }

        return trim((string) ($matches[1] ?? ''), " \t\n\r\0\x0B\"'");
    }

    /**
     * รายการคำสั่งของแอปนี้
     *
     * ค่าตั้งต้นด้านล่างเป็นชุดของเว็บไซต์เนื้อหา (/search /read /contact) ซึ่ง
     * แอปส่วนใหญ่ไม่ได้มีโมดูลเหล่านั้น · ประกาศ $cfg->chat_commands เพื่อแทนที่
     * ทั้งชุดด้วยคำสั่งจริงของแอป มิฉะนั้น /help จะโฆษณาคำสั่งที่ไม่มีอยู่จริง
     * และผู้ใช้จะแตะแล้วไม่เกิดอะไรขึ้น
     *
     * แต่ละแถวใส่ข้อความตรง ๆ ได้ (title, description, example) หรือใส่คีย์ภาษา
     * (title_key, desc_key, fill_key) ก็ได้ — ใส่ตรง ๆ เหมาะกับแอปที่ไม่ได้แปล
     * หน้าแชต เพราะคีย์ที่ไม่มีในไฟล์ภาษาจะกลายเป็นค่าว่างแบบเงียบ ๆ
     *
     * @return array<int, array<string, string>>
     */
    private static function core(): array
    {
        // CLI บางเส้นทาง (cron) ไม่ได้ผ่าน bootstrap เต็ม — $cfg ยังเป็น null ได้
        $declared = self::$cfg === null ? null : self::$cfg->chat_commands;
        if (is_array($declared) && $declared !== []) {
            $rows = [];
            foreach ($declared as $row) {
                if (is_array($row) && !empty($row['id']) && !empty($row['command'])) {
                    $rows[] = $row;
                }
            }
            if ($rows !== []) {
                return $rows;
            }
        }

        return [
            [
                'id' => 'help',
                'command' => '/help',
                'title_key' => 'AI chat cmd help title',
                'desc_key' => 'AI chat cmd help desc',
                'fill_key' => 'AI chat cmd help fill'
            ],
            [
                'id' => 'search',
                'command' => '/search',
                'title_key' => 'AI chat cmd search title',
                'desc_key' => 'AI chat cmd search desc',
                'fill_key' => 'AI chat cmd search fill'
            ],
            [
                'id' => 'contact',
                'command' => '/contact',
                'title_key' => 'AI chat cmd contact title',
                'desc_key' => 'AI chat cmd contact desc',
                'fill_key' => 'AI chat cmd contact fill'
            ],
            [
                'id' => 'read',
                'command' => '/read',
                'title_key' => 'AI chat cmd read title',
                'desc_key' => 'AI chat cmd read desc',
                'fill_key' => 'AI chat cmd read fill'
            ]
        ];
    }

    /**
     * Human-readable lines for /help body (translated).
     *
     * @return string[]
     */
    public static function helpBodyLines(): array
    {
        $lines = [];
        foreach (self::all() as $row) {
            $cmd = (string) ($row['command'] ?? '');
            if ($cmd === '') {
                continue;
            }
            // อย่าทิ้งทั้งบรรทัดเพราะไม่มีคำอธิบาย — เดิมคำสั่งที่คีย์ภาษาหายไป
            // จะถูกข้ามทุกบรรทัด แล้ว /help ตอบว่า "ยังไม่มีคำสั่ง" ทั้งที่มีอยู่
            $desc = self::text($row, 'description', 'desc_key', '');
            if ($desc === '') {
                $desc = self::text($row, 'title', 'title_key', '');
            }
            $lines[] = $desc !== '' ? $cmd.' — '.$desc : $cmd;
        }

        return $lines;
    }

    /**
     * ข้อความของแถว — ใช้ค่าที่เขียนตรง ๆ ก่อน แล้วค่อยถอยไปหาคีย์ภาษา
     *
     * @param array<string, string> $row
     * @param string                $literalKey
     * @param string                $languageKey
     * @param string                $default
     *
     * @return string
     */
    private static function text(array $row, $literalKey, $languageKey, $default): string
    {
        $literal = trim((string) ($row[$literalKey] ?? ''));
        if ($literal !== '') {
            return $literal;
        }

        $key = (string) ($row[$languageKey] ?? '');

        return $key !== '' ? (string) Language::get($key, $default) : $default;
    }

    /**
     * Prompt actions: tap inserts into composer (behavior compose) unless overridden.
     *
     * @return array<int, array<string, string>>
     */
    public static function helpPromptActions(): array
    {
        $out = [];
        foreach (self::all() as $row) {
            $cmd = (string) ($row['command'] ?? '');
            if ($cmd === '') {
                continue;
            }
            $label = self::text($row, 'title', 'title_key', $cmd);
            $value = self::text($row, 'example', 'fill_key', $cmd);
            $out[] = [
                'type' => 'prompt',
                'label' => $label,
                'value' => $value,
                'behavior' => 'compose'
            ];
        }

        return $out;
    }

    /**
     * Structured command list for API clients (optional).
     *
     * @return array<int, array<string, string>>
     */
    public static function forApi(): array
    {
        $out = [];
        foreach (self::all() as $row) {
            $out[] = [
                'id' => (string) ($row['id'] ?? ''),
                'command' => (string) ($row['command'] ?? ''),
                'title' => self::text($row, 'title', 'title_key', ''),
                'description' => self::text($row, 'description', 'desc_key', ''),
                'example' => self::text($row, 'example', 'fill_key', '')
            ];
        }

        return $out;
    }
}
