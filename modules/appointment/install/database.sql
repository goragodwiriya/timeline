-- ---------------------------------------------------------------------------
-- modules/appointment/install/database.sql — ตารางที่โมดูล appointment เป็นเจ้าของ
--
-- **ประกาศที่นี่ที่เดียว** ห้ามประกาศซ้ำใน install/database.sql ของโปรเจ็ค
--
-- ⚠️ ก่อนมีไฟล์นี้ ตารางเหล่านี้ **ไม่ถูกประกาศไว้ที่ไหนเลย** ติดตั้งใหม่จึงได้
-- ไซต์ที่ขาดตารางของตัวเอง 10 ตาราง แล้วใช้งานไม่ได้ตั้งแต่เปิดหน้าแรก
-- สคีมาด้านล่างคัดมาจากฐานข้อมูลจริง (hub) ด้วย SHOW CREATE TABLE
-- ---------------------------------------------------------------------------

CREATE TABLE `{prefix}_appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL DEFAULT '',
  `detail` text DEFAULT NULL,
  `location` varchar(255) NOT NULL DEFAULT '',
  `start_at` datetime NOT NULL,
  `end_at` datetime DEFAULT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'active' COMMENT 'active | completed | cancelled',
  `priority` varchar(8) NOT NULL DEFAULT 'normal',
  `series_id` int(11) NOT NULL DEFAULT 0 COMMENT 'ชุดของนัดที่เกิดซ้ำ กางเป็นรายครั้งไว้แล้ว',
  `remind_json` text DEFAULT NULL COMMENT 'ทับกฎปริยายของ kind appointment',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `start_at` (`start_at`,`status`),
  KEY `series` (`series_id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
;

