<?php
/**
 * @filesource modules/timeline/controllers/calendar.php
 *
 * `GET api/timeline/calendar?start=YYYY-MM-DD&end=YYYY-MM-DD`
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Calendar;

use Gcms\Api as ApiController;
use Gcms\Timeline\Access;
use Kotchasan\Http\Request;

/**
 * ป้อนข้อมูลให้ EventCalendar
 *
 * ช่วงวันที่มาจากคอมโพเนนต์เอง (`?start=&end=` ตามเดือนที่กำลังเปิดดู)
 * จึงไม่โหลดทั้งฐานข้อมูลมาแล้วค่อยตัดที่ฝั่งเบราว์เซอร์
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function index(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');
            $this->initLanguage($request);
            $login = $this->authenticateRequest($request);
            // ล็อกอินได้ไม่พอ — ต้องมีสิทธิ์ can_manage_timeline ด้วย
            if (!Access::allow($login)) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // ไม่มีช่วงมา = เดือนนี้ · คอมโพเนนต์ส่งมาเสมอ แต่คนที่เรียก API
            // ตรง ๆ ไม่ได้ส่ง และการคืนทั้งฐานข้อมูลตอบแทนไม่ใช่ค่าปริยายที่ดี
            $start = self::day($request->get('start', '')->text(), 'first day of this month');
            $end = self::day($request->get('end', '')->text(), 'last day of this month');

            return $this->successResponse(
                ['data' => Model::events($start, $end)],
                'OK'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * รับเฉพาะ YYYY-MM-DD ที่เป็นวันจริง
     *
     * @param string $value
     * @param string $fallback
     *
     * @return string
     */
    private static function day($value, $fallback)
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            return $m[0];
        }

        return date('Y-m-d', strtotime($fallback));
    }
}
