<?php
/**
 * modules/timeline/install/upgrade.php — พาฐานเดิมมาถึงสคีมาของโมดูล timeline
 *
 * install/upgrade_core.php เรียกไฟล์นี้ให้เอง ตัวแปรที่ใช้ได้คือชุดเดียวกับที่
 * upgrade_core ใช้ : $db, $db_config, $prefix, $content, $config
 *
 * นิยามตารางอยู่ที่ modules/timeline/install/database.sql ที่เดียว — ไฟล์นี้อ่าน
 * นิยามจากที่นั่นผ่าน ensureTable() และรายการคอลัมน์ด้านล่างถูกสร้างจากไฟล์
 * เดียวกัน จึงไม่มีนิยามชุดที่สองให้ค่อย ๆ ต่างกัน
 *
 * กฎเดียวกับ upgrade_core : ทุกเงื่อนไขถามว่า "ต้องแก้ไหม" ไม่ใช่ "ตอนนี้เป็นอะไร"
 */
if (!defined('ROOT_PATH')) {
    exit;
}

foreach ([
    'items',
    'item_state',
    'sources',
    'sync_runs',
    'reminders',
    'reminder_rules',
    'action_log',
    'chat_link'
] as $_name) {
    $_table = $prefix.'_'.$_name;
    if (ensureTable($db, $prefix, $_table)) {
        $content[] = '<li class="correct">timeline: สร้างตาราง '.$_name.'</li>';
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

// items — PRIMARY KEY และ AUTO_INCREMENT
//
// ⚠️ ตัวติดตั้งรุ่นเก่าประกาศคอลัมน์ id เป็น NOT NULL เฉย ๆ แล้วค่อยเติม
// PRIMARY KEY / AUTO_INCREMENT ด้วย ALTER TABLE ท้าย database.sql ซึ่ง
// ตัวปรับรุ่นไม่เคยรัน ไซต์ที่อัปเกรดจึงเพิ่มข้อมูลใหม่ไม่ได้เลย
if (!$db->indexExists($prefix.'_items', 'PRIMARY')) {
    // ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน (เช่น id เป็น 0 ทุกแถวเพราะไม่เคยมี AUTO_INCREMENT)
    // การเพิ่ม PRIMARY KEY จะล้มกลางคัน ต้องตรวจแล้วบอกให้ผู้ดูแลแก้เอง
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_items`
         GROUP BY `id` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">items: มีค่าซ้ำใน (id) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังเพิ่ม PRIMARY KEY ไม่ได้ '
            .'กรุณาแก้ให้ไม่ซ้ำแล้วกดปรับรุ่นอีกครั้ง — ตัวปรับรุ่นจะไม่ลบให้เอง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_items` ADD PRIMARY KEY (`id`)");
        $content[] = '<li class="correct">items: เพิ่ม PRIMARY KEY</li>';
    }
}
if (ensureAutoIncrement($db, $prefix.'_items', 'id', 'int(11)')) {
    $content[] = '<li class="correct">items: กำหนด id เป็น AUTO_INCREMENT</li>';
}
// items
foreach ([
    'source_id' => ['int(11)', false, '0', '', ''],
    'uid' => ['varchar(190)', false, null, 'source_id', 'ต้นทางเป็นคนตั้ง ต้องนิ่งตลอดอายุของเรื่อง'],
    'kind' => ['varchar(64)', false, '', 'uid', ''],
    'origin' => ['varchar(8)', false, 'sync', 'kind', 'sync | push | local — reconciliation แตะเฉพาะ sync'],
    'title' => ['varchar(255)', false, '', 'origin', ''],
    'subtitle' => ['varchar(255)', false, '', 'title', ''],
    'body' => ['text', true, null, 'subtitle', ''],
    'start_at' => ['datetime', true, null, 'body', ''],
    'end_at' => ['datetime', true, null, 'start_at', ''],
    'due_at' => ['datetime', true, null, 'end_at', ''],
    'all_day' => ['tinyint(1)', false, '0', 'due_at', ''],
    'tz' => ['varchar(64)', false, 'Asia/Bangkok', 'all_day', ''],
    'status' => ['varchar(16)', false, 'active', 'tz', 'active | completed | cancelled | expired'],
    'priority' => ['varchar(8)', false, 'normal', 'status', 'low | normal | high | critical'],
    'entity_type' => ['varchar(64)', false, '', 'priority', ''],
    'entity_id' => ['varchar(190)', false, '', 'entity_type', ''],
    'entity_label' => ['varchar(190)', false, '', 'entity_id', ''],
    'contact_json' => ['text', true, null, 'entity_label', ''],
    'links_json' => ['text', true, null, 'contact_json', ''],
    'actions_json' => ['text', true, null, 'links_json', ''],
    'facts_json' => ['text', true, null, 'actions_json', 'ข้อมูลสรุปที่ต้นทางจัดรูปมาแล้ว พร้อมแสดงบนการ์ด'],
    'meta_json' => ['text', true, null, 'facts_json', ''],
    'hint_json' => ['text', true, null, 'meta_json', ''],
    'payload_hash' => ['char(40)', false, '', 'hint_json', 'ตอบว่าเปลี่ยนจริงไหม โดยไม่ต้องเทียบทีละฟิลด์'],
    'first_seen_at' => ['datetime', false, null, 'payload_hash', ''],
    'last_seen_run' => ['int(11)', false, '0', 'first_seen_at', ''],
    'vanished_at' => ['datetime', true, null, 'last_seen_run', 'หายจาก snapshot แล้ว รอลบจริงหลัง 30 วัน']
] as $_col => $_def) {
    if (ensureColumn($db, $prefix.'_items', $_col, $_def[0], $_def[1], $_def[2], $_def[4], $_def[3])) {
        $content[] = '<li class="correct">items: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if (ensureIndexes($db, $prefix.'_items', [
    'due_at' => '`due_at`',
    'attention' => '`status`, `vanished_at`, `due_at`',
    'entity' => '`entity_id`'
])) {
    $content[] = '<li class="correct">items: ปรับดัชนี</li>';
}
// items — ดัชนี UNIQUE
//
// ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน คำสั่งจะล้ม ตัวปรับรุ่นต้องตรวจแล้วบอกให้ผู้ดูแล
// แก้เอง ห้ามลบแถวที่ซ้ำให้เอง — ข้อมูลของผู้ใช้ไม่ใช่ของเราที่จะทิ้ง
if (!$db->indexExists($prefix.'_items', 'source_uid')) {
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_items`
         GROUP BY `source_id`, `uid` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">items: มีค่าซ้ำใน (`source_id`, `uid`) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังสร้างดัชนี source_uid แบบ UNIQUE ไม่ได้ '
            .'กรุณาแก้ให้เหลือชุดเดียวแล้วกดปรับรุ่นอีกครั้ง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_items` ADD UNIQUE KEY `source_uid` (`source_id`, `uid`)");
        $content[] = '<li class="correct">items: เพิ่มดัชนี UNIQUE source_uid</li>';
    }
}

// item_state — PRIMARY KEY และ AUTO_INCREMENT
//
// ⚠️ ตัวติดตั้งรุ่นเก่าประกาศคอลัมน์ id เป็น NOT NULL เฉย ๆ แล้วค่อยเติม
// PRIMARY KEY / AUTO_INCREMENT ด้วย ALTER TABLE ท้าย database.sql ซึ่ง
// ตัวปรับรุ่นไม่เคยรัน ไซต์ที่อัปเกรดจึงเพิ่มข้อมูลใหม่ไม่ได้เลย
if (!$db->indexExists($prefix.'_item_state', 'PRIMARY')) {
    // ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน (เช่น id เป็น 0 ทุกแถวเพราะไม่เคยมี AUTO_INCREMENT)
    // การเพิ่ม PRIMARY KEY จะล้มกลางคัน ต้องตรวจแล้วบอกให้ผู้ดูแลแก้เอง
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_item_state`
         GROUP BY `item_id` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">item_state: มีค่าซ้ำใน (item_id) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังเพิ่ม PRIMARY KEY ไม่ได้ '
            .'กรุณาแก้ให้ไม่ซ้ำแล้วกดปรับรุ่นอีกครั้ง — ตัวปรับรุ่นจะไม่ลบให้เอง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_item_state` ADD PRIMARY KEY (`item_id`)");
        $content[] = '<li class="correct">item_state: เพิ่ม PRIMARY KEY</li>';
    }
}
// item_state
foreach ([
    'item_id' => ['int(11)', false, null, '', ''],
    'state' => ['varchar(16)', false, 'none', 'item_id', 'none | done | dismissed | snoozed'],
    'snooze_until' => ['datetime', true, null, 'state', ''],
    'note' => ['varchar(255)', false, '', 'snooze_until', ''],
    'pinned' => ['tinyint(1)', false, '0', 'note', ''],
    'updated_at' => ['datetime', false, null, 'pinned', '']
] as $_col => $_def) {
    if (ensureColumn($db, $prefix.'_item_state', $_col, $_def[0], $_def[1], $_def[2], $_def[4], $_def[3])) {
        $content[] = '<li class="correct">item_state: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if (ensureIndexes($db, $prefix.'_item_state', [
    'state' => '`state`, `snooze_until`'
])) {
    $content[] = '<li class="correct">item_state: ปรับดัชนี</li>';
}

// sources — PRIMARY KEY และ AUTO_INCREMENT
//
// ⚠️ ตัวติดตั้งรุ่นเก่าประกาศคอลัมน์ id เป็น NOT NULL เฉย ๆ แล้วค่อยเติม
// PRIMARY KEY / AUTO_INCREMENT ด้วย ALTER TABLE ท้าย database.sql ซึ่ง
// ตัวปรับรุ่นไม่เคยรัน ไซต์ที่อัปเกรดจึงเพิ่มข้อมูลใหม่ไม่ได้เลย
if (!$db->indexExists($prefix.'_sources', 'PRIMARY')) {
    // ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน (เช่น id เป็น 0 ทุกแถวเพราะไม่เคยมี AUTO_INCREMENT)
    // การเพิ่ม PRIMARY KEY จะล้มกลางคัน ต้องตรวจแล้วบอกให้ผู้ดูแลแก้เอง
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_sources`
         GROUP BY `id` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">sources: มีค่าซ้ำใน (id) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังเพิ่ม PRIMARY KEY ไม่ได้ '
            .'กรุณาแก้ให้ไม่ซ้ำแล้วกดปรับรุ่นอีกครั้ง — ตัวปรับรุ่นจะไม่ลบให้เอง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_sources` ADD PRIMARY KEY (`id`)");
        $content[] = '<li class="correct">sources: เพิ่ม PRIMARY KEY</li>';
    }
}
if (ensureAutoIncrement($db, $prefix.'_sources', 'id', 'int(11)')) {
    $content[] = '<li class="correct">sources: กำหนด id เป็น AUTO_INCREMENT</li>';
}
// sources
foreach ([
    'slug' => ['varchar(32)', false, null, '', 'ต้องตรงกับ source.slug ใน manifest'],
    'name' => ['varchar(100)', false, '', 'slug', ''],
    'base_url' => ['varchar(255)', false, '', 'name', 'ถึงระดับที่ต่อท้ายด้วย /timeline/... ได้'],
    'home_url' => ['varchar(255)', false, '', 'base_url', 'หน้าแรกของระบบต้นทาง จาก manifest'],
    'token' => ['varchar(255)', false, '', 'home_url', ''],
    'timezone' => ['varchar(64)', false, 'Asia/Bangkok', 'token', ''],
    'interval_min' => ['smallint(6)', false, '360', 'timezone', ''],
    'horizon_past' => ['varchar(16)', false, 'P90D', 'interval_min', ''],
    'horizon_future' => ['varchar(16)', false, 'P12M', 'horizon_past', ''],
    'enabled' => ['tinyint(1)', false, '1', 'horizon_future', ''],
    'protocol_version' => ['varchar(16)', false, '', 'enabled', ''],
    'manifest_json' => ['text', true, null, 'protocol_version', ''],
    'last_sync_at' => ['datetime', true, null, 'manifest_json', 'ครั้งล่าสุดที่พยายาม'],
    'last_ok_at' => ['datetime', true, null, 'last_sync_at', 'ครั้งล่าสุดที่สำเร็จ — ตัวที่ใช้บอกผู้ใช้ว่าข้อมูลเก่าแค่ไหน'],
    'last_status' => ['varchar(16)', false, '', 'last_ok_at', 'ok | partial | failed'],
    'last_error' => ['varchar(255)', false, '', 'last_status', ''],
    'fail_count' => ['smallint(6)', false, '0', 'last_error', ''],
    'paused_until' => ['datetime', true, null, 'fail_count', ''],
    'sort' => ['smallint(6)', false, '0', 'paused_until', ''],
    'created_at' => ['datetime', false, null, 'sort', '']
] as $_col => $_def) {
    if (ensureColumn($db, $prefix.'_sources', $_col, $_def[0], $_def[1], $_def[2], $_def[4], $_def[3])) {
        $content[] = '<li class="correct">sources: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if (ensureIndexes($db, $prefix.'_sources', [
    'enabled' => '`enabled`, `last_sync_at`'
])) {
    $content[] = '<li class="correct">sources: ปรับดัชนี</li>';
}
// sources — ดัชนี UNIQUE
//
// ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน คำสั่งจะล้ม ตัวปรับรุ่นต้องตรวจแล้วบอกให้ผู้ดูแล
// แก้เอง ห้ามลบแถวที่ซ้ำให้เอง — ข้อมูลของผู้ใช้ไม่ใช่ของเราที่จะทิ้ง
if (!$db->indexExists($prefix.'_sources', 'slug')) {
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_sources`
         GROUP BY `slug` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">sources: มีค่าซ้ำใน (`slug`) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังสร้างดัชนี slug แบบ UNIQUE ไม่ได้ '
            .'กรุณาแก้ให้เหลือชุดเดียวแล้วกดปรับรุ่นอีกครั้ง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_sources` ADD UNIQUE KEY `slug` (`slug`)");
        $content[] = '<li class="correct">sources: เพิ่มดัชนี UNIQUE slug</li>';
    }
}

// sync_runs — PRIMARY KEY และ AUTO_INCREMENT
//
// ⚠️ ตัวติดตั้งรุ่นเก่าประกาศคอลัมน์ id เป็น NOT NULL เฉย ๆ แล้วค่อยเติม
// PRIMARY KEY / AUTO_INCREMENT ด้วย ALTER TABLE ท้าย database.sql ซึ่ง
// ตัวปรับรุ่นไม่เคยรัน ไซต์ที่อัปเกรดจึงเพิ่มข้อมูลใหม่ไม่ได้เลย
if (!$db->indexExists($prefix.'_sync_runs', 'PRIMARY')) {
    // ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน (เช่น id เป็น 0 ทุกแถวเพราะไม่เคยมี AUTO_INCREMENT)
    // การเพิ่ม PRIMARY KEY จะล้มกลางคัน ต้องตรวจแล้วบอกให้ผู้ดูแลแก้เอง
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_sync_runs`
         GROUP BY `id` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">sync_runs: มีค่าซ้ำใน (id) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังเพิ่ม PRIMARY KEY ไม่ได้ '
            .'กรุณาแก้ให้ไม่ซ้ำแล้วกดปรับรุ่นอีกครั้ง — ตัวปรับรุ่นจะไม่ลบให้เอง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_sync_runs` ADD PRIMARY KEY (`id`)");
        $content[] = '<li class="correct">sync_runs: เพิ่ม PRIMARY KEY</li>';
    }
}
if (ensureAutoIncrement($db, $prefix.'_sync_runs', 'id', 'int(11)')) {
    $content[] = '<li class="correct">sync_runs: กำหนด id เป็น AUTO_INCREMENT</li>';
}
// sync_runs
foreach ([
    'source_id' => ['int(11)', false, '0', '', ''],
    'started_at' => ['datetime', false, null, 'source_id', ''],
    'finished_at' => ['datetime', true, null, 'started_at', ''],
    'status' => ['varchar(16)', false, 'running', 'finished_at', 'running | ok | partial | failed'],
    'items_seen' => ['int(11)', false, '0', 'status', ''],
    'items_changed' => ['int(11)', false, '0', 'items_seen', ''],
    'items_vanished' => ['int(11)', false, '0', 'items_changed', ''],
    'http_code' => ['smallint(6)', false, '0', 'items_vanished', ''],
    'error' => ['varchar(255)', false, '', 'http_code', '']
] as $_col => $_def) {
    if (ensureColumn($db, $prefix.'_sync_runs', $_col, $_def[0], $_def[1], $_def[2], $_def[4], $_def[3])) {
        $content[] = '<li class="correct">sync_runs: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if (ensureIndexes($db, $prefix.'_sync_runs', [
    'source' => '`source_id`, `started_at`'
])) {
    $content[] = '<li class="correct">sync_runs: ปรับดัชนี</li>';
}

// reminders — PRIMARY KEY และ AUTO_INCREMENT
//
// ⚠️ ตัวติดตั้งรุ่นเก่าประกาศคอลัมน์ id เป็น NOT NULL เฉย ๆ แล้วค่อยเติม
// PRIMARY KEY / AUTO_INCREMENT ด้วย ALTER TABLE ท้าย database.sql ซึ่ง
// ตัวปรับรุ่นไม่เคยรัน ไซต์ที่อัปเกรดจึงเพิ่มข้อมูลใหม่ไม่ได้เลย
if (!$db->indexExists($prefix.'_reminders', 'PRIMARY')) {
    // ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน (เช่น id เป็น 0 ทุกแถวเพราะไม่เคยมี AUTO_INCREMENT)
    // การเพิ่ม PRIMARY KEY จะล้มกลางคัน ต้องตรวจแล้วบอกให้ผู้ดูแลแก้เอง
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_reminders`
         GROUP BY `id` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">reminders: มีค่าซ้ำใน (id) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังเพิ่ม PRIMARY KEY ไม่ได้ '
            .'กรุณาแก้ให้ไม่ซ้ำแล้วกดปรับรุ่นอีกครั้ง — ตัวปรับรุ่นจะไม่ลบให้เอง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_reminders` ADD PRIMARY KEY (`id`)");
        $content[] = '<li class="correct">reminders: เพิ่ม PRIMARY KEY</li>';
    }
}
if (ensureAutoIncrement($db, $prefix.'_reminders', 'id', 'int(11)')) {
    $content[] = '<li class="correct">reminders: กำหนด id เป็น AUTO_INCREMENT</li>';
}
// reminders
foreach ([
    'item_id' => ['int(11)', false, '0', '', ''],
    'offset_key' => ['varchar(32)', false, '', 'item_id', 'เช่น P7D หรือ overdue:3'],
    'fire_at' => ['datetime', false, null, 'offset_key', ''],
    'sent_at' => ['datetime', true, null, 'fire_at', ''],
    'channel' => ['varchar(16)', false, '', 'sent_at', ''],
    'status' => ['varchar(16)', false, 'pending', 'channel', 'pending | sent | skipped | failed'],
    'error' => ['varchar(255)', false, '', 'status', '']
] as $_col => $_def) {
    if (ensureColumn($db, $prefix.'_reminders', $_col, $_def[0], $_def[1], $_def[2], $_def[4], $_def[3])) {
        $content[] = '<li class="correct">reminders: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if (ensureIndexes($db, $prefix.'_reminders', [
    'due' => '`status`, `fire_at`'
])) {
    $content[] = '<li class="correct">reminders: ปรับดัชนี</li>';
}
// reminders — ดัชนี UNIQUE
//
// ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน คำสั่งจะล้ม ตัวปรับรุ่นต้องตรวจแล้วบอกให้ผู้ดูแล
// แก้เอง ห้ามลบแถวที่ซ้ำให้เอง — ข้อมูลของผู้ใช้ไม่ใช่ของเราที่จะทิ้ง
if (!$db->indexExists($prefix.'_reminders', 'item_offset')) {
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_reminders`
         GROUP BY `item_id`, `offset_key` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">reminders: มีค่าซ้ำใน (`item_id`, `offset_key`) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังสร้างดัชนี item_offset แบบ UNIQUE ไม่ได้ '
            .'กรุณาแก้ให้เหลือชุดเดียวแล้วกดปรับรุ่นอีกครั้ง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_reminders` ADD UNIQUE KEY `item_offset` (`item_id`, `offset_key`)");
        $content[] = '<li class="correct">reminders: เพิ่มดัชนี UNIQUE item_offset</li>';
    }
}

// reminder_rules — PRIMARY KEY และ AUTO_INCREMENT
//
// ⚠️ ตัวติดตั้งรุ่นเก่าประกาศคอลัมน์ id เป็น NOT NULL เฉย ๆ แล้วค่อยเติม
// PRIMARY KEY / AUTO_INCREMENT ด้วย ALTER TABLE ท้าย database.sql ซึ่ง
// ตัวปรับรุ่นไม่เคยรัน ไซต์ที่อัปเกรดจึงเพิ่มข้อมูลใหม่ไม่ได้เลย
if (!$db->indexExists($prefix.'_reminder_rules', 'PRIMARY')) {
    // ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน (เช่น id เป็น 0 ทุกแถวเพราะไม่เคยมี AUTO_INCREMENT)
    // การเพิ่ม PRIMARY KEY จะล้มกลางคัน ต้องตรวจแล้วบอกให้ผู้ดูแลแก้เอง
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_reminder_rules`
         GROUP BY `id` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">reminder_rules: มีค่าซ้ำใน (id) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังเพิ่ม PRIMARY KEY ไม่ได้ '
            .'กรุณาแก้ให้ไม่ซ้ำแล้วกดปรับรุ่นอีกครั้ง — ตัวปรับรุ่นจะไม่ลบให้เอง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_reminder_rules` ADD PRIMARY KEY (`id`)");
        $content[] = '<li class="correct">reminder_rules: เพิ่ม PRIMARY KEY</li>';
    }
}
if (ensureAutoIncrement($db, $prefix.'_reminder_rules', 'id', 'int(11)')) {
    $content[] = '<li class="correct">reminder_rules: กำหนด id เป็น AUTO_INCREMENT</li>';
}
// reminder_rules
foreach ([
    'source_id' => ['int(11)', false, '0', '', '0 = ใช้กับทุกระบบต้นทาง'],
    'kind' => ['varchar(64)', false, null, 'source_id', 'ชื่อเต็ม หรือ prefix ตามด้วย .* '],
    'offsets_json' => ['text', true, null, 'kind', 'เช่น ["P30D","P7D","P1D","PT0S"]'],
    'channels_json' => ['text', true, null, 'offsets_json', 'เช่น ["telegram","line"]'],
    'max_repeat' => ['smallint(6)', false, '0', 'channels_json', 'เพดานการเตือนซ้ำรายวัน 0 = ไม่ซ้ำ'],
    'enabled' => ['tinyint(1)', false, '1', 'max_repeat', ''],
    'visible' => ['tinyint(1)', false, '1', 'enabled', 'แสดงบนหน้ากระดานไหม']
] as $_col => $_def) {
    if (ensureColumn($db, $prefix.'_reminder_rules', $_col, $_def[0], $_def[1], $_def[2], $_def[4], $_def[3])) {
        $content[] = '<li class="correct">reminder_rules: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
// reminder_rules — ดัชนี UNIQUE
//
// ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน คำสั่งจะล้ม ตัวปรับรุ่นต้องตรวจแล้วบอกให้ผู้ดูแล
// แก้เอง ห้ามลบแถวที่ซ้ำให้เอง — ข้อมูลของผู้ใช้ไม่ใช่ของเราที่จะทิ้ง
if (!$db->indexExists($prefix.'_reminder_rules', 'source_kind')) {
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_reminder_rules`
         GROUP BY `source_id`, `kind` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">reminder_rules: มีค่าซ้ำใน (`source_id`, `kind`) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังสร้างดัชนี source_kind แบบ UNIQUE ไม่ได้ '
            .'กรุณาแก้ให้เหลือชุดเดียวแล้วกดปรับรุ่นอีกครั้ง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_reminder_rules` ADD UNIQUE KEY `source_kind` (`source_id`, `kind`)");
        $content[] = '<li class="correct">reminder_rules: เพิ่มดัชนี UNIQUE source_kind</li>';
    }
}

// action_log — PRIMARY KEY และ AUTO_INCREMENT
//
// ⚠️ ตัวติดตั้งรุ่นเก่าประกาศคอลัมน์ id เป็น NOT NULL เฉย ๆ แล้วค่อยเติม
// PRIMARY KEY / AUTO_INCREMENT ด้วย ALTER TABLE ท้าย database.sql ซึ่ง
// ตัวปรับรุ่นไม่เคยรัน ไซต์ที่อัปเกรดจึงเพิ่มข้อมูลใหม่ไม่ได้เลย
if (!$db->indexExists($prefix.'_action_log', 'PRIMARY')) {
    // ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน (เช่น id เป็น 0 ทุกแถวเพราะไม่เคยมี AUTO_INCREMENT)
    // การเพิ่ม PRIMARY KEY จะล้มกลางคัน ต้องตรวจแล้วบอกให้ผู้ดูแลแก้เอง
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_action_log`
         GROUP BY `id` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">action_log: มีค่าซ้ำใน (id) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังเพิ่ม PRIMARY KEY ไม่ได้ '
            .'กรุณาแก้ให้ไม่ซ้ำแล้วกดปรับรุ่นอีกครั้ง — ตัวปรับรุ่นจะไม่ลบให้เอง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_action_log` ADD PRIMARY KEY (`id`)");
        $content[] = '<li class="correct">action_log: เพิ่ม PRIMARY KEY</li>';
    }
}
if (ensureAutoIncrement($db, $prefix.'_action_log', 'id', 'int(11)')) {
    $content[] = '<li class="correct">action_log: กำหนด id เป็น AUTO_INCREMENT</li>';
}
// action_log
foreach ([
    'item_id' => ['int(11)', false, '0', '', ''],
    'source_id' => ['int(11)', false, '0', 'item_id', ''],
    'action' => ['varchar(32)', false, '', 'source_id', ''],
    'params_json' => ['text', true, null, 'action', ''],
    'idempotency_key' => ['char(36)', false, '', 'params_json', ''],
    'http_code' => ['smallint(6)', false, '0', 'idempotency_key', ''],
    'response_json' => ['text', true, null, 'http_code', ''],
    'origin' => ['varchar(16)', false, 'web', 'response_json', 'web | line | telegram'],
    'member_id' => ['int(11)', false, '0', 'origin', ''],
    'created_at' => ['datetime', false, null, 'member_id', '']
] as $_col => $_def) {
    if (ensureColumn($db, $prefix.'_action_log', $_col, $_def[0], $_def[1], $_def[2], $_def[4], $_def[3])) {
        $content[] = '<li class="correct">action_log: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if (ensureIndexes($db, $prefix.'_action_log', [
    'item' => '`item_id`, `created_at`'
])) {
    $content[] = '<li class="correct">action_log: ปรับดัชนี</li>';
}
// action_log — ดัชนี UNIQUE
//
// ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน คำสั่งจะล้ม ตัวปรับรุ่นต้องตรวจแล้วบอกให้ผู้ดูแล
// แก้เอง ห้ามลบแถวที่ซ้ำให้เอง — ข้อมูลของผู้ใช้ไม่ใช่ของเราที่จะทิ้ง
if (!$db->indexExists($prefix.'_action_log', 'idempotency_key')) {
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_action_log`
         GROUP BY `idempotency_key` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">action_log: มีค่าซ้ำใน (`idempotency_key`) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังสร้างดัชนี idempotency_key แบบ UNIQUE ไม่ได้ '
            .'กรุณาแก้ให้เหลือชุดเดียวแล้วกดปรับรุ่นอีกครั้ง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_action_log` ADD UNIQUE KEY `idempotency_key` (`idempotency_key`)");
        $content[] = '<li class="correct">action_log: เพิ่มดัชนี UNIQUE idempotency_key</li>';
    }
}

// chat_link — PRIMARY KEY และ AUTO_INCREMENT
//
// ⚠️ ตัวติดตั้งรุ่นเก่าประกาศคอลัมน์ id เป็น NOT NULL เฉย ๆ แล้วค่อยเติม
// PRIMARY KEY / AUTO_INCREMENT ด้วย ALTER TABLE ท้าย database.sql ซึ่ง
// ตัวปรับรุ่นไม่เคยรัน ไซต์ที่อัปเกรดจึงเพิ่มข้อมูลใหม่ไม่ได้เลย
if (!$db->indexExists($prefix.'_chat_link', 'PRIMARY')) {
    // ⚠️ ถ้าไซต์มีค่าซ้ำอยู่ก่อน (เช่น id เป็น 0 ทุกแถวเพราะไม่เคยมี AUTO_INCREMENT)
    // การเพิ่ม PRIMARY KEY จะล้มกลางคัน ต้องตรวจแล้วบอกให้ผู้ดูแลแก้เอง
    $_dup = $db->customQuery(
        "SELECT COUNT(*) AS `c` FROM (SELECT 1 FROM `".$prefix."_chat_link`
         GROUP BY `code` HAVING COUNT(*) > 1) `x`"
    );
    if (!empty($_dup) && (int) $_dup[0]->c > 0) {
        $content[] = '<li class="warning">chat_link: มีค่าซ้ำใน (code) อยู่ '
            .number_format((int) $_dup[0]->c).' ชุด จึงยังเพิ่ม PRIMARY KEY ไม่ได้ '
            .'กรุณาแก้ให้ไม่ซ้ำแล้วกดปรับรุ่นอีกครั้ง — ตัวปรับรุ่นจะไม่ลบให้เอง</li>';
    } else {
        $db->query("ALTER TABLE `".$prefix."_chat_link` ADD PRIMARY KEY (`code`)");
        $content[] = '<li class="correct">chat_link: เพิ่ม PRIMARY KEY</li>';
    }
}
// chat_link
foreach ([
    'code' => ['varchar(16)', false, null, '', ''],
    'member_id' => ['int(11)', false, '0', 'code', ''],
    'channel' => ['varchar(16)', false, '', 'member_id', 'ว่าง = ผูกได้ทุกช่องทาง'],
    'expires_at' => ['datetime', false, null, 'channel', ''],
    'created_at' => ['datetime', false, null, 'expires_at', '']
] as $_col => $_def) {
    if (ensureColumn($db, $prefix.'_chat_link', $_col, $_def[0], $_def[1], $_def[2], $_def[4], $_def[3])) {
        $content[] = '<li class="correct">chat_link: ปรับคอลัมน์ '.$_col.'</li>';
    }
}
if (ensureIndexes($db, $prefix.'_chat_link', [
    'member_id' => '`member_id`',
    'expires_at' => '`expires_at`'
])) {
    $content[] = '<li class="correct">chat_link: ปรับดัชนี</li>';
}

$content[] = '<li class="correct">timeline อัปเกรดสำเร็จ</li>';
