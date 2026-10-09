<?php
/**
 * @filesource modules/appointment/controllers/init.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Appointment\Init;

/**
 * ลงทะเบียนเมนูของระบบนัดหมาย
 *
 * ใช้จุดต่อของเฟรมเวิร์ก (initModule) แทนการแก้ modules/index/controllers/menus.php
 * ซึ่งเป็นไฟล์กลางของ adminframework — แก้ที่นั่นแล้วการอัปเดตครั้งหน้าจะทับหาย
 * และลูกตัวอื่นก็ได้เมนูที่ไม่มีโมดูลรองรับไปด้วย
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * @param array       $menus
     * @param mixed       $params
     * @param object|null $login
     *
     * @return array
     */
    public static function initMenus($menus, $params = null, $login = null)
    {
        // เหมือนกับ timeline — นัดหมายเก็บชื่อคน สถานที่ และเวลาจริง
        if (!\Gcms\Timeline\Access::allow($login)) {
            return $menus;
        }

        $item = [[
            'title' => 'Appointments',
            'url' => '/appointment',
            'icon' => 'icon-clock'
        ]];

        // วางต่อจาก "วันนี้" — นัดหมายเป็น item ชนิดหนึ่งบนไทม์ไลน์เดียวกัน
        // ไม่ใช่ระบบแยก จึงควรอยู่ติดกันบนเมนู
        //
        // ค้นตำแหน่งจาก url เอง ไม่ส่งชื่อคีย์หรือเลขตำแหน่งตายตัวให้
        // insertMenuAfter เพราะเมนูถูกแปลงเป็น array ตัวเลขไปแล้วตั้งแต่โมดูลแรก
        // ที่แทรก และลำดับที่แต่ละโมดูลถูกเรียกมาจาก readdir() ซึ่งไม่รับประกัน
        // ลำดับ — เลขตำแหน่งจึงถูกบ้างผิดบ้างแล้วแต่ระบบไฟล์
        foreach (array_values($menus) as $index => $menu) {
            if (isset($menu['url']) && $menu['url'] === '/today') {
                return parent::insertMenuAfter($menus, $item, $index);
            }
        }

        // โมดูล timeline ยังไม่ได้ลงทะเบียน (readdir คืน appointment มาก่อน) —
        // แทรกไว้ต้น ๆ แทนต่อท้ายสุดหลังเมนูตั้งค่า · พอ timeline แทรกบล็อกของมัน
        // ต่อจาก Dashboard ทีหลัง ผลลัพธ์จะกลายเป็นลำดับที่ต้องการพอดี
        return parent::insertMenuAfter($menus, $item, 0);
    }
}
