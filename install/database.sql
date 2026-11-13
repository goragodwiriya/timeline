-- -------------------------------------------------------------------------
-- install/database.sql — ตารางและข้อมูลตัวอย่างของ timeline
--
-- ตารางแกนของ Gcms (user, category, logs, login_attempt, number, user_meta,
-- user_session, language) อยู่ใน install/core.sql ซึ่งเหมือนกันทุกโปรเจ็ค
-- ไฟล์นี้เก็บเฉพาะสิ่งที่เป็นของโปรเจ็คนี้เท่านั้น
--
-- ข้อกำหนด: InnoDB + utf8mb4 และเขียน PRIMARY KEY/KEY ไว้ในคำสั่ง CREATE TABLE
-- เลย เพื่อให้ตารางที่ตัวปรับรุ่นสร้างจากไฟล์นี้ได้ index ครบตั้งแต่แรก
-- -------------------------------------------------------------------------

INSERT INTO `{prefix}_category` (`type`, `category_id`, `topic`, `color`, `is_active`) VALUES
('department', '1', 'บริหาร', NULL, 1),
('department', '2', 'จัดซื้อจัดจ้าง', NULL, 1),
('department', '3', 'บุคคล', NULL, 1);

-- -------------------------------------------------------------------------
-- ผนวกจาก install/chat.sql (ตัวติดตั้งเดิมโหลดไฟล์นี้แยกต่างหาก)
-- -------------------------------------------------------------------------
-- ---------------------------------------------------------------------------
-- ตารางของ Gcms\Chat (ยกมาจาก gcms_chat / gcms.in.th)
-- Hub ใช้คนเดียวจึงแทบไม่ได้ใช้ handoff แต่ Orchestrator เรียกถึงตารางนี้ตรง ๆ
-- จึงต้องมีอยู่ ไม่งั้นทุกข้อความที่เข้ามาจะพังตั้งแต่บรรทัดแรก
-- ---------------------------------------------------------------------------

CREATE TABLE `{prefix}_ai_chat_handoffs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `channel` varchar(20) NOT NULL,
  `conversation_id` varchar(100) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `user_id` int(11) DEFAULT NULL,
  `requester_name` varchar(150) DEFAULT NULL,
  `requester_email` varchar(255) DEFAULT NULL,
  `requester_phone` varchar(50) DEFAULT NULL,
  `requester_username` varchar(100) DEFAULT NULL,
  `message` mediumtext NOT NULL,
  `history_json` mediumtext DEFAULT NULL,
  `source_json` mediumtext DEFAULT NULL,
  `notifications_json` mediumtext DEFAULT NULL,
  `requester_notifications_json` mediumtext DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `accepted_by` int(11) DEFAULT NULL,
  `accepted_by_name` varchar(150) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `closed_by_name` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `channel` (`channel`),
  KEY `conversation_id` (`conversation_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `{prefix}_ai_chat_quick_answers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `keywords` mediumtext NOT NULL,
  `match_mode` varchar(20) NOT NULL DEFAULT 'contains',
  `answer_text` mediumtext NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `published` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `published_sort` (`published`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- chat_pending — คิวข้อความแชทที่รอประมวลผล (Gcms\Chat)
--
-- ⚠️ ก่อนหน้านี้ตารางนี้ไม่ถูกประกาศไว้ที่ไหนเลย ติดตั้งใหม่จึงไม่มีตารางนี้
-- สคีมาคัดมาจากฐานข้อมูลจริง (hub) ด้วย SHOW CREATE TABLE
--
-- อยู่ในไฟล์ของโปรเจ็คไม่ใช่ของโมดูล เพราะเจ้าของคือ Gcms/Chat ซึ่งเป็นโค้ด
-- ระดับโปรเจ็ค ไม่มีโมดูลไหนเป็นเจ้าของ (เช่นเดียวกับ ai_chat_* สองตารางข้างบน)
-- ---------------------------------------------------------------------------

CREATE TABLE `{prefix}_chat_pending` (
  `token` varchar(32) NOT NULL,
  `member_id` int(11) NOT NULL DEFAULT 0,
  `kind` varchar(32) NOT NULL DEFAULT '',
  `payload_json` text DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`token`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
