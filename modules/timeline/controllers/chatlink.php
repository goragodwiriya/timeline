<?php
/**
 * @filesource modules/timeline/controllers/chatlink.php
 *
 * API หน้าผูกบัญชีแชต
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Chatlink;

use Gcms\Api as ApiController;
use Gcms\Timeline\Access;
use Gcms\Timeline\ChatLink;
use Kotchasan\Http\Request;

/**
 * `api/timeline/chatlink` · `.../issue` · `.../revoke`
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * สถานะการผูกของผู้ใช้ที่ล็อกอินอยู่
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

            $cfg = \Kotchasan\Config::create();

            $bot = (string) ($cfg->telegram_bot_username ?? '');

            return $this->successResponse([
                'data' => [
                    'rows' => ChatLink::status((int) $login->id),
                    'bot' => $bot,
                    'has_bot' => $bot !== '',
                    'line' => (string) ($cfg->line_official_account ?? '')
                ]
            ], 'OK');
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * ออกรหัสใหม่
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function issue(Request $request)
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

            return $this->successResponse(ChatLink::issue((int) $login->id), 'OK');
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * ถอนการผูกของช่องทางหนึ่ง
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function revoke(Request $request)
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

            ChatLink::revoke($request->post('channel', '')->filter('a-z'), (int) $login->id);

            return $this->successResponse(['data' => ChatLink::status((int) $login->id)], 'OK');
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }
}
