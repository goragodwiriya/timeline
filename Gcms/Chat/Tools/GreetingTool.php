<?php
/**
 * @filesource Gcms/Chat/Tools/GreetingTool.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat\Tools;

use Gcms\Chat\Message;
use Gcms\Chat\Response;
use Gcms\Chat\SettingsRepository;
use Gcms\Chat\ToolInterface;
use Kotchasan\Language;

/**
 * Small greeting / entry tool.
 *
 * @since 1.0
 */
class GreetingTool implements ToolInterface
{
    /**
     * @return string
     */
    public function name()
    {
        return 'greeting';
    }

    /**
     * @return string
     */
    public function description()
    {
        return 'Handle simple greetings and introduce the chat core';
    }

    /**
     * @param Message $message
     *
     * @return bool
     */
    public function supports(Message $message)
    {
        return preg_match('/^(hi|hello|hey|สวัสดี|หวัดดี|ดีครับ|ดีค่ะ)/iu', $message->text) === 1;
    }

    /**
     * @param Message $message
     *
     * @return Response
     */
    public function handle(Message $message)
    {
        $settings = new SettingsRepository();
        $text = $settings->formatMessage('welcome_message');
        if ($text === '') {
            $text = Language::get(
                'AI chat greeting default',
                'สวัสดี · พิมพ์ /menu เพื่อดูปุ่มลัด หรือ /help เพื่อดูคำสั่งทั้งหมด'
            );
        }

        return Response::text(
            $text,
            $message,
            $this->name(),
            [],
            [],
            []
        );
    }
}