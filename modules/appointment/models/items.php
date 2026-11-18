<?php
/**
 * @filesource modules/appointment/models/items.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Appointment\Items;

use Kotchasan\ApiException;
use Kotchasan\Language;
use Kotchasan\Text;

/**
 * นัดหมายสำหรับหน้าจอ
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * @param int $memberId
     *
     * @return array
     */
    public static function listAll($memberId)
    {
        $rows = static::createQuery()
            ->select()
            ->from('appointments')
            ->where([
                ['member_id', (int) $memberId],
                ['start_at', '>', date('Y-m-d H:i:s', strtotime('-90 days'))]
            ])
            ->orderBy('start_at')
            ->fetchAll(true);

        $today = strtotime('today');
        $out = [];
        foreach ($rows as $row) {
            $ts = strtotime($row['start_at']);
            $days = (int) floor(($ts - $today) / 86400);
            $row['id'] = (int) $row['id'];
            $row['all_day'] = (int) $row['all_day'] === 1;
            $row['days'] = $days;
            $row['when'] = \Gcms\Timeline\Attention::whenText($days, $row['all_day'], $row['start_at']);
            $row['date_text'] = \Kotchasan\Date::format($row['start_at'], 'l d M Y')
                .($row['all_day'] ? '' : ' '.date(Language::get('TIME_FORMAT'), $ts));
            $row['past'] = $ts < time();
            // ส่งเป็นชื่อคลาสมาเลย เพราะ template แปะค่าลง class ตรง ๆ ได้
            // แต่เขียนเงื่อนไขในนั้นไม่ได้
            $row['past_class'] = $row['past'] ? 'is-past' : '';
            // เก็บแบบที่ Text::textarea() เข้ารหัสไว้ แต่จอวาดด้วย data-text
            // (textContent) ส่งตัวที่เข้ารหัสไปตรง ๆ จะเห็น &lt; แทน <
            $row['detail'] = Text::untextarea((string) $row['detail']);
            $row['where_text'] = $row['location'] === '' ? '' : '📍 '.$row['location'];
            // array ศูนย์หรือหนึ่งตัว แทน data-if ที่ถอดอิลิเมนต์ทิ้งถาวร
            $row['done_button'] = $row['status'] === 'active' ? [$row['id']] : [];
            $row['done_badge'] = $row['status'] === 'active' ? [] : [[
                'text' => Language::get($row['status'] === 'cancelled' ? 'Cancelled' : 'Done')
            ]];
            $out[] = $row;
        }

        return $out;
    }

    /**
     * ข้อมูลสำหรับเปิดฟอร์มใน Modal
     *
     * ฟอร์มกรอกวันกับเวลาแยกช่อง แต่ตารางเก็บเป็น datetime ช่องเดียว จึงต้องแยก
     * ออกตรงนี้ · ตอนบันทึกประกอบกลับด้วย record()
     *
     * @param int $id       0 = เพิ่มใหม่
     * @param int $memberId
     *
     * @throws ApiException 404 ไม่พบ · 403 ไม่ใช่นัดของผู้ใช้คนนี้
     *
     * @return array
     */
    public static function modalPayload($id, $memberId)
    {
        $id = (int) $id;
        if ($id > 0) {
            self::assertOwner($id, $memberId);
            $row = \Kotchasan\DB::create()->first('appointments', [['id', $id]]);
            $start = strtotime($row->start_at);
            $allDay = (int) $row->all_day === 1;
            $data = [
                'id' => $id,
                'title' => $row->title,
                'date' => date('Y-m-d', $start),
                'start_time' => $allDay ? '' : date('H:i', $start),
                'end_time' => $allDay || empty($row->end_at) ? '' : date('H:i', strtotime($row->end_at)),
                'all_day' => $allDay ? 1 : 0,
                'location' => $row->location,
                'detail' => Text::untextarea((string) $row->detail),
                'priority' => $row->priority,
                'status' => $row->status
            ];
            $title = '{LNG_Edit appointment}';
        } else {
            $data = [
                'id' => 0,
                'title' => '',
                'date' => date('Y-m-d'),
                'start_time' => '',
                'end_time' => '',
                'all_day' => 0,
                'location' => '',
                'detail' => '',
                'priority' => 'normal',
                'status' => 'active'
            ];
            $title = '{LNG_Add appointment}';
        }

        return [
            'data' => (object) $data,
            // ตัวเลือกของ <select> ต้องอยู่ระดับบนสุด ไม่ใช่ใน data — เหตุผลเดียวกับ
            // Timeline\Reminders\Model::modalPayload()
            'options' => [
                'priority' => [
                    ['value' => 'low', 'text' => Language::get('Low')],
                    ['value' => 'normal', 'text' => Language::get('Normal')],
                    ['value' => 'high', 'text' => Language::get('High')],
                    ['value' => 'critical', 'text' => Language::get('Critical')]
                ],
                'status' => [
                    ['value' => 'active', 'text' => Language::get('Upcoming')],
                    ['value' => 'completed', 'text' => Language::get('Done')],
                    ['value' => 'cancelled', 'text' => Language::get('Cancelled')]
                ]
            ],
            'actions' => [[
                'type' => 'modal',
                'action' => 'show',
                'template' => '/appointment/form.html',
                'title' => $title,
                'titleClass' => 'icon-clock'
            ]]
        ];
    }

    /**
     * ประกอบค่าจากฟอร์มเป็นแถวที่ LocalProvider::save() รับ
     *
     * ตรวจทีละช่องที่นี่ก่อน เพื่อให้ข้อความบอกได้ว่าช่องไหนผิด — ถ้าส่งต่อไปให้
     * LocalProvider ตรวจ จะได้แค่ "วันเวลาไม่ถูกต้อง" ทั้งที่แค่ลืมกรอกเวลา
     *
     * @param array $input date (Y-m-d) · start_time / end_time (H:i) · all_day และช่องอื่นตามตาราง
     *
     * @throws ApiException 422
     *
     * @return array
     */
    public static function record(array $input)
    {
        $date = (string) ($input['date'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ApiException(Language::get('Please choose the appointment date'), 422);
        }

        $allDay = !empty($input['all_day']);
        $startTime = (string) ($input['start_time'] ?? '');
        $endTime = (string) ($input['end_time'] ?? '');
        if (!$allDay) {
            if (!preg_match('/^\d{2}:\d{2}$/', $startTime)) {
                throw new ApiException(Language::get('Please fill in the start time, or tick All day'), 422);
            }
            if ($endTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
                throw new ApiException(Language::get('The end time is invalid'), 422);
            }
        }

        return [
            'title' => $input['title'] ?? '',
            'detail' => $input['detail'] ?? '',
            'location' => $input['location'] ?? '',
            'start_at' => $date.' '.($allDay ? '00:00' : $startTime).':00',
            // นัดทั้งวันไม่มีเวลาจบ · ช่องเวลาจบที่ถูกปิดไว้ในฟอร์มก็ไม่ถูกส่งมาอยู่แล้ว
            'end_at' => $allDay || $endTime === '' ? '' : $date.' '.$endTime.':00',
            'all_day' => $allDay ? 1 : 0,
            'status' => $input['status'] ?? 'active',
            'priority' => $input['priority'] ?? 'normal'
        ];
    }

    /**
     * ปิดนัดหมายโดยไม่แตะฟิลด์อื่น
     *
     * @param int $id
     */
    public static function complete($id)
    {
        \Kotchasan\DB::create()->update('appointments', ['id', (int) $id], [
            'status' => 'completed',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // item บนไทม์ไลน์ต้องเปลี่ยนตามทันที ไม่ใช่รอรอบ cron
        \Gcms\Timeline\LocalProvider::publish();
    }

    /**
     * นัดหมายนี้เป็นของผู้ใช้คนนี้จริงไหม
     *
     * Hub ใช้คนเดียวก็จริง แต่การตรวจเจ้าของราคาถูกมากและเป็นสิ่งที่ต้องมีอยู่แล้ว
     * ตอนวันหนึ่งมีผู้ใช้คนที่สอง — เพิ่มทีหลังคือตอนที่ลืมง่ายที่สุด
     *
     * @param int $id
     * @param int $memberId
     *
     * @throws ApiException
     */
    public static function assertOwner($id, $memberId)
    {
        $row = \Kotchasan\DB::create()->first('appointments', [['id', (int) $id]], ['member_id']);
        if ($row === null) {
            throw new ApiException(Language::get('This appointment was not found'), 404);
        }
        if ((int) $row->member_id !== (int) $memberId) {
            throw new ApiException(Language::get('This is not your appointment'), 403);
        }
    }
}
