<?php
/**
 * @filesource modules/timeline/controllers/init.php
 */

namespace Timeline\Init;

class Controller extends \Gcms\Controller
{
    /**
     * Register timeline permissions.
     *
     * @param array $permissions
     * @param mixed $params
     * @param object|null $login
     *
     * @return array
     */
    public static function initPermission($permissions, $params = null, $login = null)
    {
        $permissions[] = [
            'value' => 'can_manage_timeline',
            'text' => '{LNG_Can manage} {LNG_Timeline}'
        ];

        return $permissions;
    }

    /**
     * Register timeline menus.
     *
     * @param array $menus
     * @param mixed $params
     * @param object|null $login
     *
     * @return array
     */
    public static function initMenus($menus, $params = null, $login = null)
    {
        // เมนูที่กดแล้วได้ 401 คือเมนูที่ไม่ควรมี — และมันบอกคนที่ไม่มีสิทธิ์
        // ด้วยว่าระบบนี้เก็บอะไรไว้บ้าง ซึ่งไม่จำเป็นต้องบอก
        if (!\Gcms\Timeline\Access::allow($login)) {
            return $menus;
        }

        $menus['dashboard'] = [
            'title' => 'Calendar',
            'url' => '/',
            'icon' => 'icon-calendar'
        ];

        $timelineMenu = [
            [
                'title' => '{LNG_Today}',
                'url' => '/today',
                'icon' => 'icon-event'
            ],
            [
                'title' => 'Connections',
                'url' => '/connections',
                'icon' => 'icon-refresh'
            ],
            [
                'title' => 'Chat account',
                'url' => '/chat-account',
                'icon' => 'icon-comments'
            ],
            [
                'title' => 'Notification history',
                'url' => '/history',
                'icon' => 'icon-bell'
            ]
        ];

        $menus = parent::insertMenuAfter($menus, $timelineMenu, 0);

        return $menus;
    }
}
