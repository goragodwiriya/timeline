<?php
/**
 * modules/appointment/install/upgrade.php — พาฐานเดิมมาถึงสคีมาของโมดูล appointment
 *
 * install/upgrade_core.php เรียกไฟล์นี้ให้เอง ตัวแปรที่ใช้ได้คือชุดเดียวกับที่
 * upgrade_core ใช้ : $db, $db_config, $prefix, $content, $config
 *
 * นิยามตารางอยู่ที่ modules/appointment/install/database.sql ที่เดียว — ไฟล์นี้อ่าน
 * นิยามจากที่นั่นผ่าน ensureTable() และรายการคอลัมน์ด้านล่างถูกสร้างจากไฟล์
 * เดียวกัน จึงไม่มีนิยามชุดที่สองให้ค่อย ๆ ต่างกัน
 *
 * กฎเดียวกับ upgrade_core : ทุกเงื่อนไขถามว่า "ต้องแก้ไหม" ไม่ใช่ "ตอนนี้เป็นอะไร"
 */
if (!defined('ROOT_PATH')) {
    exit;
}

foreach ([
    'appointments'
] as $_name) {
    $_table = $prefix.'_'.$_name;
    if (ensureTable($db, $prefix, $_table)) {
        $content[] = '<li class="correct">appointment: สร้างตาราง '.$_name.'</li>';
    }
    // ⚠️ ต้องแปลงก่อนปรับคอลัมน์เสมอ — CONVERT TO CHARACTER SET เลื่อนชนิด TEXT
    // เป็น MEDIUMTEXT ถ้าแปลงทีหลังชนิดจะไม่ตรงกับที่ติดตั้งใหม่
    if (convertToInnoDB($db, $_table)) {
        $content[] = '<li class="correct">'.$_name.': แปลงเป็น InnoDB</li>';
    }
    if (convertToUtf8mb4($db, $_table)) {
        $content[] = '<li class="correct">'.$_name.': แปลงเป็น utf8mb4</li>';
    }
}

// appointments — PRIMARY KEY และ AUTO_INCREMENT
//
// ⚠️ ตัวติดตั้งรุ่นเก่าประกาศคอลัมน์ id เป็น NOT NULL เฉย ๆ แล้วค่อยเติม
// PRIMARY KEY / AUTO_INCREMENT ด้วย ALTER TABLE ท้าย database.sql ซึ่ง
// ตัวปรับรุ่นไม่เคยรัน ไซต์ที่อัปเกรดจึงเพิ่มข้อมูลใหม่ไม่ได้เลย
if (!$db->indexExists($prefix.'_appointments', 'PRIMARY')) {
    // ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน (เช่น id เป็น 0 ทุกแถวเพราะไม่เคยมี AUTO_INCREMENT)
    // การเพิ่ม PRIMARY KEY จะล้มกลางคัน ต้องตรวจแล้วบอกให้ผู้ดูแลแก้เอง
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_appointments`
         GROUP BY `id` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">appointments: มีค่าซ้ำใน (id) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังเพิ่ม PRIMARY KEY ไม่ได้ '
            .'กรุณาแก้ให้ไม่ซ้ำแล้วกดปรับรุ่นอีกครั้ง — ตัวปรับรุ่นจะไม่ลบให้เอง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_appointments` ADD PRIMARY KEY (`id`)");
        $content[] = '<li class="correct">appointments: เพิ่ม PRIMARY KEY</li>';
    }
}
if (ensureAutoIncrement($db, $prefix.'_appointments', 'id', 'int(11)')) {
    $content[] = '<li class="correct">appointments: กำหนด id เป็น AUTO_INCREMENT</li>';
}
// appointments
foreach ([
    'member_id' => ['int(11)', false, '0', '', ''],
    'title' => ['varchar(255)', false, '', 'member_id', ''],
    'detail' => ['text', true, null, 'title', ''],
    'location' => ['varchar(255)', false, '', 'detail', ''],
    'start_at' => ['datetime', false, null, 'location', ''],
    'end_at' => ['datetime', true, null, 'start_at', ''],
    'all_day' => ['tinyint(1)', false, '0', 'end_at', ''],
    'status' => ['varchar(16)', false, 'active', 'all_day', 'active | completed | cancelled'],
    'priority' => ['varchar(8)', false, 'normal', 'status', ''],
    'series_id' => ['int(11)', false, '0', 'priority', 'ชุดของนัดที่เกิดซ้ำ กางเป็นรายครั้งไว้แล้ว'],
    'remind_json' => ['text', true, null, 'series_id', 'ทับกฎปริยายของ kind appointment'],
    'created_at' => ['datetime', false, null, 'remind_json', ''],
    'updated_at' => ['datetime', false, null, 'created_at', '']
] as $_col => $_def) {
    if (ensureColumn($db, $prefix.'_appointments', $_col, $_def[0], $_def[1], $_def[2], $_def[4], $_def[3])) {
        $content[] = '<li class="correct">appointments: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if (ensureIndexes($db, $prefix.'_appointments', [
    'start_at' => '`start_at`, `status`',
    'series' => '`series_id`'
])) {
    $content[] = '<li class="correct">appointments: ปรับดัชนี</li>';
}

$content[] = '<li class="correct">appointment อัปเกรดสำเร็จ</li>';
