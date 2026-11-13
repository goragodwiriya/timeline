<?php
/**
 * @filesource Gcms/Timeline/Client.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

use Kotchasan\Curl;
use Kotchasan\Language;

/**
 * ตัวคุยกับระบบต้นทางตาม TIMELINE-PROTOCOL.md
 *
 * ทุกคำขอออกจากที่นี่ที่เดียว เพื่อให้ timeout การยืนยันตัวตน และการตีความ
 * ซองข้อมูลของสองเฟรมเวิร์กที่ต่างกัน อยู่รวมกันจุดเดียว
 *
 * @since 1.0
 */
class Client
{
    /**
     * ต่อไม่ติดใน 5 วินาที = ปลายทางไม่อยู่ ไม่ต้องรอ
     */
    const CONNECT_TIMEOUT = 5;

    /**
     * ทั้งคำขอต้องจบใน 20 วินาที — ยาวกว่านี้รอบ cron จะซ้อนกันเอง
     */
    const TIMEOUT = 20;

    /**
     * จำนวน source ที่ยิงพร้อมกันได้
     */
    const CONCURRENCY = 6;

    /**
     * สร้าง Curl ที่ตั้งค่าครบสำหรับ source หนึ่ง
     *
     * @param object $source แถวจาก timeline_sources
     * @param int    $runId  ใส่ไปในหัว X-Hub-Run เพื่อให้ไล่ log ฝั่งต้นทางได้
     *
     * @return Curl
     */
    public static function curl($source, $runId = 0)
    {
        $curl = new Curl();
        $curl->setHeaders([
            'Authorization' => 'Bearer '.$source->token,
            'Accept' => 'application/json',
            'User-Agent' => 'TimelineHub/1.0',
            'X-Hub-Run' => (string) $runId
        ]);
        $curl->setOptions([
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            // ปลายทางเปลี่ยน URL แล้วส่ง redirect มา จะพา token ข้ามโดเมนไปด้วย
            CURLOPT_FOLLOWLOCATION => false
        ]);

        return $curl;
    }

    /**
     * ประกอบ URL ปลายทาง
     *
     * @param object $source
     * @param string $path  เช่น 'timeline/items'
     * @param array  $query
     *
     * @return string
     */
    public static function url($source, $path, array $query = [])
    {
        $url = rtrim($source->base_url, '/').'/'.ltrim($path, '/');

        return empty($query) ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * ยิงหลายคำขอพร้อมกัน
     *
     * ห้ามยิงเรียงทีละตัว — source เดียวที่ค้างจะกินเวลาจนตัวหลังไม่ได้ sync
     * และเมื่อมีหลายระบบ รอบ cron จะซ้อนกันเองจนพังทั้งกระดาน
     *
     * @param array $requests [key => ['curl' => Curl, 'url' => string, 'post' => string|null]]
     *
     * @return array [key => ['ok' => bool, 'status' => int, 'data' => array|null, 'error' => string]]
     */
    public static function multi(array $requests)
    {
        $results = [];
        foreach (Curl::multi($requests, self::CONCURRENCY) as $key => $raw) {
            $results[$key] = self::interpret($raw);
        }

        return $results;
    }

    /**
     * ยิงคำขอเดียว
     *
     * @param Curl        $curl
     * @param string      $url
     * @param string|null $post
     *
     * @return array
     */
    public static function one(Curl $curl, $url, $post = null)
    {
        $result = self::multi([['curl' => $curl, 'url' => $url, 'post' => $post]]);

        return $result[0];
    }

    /**
     * ตีความคำตอบดิบให้เป็นผลลัพธ์รูปเดียวกัน
     *
     * ซองข้อมูลของสองฝั่งไม่เหมือนกันและเปลี่ยนไม่ได้ — Kotchasan ตอบ
     * {success, code, message, data} ส่วน BluHost ตอบ {ok, data, meta}
     * จึงยึด **HTTP status เป็นคำตอบจริง** แล้วอ่านเนื้อจากคีย์ data
     * (TIMELINE-PROTOCOL.md §3.4)
     *
     * @param array $raw ผลจาก Curl::multi()
     *
     * @return array
     */
    private static function interpret(array $raw)
    {
        $status = (int) ($raw['status'] ?? 0);

        if (!empty($raw['error'])) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => null,
                'error' => Language::sprintf('Cannot connect: %s', $raw['errorMessage'] ?: 'cURL '.$raw['error'])
            ];
        }

        $body = json_decode((string) ($raw['body'] ?? ''), true);

        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => null,
                'error' => self::errorText($body, $status)
            ];
        }

        if ($status === 204) {
            return ['ok' => true, 'status' => $status, 'data' => [], 'error' => ''];
        }

        if (!is_array($body)) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => null,
                'error' => Language::get('The source did not answer with JSON')
            ];
        }

        // ธงบูลีนจะชื่อ ok หรือ success ก็ได้ · ไม่มีธงเลยกับ 2xx ถือว่าสำเร็จ
        $flag = $body['ok'] ?? ($body['success'] ?? true);
        if (!$flag) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => null,
                'error' => self::errorText($body, $status)
            ];
        }

        if (!array_key_exists('data', $body)) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => null,
                'error' => Language::get('The answer has no data key')
            ];
        }

        return ['ok' => true, 'status' => $status, 'data' => $body['data'], 'error' => ''];
    }

    /**
     * @param mixed $body
     * @param int   $status
     *
     * @return string
     */
    private static function errorText($body, $status)
    {
        if (is_array($body)) {
            if (!empty($body['error']['message'])) {
                return 'HTTP '.$status.': '.$body['error']['message'];
            }
            if (!empty($body['message'])) {
                return 'HTTP '.$status.': '.$body['message'];
            }
        }

        return 'HTTP '.$status;
    }

    /**
     * สถานะนี้ต้องหยุดยิงทันที ไม่ retry
     *
     * token ผิดจะไม่หายเองด้วยการยิงซ้ำ มีแต่จะโดนปลายทางแบน
     *
     * @param int $status
     *
     * @return bool
     */
    public static function isFatal($status)
    {
        return in_array((int) $status, [400, 401, 403, 404], true);
    }
}
