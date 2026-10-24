<?php
/**
 * @filesource Gcms/Chat/Tools/EscalationTool.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat\Tools;

use Gcms\Chat\ChatCommandCatalog;
use Gcms\Chat\ChatInputNormalizer;
use Gcms\Chat\HandoffFollowUp;
use Gcms\Chat\HandoffNotifier;
use Gcms\Chat\HandoffStore;
use Gcms\Chat\Message;
use Gcms\Chat\Response;
use Gcms\Chat\SettingsRepository;
use Gcms\Chat\ToolInterface;
use Kotchasan\Language;

/**
 * Handoff / human contact guidance.
 *
 * @since 1.0
 */
class EscalationTool implements ToolInterface
{
    /**
     * @var HandoffStore
     */
    private $handoffs;

    /**
     * @var HandoffNotifier
     */
    private $notifier;

    /**
     * @param HandoffStore|null $handoffs
     * @param HandoffNotifier|null $notifier
     */
    public function __construct(?HandoffStore $handoffs = null, ?HandoffNotifier $notifier = null)
    {
        $this->handoffs = $handoffs ?: new HandoffStore();
        $this->notifier = $notifier ?: new HandoffNotifier();
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'escalation';
    }

    /**
     * @return string
     */
    public function description()
    {
        return 'Handle requests to talk to a human or escalate the case';
    }

    /**
     * @param Message $message
     *
     * @return bool
     */
    public function supports(Message $message)
    {
        if (ChatCommandCatalog::messageMatchesCommand($message->text, 'contact')) {
            return true;
        }

        return preg_match('/(agent|staff|human|support|เจ้าหน้าที่|แอดมิน|คนจริง|ติดต่อคน|ส่งต่อ)/iu', $message->text) === 1;
    }

    /**
     * @param Message $message
     *
     * @return Response
     */
    public function handle(Message $message)
    {
        if ($message->conversationId === '') {
            $message->conversationId = $this->newConversationId();
        }

        $existing = $this->handoffs->latestByConversation($message->conversationId);
        if (HandoffFollowUp::isActive($existing)) {
            return HandoffFollowUp::respond($message, $existing, $this->handoffs);
        }

        if (ChatCommandCatalog::messageMatchesCommand($message->text, 'contact')) {
            ChatInputNormalizer::stripContactCommand($message);
        }

        $handoff = $this->handoffs->create($message);
        try {
            $notifications = $this->notifier->notifyNew($handoff);
            $stored = $this->handoffs->updateNotifications($handoff['id'], $notifications);
            if (is_array($stored)) {
                $handoff = $stored;
            }
        } catch (\Exception $e) {
        }

        $settings = new SettingsRepository();
        $text = $settings->formatMessage('escalation_created_message', [
            'id' => $handoff['id'] ?? '',
            'status' => $handoff['status'] ?? 'open',
            'channel' => $handoff['channel'] ?? $message->channel,
            'requester' => $this->requesterText($handoff),
            'message' => $handoff['message'] ?? $message->text
        ]);
        if ($text === '') {
            $text = str_replace(
                ['{ID}', '{STATUS}', '{CHANNEL}', '{REQUESTER}'],
                [
                    (string) ($handoff['id'] ?? ''),
                    (string) ($handoff['status'] ?? 'open'),
                    (string) ($handoff['channel'] ?? $message->channel),
                    $this->requesterText($handoff)
                ],
                Language::get(
                    'AI chat escalation created',
                    'Your request was sent to staff (ID #{ID}). They can see your latest message and context. You can type more in this chat if needed.'
                )
            );
        }

        return Response::text(
            $text,
            $message,
            $this->name(),
            [
                'handoff' => [
                    'id' => $handoff['id'],
                    'status' => $handoff['status']
                ]
            ],
            [],
            []
        );
    }

    /**
     * @param array $handoff
     *
     * @return string
     */
    private function requesterText(array $handoff): string
    {
        $user = isset($handoff['user']) && is_array($handoff['user']) ? $handoff['user'] : [];
        foreach (['name', 'email', 'phone', 'username'] as $field) {
            $value = trim((string) ($user[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Guest';
    }

    /**
     * @return string
     */
    private function newConversationId()
    {
        try {
            return 'chat-'.bin2hex(random_bytes(8));
        } catch (\Exception $e) {
            return 'chat-'.str_replace('.', '', uniqid('', true));
        }
    }
}