<?php
/**
 * @filesource modules/timeline/controllers/card.php
 *
 * API ของปุ่มบนการ์ด — ทั้งที่ Hub ทำเอง และที่ส่งกลับไปให้ต้นทางทำ
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Card;

use Gcms\Api as ApiController;
use Gcms\Timeline\Access;
use Gcms\Timeline\ActionRunner;
use Kotchasan\Http\Request;

/**
 * `api/timeline/card/state` · `api/timeline/card/run`
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * เปลี่ยนสถานะที่ Hub เป็นเจ้าของ — จัดการแล้ว / ซ่อน / เลื่อน
     *
     * ไม่ยุ่งกับต้นทางเลย และ sync จะไม่มีวันเขียนทับ เพราะอยู่คนละตาราง
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function state(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->initLanguage($request);
            $this->validateCsrfToken($request);
            $login = $this->authenticateRequest($request);
            // ล็อกอินได้ไม่พอ — ต้องมีสิทธิ์ can_manage_timeline ด้วย
            if (!Access::allow($login)) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $result = Model::setState(
                $request->post('id', 0)->toInt(),
                $request->post('state', '')->filter('a-z'),
                $request->post('days', 0)->toInt()
            );

            return $this->successResponse($result, 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * ยิง action กลับไปให้ระบบต้นทางทำ
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function run(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->initLanguage($request);
            $this->validateCsrfToken($request);
            $login = $this->authenticateRequest($request);
            // ล็อกอินได้ไม่พอ — ต้องมีสิทธิ์ can_manage_timeline ด้วย
            if (!Access::allow($login)) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $params = $request->post('params', '')->toString();
            $params = $params === '' ? [] : json_decode($params, true);

            $result = ActionRunner::run(
                $request->post('id', 0)->toInt(),
                $request->post('action', '')->filter('a-z0-9_'),
                is_array($params) ? $params : [],
                (int) $login->id,
                'web'
            );

            return $this->successResponse($result, $result['message']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
