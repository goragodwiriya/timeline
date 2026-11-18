<?php
/**
 * @filesource Gcms/Search/SearchIntent.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Search;

use Gcms\Chat\ChatCommandCatalog;

/**
 * Detect search intent and strip command words from user text (chat + future site search).
 *
 * Slash-commands (Telegram-style, explicit control):
 * - `/search …`, `/find …`, `/s …`, `/ค้นหา …` → query is the remainder (min length in extractQuery).
 *
 * @since 1.0
 */
class SearchIntent
{
    /**
     * @param string $text
     *
     * @return bool
     */
    public static function matches($text)
    {
        $text = ChatCommandCatalog::normalizeText($text);

        if ($text === '') {
            return false;
        }

        if (ChatCommandCatalog::messageMatchesCommand($text, 'search')) {
            return true;
        }

        // Legacy aliases (same routing as catalog /search).
        if (preg_match('#^/(find|s)(\s+|$)#iu', $text) === 1) {
            return true;
        }
        if (preg_match('#^/ค้นหา(\s+|$)#u', $text) === 1) {
            return true;
        }

        if (preg_match('/(ค้นหา|หา|search|find).*(บทความ|เอกสาร|article|document|ข่าว|news|ประกาศ|announcement|สินค้า|product|products)|(บทความ|เอกสาร|article|document|ข่าว|news|ประกาศ|สินค้า|product).*(เกี่ยวกับ|เรื่อง|about|search|find)/iu', $text) === 1) {
            return true;
        }

        if (preg_match('/^(ค้นหา|หา|search|find)\s+(บทความ|เอกสาร|ข่าว|ประกาศ|สินค้า|article|document|product)s?\s+(.{2,})/iu', $text) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param string $text
     *
     * @return string
     */
    public static function extractQuery($text)
    {
        $text = ChatCommandCatalog::normalizeText($text);

        if (ChatCommandCatalog::messageMatchesCommand($text, 'search')) {
            $query = ChatCommandCatalog::extractArgument($text, 'search');
            if ($query !== '' && mb_strlen($query) >= 2) {
                return $query;
            }

            return '';
        }

        $query = $text;
        if (preg_match('#^/(find|s)\s*(.*)$#ius', $query, $m) === 1) {
            $query = trim((string) ($m[2] ?? ''));
        } elseif (preg_match('#^/ค้นหา\s*(.*)$#us', $query, $m) === 1) {
            $query = trim((string) ($m[1] ?? ''));
        }

        $patterns = [
            '/^(ค้นหา|หา)\s*(บทความ|เอกสาร|ข่าว|ประกาศ|สินค้า)\s*/iu',
            '/^(search|find)\s*(articles?|documents?|news|announcements?|products?)\s*/iu',
            '/^(บทความ|เอกสาร|ข่าว|ประกาศ|สินค้า)\s+(เกี่ยวกับ|เรื่อง)\s*/iu',
            '/^(articles?|documents?|news|announcements?|products?)\s+about\s*/iu'
        ];
        foreach ($patterns as $pattern) {
            $query = preg_replace($pattern, '', $query);
        }

        $query = trim($query, " \t\n\r\0\x0B\"'");

        return mb_strlen($query) >= 2 ? $query : '';
    }
}
