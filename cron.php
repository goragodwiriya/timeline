<?php
/**
 * @filesource cron.php
 *
 * จุดเข้าเดียวของงานตามเวลาทั้งหมดของ Hub
 *
 * ตั้งใน crontab ของเครื่องที่ออนไลน์ (ไม่ใช่เครื่องที่บ้าน):
 *
 *   *\/5 * * * * php /path/to/hub/cron.php >> /path/to/hub/datas/logs/cron.log 2>&1
 *
 * เรียกผ่านเว็บก็ได้เมื่อเครื่องไม่มี crontab ให้ใช้ ต้องแนบกุญแจ:
 *
 *   https://hub.example.com/cron.php?key=<cron_key ใน settings/config.php>
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

include 'load.php';

$app = Kotchasan::createWebApplication('Gcms\Config');
$cfg = Kotchasan\Config::create();
$cli = PHP_SAPI === 'cli';

if (!$cli) {
    header('Content-Type: text/plain; charset=UTF-8');
    $key = isset($_GET['key']) ? (string) $_GET['key'] : '';
    // hash_equals กันการเดากุญแจทีละตัวอักษรด้วยการจับเวลาตอบกลับ
    if (empty($cfg->cron_key) || !hash_equals((string) $cfg->cron_key, $key)) {
        http_response_code(403);
        exit("ไม่ได้รับอนุญาต\n");
    }
}

/*
 * รอบ cron ที่ทำงานนานกว่าช่วงห่างของ cron จะซ้อนกันเอง แล้ว sync ชุดเดียวกัน
 * พร้อมกันสองรอบ — จับล็อกไม่ได้ให้ออกทันที ไม่ใช่รอ เพราะรอบถัดไปมาอีกใน 5 นาที
 */
$lockFile = ROOT_PATH.DATA_FOLDER.'logs/cron.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s')." ข้าม — รอบก่อนหน้ายังทำงานอยู่\n";
    exit;
}

$started = microtime(true);
$log = function ($message) {
    echo date('Y-m-d H:i:s').' '.$message."\n";
};

try {
    // 1. ดึงข้อมูลจากระบบต้นทางที่ถึงรอบแล้ว
    $results = \Gcms\Timeline\SyncEngine::runDue();
    if (empty($results)) {
        $log('sync: ยังไม่ถึงรอบของระบบใด');
    }
    foreach ($results as $slug => $result) {
        $log(sprintf(
            'sync %-12s %-8s seen=%-4d changed=%-4d vanished=%-4d %s',
            $slug,
            $result['status'],
            $result['seen'],
            $result['changed'],
            $result['vanished'],
            $result['error'] ?: $result['warning']
        ));
    }

    // 1.5 นัดหมายที่ Hub เก็บเอง — เขียน item ให้ตรงกับความจริงปัจจุบัน
    //
    //     ปกติ LocalProvider เขียนตอนข้อมูลเปลี่ยนอยู่แล้ว ตรงนี้เป็นตาข่ายรับ
    //     กรณีที่แก้ข้อมูลด้วยวิธีอื่น และเป็นตัวที่ทำให้นัดที่เพิ่งผ่านไปเปลี่ยน
    //     สถานะบนจอโดยไม่ต้องรอให้ใครมาแตะ
    $local = \Gcms\Timeline\LocalProvider::publish();
    if ($local['changed'] > 0 || $local['removed'] > 0) {
        $log(sprintf('local: นัดหมาย %d รายการ · เปลี่ยน %d · ลบ %d', $local['seen'], $local['changed'], $local['removed']));
    }

    // 2. ตั้งนัดการเตือนใหม่ให้ item ที่เพิ่งเปลี่ยน แล้วส่งอันที่ถึงเวลา
    //
    //    arm ต้องมาหลัง sync เสมอ (ข้อมูลเปลี่ยน = นัดเปลี่ยน) ส่วน fire ต้องรัน
    //    ทุกรอบไม่ว่ารอบนั้นจะมี sync หรือไม่
    $armed = \Gcms\Timeline\ReminderEngine::arm();
    if ($armed > 0) {
        $log('reminder: ตั้งนัดใหม่ '.$armed.' รายการ');
    }

    $fired = \Gcms\Timeline\ReminderEngine::fire();
    if (array_sum($fired) > 0) {
        $log(sprintf('reminder: ส่ง %d · ข้าม %d · ล้มเหลว %d', $fired['sent'], $fired['skipped'], $fired['failed']));
    }

    // 3. ลบของที่หายไปนานเกินช่วงผ่อนผัน
    $purged = \Gcms\Timeline\Reconciler::purge();
    if ($purged > 0) {
        $log('purge: ลบ item ที่หายไปเกิน '.\Gcms\Timeline\Reconciler::GRACE_DAYS.' วัน '.$purged.' รายการ');
    }
} catch (\Throwable $e) {
    // cron ที่ตายเงียบคือ cron ที่ไม่มีใครรู้ว่าตาย
    $log('ผิดพลาด: '.$e->getMessage().' ('.$e->getFile().':'.$e->getLine().')');
    \Kotchasan\Logger::error('Hub cron ล้มเหลว', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

$log(sprintf('เสร็จใน %.2f วินาที', microtime(true) - $started));
