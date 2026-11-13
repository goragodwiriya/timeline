<?php
/**
 * @filesource Gcms/Timeline/LocalProvider.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

use Kotchasan\ApiException;
use Kotchasan\Language;

/**
 * นัดหมายที่ Hub เป็นเจ้าของเอง
 *
 * เป็น provider เดียวที่ Hub เก็บข้อมูลจริงไว้กับตัว ทุกตัวที่เหลือ Hub ถือแค่
 * สำเนา — เพราะยังไม่มีระบบนัดหมายที่ไหนให้ดึงมา
 *
 * เพื่อไม่ให้ข้อยกเว้นนี้รั่วเข้า core: นัดหมายถูกแปลงเป็น item รูปเดียวกับที่มา
 * จากระบบภายนอกทุกประการ ต่างแค่ `origin = 'local'` ซึ่งได้รับยกเว้นจาก
 * reconciliation เหมือน item ที่มาจาก push · วันที่นัดหมายโตจนต้องแยกออกไปเป็น
 * แอปของตัวเอง เปลี่ยนแค่ `base_url` ของ source นั้น ไม่ต้องแตะ core
 *
 * @see HUB-DESIGN.md §9
 *
 * @since 1.0
 */
class LocalProvider extends \Kotchasan\Model
{
    /**
     * slug ของ source ที่ผูกกับ provider นี้
     */
    const SLUG = 'local';

    /**
     * สถานะที่รับได้
     */
    const STATUSES = ['active', 'completed', 'cancelled'];

    /**
     * สร้างหรือแก้นัดหมาย แล้วอัปเดต item ให้ตรงทันที
     *
     * @param array $data
     * @param int   $memberId
     * @param int   $id       0 = สร้างใหม่
     *
     * @throws ApiException
     *
     * @return int id ของนัดหมาย
     */
    public static function save(array $data, $memberId, $id = 0)
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ApiException(Language::get('Please fill in the appointment subject'), 422);
        }

        $startAt = self::datetime($data['start_at'] ?? '');
        if ($startAt === null) {
            throw new ApiException(Language::get('The appointment date and time are invalid'), 422);
        }

        $endAt = isset($data['end_at']) && $data['end_at'] !== '' ? self::datetime($data['end_at']) : null;
        if ($endAt !== null && strcmp($endAt, $startAt) < 0) {
            throw new ApiException(Language::get('The end time is before the start time'), 422);
        }

        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, self::STATUSES, true)) {
            throw new ApiException(Language::get('Invalid status'), 422);
        }

        $row = [
            'member_id' => (int) $memberId,
            'title' => mb_substr($title, 0, 255),
            'detail' => isset($data['detail']) && $data['detail'] !== '' ? (string) $data['detail'] : null,
            'location' => mb_substr((string) ($data['location'] ?? ''), 0, 255),
            'start_at' => $startAt,
            'end_at' => $endAt,
            'all_day' => !empty($data['all_day']) ? 1 : 0,
            'status' => $status,
            'priority' => in_array($data['priority'] ?? '', ['low', 'normal', 'high', 'critical'], true)
                ? $data['priority'] : 'normal',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $db = \Kotchasan\DB::create();
        if ($id > 0) {
            $db->update('appointments', ['id', (int) $id], $row);
        } else {
            $row['created_at'] = date('Y-m-d H:i:s');
            $id = (int) $db->insert('appointments', $row);
        }

        self::publish();

        return (int) $id;
    }

    /**
     * ลบนัดหมาย
     *
     * ชื่อ remove ไม่ใช่ delete เพราะ Kotchasan\Model ประกาศ delete() ไว้เป็น
     * เมธอดของอ็อบเจกต์ การประกาศทับเป็น static จึงเป็น fatal ตั้งแต่โหลดคลาส
     *
     * @param int $id
     *
     * @return bool
     */
    public static function remove($id)
    {
        \Kotchasan\DB::create()->delete('appointments', [['id', (int) $id]], 0);
        self::publish();

        return true;
    }

    /**
     * เขียนนัดหมายทั้งหมดลงตาราง items ให้ตรงกับความจริงปัจจุบัน
     *
     * เรียกทุกครั้งที่นัดหมายเปลี่ยน และทุกรอบ cron — ราคาถูกเพราะนัดหมายมีไม่มาก
     * และการเขียนใหม่ทั้งชุดทำให้ไม่ต้องไล่ว่าอะไรเปลี่ยนไปบ้าง ซึ่งเป็นที่มาของ
     * ความไม่ตรงกันในระบบแบบนี้เสมอ
     *
     * @return array ['seen' => int, 'changed' => int, 'removed' => int]
     */
    public static function publish()
    {
        $source = self::source();
        if ($source === null) {
            return ['seen' => 0, 'changed' => 0, 'removed' => 0];
        }

        $sourceId = (int) $source->id;
        $existing = ItemStore::preload($sourceId);
        $seen = 0;
        $changed = 0;
        $keep = [];

        foreach (self::appointments() as $appointment) {
            $item = self::toItem($appointment);
            $keep[$item['uid']] = true;
            $outcome = ItemStore::store($sourceId, $item, 1, $existing, 'local');
            ++$seen;
            if ($outcome !== 'same') {
                ++$changed;
            }
        }

        // นัดที่ถูกลบไปแล้วต้องหายจากจอด้วย — reconciliation ปกติไม่แตะ origin
        // local จึงต้องเก็บกวาดเองตรงนี้
        $removed = 0;
        $db = \Kotchasan\DB::create();
        foreach ($existing as $uid => $info) {
            if (!isset($keep[$uid])) {
                $db->delete('item_state', [['item_id', $info['id']]], 0);
                $db->delete('reminders', [['item_id', $info['id']]], 0);
                $db->delete('items', [['id', $info['id']]], 0);
                ++$removed;
            }
        }

        $db->update('sources', ['id', $sourceId], [
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_ok_at' => date('Y-m-d H:i:s'),
            'last_status' => 'ok',
            'last_error' => ''
        ]);

        return ['seen' => $seen, 'changed' => $changed, 'removed' => $removed];
    }

    /**
     * แถวของ source `local`
     *
     * @return object|null
     */
    public static function source()
    {
        $row = static::createQuery()->select()->from('sources')->where(['slug', self::SLUG])->first();

        return $row === false ? null : $row;
    }

    /**
     * นัดหมายที่ยังอยู่ในกรอบเวลาที่สนใจ
     *
     * @return array
     */
    private static function appointments()
    {
        return static::createQuery()
            ->select()
            ->from('appointments')
            ->where([
                ['status', '!=', 'cancelled'],
                ['start_at', '>', date('Y-m-d H:i:s', strtotime('-90 days'))]
            ])
            ->orderBy('start_at')
            ->fetchAll(false);
    }

    /**
     * แปลงนัดหมายเป็น item ตามสัญญาเดียวกับ provider ภายนอก
     *
     * @param object $appointment
     *
     * @return array
     */
    private static function toItem($appointment)
    {
        $id = (int) $appointment->id;
        $allDay = (int) $appointment->all_day === 1;
        $parts = [];
        if ($appointment->location !== '') {
            $parts[] = $appointment->location;
        }
        if (!empty($appointment->detail)) {
            $parts[] = mb_substr((string) $appointment->detail, 0, 120);
        }

        return [
            'uid' => 'appt:'.$id,
            'kind' => 'appointment',
            'title' => $appointment->title,
            'subtitle' => implode(' · ', $parts),
            'start_at' => $allDay ? date('Y-m-d', strtotime($appointment->start_at)) : $appointment->start_at,
            'end_at' => $appointment->end_at,
            'all_day' => $allDay,
            'status' => $appointment->status === 'completed' ? 'completed' : 'active',
            'priority' => $appointment->priority,
            'entity' => ['type' => 'appointment', 'id' => (string) $id, 'label' => $appointment->title],
            'links' => [[
                'label' => Language::get('Open appointment'),
                'url' => rtrim(WEB_URL, '/').'/#/appointment?id='.$id,
                'primary' => true
            ]],
            'facts' => self::facts($appointment, $allDay),
            'meta' => ['location' => $appointment->location],
            'hint' => ['icon' => 'clock', 'color' => '#3b82f6']
        ];
    }

    /**
     * ข้อมูลสรุปของนัดหนึ่งใบ
     *
     * บรรทัดรองถูกตัดที่ 255 ตัวอักษรและมีสถานที่กับรายละเอียดเบียดกันอยู่แล้ว
     * รายละเอียดยาว ๆ จึงมาอยู่ตรงนี้แทนที่จะถูกตัดหาย
     *
     * @param object $appointment
     * @param bool   $allDay
     *
     * @return array
     */
    private static function facts($appointment, $allDay)
    {
        $facts = [];

        if (trim((string) $appointment->location) !== '') {
            $facts[] = ['label' => Language::get('Venue'), 'value' => (string) $appointment->location];
        }

        // ใส่ "เวลา" เฉพาะตอนที่เป็นช่วง — เวลาเริ่มอย่างเดียวอยู่ในบรรทัดวันที่
        // ของทุกจออยู่แล้ว (Attention::whenText) ใส่ซ้ำจะได้ 09:00 น. สองรอบติดกัน
        if (!$allDay) {
            $start = strtotime((string) $appointment->start_at);
            $end = empty($appointment->end_at) ? false : strtotime((string) $appointment->end_at);
            if ($end !== false && $end > $start) {
                $facts[] = [
                    'label' => Language::get('Time'),
                    'value' => date('H:i', $start).' – '.date(Language::get('TIME_FORMAT'), $end)
                ];
            }
        }

        if (trim((string) $appointment->detail) !== '') {
            $facts[] = ['label' => Language::get('Detail'), 'value' => (string) $appointment->detail];
        }

        return $facts;
    }

    /**
     * @param string $value
     *
     * @return string|null
     */
    private static function datetime($value)
    {
        $ts = strtotime((string) $value);
        if ($ts === false || (int) date('Y', $ts) < 2000) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
