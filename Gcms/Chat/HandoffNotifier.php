<?php
/**
 * @filesource Gcms/Chat/HandoffNotifier.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

/**
 * Notify staff when a new AI handoff is created.
 *
 * @since 1.0
 */
class HandoffNotifier extends \Kotchasan\KBase
{
    /**
     * Send a requester-facing status update.
     *
     * @param array  $handoff
     * @param string $status
     *
     * @return array
     */
    public function notifyRequesterStatus(array $handoff, string $status)
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['accepted', 'closed'], true)) {
            return [
                'status' => 'skipped',
                'channel' => trim((string) ($handoff['channel'] ?? 'web')),
                'recipients' => 0,
                'message' => '',
                'error' => ''
            ];
        }

        $settings = new SettingsRepository();
        $message = $settings->formatMessage($status === 'accepted' ? 'handoff_accepted_message' : 'handoff_closed_message', [
            'id' => $handoff['id'] ?? '',
            'status' => $status,
            'channel' => $handoff['channel'] ?? '',
            'requester' => $this->requesterText($handoff),
            'message' => $handoff['message'] ?? ''
        ]);
        if ($message === '') {
            $message = $status === 'accepted'
                ? 'เจ้าหน้าที่รับเรื่องคำขอ #'.(int) ($handoff['id'] ?? 0).' แล้ว หากต้องการเพิ่มข้อมูล ให้ตอบกลับในช่องทางนี้ได้เลย'
                : 'เจ้าหน้าที่ปิดคำขอ #'.(int) ($handoff['id'] ?? 0).' แล้ว ขอบคุณที่ติดต่อเข้ามา หากยังต้องการความช่วยเหลือเพิ่มเติมสามารถส่งข้อความใหม่ได้';
        }

        $channel = strtolower(trim((string) ($handoff['channel'] ?? 'web')));
        if ($channel === 'web') {
            return [
                'status' => 'available',
                'channel' => 'web',
                'recipients' => 1,
                'message' => $message,
                'error' => ''
            ];
        }

        $target = $this->replyTarget($handoff);
        if ($target === '') {
            return [
                'status' => 'skipped',
                'channel' => $channel,
                'recipients' => 0,
                'message' => $message,
                'error' => ''
            ];
        }

        switch ($channel) {
        case 'line':
            $error = \Gcms\Line::sendTo($target, $message);

            return [
                'status' => $error === '' ? 'sent' : 'error',
                'channel' => 'line',
                'recipients' => 1,
                'message' => $message,
                'error' => trim((string) $error)
            ];

        case 'telegram':
            $error = \Gcms\Telegram::sendTo($target, $message);

            return [
                'status' => $error === '' ? 'sent' : 'error',
                'channel' => 'telegram',
                'recipients' => 1,
                'message' => $message,
                'error' => trim((string) $error)
            ];

        default:
            return [
                'status' => 'skipped',
                'channel' => $channel,
                'recipients' => 0,
                'message' => $message,
                'error' => ''
            ];
        }
    }

    /**
     * Send notifications for a new handoff.
     *
     * @param array $handoff
     *
     * @return array
     */
    public function notifyNew(array $handoff)
    {
        $recipients = $this->recipients();

        return [
            'email' => $this->notifyEmail($handoff, $recipients['emails']),
            'line' => $this->notifyLine($handoff, $recipients['line_uids']),
            'telegram' => $this->notifyTelegram($handoff, $recipients['telegram_ids'])
        ];
    }

    /**
     * @return array
     */
    private function recipients()
    {
        $emails = [];
        $lineUids = [];
        $telegramIds = [];

        $query = \Kotchasan\Model::createQuery()
            ->select('id', 'name', 'username', 'line_uid', 'telegram_id', 'status', 'permission')
            ->from('user')
            ->where(['active', 1]);

        foreach ($query->execute()->fetchAll() as $item) {
            if (!$this->shouldNotify($item)) {
                continue;
            }

            $email = trim((string) ($item->username ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = $email;
            }

            $lineUid = trim((string) ($item->line_uid ?? ''));
            if ($lineUid !== '') {
                $lineUids[$lineUid] = $lineUid;
            }

            $telegramId = trim((string) ($item->telegram_id ?? ''));
            if ($telegramId !== '') {
                $telegramIds[$telegramId] = $telegramId;
            }
        }

        $defaultTelegram = trim((string) (self::$cfg->telegram_chat_id ?? ''));
        if ($defaultTelegram !== '') {
            $telegramIds[$defaultTelegram] = $defaultTelegram;
        }

        return [
            'emails' => array_values($emails),
            'line_uids' => array_values($lineUids),
            'telegram_ids' => array_values($telegramIds)
        ];
    }

    /**
     * @param object $item
     *
     * @return bool
     */
    private function shouldNotify($item)
    {
        if ((int) ($item->status ?? 0) === 1) {
            return true;
        }

        return in_array('can_use_ai_chat', $this->permissions($item->permission ?? ''), true);
    }

    /**
     * @param mixed $permission
     *
     * @return array
     */
    private function permissions($permission)
    {
        if (is_array($permission)) {
            $items = $permission;
        } elseif (is_string($permission)) {
            $items = $permission === '' ? [] : explode(',', trim($permission, " \t\n\r\0\x0B,"));
        } else {
            $items = [];
        }

        $items = array_map('trim', $items);

        return array_values(array_filter($items, function ($item) {
            return $item !== '';
        }));
    }

    /**
     * @param array $handoff
     *
     * @return string
     */
    private function plainMessage(array $handoff)
    {
        $requester = $this->requesterText($handoff);
        $message = trim((string) ($handoff['message'] ?? ''));
        $siteTitle = trim(strip_tags((string) (self::$cfg->web_title ?? 'Website')));

        $lines = [
            $siteTitle !== '' ? $siteTitle : 'Website',
            'New AI handoff #'.(int) ($handoff['id'] ?? 0),
            'Channel: '.trim((string) ($handoff['channel'] ?? 'web')),
            'Requester: '.$requester
        ];

        if ($message !== '') {
            $lines[] = 'Message: '.$message;
        }

        $lines[] = 'Open in admin: '.$this->adminUrl();

        return implode("\n", $lines);
    }

    /**
     * @param array $handoff
     * @param array $emails
     *
     * @return array
     */
    private function notifyEmail(array $handoff, array $emails)
    {
        if (empty($emails)) {
            return [
                'status' => 'skipped',
                'recipients' => 0,
                'error' => ''
            ];
        }

        $subject = 'New AI handoff #'.(int) ($handoff['id'] ?? 0);
        $body = '<p>A new AI handoff request is waiting for staff follow-up.</p>'
        .'<p><strong>Channel:</strong> '.htmlspecialchars((string) ($handoff['channel'] ?? 'web')).'<br>'
        .'<strong>Requester:</strong> '.htmlspecialchars($this->requesterText($handoff)).'<br>'
        .'<strong>Message:</strong> '.nl2br(htmlspecialchars((string) ($handoff['message'] ?? ''))).'</p>';

        $result = \Index\Email\Model::sendTemplate([
            'to' => implode(',', $emails),
            'subject' => $subject,
            'body' => $body,
            'headerTitle' => $subject,
            'buttonUrl' => $this->adminUrl(),
            'buttonLabel' => 'Open AI Handoffs'
        ]);

        return [
            'status' => $result === true ? 'sent' : 'error',
            'recipients' => count($emails),
            'error' => $result === true ? '' : trim(strip_tags((string) $result))
        ];
    }

    /**
     * @param array $handoff
     * @param array $lineUids
     *
     * @return array
     */
    private function notifyLine(array $handoff, array $lineUids)
    {
        if (empty($lineUids)) {
            return [
                'status' => 'skipped',
                'recipients' => 0,
                'error' => ''
            ];
        }

        $error = \Gcms\Line::sendTo($lineUids, $this->plainMessage($handoff));

        return [
            'status' => $error === '' ? 'sent' : 'error',
            'recipients' => count($lineUids),
            'error' => trim((string) $error)
        ];
    }

    /**
     * @param array $handoff
     * @param array $telegramIds
     *
     * @return array
     */
    private function notifyTelegram(array $handoff, array $telegramIds)
    {
        if (empty($telegramIds)) {
            return [
                'status' => 'skipped',
                'recipients' => 0,
                'error' => ''
            ];
        }

        $error = \Gcms\Telegram::sendTo($telegramIds, $this->plainMessage($handoff));

        return [
            'status' => $error === '' ? 'sent' : 'error',
            'recipients' => count($telegramIds),
            'error' => trim((string) $error)
        ];
    }

    /**
     * @param array $handoff
     *
     * @return string
     */
    private function requesterText(array $handoff)
    {
        $user = isset($handoff['user']) && is_array($handoff['user']) ? $handoff['user'] : [];
        foreach (['name', 'email', 'phone', 'username'] as $field) {
            $value = trim((string) ($user[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $source = isset($handoff['source']) && is_array($handoff['source']) ? $handoff['source'] : [];
        if (($handoff['channel'] ?? '') === 'line') {
            foreach (['userId', 'groupId', 'roomId'] as $field) {
                $value = trim((string) ($source[$field] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        if (($handoff['channel'] ?? '') === 'telegram') {
            $username = trim((string) ($source['username'] ?? ''));
            if ($username !== '') {
                return '@'.$username;
            }
            foreach (['title', 'id'] as $field) {
                $value = trim((string) ($source[$field] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return 'Guest';
    }

    /**
     * @return string
     */
    private function adminUrl()
    {
        return rtrim((string) WEB_URL, '/').'/admin/ai-handoffs';
    }

    /**
     * @param array $handoff
     *
     * @return string
     */
    private function replyTarget(array $handoff)
    {
        $conversationId = trim((string) ($handoff['conversation_id'] ?? ''));
        if ($conversationId !== '') {
            return $conversationId;
        }

        $source = isset($handoff['source']) && is_array($handoff['source']) ? $handoff['source'] : [];
        if (($handoff['channel'] ?? '') === 'line') {
            foreach (['userId', 'groupId', 'roomId'] as $field) {
                $value = trim((string) ($source[$field] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        if (($handoff['channel'] ?? '') === 'telegram') {
            $value = trim((string) ($source['id'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}