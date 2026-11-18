<?php
/**
 * @filesource Gcms/Timeline/Offsets.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

use Kotchasan\ApiException;
use Kotchasan\Language;

/**
 * แปลงช่วงเวลาเตือนล่วงหน้าไปกลับระหว่างสิ่งที่คนพิมพ์กับสิ่งที่ระบบเก็บ
 *
 * ระบบเก็บเป็น ISO-8601 duration (`P30D` `PT1H`) เพราะ `DateInterval` รับรูปนั้น
 * ตรง ๆ แต่ไม่มีใครอยากพิมพ์ — และรูปนั้นมีกับดักที่มองไม่เห็นด้วย: `P1M` คือ
 * หนึ่งเดือน ส่วน `PT1M` คือหนึ่งนาที ต่างกันแค่ตัว T ตัวเดียว
 *
 * ที่นี่จึงรับ "30, 7, 1 วัน, 1 ชม." แล้วแปลงให้ และแปลงกลับเพื่อแสดงในฟอร์ม
 *
 * @since 1.0
 */
class Offsets
{
    /**
     * จำนวนช่วงต่อกฎหนึ่งข้อ — มากกว่านี้คือการเตือนที่ถี่จนถูกปิดทิ้ง
     */
    const MAX = 8;

    /**
     * แปลงข้อความที่ผู้ใช้กรอกเป็น ISO-8601 duration
     *
     * ตัวเลขเปล่า = วัน เพราะเป็นหน่วยที่ใช้บ่อยที่สุดจนไม่มีใครพิมพ์กำกับ
     *
     * @param string $text เช่น "30, 7, 1" หรือ "3 วัน, 2 วัน, 1 วัน, 1 ชม."
     *
     * @throws ApiException 422 เมื่ออ่านไม่ออก
     *
     * @return array<string>
     */
    public static function parse($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            throw new ApiException(Language::get('At least one reminder time is required'), 422);
        }

        $out = [];
        foreach (preg_split('/[,\n·]+/u', $text) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $iso = self::token($token);
            if ($iso === null) {
                throw new ApiException(Language::sprintf('Cannot read the time span: %s', $token), 422);
            }
            // ซ้ำกันไม่มีประโยชน์ — offset_key ซ้ำถูกกันที่ระดับฐานข้อมูลอยู่แล้ว
            if (!in_array($iso, $out, true)) {
                $out[] = $iso;
            }
        }

        if (empty($out)) {
            throw new ApiException(Language::get('At least one reminder time is required'), 422);
        }
        if (count($out) > self::MAX) {
            throw new ApiException(Language::sprintf('At most %d reminder times', self::MAX), 422);
        }

        // เรียงจากไกลไปใกล้ ให้ตรงกับลำดับที่มันจะถูกส่งจริง
        usort($out, static function ($a, $b) {
            return self::seconds($b) <=> self::seconds($a);
        });

        return $out;
    }

    /**
     * แปลงกลับเป็นข้อความสำหรับแสดงในฟอร์มและในตาราง
     *
     * @param array|string $offsets array หรือ JSON
     *
     * @return string
     */
    public static function format($offsets)
    {
        if (!is_array($offsets)) {
            $offsets = (array) json_decode((string) $offsets, true);
        }

        $parts = [];
        foreach ($offsets as $iso) {
            $parts[] = self::human((string) $iso);
        }

        return implode(', ', $parts);
    }

    /**
     * คำอธิบายเต็มประโยคของกฎหนึ่งข้อ
     *
     * @param array|string $offsets
     * @param int          $maxRepeat
     *
     * @return string
     */
    public static function describe($offsets, $maxRepeat = 0)
    {
        if (!is_array($offsets)) {
            $offsets = (array) json_decode((string) $offsets, true);
        }

        $parts = [];
        foreach ($offsets as $iso) {
            $iso = (string) $iso;
            $parts[] = $iso === 'PT0S' ? Language::get('At the time') : Language::sprintf('%s before', self::human($iso));
        }
        if ((int) $maxRepeat > 0) {
            $parts[] = Language::sprintf('then repeat daily %d more times', (int) $maxRepeat);
        }

        return implode(' · ', $parts);
    }

    /**
     * หนึ่ง token เช่น "30" "7 วัน" "1 ชม." "90 นาที" "0"
     *
     * @param string $token
     *
     * @return string|null
     */
    private static function token($token)
    {
        $token = str_replace(['๐', '๑', '๒', '๓', '๔', '๕', '๖', '๗', '๘', '๙'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $token);

        // เขียนเป็น ISO มาเองก็รับ — ผู้ใช้เก่า ๆ หรือค่าที่ก๊อปมาจากที่อื่น
        if (preg_match('/^P(?:\d+[YMWD])+(?:T(?:\d+[HMS])+)?$/i', $token)
            || preg_match('/^PT(?:\d+[HMS])+$/i', $token)) {
            return strtoupper($token);
        }

        if (preg_match('/^(ตรงเวลา|ทันที|วันนั้น|on time|now)$/iu', $token)) {
            return 'PT0S';
        }

        if (!preg_match('/^(\d{1,4})\s*(.*)$/u', $token, $m)) {
            return null;
        }

        $n = (int) $m[1];
        $unit = trim(mb_strtolower($m[2]), " .\t");

        if ($n === 0) {
            return 'PT0S';
        }

        if ($unit === '' || preg_match('/^(วัน|d|day|days)$/u', $unit)) {
            return 'P'.$n.'D';
        }
        if (preg_match('/^(ชม|ชั่วโมง|h|hr|hrs|hour|hours)$/u', $unit)) {
            return 'PT'.$n.'H';
        }
        if (preg_match('/^(นาที|น|m|min|mins|minute|minutes)$/u', $unit)) {
            return 'PT'.$n.'M';
        }
        if (preg_match('/^(สัปดาห์|อาทิตย์|w|week|weeks)$/u', $unit)) {
            return 'P'.($n * 7).'D';
        }
        if (preg_match('/^(เดือน|mo|month|months)$/u', $unit)) {
            return 'P'.$n.'M';
        }

        return null;
    }

    /**
     * ISO หนึ่งค่าเป็นภาษาคน
     *
     * @param string $iso
     *
     * @return string
     */
    public static function human($iso)
    {
        if ($iso === 'PT0S') {
            return Language::get('On time');
        }
        if (preg_match('/^P(\d+)D$/', $iso, $m)) {
            return Language::sprintf('%d days', (int) $m[1]);
        }
        if (preg_match('/^P(\d+)M$/', $iso, $m)) {
            return Language::sprintf('%d months', (int) $m[1]);
        }
        if (preg_match('/^PT(\d+)H$/', $iso, $m)) {
            return Language::sprintf('%d hr', (int) $m[1]);
        }
        if (preg_match('/^PT(\d+)M$/', $iso, $m)) {
            return Language::sprintf('%d min', (int) $m[1]);
        }

        return $iso;
    }

    /**
     * ความยาวเป็นวินาทีโดยประมาณ ใช้เรียงลำดับเท่านั้น
     *
     * @param string $iso
     *
     * @return int
     */
    private static function seconds($iso)
    {
        try {
            $interval = new \DateInterval($iso);
        } catch (\Exception $e) {
            return 0;
        }

        return $interval->y * 31536000 + $interval->m * 2592000 + $interval->d * 86400
            + $interval->h * 3600 + $interval->i * 60 + $interval->s;
    }
}
