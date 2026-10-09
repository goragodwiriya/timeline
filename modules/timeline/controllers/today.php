<?php
/**
 * @filesource modules/timeline/controllers/today.php
 *
 * API หน้าจอหลัก — ตอนนี้ต้องสนใจอะไรบ้าง
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Today;

use Gcms\Api as ApiController;
use Gcms\Timeline\Access;
use Gcms\Timeline\Attention;
use Kotchasan\Http\Request;

/**
 * `api/timeline/today`
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

            $filter = [
                'source_id' => $request->get('source_id', 0)->toInt(),
                'kind' => $request->get('kind', '')->filter('a-z0-9_.'),
                // กลุ่มที่เลือกดู (overdue|today|soon|upcoming) · ว่าง = ทั้งหมด
                'view' => $request->get('view', '')->filter('a-z')
            ];

            // หน้าปฏิทินขอแค่ตัวเลขสรุปกับการ์ดนำทาง ไม่ได้ใช้รายการสักใบ
            // — ส่งการ์ดทุกใบไปให้จอที่ไม่แสดงมัน คือการโหลดทั้งฐานข้อมูลทิ้ง
            if ($request->get('summary')->toInt() === 1) {
                return $this->successResponse(Model::summary($filter), 'OK');
            }

            return $this->successResponse(Model::payload($filter), 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
