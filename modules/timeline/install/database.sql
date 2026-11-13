-- ---------------------------------------------------------------------------
-- modules/timeline/install/database.sql — ตารางที่โมดูล timeline เป็นเจ้าของ
--
-- **ประกาศที่นี่ที่เดียว** ห้ามประกาศซ้ำใน install/database.sql ของโปรเจ็ค
--
-- ⚠️ ก่อนมีไฟล์นี้ ตารางเหล่านี้ **ไม่ถูกประกาศไว้ที่ไหนเลย** ติดตั้งใหม่จึงได้
-- ไซต์ที่ขาดตารางของตัวเอง 10 ตาราง แล้วใช้งานไม่ได้ตั้งแต่เปิดหน้าแรก
-- สคีมาด้านล่างคัดมาจากฐานข้อมูลจริง (hub) ด้วย SHOW CREATE TABLE
-- ---------------------------------------------------------------------------

CREATE TABLE `{prefix}_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_id` int(11) NOT NULL DEFAULT 0,
  `uid` varchar(190) NOT NULL COMMENT 'ต้นทางเป็นคนตั้ง ต้องนิ่งตลอดอายุของเรื่อง',
  `kind` varchar(64) NOT NULL DEFAULT '',
  `origin` varchar(8) NOT NULL DEFAULT 'sync' COMMENT 'sync | push | local — reconciliation แตะเฉพาะ sync',
  `title` varchar(255) NOT NULL DEFAULT '',
  `subtitle` varchar(255) NOT NULL DEFAULT '',
  `body` text DEFAULT NULL,
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT 0,
  `tz` varchar(64) NOT NULL DEFAULT 'Asia/Bangkok',
  `status` varchar(16) NOT NULL DEFAULT 'active' COMMENT 'active | completed | cancelled | expired',
  `priority` varchar(8) NOT NULL DEFAULT 'normal' COMMENT 'low | normal | high | critical',
  `entity_type` varchar(64) NOT NULL DEFAULT '',
  `entity_id` varchar(190) NOT NULL DEFAULT '',
  `entity_label` varchar(190) NOT NULL DEFAULT '',
  `contact_json` text DEFAULT NULL,
  `links_json` text DEFAULT NULL,
  `actions_json` text DEFAULT NULL,
  `facts_json` text DEFAULT NULL COMMENT 'ข้อมูลสรุปที่ต้นทางจัดรูปมาแล้ว พร้อมแสดงบนการ์ด',
  `meta_json` text DEFAULT NULL,
  `hint_json` text DEFAULT NULL,
  `payload_hash` char(40) NOT NULL DEFAULT '' COMMENT 'ตอบว่าเปลี่ยนจริงไหม โดยไม่ต้องเทียบทีละฟิลด์',
  `first_seen_at` datetime NOT NULL,
  `last_seen_run` int(11) NOT NULL DEFAULT 0,
  `vanished_at` datetime DEFAULT NULL COMMENT 'หายจาก snapshot แล้ว รอลบจริงหลัง 30 วัน',
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_uid` (`source_id`,`uid`),
  KEY `due_at` (`due_at`),
  KEY `attention` (`status`,`vanished_at`,`due_at`),
  KEY `entity` (`entity_id`)
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;

CREATE TABLE `{prefix}_item_state` (
  `item_id` int(11) NOT NULL,
  `state` varchar(16) NOT NULL DEFAULT 'none' COMMENT 'none | done | dismissed | snoozed',
  `snooze_until` datetime DEFAULT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`item_id`),
  KEY `state` (`state`,`snooze_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;

CREATE TABLE `{prefix}_sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(32) NOT NULL COMMENT 'ต้องตรงกับ source.slug ใน manifest',
  `name` varchar(100) NOT NULL DEFAULT '',
  `base_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'ถึงระดับที่ต่อท้ายด้วย /timeline/... ได้',
  `home_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'หน้าแรกของระบบต้นทาง จาก manifest',
  `token` varchar(255) NOT NULL DEFAULT '',
  `timezone` varchar(64) NOT NULL DEFAULT 'Asia/Bangkok',
  `interval_min` smallint(6) NOT NULL DEFAULT 360,
  `horizon_past` varchar(16) NOT NULL DEFAULT 'P90D',
  `horizon_future` varchar(16) NOT NULL DEFAULT 'P12M',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `protocol_version` varchar(16) NOT NULL DEFAULT '',
  `manifest_json` text DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL COMMENT 'ครั้งล่าสุดที่พยายาม',
  `last_ok_at` datetime DEFAULT NULL COMMENT 'ครั้งล่าสุดที่สำเร็จ — ตัวที่ใช้บอกผู้ใช้ว่าข้อมูลเก่าแค่ไหน',
  `last_status` varchar(16) NOT NULL DEFAULT '' COMMENT 'ok | partial | failed',
  `last_error` varchar(255) NOT NULL DEFAULT '',
  `fail_count` smallint(6) NOT NULL DEFAULT 0,
  `paused_until` datetime DEFAULT NULL,
  `sort` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `enabled` (`enabled`,`last_sync_at`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;

CREATE TABLE `{prefix}_sync_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_id` int(11) NOT NULL DEFAULT 0,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'running' COMMENT 'running | ok | partial | failed',
  `items_seen` int(11) NOT NULL DEFAULT 0,
  `items_changed` int(11) NOT NULL DEFAULT 0,
  `items_vanished` int(11) NOT NULL DEFAULT 0,
  `http_code` smallint(6) NOT NULL DEFAULT 0,
  `error` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `source` (`source_id`,`started_at`)
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;

CREATE TABLE `{prefix}_reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL DEFAULT 0,
  `offset_key` varchar(32) NOT NULL DEFAULT '' COMMENT 'เช่น P7D หรือ overdue:3',
  `fire_at` datetime NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `channel` varchar(16) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending | sent | skipped | failed',
  `error` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_offset` (`item_id`,`offset_key`),
  KEY `due` (`status`,`fire_at`)
) ENGINE=InnoDB AUTO_INCREMENT=245 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;

CREATE TABLE `{prefix}_reminder_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_id` int(11) NOT NULL DEFAULT 0 COMMENT '0 = ใช้กับทุกระบบต้นทาง',
  `kind` varchar(64) NOT NULL COMMENT 'ชื่อเต็ม หรือ prefix ตามด้วย .* ',
  `offsets_json` text DEFAULT NULL COMMENT 'เช่น ["P30D","P7D","P1D","PT0S"]',
  `channels_json` text DEFAULT NULL COMMENT 'เช่น ["telegram","line"]',
  `max_repeat` smallint(6) NOT NULL DEFAULT 0 COMMENT 'เพดานการเตือนซ้ำรายวัน 0 = ไม่ซ้ำ',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `visible` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'แสดงบนหน้ากระดานไหม',
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_kind` (`source_id`,`kind`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;

CREATE TABLE `{prefix}_action_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL DEFAULT 0,
  `source_id` int(11) NOT NULL DEFAULT 0,
  `action` varchar(32) NOT NULL DEFAULT '',
  `params_json` text DEFAULT NULL,
  `idempotency_key` char(36) NOT NULL DEFAULT '',
  `http_code` smallint(6) NOT NULL DEFAULT 0,
  `response_json` text DEFAULT NULL,
  `origin` varchar(16) NOT NULL DEFAULT 'web' COMMENT 'web | line | telegram',
  `member_id` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `item` (`item_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;

CREATE TABLE `{prefix}_chat_link` (
  `code` varchar(16) NOT NULL,
  `member_id` int(11) NOT NULL DEFAULT 0,
  `channel` varchar(16) NOT NULL DEFAULT '' COMMENT 'ว่าง = ผูกได้ทุกช่องทาง',
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`code`),
  KEY `member_id` (`member_id`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;

