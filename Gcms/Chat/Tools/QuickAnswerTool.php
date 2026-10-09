<?php
/**
 * @filesource Gcms/Chat/Tools/QuickAnswerTool.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat\Tools;

use Gcms\Chat\ChatCommandCatalog;
use Gcms\Chat\Message;
use Gcms\Chat\QuickAnswerRepository;
use Gcms\Chat\Response;
use Gcms\Chat\ToolInterface;
use Gcms\Search\SearchIntent;

/**
 * Answer configured quick FAQs from DB settings.
 *
 * @since 1.0
 */
class QuickAnswerTool implements ToolInterface
{
    /**
     * @var QuickAnswerRepository
     */
    private $answers;

    /**
     * @var array|null
     */
    private $matched;

    /**
     * @param QuickAnswerRepository|null $answers
     */
    public function __construct(?QuickAnswerRepository $answers = null)
    {
        $this->answers = $answers ?: new QuickAnswerRepository();
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'quick-answers';
    }

    /**
     * @return string
     */
    public function description()
    {
        return 'Answer configured quick FAQs and common questions';
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
            $this->matched = null;

            return false;
        }

        // DocumentDetailTool::messageLooksLikeDetailIntent() เคยถูกเรียกร่วมตรงนี้
        // ด้วย แต่ Hub ไม่มีโมดูล document จึงไม่ได้นำคลาสนั้นมา — เรียกไปก็เป็น
        // fatal ทันทีที่ข้อความใดตกมาถึงเครื่องมือนี้ ซึ่งคือข้อความทั่วไปทุกข้อความ
        if (SearchIntent::matches($message->text)) {
            $this->matched = null;

            return false;
        }

        $this->matched = $this->answers->matchText($message->text);

        return $this->matched !== null;
    }

    /**
     * @param Message $message
     *
     * @return Response
     */
    public function handle(Message $message)
    {
        $item = $this->matched ?: $this->answers->matchText($message->text);
        if ($item === null) {
            return Response::text('', $message, $this->name());
        }

        return Response::text(
            $item['answer_text'],
            $message,
            $this->name(),
            [
                'quick_answer' => [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'match_mode' => $item['match_mode']
                ]
            ],
            [],
            []
        );
    }
}