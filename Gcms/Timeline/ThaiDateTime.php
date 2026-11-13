<?php
/**
 * @filesource Gcms/Timeline/ThaiDateTime.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

/**
 * อ่านวันและเวลาจากภาษาไทยที่คนพิมพ์จริง
 *
 * ยกแนวทางมาจาก `appointment-system/backend/services/AIService.php` ที่เคยเขียนไว้
 * แต่แยกออกมาเป็นตัวแปลล้วน ๆ ไม่ผูกกับ AI — **การบันทึกนัดต้องทำได้แม้ AI ล่ม
 * โควตาหมด หรือแปลผิด** (HUB-DESIGN.md §8.4) ตัวนี้จึงเป็นทางหลัก ส่วน AI เป็น
 * ทางเสริมสำหรับประโยคที่ซับซ้อนกว่านี้
 *
 * ไม่พยายามเข้าใจทุกประโยค — คืน null เมื่อไม่มั่นใจ ดีกว่าเดาผิดแล้วบันทึกนัดผิดวัน
 * แต่ "ไม่มั่นใจ" ต้องแปลว่าอ่านไม่ออกจริง ๆ ไม่ใช่แค่คนพิมพ์คนละแบบกับที่โค้ดรู้จัก
 * — รูปแบบวันที่ที่คนไทยใช้จริงมีเยอะกว่าที่นึกไว้มาก ดู {@see self::FORMATS}
 *
 * @since 1.0
 */
class ThaiDateTime
{
    /**
     * ตัวอย่างรูปแบบที่อ่านออก — ใช้บอกผู้ใช้เมื่ออ่านไม่ออก
     *
     * เขียนไว้ที่เดียวกับตัวอ่าน เพราะข้อความช่วยเหลือที่ไม่ตรงกับสิ่งที่โค้ดทำ
     * แย่กว่าไม่มีข้อความช่วยเหลือเลย
     */
    const FORMATS = '10/9 · 10-9-69 · 10 ก.ย. · 1 ตค · 10 กันยายน 2569 · 1 Oct 2026 · พรุ่งนี้ · ศุกร์นี้ · อีก 3 วัน · สิ้นเดือน';

    /**
     * คำบอกวันในสัปดาห์
     */
    const WEEKDAYS = [
        'จันทร์' => 'monday', 'อังคาร' => 'tuesday', 'พุธ' => 'wednesday',
        'พฤหัสบดี' => 'thursday', 'พฤหัส' => 'thursday', 'ศุกร์' => 'friday',
        'เสาร์' => 'saturday', 'อาทิตย์' => 'sunday'
    ];

    /**
     * ตัวเลขไทยที่ใช้บอกเวลา
     */
    const NUMBERS = [
        'หนึ่ง' => 1, 'สอง' => 2, 'สาม' => 3, 'สี่' => 4, 'ห้า' => 5, 'หก' => 6,
        'เจ็ด' => 7, 'แปด' => 8, 'เก้า' => 9, 'สิบเอ็ด' => 11, 'สิบสอง' => 12, 'สิบ' => 10
    ];

    /**
     * ชิ้นส่วน regex ของชื่อเดือน **เรียงจากยาวไปสั้น**
     *
     * ลำดับสำคัญ — PCRE ใน alternation เลือกตัวแรกที่แมตช์ ไม่ใช่ตัวที่ยาวที่สุด
     * ถ้า `sep` มาก่อน `september` คำว่า "September" จะถูกอ่านเป็น "Sep" แล้วเหลือ
     * "tember" ค้างอยู่ในชื่อเรื่อง
     *
     * ตัวย่อไทยเขียนแบบ `ต\.?ค\.?` เพื่อรับทั้ง "ต.ค." "ตค." "ต.ค" และ "ตค"
     * — จุดเป็นสิ่งที่คนพิมพ์ในมือถือละมากที่สุด
     */
    const MONTH_PARTS = [
        'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
        'มี\.?ค\.?', 'เม\.?ย\.?', 'มิ\.?ย\.?',
        'ม\.?ค\.?', 'ก\.?พ\.?', 'พ\.?ค\.?', 'ก\.?ค\.?', 'ส\.?ค\.?',
        'ก\.?ย\.?', 'ต\.?ค\.?', 'พ\.?ย\.?', 'ธ\.?ค\.?',
        'january', 'february', 'march', 'april', 'august', 'september',
        'october', 'november', 'december', 'june', 'july', 'may',
        'jan\.?', 'feb\.?', 'mar\.?', 'apr\.?', 'jun\.?', 'jul\.?',
        'aug\.?', 'sept?\.?', 'oct\.?', 'nov\.?', 'dec\.?'
    ];

    /**
     * ชื่อเดือนหลัง normalize (พิมพ์เล็ก ไม่มีจุด ไม่มีช่องว่าง) → เลขเดือน
     */
    const MONTH_LOOKUP = [
        'มกราคม' => 1, 'มค' => 1, 'กุมภาพันธ์' => 2, 'กพ' => 2,
        'มีนาคม' => 3, 'มีค' => 3, 'เมษายน' => 4, 'เมย' => 4,
        'พฤษภาคม' => 5, 'พค' => 5, 'มิถุนายน' => 6, 'มิย' => 6,
        'กรกฎาคม' => 7, 'กค' => 7, 'สิงหาคม' => 8, 'สค' => 8,
        'กันยายน' => 9, 'กย' => 9, 'ตุลาคม' => 10, 'ตค' => 10,
        'พฤศจิกายน' => 11, 'พย' => 11, 'ธันวาคม' => 12, 'ธค' => 12,
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2,
        'march' => 3, 'mar' => 3, 'april' => 4, 'apr' => 4,
        'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8, 'september' => 9, 'sept' => 9, 'sep' => 9,
        'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11,
        'december' => 12, 'dec' => 12
    ];

    /**
     * ตัวคั่นวัน/เดือน/ปี ที่ยอมรับ — `/` `-` `.` และช่องว่าง
     */
    const SEP = '[\/\-.\s]';

    /**
     * แยกข้อความหนึ่งประโยคเป็นส่วนประกอบของนัดหมาย
     *
     * @param string $text
     *
     * @return array ['date','time','all_day','title','location','duration']
     *               date เป็น null เมื่ออ่านไม่ออก — ผู้เรียกต้องถามต่อ
     */
    public static function parse($text)
    {
        $text = self::normalize($text);

        $location = self::location($text);
        $time = self::time($text);
        $date = self::date($text);

        return [
            'date' => $date,
            'time' => $time,
            // ไม่ระบุเวลา = นัดทั้งวัน ไม่ใช่เที่ยงคืน ซึ่งไม่มีใครหมายถึง
            'all_day' => $time === null,
            'duration' => self::duration($text),
            'location' => $location,
            'title' => self::title($text, $location)
        ];
    }

    /**
     * ปรับข้อความให้อยู่ในรูปเดียวก่อนอ่าน
     *
     * เลขไทยต้องกลายเป็นเลขอารบิก ไม่งั้น "๑๐ ก.ย." อ่านไม่ออกทั้งที่เป็นรูปที่
     * แป้นพิมพ์ไทยบางตัวให้มาเอง · ขีดยาวและช่องว่างแบบพิเศษที่มากับการก๊อปวาง
     * จากเอกสารก็ต้องกลายเป็นขีดสั้นและช่องว่างธรรมดา
     *
     * @param string $text
     *
     * @return string
     */
    public static function normalize($text)
    {
        $text = (string) $text;
        $text = str_replace(
            ['๐', '๑', '๒', '๓', '๔', '๕', '๖', '๗', '๘', '๙', '–', '—', '−', "\xC2\xA0"],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '-', '-', '-', ' '],
            $text
        );

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * เวลาในรูป HH:MM
     *
     * @param string $text
     *
     * @return string|null
     */
    public static function time($text)
    {
        $text = self::normalize($text);

        if (preg_match('/(\d{1,2})[:.](\d{2})\s*น?\.?/u', $text, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];

            return $h <= 23 && $i <= 59 ? sprintf('%02d:%02d', $h, $i) : null;
        }
        // 14 น. · 9 นาฬิกา — บอกชั่วโมงเปล่า ๆ โดยไม่มีนาที
        if (preg_match('/(\d{1,2})\s*(?:น\.|นาฬิกา)/u', $text, $m)) {
            $h = (int) $m[1];

            return $h <= 23 ? sprintf('%02d:00', $h) : null;
        }
        // หนึ่งทุ่ม = 19:00 · หกทุ่ม = เที่ยงคืน · รับทั้งเลขอารบิกและคำไทย
        // เพราะคนพิมพ์ "สองทุ่ม" บ่อยกว่า "2 ทุ่ม"
        if (preg_match('/(\d{1,2})\s*ทุ่ม/u', $text, $m)) {
            $h = (int) $m[1] + 18;

            return sprintf('%02d:00', $h >= 24 ? 0 : $h);
        }
        foreach (self::NUMBERS as $word => $number) {
            if (preg_match('/'.$word.'\s*ทุ่ม/u', $text)) {
                $h = $number + 18;

                return sprintf('%02d:00', $h >= 24 ? 0 : $h);
            }
        }
        if (preg_match('/เที่ยงคืน/u', $text)) {
            return '00:00';
        }
        if (preg_match('/เที่ยง/u', $text)) {
            return '12:00';
        }
        if (preg_match('/(\d{1,2})\s*โมงครึ่ง/u', $text, $m)) {
            return self::clock((int) $m[1], 30, $text);
        }
        if (preg_match('/(\d{1,2})\s*โมง/u', $text, $m)) {
            return self::clock((int) $m[1], 0, $text);
        }
        foreach (self::NUMBERS as $word => $number) {
            if (preg_match('/'.$word.'\s*โมง/u', $text)) {
                return self::clock($number, 0, $text);
            }
        }
        if (preg_match('/บ่าย\s*(\d{1,2})/u', $text, $m)) {
            $h = (int) $m[1];

            return sprintf('%02d:00', $h <= 6 ? $h + 12 : $h);
        }

        return null;
    }

    /**
     * แปลง "N โมง" เป็นเวลา 24 ชั่วโมงโดยดูคำรอบข้าง
     *
     * ภาษาพูดไม่ได้บอกเช้าบ่ายทุกครั้ง — "บ่ายสองโมง" ชัด แต่ "สองโมง" เฉย ๆ
     * หมายถึงแปดโมงเช้าตามการนับแบบไทย ซึ่งคนพิมพ์มักไม่ได้ตั้งใจ · เลือกตีความ
     * ตามนาฬิกาสากลที่คนพิมพ์คุ้นกว่า และเติมบ่ายให้เมื่อมีคำว่าบ่าย/เย็น/ค่ำ
     *
     * @param int    $hour
     * @param int    $minute
     * @param string $text
     *
     * @return string|null
     */
    private static function clock($hour, $minute, $text)
    {
        if ($hour < 1 || $hour > 23) {
            return null;
        }
        $afternoon = preg_match('/บ่าย|เย็น|ค่ำ/u', $text) === 1;
        if ($afternoon && $hour <= 6) {
            $hour += 12;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * วันที่ในรูป Y-m-d
     *
     * ลองทีละรูปแบบตามลำดับความชัดเจน — รูปที่ระบุปีมาด้วยชัดที่สุดจึงมาก่อน
     * และรูปที่ต้องเดาปีมาทีหลัง · **รูปที่แมตช์แล้วได้วันที่ไม่มีอยู่จริง ต้อง
     * ไปลองรูปถัดไป ไม่ใช่ตอบ null ทันที** ไม่งั้น "14.30 น." จะกลายเป็น
     * "วันที่ 14 เดือน 30" แล้วปิดประตูไม่ให้รูปอื่นได้ลอง
     *
     * @param string $text
     *
     * @return string|null
     */
    public static function date($text)
    {
        $text = self::normalize($text);
        if ($text === '') {
            return null;
        }

        // ตัดเวลาและระยะเวลาออกก่อน — ตัวเลขของมันหน้าตาเหมือนวันที่
        // "ประชุม 2 ชั่วโมง 10 ก.ย." ถ้าไม่ตัด "2" จะถูกอ่านเป็นวันที่
        $clean = self::maskTime($text);

        foreach ([
            'isoDate', 'numericWithYear', 'namedMonth', 'monthFirst',
            'numericNoYear', 'relative', 'inDays', 'weekday', 'dayOnly'
        ] as $reader) {
            $found = self::$reader($clean);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 2026-09-10 · 2026/9/10
     *
     * @param string $text
     *
     * @return string|null
     */
    private static function isoDate($text)
    {
        if (preg_match('#(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})#u', $text, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return null;
    }

    /**
     * 10/9/2569 · 10-9-69 · 10.9.26 · 10 9 2026
     *
     * @param string $text
     *
     * @return string|null
     */
    private static function numericWithYear($text)
    {
        // ห้ามให้ชั่วโมงของเวลากลายเป็นปี — "10/9 14:00" ต้องไม่อ่านว่า
        // วันที่ 10 เดือน 9 ปี 2014 · ตัวเลขที่มี ":" ต่อท้ายไม่ใช่ปีแน่นอน
        $pattern = '#(?<![\d\/\-.:])(\d{1,2})'.self::SEP.'(\d{1,2})'.self::SEP.'(\d{2,4})(?![\d\/\-.:])#u';
        if (preg_match_all($pattern, $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $found = self::ymd((int) $m[3], (int) $m[2], (int) $m[1]);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * 10/9 · 1-9 · 10.9 — ไม่ระบุปี
     *
     * ปีที่ไม่ได้บอกคือ "รอบถัดไปที่จะมาถึง" ไม่ใช่ปีปฏิทินปัจจุบันเสมอ —
     * พิมพ์ "1/9" ในเดือนธันวาคม แปลว่ากันยายนปีหน้า ไม่ใช่กันยายนที่ผ่านไปแล้ว
     *
     * @param string $text
     *
     * @return string|null
     */
    private static function numericNoYear($text)
    {
        $pattern = '#(?<![\d\/\-.])(\d{1,2})[\/\-.](\d{1,2})(?![\d\/\-.])#u';
        if (preg_match_all($pattern, $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $found = self::upcoming((int) $m[2], (int) $m[1]);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * 10 ก.ย. · 1 ตค · 10 กันยายน 2569 · 1-Oct-2026 · 10th September
     *
     * @param string $text
     *
     * @return string|null
     */
    private static function namedMonth($text)
    {
        $pattern = '/(\d{1,2})(?:st|nd|rd|th)?\s*[-\s]?\s*('.self::monthPattern().')'
            .'(?:\s*,?\s*[-\s]?\s*(\d{2,4})(?![\d:.]))?/iu';
        if (!preg_match($pattern, $text, $m)) {
            return null;
        }

        $month = self::monthNumber($m[2]);
        if ($month === null) {
            return null;
        }
        $day = (int) $m[1];

        return isset($m[3]) && $m[3] !== ''
            ? self::ymd((int) $m[3], $month, $day)
            : self::upcoming($month, $day);
    }

    /**
     * ก.ย. 10 · October 1, 2026 · กันยายน 10 2569
     *
     * @param string $text
     *
     * @return string|null
     */
    private static function monthFirst($text)
    {
        $pattern = '/('.self::monthPattern().')\s*[-\s]?\s*(\d{1,2})(?:st|nd|rd|th)?'
            .'(?:\s*,?\s*[-\s]?\s*(\d{2,4})(?![\d:.]))?/iu';
        if (!preg_match($pattern, $text, $m)) {
            return null;
        }

        $month = self::monthNumber($m[1]);
        if ($month === null) {
            return null;
        }
        $day = (int) $m[2];

        return isset($m[3]) && $m[3] !== ''
            ? self::ymd((int) $m[3], $month, $day)
            : self::upcoming($month, $day);
    }

    /**
     * คำบอกวันแบบสัมพัทธ์ — พรุ่งนี้ · สิ้นเดือน · เดือนหน้า
     *
     * @param string $text
     *
     * @return string|null
     */
    private static function relative($text)
    {
        $today = strtotime('today');

        $map = [
            'มะรืนนี้|มะรืน' => '+2 days',
            'พรุ่งนี้|พรุ้งนี้|พรุ่งนี|วันพรุ่ง' => '+1 day',
            'เมื่อวานซืน' => '-2 days',
            'เมื่อวาน|เมื่อวานนี้' => '-1 day',
            'วันนี้|เย็นนี้|คืนนี้|เช้านี้|บ่ายนี้|ตอนนี้' => 'today',
            'อาทิตย์หน้า|สัปดาห์หน้า|วีคหน้า' => '+1 week',
            'อาทิตย์ที่แล้ว|สัปดาห์ที่แล้ว' => '-1 week',
            'เดือนหน้า' => 'first day of next month',
            'ปีหน้า' => '+1 year',
            'สิ้นเดือนนี้|สิ้นเดือน|ปลายเดือน' => 'last day of this month',
            'ต้นเดือนหน้า' => 'first day of next month',
            'ต้นเดือน' => 'first day of this month'
        ];

        foreach ($map as $words => $expr) {
            if (preg_match('/'.$words.'/u', $text)) {
                return date('Y-m-d', strtotime($expr, $today));
            }
        }

        // กลางเดือน — วันที่ 15 ไม่ใช่ค่าที่ strtotime รู้จัก
        if (preg_match('/กลางเดือนหน้า/u', $text)) {
            return date('Y-m-15', strtotime('first day of next month', $today));
        }
        if (preg_match('/กลางเดือน/u', $text)) {
            return date('Y-m-15', $today);
        }

        return null;
    }

    /**
     * อีก 3 วัน · ใน 2 สัปดาห์ · อีกเดือน · 5 วันข้างหน้า
     *
     * @param string $text
     *
     * @return string|null
     */
    private static function inDays($text)
    {
        $units = [
            'วัน' => 'days',
            'สัปดาห์' => 'weeks',
            'อาทิตย์' => 'weeks',
            'เดือน' => 'months',
            'ปี' => 'years'
        ];

        foreach ($units as $thai => $unit) {
            // "อีก 3 วัน" · "ใน 3 วัน" · "3 วันข้างหน้า"
            if (preg_match('/(?:อีก|ใน)\s*(\d{1,3})\s*'.$thai.'/u', $text, $m)
                || preg_match('/(\d{1,3})\s*'.$thai.'(?:ข้างหน้า|ถัดไป)/u', $text, $m)) {
                return date('Y-m-d', strtotime('+'.((int) $m[1]).' '.$unit, strtotime('today')));
            }
            // "อีกวัน" · "อีกเดือน" — ไม่มีเลขแปลว่าหนึ่ง
            if (preg_match('/อีก\s*'.$thai.'/u', $text)) {
                return date('Y-m-d', strtotime('+1 '.$unit, strtotime('today')));
            }
        }

        return null;
    }

    /**
     * ศุกร์นี้ · วันจันทร์หน้า · friday
     *
     * @param string $text
     *
     * @return string|null
     */
    private static function weekday($text)
    {
        foreach (self::WEEKDAYS as $thai => $english) {
            $found = mb_strpos($text, $thai) !== false
                || preg_match('/\b'.$english.'\b/i', $text) === 1;
            if (!$found) {
                continue;
            }

            // "ศุกร์นี้" (และ "วันศุกร์" เฉย ๆ) คือศุกร์ที่กำลังจะถึง
            // ส่วน "ศุกร์หน้า" คือของสัปดาห์ถัดจากนั้นอีกหนึ่งสัปดาห์
            //
            // `strtotime('next friday')` ของภาษานี้ให้ "ศุกร์ที่กำลังจะถึง" ไม่ใช่
            // "ศุกร์สัปดาห์หน้า" ตามที่ชื่อชวนให้เข้าใจ — ต้องบวกสัปดาห์เอง
            $expr = 'next '.$english;
            if (preg_match('/'.$thai.'\s*หน้า/u', $text)) {
                $expr .= ' +1 week';
            }

            return date('Y-m-d', strtotime($expr, strtotime('today')));
        }

        return null;
    }

    /**
     * วันที่ 10 · หรือคำตอบที่เป็นตัวเลขโดด ๆ
     *
     * ตัวเลขโดดรับเฉพาะตอนที่ทั้งข้อความมีแค่ตัวเลขนั้น — ในประโยคเต็ม
     * "ประชุม 10 คน" เลข 10 ไม่ใช่วันที่ และการเดาผิดตรงนี้เงียบมาก
     *
     * @param string $text
     *
     * @return string|null
     */
    private static function dayOnly($text)
    {
        if (preg_match('/วันที่\s*(\d{1,2})(?!\d)/u', $text, $m)) {
            return self::upcomingDay((int) $m[1]);
        }
        if (preg_match('/^(\d{1,2})$/u', trim($text), $m)) {
            return self::upcomingDay((int) $m[1]);
        }

        return null;
    }

    /**
     * ลบเวลาและระยะเวลาออกจากข้อความ เหลือไว้แต่ส่วนที่อาจเป็นวันที่
     *
     * `\d{1,2}.\d{2}` ตัดเฉพาะตอนมีคำบอกเวลาต่อท้าย — ไม่งั้น "10.09" ที่คนตั้งใจ
     * ให้เป็นวันที่จะหายไปด้วย ส่วน `\d{1,2}:\d{2}` ตัดเสมอเพราะไม่มีใครเขียน
     * วันที่ด้วยทวิภาค
     *
     * @param string $text
     *
     * @return string
     */
    private static function maskTime($text)
    {
        $patterns = [
            '/\d{1,2}:\d{2}(?::\d{2})?\s*(?:น\.?|นาฬิกา)?/u',
            '/\d{1,2}\.\d{2}\s*(?:น\.|นาฬิกา)/u',
            '/\d{1,2}\s*(?:น\.|นาฬิกา|โมงครึ่ง|โมง|ทุ่ม)/u',
            '/บ่าย\s*\d{1,2}/u',
            '/\d+\s*(?:ชั่วโมงครึ่ง|ชั่วโมง|ชม\.?|นาที)/u'
        ];

        return (string) preg_replace($patterns, ' ', $text);
    }

    /**
     * alternation ของชื่อเดือนทุกแบบ ประกอบครั้งเดียวแล้วใช้ซ้ำ
     *
     * @return string
     */
    private static function monthPattern()
    {
        static $pattern = null;
        if ($pattern === null) {
            $pattern = implode('|', self::MONTH_PARTS);
        }

        return $pattern;
    }

    /**
     * แปลงชื่อเดือนที่แมตช์มาเป็นเลขเดือน
     *
     * @param string $name
     *
     * @return int|null
     */
    private static function monthNumber($name)
    {
        $key = mb_strtolower(str_replace(['.', ' '], '', trim((string) $name)));

        return self::MONTH_LOOKUP[$key] ?? null;
    }

    /**
     * ประกอบวันที่พร้อมแปลง พ.ศ. เป็น ค.ศ.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     *
     * @return string|null
     */
    private static function ymd($year, $month, $day)
    {
        if ($year < 100) {
            // 69 = 2569 ถ้าตีเป็น 2069 จะกลายเป็นนัดในอีกสี่สิบปี
            $year += $year > 50 ? 2500 : 2000;
        }
        if ($year > 2400) {
            $year -= 543;
        }
        if ($month < 1 || $month > 12 || !checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * วันที่ที่กำลังจะถึงของวัน/เดือนที่ให้มา โดยไม่ระบุปี
     *
     * วนหาปีถัดไปได้ถึงสี่รอบ เพื่อให้ "29 ก.พ." ที่ไม่มีในปีนี้ไปตกปีอธิกสุรทิน
     * ที่ใกล้ที่สุด แทนที่จะตอบว่าอ่านไม่ออก
     *
     * @param int $month
     * @param int $day
     *
     * @return string|null
     */
    private static function upcoming($month, $day)
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        $today = date('Y-m-d');
        $year = (int) date('Y');

        for ($i = 0; $i <= 4; ++$i, ++$year) {
            if (!checkdate($month, $day, $year)) {
                continue;
            }
            $ymd = sprintf('%04d-%02d-%02d', $year, $month, $day);
            if ($ymd >= $today) {
                return $ymd;
            }
        }

        return null;
    }

    /**
     * วันที่ N ของเดือนนี้ หรือเดือนถัดไปถ้าผ่านไปแล้ว
     *
     * @param int $day
     *
     * @return string|null
     */
    private static function upcomingDay($day)
    {
        if ($day < 1 || $day > 31) {
            return null;
        }

        $today = date('Y-m-d');
        $cursor = strtotime('first day of this month');

        for ($i = 0; $i <= 12; ++$i) {
            $year = (int) date('Y', $cursor);
            $month = (int) date('n', $cursor);
            if (checkdate($month, $day, $year)) {
                $ymd = sprintf('%04d-%02d-%02d', $year, $month, $day);
                if ($ymd >= $today) {
                    return $ymd;
                }
            }
            $cursor = strtotime('+1 month', $cursor);
        }

        return null;
    }

    /**
     * ระยะเวลาเป็นนาที
     *
     * @param string $text
     *
     * @return int|null
     */
    public static function duration($text)
    {
        $text = self::normalize($text);
        $minutes = 0;

        if (preg_match('/(\d+)\s*(?:ชั่วโมง|ชม\.?)ครึ่ง/u', $text, $m)) {
            return ((int) $m[1]) * 60 + 30;
        }
        if (preg_match('/(\d+)\s*(?:ชั่วโมง|ชม\.?)/u', $text, $m)) {
            $minutes += ((int) $m[1]) * 60;
        }
        if (preg_match('/(\d+)\s*นาที/u', $text, $m)) {
            $minutes += (int) $m[1];
        }
        if ($minutes === 0 && preg_match('/ครึ่งชั่วโมง/u', $text)) {
            return 30;
        }

        return $minutes > 0 ? $minutes : null;
    }

    /**
     * สถานที่ — คำหลัง @ หรือหลังคำว่า "ที่"
     *
     * @param string $text
     *
     * @return string
     */
    public static function location($text)
    {
        $text = self::normalize($text);

        if (preg_match('/@\s*(.+)$/u', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\sที่\s*([^\d]+?)(?:\s*(?:เวลา|ตอน|วันที่|กับ)|$)/u', $text, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * เรื่องที่นัด — ตัดคำบอกวัน เวลา และสถานที่ออกจากประโยค
     *
     * @param string $text
     * @param string $location
     *
     * @return string
     */
    public static function title($text, $location = '')
    {
        $out = self::normalize($text);
        if ($location !== '') {
            $out = str_replace(['@ '.$location, '@'.$location, 'ที่ '.$location, $location], '', $out);
        }

        // ตัดเวลาและระยะเวลาออกก่อน ด้วยตัวเดียวกับที่ date() ใช้
        //
        // เดิมตัดวันที่ก่อน แล้ว `\d+ ตัวคั่น \d+ ตัวคั่น \d+` (ซึ่งรับช่องว่าง
        // เป็นตัวคั่นด้วย) คว้า "10/9 14" ไปทั้งก้อน เหลือ ":00" ค้างอยู่ในชื่อเรื่อง
        $out = self::maskTime($out);

        $month = self::monthPattern();
        $numbers = implode('|', array_keys(self::NUMBERS));
        $weekdays = implode('|', array_keys(self::WEEKDAYS));

        $patterns = [
            // วันที่ทุกรูป
            '#\d{4}[\/\-.]\d{1,2}[\/\-.]\d{1,2}#u',
            '#(?<![\d\/\-.:])\d{1,2}'.self::SEP.'\d{1,2}'.self::SEP.'\d{2,4}(?![\d\/\-.:])#u',
            '#\d{1,2}[\/\-.]\d{1,2}#u',
            '/\d{1,2}(?:st|nd|rd|th)?\s*[-\s]?\s*(?:'.$month.')\.?(?:\s*,?\s*[-\s]?\s*\d{2,4}(?![\d:.]))?/iu',
            '/(?:'.$month.')\.?\s*[-\s]?\s*\d{1,2}(?:st|nd|rd|th)?(?:\s*,?\s*[-\s]?\s*\d{2,4}(?![\d:.]))?/iu',
            '/วันที่\s*\d{1,2}/u',
            // เศษของเวลาที่ maskTime ทิ้งไว้ และคำบอกช่วงเวลา
            '/(?:\d{1,2}|'.$numbers.')\s*(?:โมงครึ่ง|โมง|ทุ่ม|นาฬิกา|น\.)/u',
            '/ครึ่งชั่วโมง|ชั่วโมงครึ่ง|ชั่วโมง|ชม\.|นาที|โมง|ทุ่ม|นาฬิกา/u',
            // คำบอกวันแบบสัมพัทธ์
            '/(?:วัน)?(?:'.$weekdays.')\s*(?:นี้|หน้า)?/u',
            '/(?:อีก|ใน)\s*\d{0,3}\s*(?:วัน|สัปดาห์|อาทิตย์|เดือน|ปี)(?:ข้างหน้า|ถัดไป)?/u',
            '/\d{1,3}\s*(?:วัน|สัปดาห์|อาทิตย์|เดือน|ปี)(?:ข้างหน้า|ถัดไป)/u',
            '/พรุ่งนี้|พรุ้งนี้|มะรืนนี้|มะรืน|เมื่อวานซืน|เมื่อวานนี้|เมื่อวาน|วันนี้|เย็นนี้|คืนนี้|เช้านี้|บ่ายนี้/u',
            '/สัปดาห์หน้า|อาทิตย์หน้า|เดือนหน้า|ปีหน้า|สิ้นเดือนนี้|สิ้นเดือน|ปลายเดือน|กลางเดือนหน้า|กลางเดือน|ต้นเดือนหน้า|ต้นเดือน/u',
            '/เที่ยงคืน|เที่ยง|บ่าย|เย็น|ค่ำ|เช้า|ตอน|เวลา|^นัด\s/u'
        ];

        $out = preg_replace($patterns, ' ', $out);
        $out = trim(preg_replace('/\s+/u', ' ', $out), " \t\n\r@·-,");

        return $out;
    }
}
