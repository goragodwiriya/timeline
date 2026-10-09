<?php
/**
 * @filesource modules/appointment/controllers/items.php
 *
 * API ของระบบนัดหมาย
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

// ชื่อ endpoint เป็น items ไม่ใช่ list เพราะ router สร้างชื่อคลาสจากส่วนของ URL
// ตรง ๆ (`api/appointment/list` -> `Appointment\List\Controller`) และ `List`
// เป็นคำสงวนของภาษา ใช้เป็นชื่อ namespace ไม่ได้ — จะได้ 404 ที่หาสาเหตุยาก
namespace Appointment\Items;

use Gcms\Api as ApiController;
use Gcms\Timeline\Access;
use Gcms\Timeline\LocalProvider;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * `api/appointment/items` · `.../form` · `.../save` · `.../remove` · `.../complete`
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * รายการนัดหมาย
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

            return $this->successResponse(['data' => Model::listAll((int) $login->id)], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * ฟอร์มเพิ่ม/แก้นัดหมาย — ตอบเป็น action ให้ ResponseHandler เปิด Modal
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

            return $this->successResponse(
                Model::modalPayload($request->get('id', 0)->toInt(), (int) $login->id),
                'OK'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * บันทึกนัดหมายจากฟอร์ม
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

            $id = $request->post('id', 0)->toInt();
            // แก้ได้เฉพาะนัดของตัวเอง — id มาจากช่องซ่อนในฟอร์ม ซึ่งแก้ได้จากเบราว์เซอร์
            if ($id > 0) {
                Model::assertOwner($id, (int) $login->id);
            }

            $id = LocalProvider::save(Model::record([
                'title' => $request->post('title', '')->topic(),
                'detail' => $request->post('detail', '')->textarea(),
                'location' => $request->post('location', '')->topic(),
                'date' => $request->post('date', '')->filter('0-9\-'),
                'start_time' => $request->post('start_time', '')->filter('0-9:'),
                'end_time' => $request->post('end_time', '')->filter('0-9:'),
                // toBoolean ไม่ใช่ toInt — FormManager ส่งสวิตช์มาเป็น true/false
                // ซึ่ง toInt อ่าน "true" ได้ 0 นัดทั้งวันจึงถูกถามหาเวลาเริ่มทุกครั้ง
                'all_day' => $request->post('all_day')->toBoolean(),
                'status' => $request->post('status', 'active')->filter('a-z'),
                'priority' => $request->post('priority', 'normal')->filter('a-z')
            ]), (int) $login->id, $id);

            // ฟอร์มอยู่ใน Modal จึงเป็นหน้าที่ของเซิร์ฟเวอร์ที่จะสั่งปิดและสั่งให้
            // รายการโหลดใหม่ — เหมือน Timeline\Connections\Controller::save()
            return $this->successResponse([
                'id' => $id,
                'actions' => [
                    ['type' => 'modal', 'action' => 'close'],
                    ['type' => 'notification', 'level' => 'success', 'message' => Language::get('Saved')],
                    ['type' => 'callback', 'function' => 'EventManager.emit', 'args' => ['appointment:reload']]
                ]
            ], Language::get('Saved'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * ลบนัดหมาย
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

            Model::assertOwner($request->post('id', 0)->toInt(), (int) $login->id);
            LocalProvider::remove($request->post('id', 0)->toInt());

            return $this->successResponse(['data' => Model::listAll((int) $login->id)], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * ทำเครื่องหมายว่านัดนี้ผ่านไปแล้ว
     *
     * แยกเป็น endpoint ของตัวเอง ไม่ใช้ save() — ไม่งั้นหน้าจอต้องอ่านนัดทั้งใบ
     * กลับมาก่อนแล้วส่งทุกฟิลด์กลับไป ซึ่งนอกจากเปลืองรอบแล้ว ยังเสี่ยงที่ค่าที่
     * ผู้ใช้ไม่ได้ตั้งใจแก้จะถูกเขียนทับด้วยค่าที่หน้าจอถืออยู่ตอนนั้น
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function complete(Request $request)
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

            $id = $request->post('id', 0)->toInt();
            Model::assertOwner($id, (int) $login->id);
            Model::complete($id);

            return $this->successResponse(['data' => Model::listAll((int) $login->id)], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
