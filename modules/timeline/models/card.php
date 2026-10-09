<?php
/**
 * @filesource modules/timeline/models/card.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Card;

use Kotchasan\ApiException;
use Kotchasan\Language;

/**
 * สถานะของ item ที่ผู้ใช้เป็นคนกำหนด
 *
 * เขียนลง item_state เท่านั้น ไม่แตะตาราง items — นั่นคือเหตุผลที่ปัญหา
 * "กดจัดการแล้ว sync รอบหน้าเอาของเก่ามาทับ" เป็นไปไม่ได้ในระบบนี้
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * สถานะที่รับได้
     */
    const STATES = ['none', 'done', 'dismissed', 'snoozed'];

    /**
     * @param int    $itemId
     * @param string $state
     * @param int    $days   จำนวนวันที่เลื่อน ใช้เมื่อ state = snoozed
     *
     * @throws ApiException
     *
     * @return array
     */
    public static function setState($itemId, $state, $days = 0)
    {
        if (!in_array($state, self::STATES, true)) {
            throw new ApiException(Language::get('Invalid status'), 422);
        }

        $db = \Kotchasan\DB::create();
        $exists = $db->first('items', [['id', (int) $itemId]], ['id']);
        if ($exists === null) {
            throw new ApiException(Language::get('This item was not found'), 404);
        }

        $snoozeUntil = null;
        if ($state === 'snoozed') {
            $days = max(1, min((int) $days, 365));
            $snoozeUntil = date('Y-m-d H:i:s', strtotime('+'.$days.' days'));
        }

        $row = [
            'state' => $state,
            'snooze_until' => $snoozeUntil,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($db->exists('item_state', [['item_id', (int) $itemId]])) {
            $db->update('item_state', ['item_id', (int) $itemId], $row);
        } else {
            $row['item_id'] = (int) $itemId;
            $db->insert('item_state', $row);
        }

        return ['id' => (int) $itemId, 'state' => $state, 'snooze_until' => $snoozeUntil];
    }
}
