<?php
/**
 * @filesource Gcms/Timeline/ActionRunner.php
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
 * ส่งคำสั่งกลับไปให้ระบบต้นทางทำ
 *
 * ทางเดียวที่ Hub เขียนอะไรกลับไปยังระบบอื่น — Hub ไม่รู้ว่า action ที่ส่งไป
 * แปลว่าอะไร และต้องไม่มีวันรู้ หน้าที่มีแค่ส่งตามที่ต้นทางประกาศไว้ใน item
 *
 * @see TIMELINE-PROTOCOL.md §10
 *
 * @since 1.0
 */
class ActionRunner extends \Kotchasan\Model
{
    /**
     * ยิง action หนึ่งคำสั่ง
     *
     * @param int    $itemId
     * @param string $action
     * @param array  $params
     * @param int    $memberId ผู้ที่กดปุ่ม
     * @param string $origin   web | line | telegram
     *
     * @throws ApiException
     *
     * @return array item ที่อัปเดตแล้ว
     */
    public static function run($itemId, $action, array $params, $memberId = 0, $origin = 'web')
    {
        $item = static::createQuery()
            ->select('I.id', 'I.uid', 'I.source_id', 'I.actions_json', 'I.last_seen_run',
                'Src.base_url', 'Src.token', 'Src.slug', 'Src.enabled')
            ->from('items I')
            ->join('sources Src', [['Src.id', 'I.source_id']], 'INNER')
            ->where(['I.id', (int) $itemId])
            ->first();

        if ($item === null || $item === false) {
            throw new ApiException(Language::get('This item was not found'), 404);
        }
        if (empty($item->enabled)) {
            throw new ApiException(Language::get('The source system is disconnected'), 409);
        }

        // ยิงได้เฉพาะ action ที่ต้นทางประกาศไว้กับ item ใบนี้เท่านั้น
        // ไม่ใช่อะไรก็ได้ที่ผู้ใช้ส่งมาจากหน้าเว็บ
        $declared = json_decode((string) $item->actions_json, true);
        $allowed = is_array($declared) ? array_column($declared, 'id') : [];
        if (!in_array($action, $allowed, true)) {
            throw new ApiException(Language::sprintf('This item has no %s action', $action), 422);
        }

        $key = self::uuid();
        $body = json_encode([
            'uid' => $item->uid,
            'action' => $action,
            'params' => $params,
            'idempotency_key' => $key,
            'requested_at' => date(DATE_ATOM)
        ], JSON_UNESCAPED_UNICODE);

        $curl = Client::curl($item);
        $curl->setHeaders([
            'Authorization' => 'Bearer '.$item->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'TimelineHub/1.0'
        ]);
        $result = Client::one($curl, Client::url($item, 'timeline/actions'), $body);

        \Kotchasan\DB::create()->insert('action_log', [
            'item_id' => (int) $item->id,
            'source_id' => (int) $item->source_id,
            'action' => $action,
            'params_json' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'idempotency_key' => $key,
            'http_code' => (int) $result['status'],
            'response_json' => json_encode($result['data'], JSON_UNESCAPED_UNICODE),
            'origin' => $origin,
            'member_id' => (int) $memberId,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        if (!$result['ok']) {
            throw new ApiException($result['error'], $result['status'] >= 400 && $result['status'] < 500 ? $result['status'] : 502);
        }

        $data = (array) $result['data'];

        // ต้นทางคืน item ที่สดใหม่มาให้ — เขียนทับทันทีเพื่อให้การ์ดอัปเดต
        // ต่อหน้าผู้ใช้ ไม่ต้องรอ sync รอบถัดไปซึ่งอาจอีกหกชั่วโมง
        if (!empty($data['item']['uid'])) {
            // ส่ง last_seen_run เดิมกลับเข้าไปด้วย — ถ้าเผลอเขียนเป็น 0
            // reconciler รอบถัดไปจะเข้าใจว่า item นี้หายไปจาก snapshot
            $existing = [$item->uid => ['id' => (int) $item->id, 'hash' => '']];
            ItemStore::store($item->source_id, $data['item'], (int) $item->last_seen_run, $existing);
        }

        return [
            'applied' => !empty($data['applied']),
            'message' => (string) ($data['message'] ?? Language::get('Action completed')),
            'item_id' => (int) $item->id
        ];
    }

    /**
     * @return string
     */
    private static function uuid()
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
