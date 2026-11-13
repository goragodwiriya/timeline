<?php
/**
 * @filesource modules/timeline/controllers/history.php
 *
 * API หน้าประวัติการแจ้งเตือน
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\History;

use Gcms\Timeline\Access;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * `api/timeline/history` · `api/timeline/history/action`
 *
 * สืบทอด Gcms\Table เพื่อให้ได้ค้นหา เรียง แบ่งหน้า และ action หมู่ ครบชุด
 * โดยไม่ต้องเขียนเอง — ประวัติโตขึ้นทุกวัน หน้าที่โหลดทั้งตารางจะใช้ไม่ได้ในไม่ช้า
 *
 * @since 1.0
 */
class Controller extends \Gcms\Table
{
    /**
     * คอลัมน์ที่ยอมให้เรียง — กันการยัด SQL ผ่าน ?sort=
     *
     * @var array
     */
    protected $allowedSortColumns = ['id', 'fire_at', 'sent_at', 'status', 'kind', 'title'];

    /**
     * ล็อกอินได้ไม่พอ ต้องมีสิทธิ์ can_manage_timeline
     *
     * @param Request $request
     * @param mixed $login
     *
     * @return mixed
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!Access::allow($login)) {
            return $this->errorResponse('Unauthorized', 401);
        }

        return true;
    }

    /**
     * ตัวกรองเพิ่มเติมที่หน้าจอส่งมา
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [
            'status' => $request->get('status')->filter('a-z'),
            'kind' => $request->get('kind')->filter('a-z0-9_.*'),
            'source_id' => $request->get('source_id')->number()
        ];
    }

    /**
     * @param array $params
     * @param object|null $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected function toDataTable($params, $login = null)
    {
        return Model::toDataTable($params);
    }

    /**
     * @param array $datas
     * @param object|null $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        return Model::format($datas);
    }

    /**
     * @param array $params
     * @param object|null $login
     *
     * @return array
     */
    protected function getFilters($params, $login = null)
    {
        return [
            'status' => Model::statusFilter(),
            'kind' => Model::kindFilter(),
            'source_id' => Model::sourceFilter()
        ];
    }

    /**
     * สรุปจำนวนแต่ละสถานะ ส่งไปกับตารางเพื่อให้หัวหน้าจอไม่ต้องยิงอีกรอบ
     *
     * @param array $params
     * @param object|null $login
     *
     * @return array
     */
    protected function getOptions($params, $login = null)
    {
        return ['summary' => Model::summary()];
    }

    /**
     * ลบประวัติที่เลือก
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleDeleteAction(Request $request, $login)
    {
        if (!Access::allow($login)) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $ids = $request->request('ids', [])->toInt();
        if (empty($ids)) {
            return $this->errorResponse(Language::get('Please select at least one item'), 400);
        }

        $result = Model::remove($ids);

        return $this->redirectResponse('reload', self::resultMessage($result), 200, 0, 'table');
    }

    /**
     * ลบประวัติทั้งหมดเท่าที่ตัวกรองปัจจุบันครอบ
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleClearAction(Request $request, $login)
    {
        if (!Access::allow($login)) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $result = Model::clear([
            'search' => $request->post('search')->topic(),
            'status' => $request->post('status')->filter('a-z'),
            'kind' => $request->post('kind')->filter('a-z0-9_.*'),
            'source_id' => $request->post('source_id')->number()
        ]);

        return $this->redirectResponse('reload', self::resultMessage($result), 200, 0, 'table');
    }

    /**
     * ข้อความสรุปผลการลบ — ต้องบอกจำนวนที่ "ลบไม่ได้" ด้วย
     *
     * ถ้าบอกแค่จำนวนที่ลบสำเร็จ คนกดจะนึกว่าลบครบแล้ว ทั้งที่คิวที่ยังไม่ได้ส่ง
     * ของเรื่องที่ยังอยู่ถูกกันไว้ (ดู Model::deletable) แล้วจะกลับมาถามว่าทำไมยังเหลือ
     *
     * @param array $result
     *
     * @return string
     */
    private static function resultMessage(array $result)
    {
        $message = Language::sprintf('Deleted %d history entries', $result['deleted']);
        if (!empty($result['kept'])) {
            $message .= ' · '.Language::sprintf('Kept %d unsent reminders whose items still exist (turn the rule off on the connections page and the queue is cleared for you)', $result['kept']);
        }

        return $message;
    }
}
