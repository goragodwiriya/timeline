<?php
/**
 * @filesource modules/timeline/models/today.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Today;

use Gcms\Timeline\Attention;
use Timeline\Connections\Model as Connections;
use Kotchasan\Language;

/**
 * เตรียมข้อมูลหน้าจอหลักให้พร้อมแสดง
 *
 * แปลง item ให้เป็น "การ์ด" ที่มีข้อความพร้อมอ่านตั้งแต่ฝั่งเซิร์ฟเวอร์
 * เพราะการ์ดชุดเดียวกันนี้จะถูกส่งไปแสดงในแชตด้วย (HUB-DESIGN.md §3)
 * ถ้าปล่อยให้ JavaScript ประกอบข้อความเอง จะต้องเขียนตรรกะเดิมซ้ำอีกรอบใน PHP
 * ตอนทำ tool ของแชต แล้วสองที่จะเริ่มไม่ตรงกัน
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ชื่อกลุ่มตามลำดับที่ต้องแสดง — เกินกำหนดขึ้นก่อนเสมอ
     */
    const LABELS = [
        'overdue' => 'Overdue',
        'today' => 'Due today',
        'soon' => 'Due soon',
        'upcoming' => 'Upcoming'
    ];

    /**
     * ไอคอนประจำกลุ่ม
     *
     * เคยเขียนตายตัวอยู่ใน template ทีละใบ · ย้ายมาที่นี่เพราะการ์ดชุดเดียวกัน
     * ต้องขึ้นทั้งหน้า "วันนี้" และหน้าปฏิทิน — เขียนไว้สองที่เมื่อไรก็เริ่มไม่ตรงกัน
     */
    const ICONS = [
        // warning ไม่ใช่ attention — ฟอนต์ไอคอนของ Now.js ไม่มีชื่อ attention
        // เขียนผิดแล้วไม่มี error อะไรเลย แค่ไม่มีไอคอนขึ้นเงียบ ๆ
        'overdue' => 'warning',
        'today' => 'calendar',
        'soon' => 'clock',
        'upcoming' => 'list'
    ];

    /**
     * รูป {LNG_x} ที่ data-i18n ของ Now.js แปลให้เองบนหน้าจอ
     *
     * ส่งคีย์ไปแทนข้อความที่แปลแล้ว เพราะการแปลเป็นงานของฝั่งหน้าจอซึ่งรู้ว่า
     * ผู้ใช้เลือกภาษาอะไรอยู่ · เซิร์ฟเวอร์เดาแทนไม่ได้เมื่อ API ถูกเรียกจาก
     * หลายที่ (หน้าเว็บ แชต และในอนาคตอาจมีที่อื่น)
     *
     * @param string $key
     *
     * @return string
     */
    private static function label($key)
    {
        return '{LNG_'.self::LABELS[$key].'}';
    }

    /**
     * @param array $filter
     *
     * @return array
     */
    public static function payload(array $filter = [])
    {
        // กลุ่มที่ผู้ใช้เลือกดูอยู่ · ค่าที่ไม่รู้จักถือว่าไม่ได้เลือก
        // — ลิงก์ที่พิมพ์ผิดต้องได้ทั้งหน้าตามเดิม ไม่ใช่จอว่างเปล่า
        $view = self::view($filter);
        $buckets = Attention::buckets($filter);
        $counts = ['overdue' => 0, 'today' => 0, 'soon' => 0, 'upcoming' => 0, 'total' => 0];
        $groups = [];

        // ส่งเป็น array เรียงลำดับแล้ว ไม่ใช่ object ที่ผู้เรียกต้องรู้ชื่อคีย์เอง
        // — หน้าเว็บและ tool ของแชตจะได้วนแสดงด้วยโค้ดชุดเดียวกัน
        foreach (self::LABELS as $name => $label) {
            $items = $buckets[$name];
            $counts[$name] = count($items);
            $counts['total'] += count($items);

            // นับทุกกลุ่มเสมอแม้กำลังกรองอยู่ แล้วค่อยข้ามกลุ่มที่ไม่ได้เลือก
            //
            // ตัวเลขบนการ์ดสรุปคือทางเดินไปกลุ่มอื่น ถ้ามันหดตามตัวกรองด้วย
            // จะไม่เหลืออะไรบอกว่ากลุ่มที่ไม่ได้เปิดอยู่มีของค้างกี่รายการ
            if ($view !== '' && $name !== $view) {
                continue;
            }

            // ข้ามกลุ่มที่ว่างตั้งแต่ตรงนี้ ไม่ส่งไปให้หน้าจอตัดสินด้วย data-if
            //
            // data-if ที่เป็นเท็จจะถอดอิลิเมนต์ออกจาก DOM และ TreeWalker ของ
            // ApiComponent หามันไม่เจออีก — พอข้อมูลรอบหน้ามีกลุ่มนั้นแล้ว
            // มันจะไม่กลับมา · ให้ data-for วนบน array ที่ถูกต้องอยู่แล้วแทน
            if (empty($items)) {
                continue;
            }

            $groups[] = [
                'key' => $name,
                'label' => $label,
                'label_lng' => self::label($name),
                'count' => count($items),
                'cards' => array_map([self::class, 'card'], $items)
            ];
        }

        $sources = self::sourceStatus();

        // ส่งมาเฉพาะระบบที่มีปัญหา หน้าจอจึงวนแสดงได้ตรง ๆ โดยไม่ต้องมีเงื่อนไข
        $alerts = [];
        foreach ($sources as $source) {
            if ($source['stale'] || !$source['enabled']) {
                $alerts[] = $source;
            }
        }

        // จำนวนที่กำลังแสดงอยู่จริง — กรองอยู่ก็นับเฉพาะกลุ่มนั้น
        $showing = $view === '' ? $counts['total'] : $counts[$view];

        return [
            'counts' => $counts,
            'view' => $view,
            'views' => self::views($view, $counts),
            'groups' => $groups,
            'sources' => $sources,
            'alerts' => $alerts,
            // array ที่มีศูนย์หรือหนึ่งสมาชิก — data-for วนแล้วได้ผลเหมือน data-if
            // แต่ไม่ถอดอิลิเมนต์ทิ้งถาวรเมื่อเงื่อนไขเป็นเท็จ
            'active_view' => $view === '' ? [] : [[
                'key' => $view,
                'label_lng' => self::label($view),
                'count' => $counts[$view]
            ]],
            // ข้อความต่างกันสองแบบ — "ไม่มีอะไรต้องสนใจ" ตอนดูทั้งหมด เป็นข่าวดี
            // ส่วนตอนกรองอยู่แปลว่าเฉพาะกลุ่มนี้ว่าง กลุ่มอื่นอาจยังมีของค้าง
            'empty_state' => $showing === 0 ? [[
                'text_lng' => $view === ''
                    ? '{LNG_Nothing needs your attention right now}'
                    : '{LNG_Nothing in this group right now}'
            ]] : [],
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * เฉพาะตัวเลขสรุปกับการ์ดนำทาง ไม่มีรายการ
     *
     * หน้าปฏิทินขอชุดนี้ เพราะที่นั่นต้องการแค่ "ตอนนี้ค้างอยู่กี่เรื่อง" กับทางกด
     * เข้าหน้า "วันนี้" · เรียก payload() เต็มใบจะได้การ์ดทุกใบของทุกกลุ่มติดมาด้วย
     * ซึ่งไม่มีอะไรบนหน้านั้นเอาไปใช้
     *
     * @param array $filter
     *
     * @return array
     */
    public static function summary(array $filter = [])
    {
        $counts = Attention::counts($filter);

        return [
            'counts' => $counts,
            'view' => '',
            'views' => self::views('', $counts),
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * การ์ดสรุปสี่ใบ ซึ่งเป็นตัวเลือกกลุ่มไปในตัว
     *
     * ลิงก์ ไอคอน และสถานะที่เลือกอยู่ คำนวณมาจากเซิร์ฟเวอร์ทั้งหมด เพราะ
     * template เขียนเงื่อนไขไม่ได้ และการ์ดชุดนี้ถูกวางไว้สองหน้า (วันนี้/ปฏิทิน)
     *
     * @param string $active กลุ่มที่กำลังเลือกอยู่ ('' = ทั้งหมด)
     * @param array  $counts
     *
     * @return array
     */
    public static function views($active, array $counts)
    {
        $views = [];
        foreach (self::LABELS as $key => $label) {
            $views[] = [
                'key' => $key,
                'label_lng' => self::label($key),
                'icon' => self::ICONS[$key],
                'count' => isset($counts[$key]) ? $counts[$key] : 0,
                // กดใบที่เลือกอยู่ = เลิกกรอง · การ์ดใบเดิมจึงเป็นทั้งปุ่มเปิดและปิด
                // ไม่ต้องเล็งหาปุ่มยกเลิกที่อื่นบนจอ
                'url' => $key === $active ? '/today' : '/today?view='.$key,
                'active' => $key === $active ? 'is-active' : ''
            ];
        }

        return $views;
    }

    /**
     * ชื่อกลุ่มที่ขอมา เฉพาะที่มีอยู่จริง
     *
     * @param array $filter
     *
     * @return string '' เมื่อไม่ได้เลือกหรือเลือกค่าที่ไม่รู้จัก
     */
    private static function view(array $filter)
    {
        $view = isset($filter['view']) ? (string) $filter['view'] : '';

        return isset(self::LABELS[$view]) ? $view : '';
    }

    /**
     * แปลง item เป็นการ์ดที่พร้อมแสดง
     *
     * @param array $item
     *
     * @return array
     */
    public static function card(array $item)
    {
        return [
            'id' => (int) $item['id'],
            'uid' => $item['uid'],
            'kind' => $item['kind'],
            'source' => $item['slug'],
            'source_name' => $item['source_name'],
            'title' => $item['title'],
            'subtitle' => $item['subtitle'],
            // whenText มีวันที่จริงอยู่ในตัวแล้ว ไม่ต้องส่งช่องวันที่แยกอีก
            'when' => Attention::whenText($item['days'], !empty($item['all_day']), $item['at'], Attention::isEstimated($item)),
            'estimated' => Attention::isEstimated($item),
            'at' => $item['at'],
            'days' => $item['days'],
            'bucket' => $item['bucket'],
            'priority' => $item['priority'],
            'all_day' => (bool) $item['all_day'],
            'state' => $item['state'] ?: 'none',
            'snooze_until' => $item['snooze_until'],
            'contact' => $item['contact'],
            // เบอร์เป็น array ศูนย์หรือหนึ่งตัว — หน้าจอวนด้วย data-for แทนการ
            // ใช้ data-if ซึ่งถอดปุ่มทิ้งถาวรเมื่อการ์ดใบก่อนไม่มีเบอร์
            'phones' => empty($item['contact']['phone']) ? [] : [(string) $item['contact']['phone']],
            'links' => $item['links'],
            'actions' => self::actions($item['actions']),
            // ข้อมูลสรุปจากต้นทาง จัดรูปมาแล้ว หน้าจอวนแสดงได้ตรง ๆ
            'facts' => $item['facts'],
            'meta' => $item['meta'],
            'icon' => $item['hint']['icon'] ?? self::iconOf($item['kind']),
            'color' => $item['hint']['color'] ?? null,
            // ข้อมูลบนจอมาจากสำเนา — ต้องบอกได้เสมอว่าสำเนานั้นเก่าแค่ไหน
            'source_synced_at' => $item['last_ok_at']
        ];
    }

    /**
     * แนบนิยามฟอร์มของแต่ละ action ไปกับปุ่มในรูป JSON
     *
     * หน้าจอจะได้สร้างฟอร์มได้ทันทีที่กด โดยไม่ต้องยิงขอข้อมูลทั้งหน้าใหม่
     * เพียงเพื่อค้นว่าปุ่มนี้ต้องถามอะไรบ้าง
     *
     * @param array $actions
     *
     * @return array
     */
    private static function actions(array $actions)
    {
        foreach ($actions as &$action) {
            $action['fields_json'] = json_encode(
                $action['fields'] ?? [],
                JSON_UNESCAPED_UNICODE
            );
            // null กลายเป็นสตริง "null" เมื่อ interpolate ลง attribute — ส่งค่าว่างมาแทน
            $action['confirm_text'] = (string) ($action['confirm'] ?? '');
        }

        return $actions;
    }

    /**
     * ไอคอนปริยายเมื่อต้นทางไม่ได้ส่ง hint มา
     *
     * ใช้ prefix ของ kind เพื่อให้ชนิดใหม่ที่ยังไม่รู้จักได้ไอคอนที่พอเข้าเค้า
     * แทนที่จะได้ไอคอนกลางทั้งหมด และต้องไม่พังกับค่าที่ไม่รู้จัก
     *
     * @param string $kind
     *
     * @return string
     */
    private static function iconOf($kind)
    {
        $map = [
            'payment' => 'money',
            'expiry' => 'calendar',
            'appointment' => 'clock',
            'task' => 'list',
            'renewal' => 'refresh',
            'followup' => 'comments',
            'alert' => 'attention'
        ];
        $prefix = explode('.', $kind)[0];

        return $map[$prefix] ?? 'file';
    }

    /**
     * สถานะการเชื่อมต่อแบบย่อ สำหรับแถบเตือนบนหัวจอ
     *
     * @return array
     */
    public static function sourceStatus()
    {
        $rows = static::createQuery()
            ->select('id', 'slug', 'name', 'enabled', 'last_status', 'last_ok_at', 'last_error', 'fail_count')
            ->from('sources')
            ->orderBy('sort')
            ->fetchAll(true);

        $out = [];
        foreach ($rows as $row) {
            $stale = empty($row['last_ok_at'])
                || strtotime($row['last_ok_at']) < strtotime('-1 day');
            $out[] = [
                'id' => (int) $row['id'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'enabled' => (bool) $row['enabled'],
                'status' => $row['last_status'],
                'last_ok_at' => $row['last_ok_at'],
                'error' => $row['last_error'],
                // สิ่งที่อันตรายที่สุดของระบบที่แสดงข้อมูลจากสำเนา
                // คือสำเนาเก่าที่ดูเหมือนสด
                'stale' => $stale,
                'state_text' => (int) $row['enabled'] !== 1
                    ? Language::get('Disconnected')
                    : Language::sprintf('Last updated %s', Connections::ago($row['last_ok_at']))
            ];
        }

        return $out;
    }
}
