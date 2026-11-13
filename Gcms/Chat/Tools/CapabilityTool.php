<?php
/**
 * @filesource Gcms/Chat/Tools/CapabilityTool.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat\Tools;

use Gcms\Chat\ChatCommandCatalog;
use Gcms\Chat\Message;
use Gcms\Chat\Response;
use Gcms\Chat\SettingsRepository;
use Gcms\Chat\ToolInterface;
use Kotchasan\Language;

/**
 * Explain current capabilities and extension model.
 *
 * @since 1.0
 */
class CapabilityTool implements ToolInterface
{
    /**
     * @return string
     */
    public function name()
    {
        return 'capabilities';
    }

    /**
     * @return string
     */
    public function description()
    {
        return 'Explain available channels, extension model, and next integration steps';
    }

    /**
     * @param Message $message
     *
     * @return bool
     */
    public function supports(Message $message)
    {
        $t = ChatCommandCatalog::normalizeText($message->text);
        if ($t !== '' && isset($t[0]) && $t[0] === '/') {
            return false;
        }

        return preg_match('/(help|capability|capabilities|what can|menu|feature|ช่วยอะไรได้บ้าง|ทำอะไรได้บ้าง|ความสามารถ|เมนู|ฟีเจอร์)/iu', $message->text) === 1;
    }

    /**
     * @param Message $message
     *
     * @return Response
     */
    public function handle(Message $message)
    {
        $settings = new SettingsRepository();
        $text = $settings->formatMessage('capability_message');
        if ($text === '') {
            $text = Language::get(
                'AI chat capability body',
                'ดูสิ่งที่ต้องสนใจ /today · ที่เกินกำหนด /overdue · นัดหมาย /appts · ว่างไหม /free · ค้นหา /find ตามด้วยชื่อ · ปุ่มลัดทั้งหมด /menu'
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