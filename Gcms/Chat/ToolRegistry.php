<?php
/**
 * @filesource Gcms/Chat/ToolRegistry.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

use \Gcms\Chat\Tools\CapabilityTool;
use \Gcms\Chat\Tools\GreetingTool;
use \Gcms\Chat\Tools\QuickAnswerTool;

/**
 * Registry for built-in and future module-defined tools.
 *
 * @since 1.0
 */
class ToolRegistry
{
    /**
     * Registered tools.
     *
     * @var ToolInterface[]
     */
    private $tools = [];

    /**
     * @param array $tools
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolInterface) {
                $this->register($tool);
            }
        }
    }

    /**
     * Register one tool.
     *
     * @param ToolInterface $tool
     *
     * @return $this
     */
    public function register(ToolInterface $tool)
    {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    /**
     * Find the first tool that supports this message.
     *
     * @param Message $message
     *
     * @return ToolInterface|null
     */
    public function match(Message $message)
    {
        foreach ($this->tools as $tool) {
            if ($tool->supports($message)) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Public metadata for all registered tools.
     *
     * @return array
     */
    public function definitions()
    {
        $items = [];
        foreach ($this->tools as $tool) {
            $items[] = [
                'name' => $tool->name(),
                'description' => $tool->description()
            ];
        }

        return $items;
    }

    /**
     * Default project-wide tools.
     *
     * เครื่องมือของ gcms_chat ที่ผูกกับโมดูล document/product ไม่ได้ถูกนำมาด้วย
     * เพราะ Hub ไม่มีโมดูลเหล่านั้น — ถ้าจะเพิ่มเครื่องมือใหม่ ให้ระวังว่าคลาสที่
     * มันเรียกถึงต้องมีอยู่จริงในโปรเจกต์นี้ ไม่ใช่แค่ใน gcms_chat
     *
     * @return self
     */
    public static function defaults()
    {
        return new self([
            new Tools\HelpTool(),
            new GreetingTool(),
            new CapabilityTool(),
            // EscalationTool ถูกถอดออก — Hub เป็นระบบส่วนตัวของคนเดียว ไม่มี
            // "เจ้าหน้าที่" อีกฝั่งให้ส่งต่อ · ที่แย่กว่าคือมันเปิดเรื่องค้างไว้
            // แล้ว HandoffFollowUp จะคว้าทุกข้อความในห้องนั้นตลอดไป ซึ่งเกิดขึ้น
            // จริงจากการกดปุ่ม /contact ที่หลุดมากับรายการคำสั่งปริยาย
            // ต้องมาก่อน TimelineTool — เป็นเครื่องมือเดียวที่ทำงานก่อนผูกบัญชี
            // ถ้าอยู่หลัง คนที่ยังไม่ผูกจะถูกปฏิเสธก่อนจะได้ผูก
            new Tools\LinkTool(),
            new Tools\AppointmentTool(),
            // ต้องมาก่อน QuickAnswerTool — ปุ่มจากข้อความเตือนมาถึงเป็นข้อความ
            // อย่าง `tl:done:12` ซึ่งไม่ควรถูกตัวตอบคำถามสำเร็จรูปคว้าไปก่อน
            new Tools\TimelineTool(),
            new QuickAnswerTool()
        ]);
    }
}