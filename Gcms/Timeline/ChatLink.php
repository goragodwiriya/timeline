<?php
/**
 * @filesource Gcms/Timeline/ChatLink.php
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
 * ผูกห้องแชตเข้ากับบัญชีผู้ใช้ของ Hub
 *
 * รหัสที่ออกให้เป็นสิ่งเดียวที่กั้นระหว่างคนแปลกหน้ากับข้อมูลหนี้สิน ลูกค้า และ
 * นัดหมายทั้งหมด — บอตอยู่บนอินเทอร์เน็ตที่ใครก็ทักได้ และ Telegram ไม่ได้บอก
 * ว่าคนที่ทักมาเป็นใคร
 *
 * ไม่มีตารางผูกบัญชีของตัวเอง — เขียนลง `user.telegram_id` / `user.line_uid`
 * ที่ระบบสมาชิกมีอยู่แล้วและใช้กับการเข้าสู่ระบบด้วย social เหมือนกัน
 *
 * @see HUB-DESIGN.md §8.2
 *
 * @since 1.0
 */
class ChatLink extends \Kotchasan\Model
{
    /**
     * อายุของรหัส
     */
    const TTL_MINUTES = 10;

    /**
     * ความยาวของรหัส
     */
    const LENGTH = 8;

    /**
     * อักษรที่ใช้ — ตัด 0 O 1 I L ออกเพราะอ่านผิดกันบ่อยตอนพิมพ์ตาม
     */
    const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * ช่องทางที่ผูกได้ พร้อมคอลัมน์ที่เก็บ
     */
    const CHANNELS = ['telegram' => 'telegram_id', 'line' => 'line_uid'];

    /**
     * ออกรหัสใหม่ให้ผู้ใช้คนหนึ่ง
     *
     * รหัสเก่าของคนเดียวกันถูกยกเลิกทิ้ง — ออกรหัสใหม่แล้วรหัสเดิมยังใช้ได้
     * แปลว่ารหัสที่หลุดไปแล้วไม่มีวันหมดฤทธิ์
     *
     * @param int $memberId
     *
     * @return array
     */
    public static function issue($memberId)
    {
        $db = \Kotchasan\DB::create();
        $db->delete('chat_link', [['member_id', (int) $memberId]], 0);
        self::purge();

        $code = self::randomCode();
        $expires = date('Y-m-d H:i:s', strtotime('+'.self::TTL_MINUTES.' minutes'));

        $db->insert('chat_link', [
            'code' => $code,
            'member_id' => (int) $memberId,
            'channel' => '',
            'expires_at' => $expires,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return [
            'code' => $code,
            'expires_at' => $expires,
            'ttl_minutes' => self::TTL_MINUTES,
            'bot' => (string) (self::$cfg->telegram_bot_username ?? ''),
            'line' => (string) (self::$cfg->line_official_account ?? '')
        ];
    }

    /**
     * ใช้รหัสเพื่อผูกห้องแชตนี้เข้ากับบัญชี
     *
     * @param string $code
     * @param string $channel        telegram | line
     * @param string $conversationId
     *
     * @throws ApiException
     *
     * @return array ข้อมูลผู้ใช้ที่ผูกสำเร็จ
     */
    public static function redeem($code, $channel, $conversationId)
    {
        if (!isset(self::CHANNELS[$channel])) {
            throw new ApiException(Language::get('This channel cannot link an account'), 422);
        }
        $conversationId = trim((string) $conversationId);
        if ($conversationId === '') {
            throw new ApiException(Language::get('The chat room ID was not found'), 422);
        }

        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
        if ($code === '') {
            throw new ApiException(Language::get('Please fill in the code'), 422);
        }

        self::purge();
        $db = \Kotchasan\DB::create();
        $row = $db->first('chat_link', [
            ['code', $code],
            ['expires_at', '>', date('Y-m-d H:i:s')]
        ]);

        if ($row === null) {
            throw new ApiException(Language::get('The code is invalid or has expired'), 422);
        }

        $column = self::CHANNELS[$channel];

        // ห้องแชตหนึ่งผูกได้กับบัญชีเดียว — ถ้ามีคนอื่นถืออยู่ต้องถอนก่อน
        // ไม่งั้นสองบัญชีจะเห็นข้อมูลผ่านห้องเดียวกันโดยไม่มีใครรู้
        $db->update('user', [[$column, $conversationId]], [$column => null]);
        $db->update('user', ['id', (int) $row->member_id], [$column => $conversationId]);

        // ใช้ได้ครั้งเดียว
        $db->delete('chat_link', [['code', $code]], 0);

        $user = $db->first('user', [['id', (int) $row->member_id]], ['id', 'name', 'username']);

        return [
            'member_id' => (int) $row->member_id,
            'name' => $user === null ? '' : (string) $user->name,
            'channel' => $channel
        ];
    }

    /**
     * ถอนการผูกของช่องทางหนึ่ง
     *
     * @param string $channel
     * @param int    $memberId
     *
     * @throws ApiException
     *
     * @return bool
     */
    public static function revoke($channel, $memberId)
    {
        if (!isset(self::CHANNELS[$channel])) {
            throw new ApiException(Language::get('Unknown channel'), 422);
        }

        return \Kotchasan\DB::create()->update(
            'user',
            ['id', (int) $memberId],
            [self::CHANNELS[$channel] => null]
        ) >= 0;
    }

    /**
     * สถานะการผูกของผู้ใช้คนหนึ่ง
     *
     * @param int $memberId
     *
     * @return array
     */
    public static function status($memberId)
    {
        $user = \Kotchasan\DB::create()->first(
            'user',
            [['id', (int) $memberId]],
            array_values(self::CHANNELS)
        );

        $out = [];
        foreach (self::CHANNELS as $channel => $column) {
            $value = $user === null ? null : $user->{$column};
            $linked = !empty($value);
            $out[] = [
                'channel' => $channel,
                'label' => $channel === 'line' ? 'LINE' : 'Telegram',
                'linked' => $linked,
                // แสดงเฉพาะท้ายรหัส — พอให้ยืนยันว่าเป็นเครื่องตัวเอง
                // โดยไม่เอา id เต็มขึ้นจอที่อาจถูกมองข้ามไหล่
                'hint' => $linked ? '…'.mb_substr((string) $value, -4) : '',
                'status_lng' => $linked ? '{LNG_Linked}' : '{LNG_Not linked}',
                'badge_class' => $linked ? 'tl-ok' : '',
                // array ศูนย์หรือหนึ่งตัว ด้วยเหตุผลเดียวกับที่อื่น
                'unlink_button' => $linked ? [$channel] : []
            ];
        }

        return $out;
    }

    /**
     * @return string
     */
    private static function randomCode()
    {
        $max = mb_strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= mb_substr(self::ALPHABET, random_int(0, $max), 1);
        }

        return $code;
    }

    /**
     * ทิ้งรหัสที่หมดอายุ
     */
    private static function purge()
    {
        \Kotchasan\DB::create()->delete(
            'chat_link',
            [['expires_at', '<', date('Y-m-d H:i:s')]],
            0
        );
    }
}
