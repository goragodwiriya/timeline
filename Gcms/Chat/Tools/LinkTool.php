<?php
/**
 * @filesource Gcms/Chat/Tools/LinkTool.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Chat\Tools;

use Gcms\Chat\Message;
use Gcms\Chat\Response;
use Gcms\Chat\ToolInterface;
use Gcms\Timeline\ChatLink;

/**
 * ผูกและถอนห้องแชตกับบัญชีผู้ใช้
 *
 * แยกจาก TimelineTool เพราะเป็นเครื่องมือเดียวที่ต้องทำงาน**ก่อน**การผูกบัญชี
 * ทุกอย่างที่เหลือปฏิเสธคนที่ยังไม่ผูก ซึ่งจะทำให้ไม่มีทางผูกได้เลยถ้าคำสั่งนี้
 * อยู่ในตัวเดียวกัน
 *
 * @since 1.0
 */
class LinkTool implements ToolInterface
{
    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function name()
    {
        return 'link';
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function description()
    {
        return 'ผูกห้องแชตนี้กับบัญชีผู้ใช้: /link <รหัส> · ถอนด้วย /unlink';
    }

    /**
     * {@inheritdoc}
     *
     * @param Message $message
     *
     * @return bool
     */
    public function supports(Message $message)
    {
        return $this->parse($message->text) !== null;
    }

    /**
     * {@inheritdoc}
     *
     * @param Message $message
     *
     * @return Response
     */
    public function handle(Message $message)
    {
        $intent = $this->parse($message->text);
        if ($intent === null) {
            return Response::text('ไม่เข้าใจคำสั่งนี้', $message, $this->name());
        }

        if ($intent['action'] === 'unlink') {
            return $this->unlink($message);
        }

        if ($intent['code'] === '') {
            return Response::text(
                'พิมพ์ /link ตามด้วยรหัสที่ได้จากหน้า "ผูกบัญชีแชต" ในระบบ เช่น /link ABCD2345',
                $message,
                $this->name()
            );
        }

        try {
            $result = ChatLink::redeem($intent['code'], $message->channel, $message->conversationId);
        } catch (\Throwable $e) {
            // ไม่บอกว่ารหัสผิดตรงไหนหรือมีอยู่จริงไหม — คำตอบเดียวกันทุกกรณี
            return Response::text('⚠️ '.$e->getMessage(), $message, $this->name());
        }

        return Response::text(
            '✅ ผูกกับบัญชี '.$result['name'].' เรียบร้อย'."\n".'พิมพ์ /today เพื่อดูว่าตอนนี้ต้องสนใจอะไรบ้าง',
            $message,
            $this->name()
        );
    }

    /**
     * @param Message $message
     *
     * @return Response
     */
    private function unlink(Message $message)
    {
        $channels = ChatLink::CHANNELS;
        if (!isset($channels[$message->channel])) {
            return Response::text('ช่องทางนี้ไม่ได้ผูกบัญชีไว้', $message, $this->name());
        }

        $column = $channels[$message->channel];
        $user = \Kotchasan\DB::create()->first('user', [[$column, (string) $message->conversationId]], ['id']);
        if ($user === null) {
            return Response::text('ห้องนี้ยังไม่ได้ผูกกับบัญชีใด', $message, $this->name());
        }

        ChatLink::revoke($message->channel, (int) $user->id);

        return Response::text('ถอนการผูกเรียบร้อย ห้องนี้จะไม่ได้รับข้อมูลอีก', $message, $this->name());
    }

    /**
     * @param string $text
     *
     * @return array|null
     */
    private function parse($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^\/?unlink$/i', $text)) {
            return ['action' => 'unlink', 'code' => ''];
        }
        if (preg_match('/^\/?link(?:\s+([A-Za-z0-9\- ]{0,32}))?$/i', $text, $m)) {
            return ['action' => 'link', 'code' => trim($m[1] ?? '')];
        }

        return null;
    }
}
