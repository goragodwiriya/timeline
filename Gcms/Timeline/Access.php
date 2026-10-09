<?php
/**
 * @filesource Gcms/Timeline/Access.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

/**
 * ด่านเดียวที่ตัดสินว่าใครเห็นข้อมูล Hub ได้
 *
 * เดิมทุก endpoint ตรวจแค่ `if (!$login)` ซึ่งแปลว่า **บัญชีใดก็ได้ที่ล็อกอินได้
 * อ่านข้อมูลได้ทั้งหมด** — ชื่อลูกหนี้ เบอร์โทร ยอดหนี้ อีเมลลูกค้า วันหมดอายุ
 * ของทุกเว็บ · สิทธิ์ `can_manage_timeline` ถูกประกาศไว้ใน
 * modules/timeline/controllers/init.php ตั้งแต่แรกแต่ไม่เคยถูกเรียกใช้ที่ไหนเลย
 *
 * รวมไว้ที่นี่ที่เดียวเพราะจุดตรวจที่กระจายอยู่สิบกว่าที่ คือจุดตรวจที่วันหนึ่ง
 * จะมีที่หนึ่งตกหล่นแล้วไม่มีใครรู้
 *
 * @since 1.0
 */
class Access
{
    /**
     * สิทธิ์ที่ต้องมี · ผู้ดูแลระบบ (status = 1) ผ่านเสมอ
     */
    const PERMISSION = 'can_manage_timeline';

    /**
     * บัญชีนี้เข้าถึงข้อมูล Hub ได้ไหม
     *
     * @param object|null $login ผลจาก authenticateRequest()
     *
     * @return bool
     */
    public static function allow($login)
    {
        if (empty($login) || !isset($login->id)) {
            return false;
        }

        // บัญชีที่ถูกระงับต้องไม่ผ่าน แม้จะยังมี token ที่ยังไม่หมดอายุอยู่ในมือ
        if (isset($login->active) && (int) $login->active !== 1) {
            return false;
        }

        // Api::hasPermission() เทียบ status ด้วย === กับ int · ค่าที่อ่านจาก
        // ฐานข้อมูลบางเส้นทางเป็นสตริง ทำให้ผู้ดูแลระบบไม่ผ่านแบบเงียบ ๆ
        if (isset($login->status) && (int) $login->status === 1) {
            return true;
        }

        return \Gcms\Api::hasPermission($login, self::PERMISSION);
    }

    /**
     * ผู้ใช้หมายเลขนี้เข้าถึงข้อมูล Hub ได้ไหม
     *
     * ใช้ทางฝั่งแชต ซึ่งไม่มี $login มาให้ มีแต่หมายเลขผู้ใช้ที่ผูกกับห้อง
     *
     * @param int $memberId
     *
     * @return bool
     */
    public static function allowMember($memberId)
    {
        $memberId = (int) $memberId;
        if ($memberId <= 0) {
            return false;
        }

        $row = \Kotchasan\DB::create()->first('user', [['id', $memberId]], ['id', 'status', 'active', 'permission']);

        return self::allow($row);
    }
}
