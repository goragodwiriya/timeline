<?php
/**
 * @filesource bin/register-telegram-commands.php
 *
 * ลงทะเบียนรายการคำสั่งที่ Telegram แสดงเมื่อผู้ใช้พิมพ์ "/"
 *
 * รันเมื่อรายการคำสั่งเปลี่ยน — ไม่ต้องรันทุกครั้งที่ deploy เพราะ Telegram
 * เก็บรายการไว้ที่ฝั่งมันเอง
 *
 *   php bin/register-telegram-commands.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

if (PHP_SAPI !== 'cli') {
    exit("รันได้จากบรรทัดคำสั่งเท่านั้น\n");
}

include dirname(__DIR__).'/load.php';
$app = Kotchasan::createWebApplication('Gcms\Config');

/*
 * อ่านจาก $cfg->chat_commands ที่เดียว — ถ้าแยกรายการไว้ตรงนี้อีกชุด เมนู "/"
 * ของ Telegram กับ /help ในแชตจะค่อย ๆ เพี้ยนออกจากกันโดยไม่มีอะไรฟ้อง
 *
 * ชื่อคำสั่งต้องเป็น a-z 0-9 _ ตามกติกาของ Telegram — คำสั่งที่ชื่อเป็นภาษาไทย
 * ใช้ในเมนูนี้ไม่ได้ แม้ตัวเครื่องมือจะรับข้อความที่พิมพ์เองได้ก็ตาม
 */
$commands = [];
foreach (\Gcms\Chat\ChatCommandCatalog::all() as $row) {
    $name = ltrim((string) ($row['command'] ?? ''), '/');
    $description = trim((string) ($row['description'] ?? ''));
    if ($name === '' || $description === '' || !preg_match('/^[a-z0-9_]{1,32}$/', $name)) {
        continue;
    }
    $commands[] = [
        'command' => $name,
        'description' => mb_substr($description, 0, 256)
    ];
}

if (empty($commands)) {
    exit("ไม่มีคำสั่งที่ลงทะเบียนได้ — ตรวจ \$cfg->chat_commands\n");
}

$error = \Gcms\Telegram::setMyCommands($commands);

if ($error !== '') {
    echo "ล้มเหลว: {$error}\n";
    exit(1);
}

echo 'ลงทะเบียน '.count($commands)." คำสั่งแล้ว\n";
foreach ($commands as $one) {
    echo '  /'.str_pad($one['command'], 10).$one['description']."\n";
}
