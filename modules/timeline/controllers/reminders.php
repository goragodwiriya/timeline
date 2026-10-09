<?php
/**
 * @filesource modules/timeline/controllers/reminders.php
 *
 * API หน้าตั้งค่าการแจ้งเตือน
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Reminders;

use Gcms\Api as ApiController;
use Gcms\Timeline\Access;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * `api/timeline/reminders` · `api/timeline/reminders/toggle`
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * กฎการเตือนทั้งหมดพร้อมสถานะ
     *
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

            return $this->successResponse(['data' => Model::listAll()], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * เปิดฟอร์มเพิ่ม/แก้กฎ (Modal)
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function form(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');
            $this->initLanguage($request);
            $login = $this->authenticateRequest($request);
            // ล็อกอินได้ไม่พอ — ต้องมีสิทธิ์ can_manage_timeline ด้วย
            if (!Access::allow($login)) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $payload = Model::modalPayload($request->get('id', 0)->toInt());
            if ($payload === null) {
                return $this->errorResponse(Language::get('This reminder rule was not found'), 404);
            }

            return $this->successResponse($payload, 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * บันทึกกฎ
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function save(Request $request)
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

            $id = Model::save([
                'source_id' => $request->post('source_id', 0)->toInt(),
                'kind' => $request->post('kind', '')->filter('a-z0-9_.*'),
                'offsets' => $request->post('offsets', '')->text(),
                'max_repeat' => $request->post('max_repeat', 0)->toInt(),
                'enabled' => $request->post('enabled')->toBoolean(),
                'visible' => $request->post('visible')->toBoolean()
            ], $request->post('id', 0)->toInt());

            return $this->successResponse([
                'id' => $id,
                'actions' => [
                    ['type' => 'modal', 'action' => 'close'],
                    ['type' => 'notification', 'level' => 'success', 'message' => Language::get('Saved')],
                    ['type' => 'callback', 'function' => 'EventManager.emit', 'args' => ['reminders:reload']]
                ]
            ], Language::get('Saved'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * ลบกฎ
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function remove(Request $request)
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

            return $this->successResponse(
                ['data' => Model::remove($request->post('id', 0)->toInt())],
                Language::get('Deleted')
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * เปิด/ปิดสวิตช์ของกฎหนึ่งข้อ — `enabled` (ส่งแชต) หรือ `visible` (ขึ้นกระดาน)
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function toggle(Request $request)
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

            return $this->successResponse([
                'data' => Model::toggle(
                    $request->post('id', 0)->toInt(),
                    $request->post('enabled')->toBoolean(),
                    // ไม่ส่ง field มา = สวิตช์เดิม เพื่อให้ของที่เรียกอยู่ก่อนไม่พัง
                    $request->post('field', 'enabled')->filter('a-z')
                )
            ], Language::get('Saved'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
