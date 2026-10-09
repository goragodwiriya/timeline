<?php
/**
 * @filesource Gcms/Chat/Orchestrator.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

use Gcms\Ai;

/**
 * Channel-agnostic chat orchestrator.
 *
 * The orchestrator first tries registered tools, then falls back to AI with
 * strict instructions so future module actions must come from tools instead of
 * prompt-only assumptions.
 *
 * @since 1.0
 */
class Orchestrator extends \Kotchasan\KBase
{
    /**
     * ชื่อภาษาที่ส่งให้โมเดล — คีย์คือรหัสภาษาของระบบ
     *
     * โมเดลเข้าใจชื่อภาษาเป็นภาษาอังกฤษดีที่สุด การส่งรหัส "th" ไปตรง ๆ
     * ได้ผลไม่แน่นอน
     */
    const LANGUAGES = ['th' => 'Thai', 'en' => 'English'];

    /**
     * @var ToolRegistry
     */
    private $tools;

    /**
     * @var ChannelRegistry
     */
    private $channels;

    /**
     * @var SettingsRepository
     */
    private $settings;

    /**
     * @var QuickAnswerRepository
     */
    private $quickAnswers;

    /**
     * @param ToolRegistry|null    $tools
     * @param ChannelRegistry|null $channels
     */
    public function __construct(?ToolRegistry $tools = null, ?ChannelRegistry $channels = null, ?SettingsRepository $settings = null, ?QuickAnswerRepository $quickAnswers = null)
    {
        $this->tools = $tools ?: ToolRegistry::defaults();
        $this->channels = $channels ?: ChannelRegistry::defaults();
        $this->settings = $settings ?: new SettingsRepository();
        $this->quickAnswers = $quickAnswers ?: new QuickAnswerRepository();
    }

    /**
     * @return ToolRegistry
     */
    public function tools()
    {
        return $this->tools;
    }

    /**
     * @return ChannelRegistry
     */
    public function channels()
    {
        return $this->channels;
    }

    /**
     * Process one normalized message.
     *
     * @param Message $message
     *
     * @return Response
     */
    public function handle(Message $message)
    {
        ChatInputNormalizer::normalizeIncoming($message);

        // เรื่องที่ส่งต่อเจ้าหน้าที่ไว้ยังไม่ปิด = ข้อความถัดไปคือคำอธิบายเพิ่ม
        // **ยกเว้นคำสั่ง** ซึ่งต้องทำงานได้เสมอ
        //
        // เดิมคว้าทุกอย่าง · เรื่องที่เปิดค้างไว้เรื่องเดียวทำให้ทั้งห้องนั้นตอบ
        // "บันทึกข้อความถึงเจ้าหน้าที่แล้ว" กับทุกข้อความไปตลอดกาล รวมถึงปุ่มที่
        // กดจากเมนู · ไม่มีทางออกจากสถานะนี้เลยนอกจากปิดเรื่องในฐานข้อมูล
        $handoffStore = new HandoffStore();
        $activeHandoff = HandoffFollowUp::activeHandoff($message, $handoffStore);
        if ($activeHandoff !== null && !self::isCommand($message->text)) {
            ChatInputNormalizer::stripContactCommand($message);

            return HandoffFollowUp::respond($message, $activeHandoff, $handoffStore);
        }

        $tool = $this->tools->match($message);
        if ($tool !== null) {
            return $tool->handle($message);
        }

        // คำสั่งที่ขึ้นต้นด้วย "/" แต่ไม่มีเครื่องมือไหนรับ
        //
        // ปล่อยให้ตกไปหา AI ไม่ได้ผล — AI ไม่รู้จักคำสั่งของระบบ จึงตอบเลี่ยง ๆ
        // หรือเงียบไปเลยเมื่อ AI ปิดอยู่ · ผู้ใช้ที่กดจากเมนู "/" ของ Telegram
        // แล้วไม่มีอะไรเกิดขึ้นจะไม่มีทางรู้ว่าพิมพ์ผิดหรือระบบพัง
        if (isset($message->text[0]) && $message->text[0] === '/') {
            return $this->unknownCommand($message);
        }

        return $this->aiFallback($message);
    }

    /**
     * ข้อความนี้เป็นคำสั่งของระบบหรือปุ่ม ไม่ใช่บทสนทนาธรรมดา
     *
     * ใช้ตัดสินว่าสถานะที่ค้างอยู่ (เรื่องที่ส่งต่อ ตัวช่วยกรอกทีละขั้น) ควรปล่อยผ่าน
     * — คำสั่งต้องทำงานได้เสมอ ไม่งั้นผู้ใช้ติดอยู่ในสถานะนั้นโดยไม่มีทางออก
     *
     * @param string $text
     *
     * @return bool
     */
    public static function isCommand($text)
    {
        $text = ChatCommandCatalog::normalizeText($text);
        if ($text === '') {
            return false;
        }

        // ปุ่มส่งค่ากลับมาเป็นข้อความขึ้นต้นด้วย tl:
        return $text[0] === '/' || strpos($text, 'tl:') === 0;
    }

    /**
     * ตอบคำสั่งที่ไม่รู้จักด้วยรายการคำสั่งที่มีจริง
     *
     * @param Message $message
     *
     * @return Response
     */
    private function unknownCommand(Message $message)
    {
        $command = preg_split('/\s+/u', trim($message->text))[0];
        $lines = ChatCommandCatalog::helpBodyLines();
        $text = \Kotchasan\Language::get('AI chat unknown command', 'ไม่รู้จักคำสั่ง :command');
        $text = str_replace(':command', $command, $text);
        if ($lines !== []) {
            $text .= "\n\n".implode("\n", $lines);
        }

        return Response::text(
            $text,
            $message,
            'unknown-command',
            [],
            [],
            ChatCommandCatalog::helpPromptActions()
        );
    }

    /**
     * AI fallback for requests that are not matched by a concrete tool.
     *
     * @param Message $message
     *
     * @return Response
     */
    private function aiFallback(Message $message)
    {
        $connector = $this->settings->connector();
        if (empty($connector['ai_enabled'])) {
            return $this->fallbackHelp($message, $this->settings->formatMessage('ai_disabled_message'));
        }

        $connection = Ai::connectionSettings($connector['ai_provider'] ?? null);

        try {
            $response = Ai::driver()->chat(
                $this->buildMessages($message),
                [
                    'system' => $this->systemPrompt(),
                    'max_tokens' => isset($connection['max_tokens']) ? (int) $connection['max_tokens'] : 1024,
                    'temperature' => isset($connection['temperature']) ? (float) $connection['temperature'] : 0.4
                ]
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fallbackHelp($message, $e->getMessage());
        } catch (\Kotchasan\ApiException $e) {
            return $this->fallbackHelp($message, $e->getMessage());
        } catch (\Exception $e) {
            return $this->fallbackHelp($message, $this->settings->formatMessage('ai_unavailable_message'));
        }

        if (!$response->success || trim((string) $response->content) === '') {
            return $this->fallbackHelp($message, $this->settings->formatMessage('ai_empty_response_message'));
        }

        return Response::text(
            trim((string) $response->content),
            $message,
            'ai-fallback',
            [
                'model' => $response->model,
                'tokens' => (int) $response->inputTokens + (int) $response->outputTokens,
                'channels' => $this->channels->definitions(),
                'tools' => $this->tools->definitions()
            ]
        );
    }

    /**
     * Build a safe prompt that keeps future actions behind tools.
     *
     * @return string
     */
    private function systemPrompt()
    {
        $commands = [];
        foreach (ChatCommandCatalog::all() as $row) {
            $command = (string) ($row['command'] ?? '');
            if ($command === '') {
                continue;
            }
            $desc = trim((string) ($row['description'] ?? ''));
            $commands[] = '- '.$command.($desc === '' ? '' : ' — '.$desc);
        }

        // บังคับภาษาให้ตรงกับภาษาของระบบเสมอ
        //
        // เดิมสั่งแค่ "ตอบเป็นภาษาของผู้ใช้" ซึ่งเดาไม่ออกเมื่อข้อความสั้น เป็น
        // อิโมจิ หรือเป็นป้ายปุ่ม · โมเดลจึงเลือกภาษาเองแบบสุ่ม และเคยตอบเป็น
        // ภาษาอาหรับมาแล้วทั้งที่ทั้งระบบเป็นภาษาไทย
        $language = self::LANGUAGES[\Kotchasan\Language::name()] ?? 'Thai';

        // คำบรรยายว่า "ระบบนี้คืออะไร" เป็นของแต่ละแอป ไม่ใช่ของเฟรมเวิร์ก
        //
        // ประกาศ $cfg->ai_chat_persona เพื่อทับ · ค่าปริยายเขียนไว้กลาง ๆ ให้แอป
        // ที่ยังไม่ได้ตั้งค่ายังตอบได้อย่างไม่เสียหาย
        $persona = trim((string) (self::$cfg->ai_chat_persona ?? ''));
        if ($persona === '') {
            $persona = "You are the assistant inside a web application.\n"
                ."You cannot see its data and you cannot perform any action yourself.";
        }

        $prompt = $persona."\n"
        ."\n"
        ."ALWAYS reply in {$language}, whatever language the incoming message appears to be in.\n"
        ."Keep it to two or three sentences.\n"
        ."\n"
        ."Rules:\n"
        ."- Never claim anything was saved, sent, paid, booked or looked up. You cannot do any of that.\n"
        ."  Only the commands below touch data, and they run before you are ever called.\n"
        ."- Never invent items, amounts, dates, names or records. You have not seen the data.\n"
        ."- When the request matches a command, name that command and stop. Do not explain the system.\n"
        ."- When nothing matches, say so plainly in one line and suggest the closest command.\n"
        ."- Never describe yourself as a chatbot, never list channels, and never list your internal tools.\n"
        ."\n"
        ."Commands the user can type:\n"
        .implode("\n", $commands)."\n";

        $quickAnswers = $this->quickAnswers->aiContext(8);
        if (!empty($quickAnswers)) {
            $prompt .= "\nConfigured quick answers:\n";
            foreach ($quickAnswers as $item) {
                $line = '- '.($item['title'] !== '' ? $item['title'] : 'Quick answer');
                if (!empty($item['keywords'])) {
                    $line .= ' | keywords: '.implode(', ', $item['keywords']);
                }
                if (!empty($item['answer_text'])) {
                    $line .= ' | answer: '.str_replace(["\r", "\n"], ' ', $item['answer_text']);
                }
                $prompt .= $line."\n";
            }
            $prompt .= "Use these as site-specific guidance when relevant.\n";
        }

        return $prompt;
    }

    /**
     * Build the AI message list from history plus the latest user input.
     *
     * @param Message $message
     *
     * @return array
     */
    private function buildMessages(Message $message)
    {
        $messages = [];
        foreach ($message->history as $item) {
            $messages[] = [
                'role' => $item['role'],
                'content' => $item['content']
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => trim($message->text)
        ];

        return $messages;
    }

    /**
     * Fallback summary when AI is unavailable.
     *
     * @param Message $message
     * @param string  $reason
     *
     * @return Response
     */
    private function fallbackHelp(Message $message, $reason)
    {
        // ตอบเป็นรายการคำสั่งจริง ไม่ใช่คำอธิบายสถาปัตยกรรม
        //
        // ข้อความปริยายเดิมพูดถึง "shared core", "registry" และรายชื่อช่องทาง
        // ซึ่งเป็นภาษาของคนเขียนโปรแกรม ไม่ใช่คำตอบของคำถามที่ผู้ใช้เพิ่งถาม
        $text = trim((string) $reason);
        $help = trim((string) $this->settings->formatMessage('fallback_help_message'));
        if ($help === '') {
            $help = \Kotchasan\Language::get('AI chat fallback help', 'พิมพ์คำสั่งด้านล่างได้เลย');
        }
        if ($text !== '' && $help !== '') {
            $text .= ' ';
        }
        $text .= $help;

        $lines = ChatCommandCatalog::helpBodyLines();
        if ($lines !== []) {
            $text .= "\n\n".implode("\n", $lines);
        }

        return Response::text(
            $text,
            $message,
            'fallback-help',
            [],
            [],
            ChatCommandCatalog::helpPromptActions()
        );
    }
}
