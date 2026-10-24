<?php
/**
 * @filesource modules/timeline/controllers/connections.php
 *
 * API หน้าสถานะการเชื่อมต่อ
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Connections;

use Gcms\Api as ApiController;
use Gcms\Timeline\Access;
use Gcms\Timeline\SyncEngine;
use Kotchasan\ApiException;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * `api/timeline/connections` · `api/timeline/connections/sync`
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * รายชื่อระบบต้นทางพร้อมสถานะล่าสุด
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
     * เปิดฟอร์มเพิ่ม/แก้ระบบต้นทาง (Modal)
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
                return $this->errorResponse(Language::get('This system was not found'), 404);
            }

            return $this->successResponse($payload, 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * เพิ่มหรือแก้ระบบต้นทาง
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
                'slug' => $request->post('slug', '')->filter('a-z0-9_'),
                'name' => $request->post('name', '')->topic(),
                'base_url' => $request->post('base_url', '')->url(),
                'token' => $request->post('token', '')->text(),
                'interval_min' => $request->post('interval_min', 360)->toInt(),
                'horizon_past' => $request->post('horizon_past', 'P90D')->filter('A-Za-z0-9'),
                'horizon_future' => $request->post('horizon_future', 'P12M')->filter('A-Za-z0-9'),
                'enabled' => $request->post('enabled')->toBoolean()
            ], $request->post('id', 0)->toInt());

            // ฟอร์มอยู่ใน Modal จึงเป็นหน้าที่ของเซิร์ฟเวอร์ที่จะสั่งปิดและสั่งให้
            // ตารางโหลดใหม่ — หน้าจอไม่ต้องรู้ว่าบันทึกแล้วต้องทำอะไรต่อ
            return $this->successResponse([
                'id' => $id,
                'actions' => [
                    ['type' => 'modal', 'action' => 'close'],
                    ['type' => 'notification', 'level' => 'success', 'message' => Language::get('Saved')],
                    ['type' => 'callback', 'function' => 'EventManager.emit', 'args' => ['timeline:reload']]
                ]
            ], Language::get('Saved'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * ลบระบบต้นทางพร้อมข้อมูลที่ดึงมาจากมัน
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

            Model::remove($request->post('id', 0)->toInt());

            return $this->successResponse(['data' => Model::listAll()], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * ทดสอบการเชื่อมต่อโดยไม่บันทึกอะไร
     *
     * ให้ผู้ใช้รู้ว่า URL กับ token ถูกไหม **ก่อน** กดบันทึก — ไม่งั้นต้องบันทึก
     * แล้วรอรอบ sync แล้วค่อยมาดูว่าพลาดตรงไหน ซึ่งเป็นวงจรที่ยาวเกินไป
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function test(Request $request)
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

            return $this->successResponse(Model::test(
                $request->post('base_url', '')->url(),
                $request->post('token', '')->text(),
                $request->post('id', 0)->toInt()
            ), 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * สั่ง sync ทันที — ทั้งหมด หรือเฉพาะระบบเดียว
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function sync(Request $request)
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
            if ($id > 0) {
                $source = Model::get($id);
                if ($source === null) {
                    throw new ApiException(Language::get('This system was not found'), 404);
                }
                // กด Sync Now ทั้งที่ระบบถูกพักอยู่ = ตั้งใจลองใหม่
                // ต้องยิงจริง ไม่ใช่ถูกเงื่อนไขพักปัดตกเงียบ ๆ
                $results = [$source->slug => SyncEngine::runOne($source)];
            } else {
                $results = SyncEngine::runAll(SyncEngine::sources(true));
            }

            return $this->successResponse([
                'results' => $results,
                'sources' => Model::listAll()
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
