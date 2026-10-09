<?php
/**
 * @filesource Gcms/Chat/HandoffStore.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

/**
 * Persist AI chat handoff requests for staff follow-up.
 *
 * @since 1.0
 */
class HandoffStore extends \Kotchasan\KBase
{
    /**
     * @var string
     */
    private const TABLE = 'ai_chat_handoffs';

    /**
     * @var bool|null
     */
    private static $tableAvailable;

    /**
     * @var bool
     */
    private static $legacyImported = false;

    /**
     * @var string
     */
    private $legacyFilePath;

    /**
     * @param string|null $filePath
     */
    public function __construct($filePath = null)
    {
        $this->legacyFilePath = $filePath ?: ROOT_PATH.DATA_FOLDER.'logs/ai-chat-handoffs.json';
    }

    /**
     * Create one handoff request from a normalized message.
     *
     * @param Message $message
     *
     * @return array
     */
    public function create(Message $message): array
    {
        $this->bootstrapLegacyImport();
        if (!$this->tableAvailable()) {
            throw new \RuntimeException('AI chat handoff table unavailable');
        }

        $timestamp = date('Y-m-d H:i:s');
        $user = $this->user($message->user);
        $id = \Kotchasan\DB::create()->insert(self::TABLE, [
            'channel' => trim((string) $message->channel),
            'conversation_id' => trim((string) $message->conversationId),
            'status' => 'open',
            'user_id' => !empty($user['id']) ? (int) $user['id'] : null,
            'requester_name' => $user['name'] ?? null,
            'requester_email' => $user['email'] ?? null,
            'requester_phone' => $user['phone'] ?? null,
            'requester_username' => $user['username'] ?? null,
            'message' => trim((string) $message->text),
            'history_json' => $this->encode($message->history),
            'source_json' => $this->encode($this->source($message)),
            'notifications_json' => $this->encode([]),
            'requester_notifications_json' => $this->encode([]),
            'created_at' => $timestamp,
            'updated_at' => $timestamp
        ]);

        if ($id === null) {
            throw new \RuntimeException('Unable to persist AI chat handoff');
        }

        return $this->findHandoff($id) ?: [];
    }

    /**
     * Append a follow-up note from the requester to an open handoff.
     *
     * @param int   $id
     * @param string $note
     * @param array $history
     *
     * @return array|null
     */
    public function appendFollowUp(int $id, string $note, array $history = []): ?array
    {
        $this->bootstrapLegacyImport();
        if ($id < 1 || !$this->tableAvailable()) {
            return null;
        }

        $note = trim($note);
        if ($note === '') {
            return null;
        }

        $row = $this->fetchOneBy(['id', $id]);
        if ($row === null) {
            return null;
        }

        $handoff = $this->decorate($row);
        if (!HandoffFollowUp::isActive($handoff)) {
            return null;
        }

        $message = trim((string) ($handoff['message'] ?? ''));
        if ($message !== '') {
            $message .= "\n\n";
        }
        $message .= '[ฝากเพิ่ม '.date('Y-m-d H:i').'] '.$note;

        $save = [
            'message' => $message,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if (!empty($history)) {
            $save['history_json'] = $this->encode($history);
        }

        \Kotchasan\DB::create()->update(self::TABLE, ['id', $id], $save);

        return $this->findHandoff($id);
    }

    /**
     * Return latest handoff entries.
     *
     * @param int $limit
     *
     * @return array
     */
    public function latest($limit = 50, array $filters = [], ?int $slaMinutes = null): array
    {
        $this->bootstrapLegacyImport();
        if (!$this->tableAvailable()) {
            return [];
        }

        $limit = max(1, min(200, (int) $limit));
        $slaMinutes = $slaMinutes === null ? (new SettingsRepository())->workflow()['sla_minutes'] : $slaMinutes;

        try {
            $query = \Kotchasan\Model::createQuery()
                ->select('*')
                ->from(self::TABLE)
                ->orderBy('id', 'DESC')
                ->limit($limit);

            $where = [];
            if (!empty($filters['status'])) {
                $where[] = ['status', strtolower(trim((string) $filters['status']))];
            }
            if (!empty($filters['channel'])) {
                $where[] = ['channel', strtolower(trim((string) $filters['channel']))];
            }
            if (!empty($where)) {
                $query->where($where);
            }

            $result = $query->execute();
        } catch (\Exception $e) {
            self::$tableAvailable = false;

            return [];
        }

        $items = [];
        foreach ($result->fetchAll() as $row) {
            $items[] = $this->decorate($row, $slaMinutes);
        }

        if (!empty($filters['overdue'])) {
            $items = array_values(array_filter($items, function ($item) {
                return !empty($item['is_overdue']);
            }));
        }

        return $items;
    }

    /**
     * Summarize a handoff list.
     *
     * @param array    $items
     * @param int|null $slaMinutes
     *
     * @return array
     */
    public function summary(array $items, ?int $slaMinutes = null): array
    {
        $slaMinutes = $slaMinutes === null ? (new SettingsRepository())->workflow()['sla_minutes'] : $slaMinutes;
        $statuses = [
            'open' => 0,
            'accepted' => 0,
            'closed' => 0
        ];
        $channels = [];
        $overdue = 0;

        foreach ($items as $item) {
            $status = $this->statusString($item['status'] ?? 'open');
            ++$statuses[$status];

            $channel = strtolower(trim((string) ($item['channel'] ?? 'web')));
            if ($channel === '') {
                $channel = 'web';
            }
            if (!isset($channels[$channel])) {
                $channels[$channel] = 0;
            }
            ++$channels[$channel];

            if (!empty($item['is_overdue'])) {
                ++$overdue;
            }
        }

        ksort($channels);

        return [
            'total' => count($items),
            'statuses' => $statuses,
            'channels' => $channels,
            'overdue' => $overdue,
            'sla_minutes' => $slaMinutes,
            'latest_at' => isset($items[0]['created_at']) ? (string) $items[0]['created_at'] : ''
        ];
    }

    /**
     * Find latest handoff by conversation ID.
     *
     * @param string   $conversationId
     * @param int|null $slaMinutes
     *
     * @return array|null
     */
    public function latestByConversation($conversationId, ?int $slaMinutes = null): ?array
    {
        $this->bootstrapLegacyImport();
        if (!$this->tableAvailable()) {
            return null;
        }

        $conversationId = trim((string) $conversationId);
        if ($conversationId === '') {
            return null;
        }

        try {
            $row = \Kotchasan\Model::createQuery()
                ->select('*')
                ->from(self::TABLE)
                ->where(['conversation_id', $conversationId])
                ->orderBy('id', 'DESC')
                ->first();
        } catch (\Exception $e) {
            self::$tableAvailable = false;

            return null;
        }

        return $row !== null ? $this->decorate($row, $slaMinutes) : null;
    }

    /**
     * Update stored notification results.
     *
     * @param int   $id
     * @param array $notifications
     *
     * @return array|null
     */
    public function updateNotifications($id, array $notifications): ?array
    {
        return $this->updateJsonField($id, 'notifications_json', $notifications);
    }

    /**
     * Update stored requester-notification results.
     *
     * @param int   $id
     * @param array $notifications
     *
     * @return array|null
     */
    public function updateRequesterNotifications($id, array $notifications): ?array
    {
        return $this->updateJsonField($id, 'requester_notifications_json', $notifications);
    }

    /**
     * Update handoff status for staff handling.
     *
     * @param int         $id
     * @param string      $status
     * @param object|null $user
     *
     * @return array|null
     */
    public function updateStatus($id, $status, $user = null): ?array
    {
        $status = strtolower(trim((string) $status));
        if (!in_array($status, ['accepted', 'closed'], true)) {
            throw new \InvalidArgumentException('Invalid handoff status');
        }

        $row = $this->fetchOneBy(['id', (int) $id]);
        if ($row === null) {
            return null;
        }

        $currentStatus = $this->statusString($row->status ?? 'open');
        if ($currentStatus === 'closed' && $status !== 'closed') {
            throw new \InvalidArgumentException('Closed handoff can not be reopened');
        }

        $actor = $this->user($user);
        $timestamp = date('Y-m-d H:i:s');
        $save = [
            'status' => $status,
            'updated_at' => $timestamp
        ];

        if ($status === 'accepted') {
            if (empty($row->accepted_at)) {
                $save['accepted_at'] = $timestamp;
            }
            $save['accepted_by'] = !empty($actor['id']) ? (int) $actor['id'] : null;
            $save['accepted_by_name'] = $actor['name'] ?? ($actor['email'] ?? '');
        }
        if ($status === 'closed') {
            $save['closed_at'] = $timestamp;
            $save['closed_by'] = !empty($actor['id']) ? (int) $actor['id'] : null;
            $save['closed_by_name'] = $actor['name'] ?? ($actor['email'] ?? '');
        }

        \Kotchasan\DB::create()->update(self::TABLE, ['id', (int) $id], $save);

        return $this->findHandoff((int) $id);
    }

    /**
     * @param object|null $user
     *
     * @return array
     */
    private function user($user): array
    {
        if (!is_object($user)) {
            return [];
        }

        return [
            'id' => (int) ($user->id ?? 0),
            'name' => trim((string) ($user->name ?? '')),
            'email' => trim((string) ($user->email ?? '')),
            'phone' => trim((string) ($user->phone ?? '')),
            'username' => trim((string) ($user->username ?? ''))
        ];
    }

    /**
     * @param Message $message
     *
     * @return array
     */
    private function source(Message $message): array
    {
        switch ($message->channel) {
        case 'line':
            return isset($message->metadata['source']) && is_array($message->metadata['source'])
                ? $message->metadata['source']
                : [];

        case 'telegram':
            return isset($message->metadata['chat']) && is_array($message->metadata['chat'])
                ? $message->metadata['chat']
                : [];

        default:
            return [];
        }
    }

    /**
     * Find one handoff by ID.
     *
     * @param int      $id
     * @param int|null $slaMinutes
     *
     * @return array|null
     */
    private function findHandoff($id, ?int $slaMinutes = null): ?array
    {
        $this->bootstrapLegacyImport();
        if (!$this->tableAvailable()) {
            return null;
        }

        $row = $this->fetchOneBy(['id', (int) $id]);

        return $row !== null ? $this->decorate($row, $slaMinutes) : null;
    }

    /**
     * @param array $where
     *
     * @return object|null
     */
    private function fetchOneBy(array $where)
    {
        if (!$this->tableAvailable()) {
            return null;
        }

        try {
            return \Kotchasan\Model::createQuery()
                ->select('*')
                ->from(self::TABLE)
                ->where($where)
                ->first();
        } catch (\Exception $e) {
            self::$tableAvailable = false;

            return null;
        }
    }

    /**
     * @param int    $id
     * @param string $column
     * @param array  $value
     *
     * @return array|null
     */
    private function updateJsonField($id, string $column, array $value): ?array
    {
        $id = (int) $id;
        if ($id < 1 || !$this->tableAvailable()) {
            return null;
        }

        \Kotchasan\DB::create()->update(self::TABLE, ['id', $id], [
            $column => $this->encode($value),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return $this->findHandoff($id);
    }

    /**
     * @param object|array $row
     * @param int|null     $slaMinutes
     *
     * @return array
     */
    private function decorate($row, ?int $slaMinutes = null): array
    {
        $slaMinutes = $slaMinutes === null ? (new SettingsRepository())->workflow()['sla_minutes'] : $slaMinutes;
        $item = is_array($row) ? $row : get_object_vars($row);
        $item['id'] = (int) ($item['id'] ?? 0);
        $item['channel'] = strtolower(trim((string) ($item['channel'] ?? 'web')));
        $item['status'] = $this->statusString($item['status'] ?? 'open');
        $item['conversation_id'] = trim((string) ($item['conversation_id'] ?? ''));
        $item['message'] = trim((string) ($item['message'] ?? ''));
        $item['source'] = $this->decode($item['source_json'] ?? '');
        $item['history'] = $this->decode($item['history_json'] ?? '');
        $item['notifications'] = $this->decode($item['notifications_json'] ?? '');
        $item['requester_notifications'] = $this->decode($item['requester_notifications_json'] ?? '');
        $item['user'] = array_filter([
            'id' => (int) ($item['user_id'] ?? 0),
            'name' => trim((string) ($item['requester_name'] ?? '')),
            'email' => trim((string) ($item['requester_email'] ?? '')),
            'phone' => trim((string) ($item['requester_phone'] ?? '')),
            'username' => trim((string) ($item['requester_username'] ?? ''))
        ], function ($value) {
            return !(is_string($value) && $value === '') && !(is_int($value) && $value === 0);
        });
        $item['accepted_by'] = $this->actor($item['accepted_by'] ?? 0, $item['accepted_by_name'] ?? '');
        $item['closed_by'] = $this->actor($item['closed_by'] ?? 0, $item['closed_by_name'] ?? '');
        $endAt = $item['status'] === 'closed' && !empty($item['closed_at']) ? $item['closed_at'] : null;
        $item['age_minutes'] = $this->ageMinutes($item['created_at'] ?? '', $endAt);
        $item['age_text'] = $this->ageText($item['age_minutes']);
        $item['is_overdue'] = $item['status'] !== 'closed' && $item['age_minutes'] > $slaMinutes;

        unset(
            $item['source_json'],
            $item['history_json'],
            $item['notifications_json'],
            $item['requester_notifications_json'],
            $item['user_id'],
            $item['requester_name'],
            $item['requester_email'],
            $item['requester_phone'],
            $item['requester_username'],
            $item['accepted_by_name'],
            $item['closed_by_name']
        );

        return $item;
    }

    /**
     * @param mixed $status
     *
     * @return string
     */
    private function statusString($status): string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, ['accepted', 'closed'], true) ? $status : 'open';
    }

    /**
     * @param mixed $json
     *
     * @return array
     */
    private function decode($json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private function encode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '[]' : $json;
    }

    /**
     * @param string      $start
     * @param string|null $end
     *
     * @return int
     */
    private function ageMinutes($start, $end = null): int
    {
        if (trim((string) $start) === '') {
            return 0;
        }

        try {
            $from = new \DateTimeImmutable((string) $start);
            $to = $end !== null && trim((string) $end) !== '' ? new \DateTimeImmutable((string) $end) : new \DateTimeImmutable();
        } catch (\Exception $e) {
            return 0;
        }

        return max(0, (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 60));
    }

    /**
     * @param int $minutes
     *
     * @return string
     */
    private function ageText($minutes): string
    {
        $minutes = max(0, (int) $minutes);
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return $hours.' h';
        }

        $days = (int) floor($hours / 24);

        return $days.' d '.($hours % 24).' h';
    }

    /**
     * @param mixed $id
     * @param mixed $name
     *
     * @return array
     */
    private function actor($id, $name): array
    {
        $actor = [];
        if ((int) $id > 0) {
            $actor['id'] = (int) $id;
        }
        $name = trim((string) $name);
        if ($name !== '') {
            $actor['name'] = $name;
        }

        return $actor;
    }

    /**
     * Import old JSON handoffs once after the new DB table exists.
     *
     * @return void
     */
    private function bootstrapLegacyImport(): void
    {
        if (self::$legacyImported) {
            return;
        }
        self::$legacyImported = true;

        if (!$this->tableAvailable() || !is_file($this->legacyFilePath)) {
            return;
        }

        try {
            $existing = \Kotchasan\Model::createQuery()
                ->select('id')
                ->from(self::TABLE)
                ->limit(1)
                ->first();
        } catch (\Exception $e) {
            return;
        }
        if ($existing !== null) {
            return;
        }

        $content = file_get_contents($this->legacyFilePath);
        $decoded = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($decoded)) {
            return;
        }

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $acceptedBy = isset($item['accepted_by']) && is_array($item['accepted_by']) ? $item['accepted_by'] : [];
            $closedBy = isset($item['closed_by']) && is_array($item['closed_by']) ? $item['closed_by'] : [];
            $user = isset($item['user']) && is_array($item['user']) ? $item['user'] : [];

            try {
                \Kotchasan\DB::create()->insert(self::TABLE, [
                    'id' => isset($item['id']) ? (int) $item['id'] : null,
                    'channel' => trim((string) ($item['channel'] ?? 'web')),
                    'conversation_id' => trim((string) ($item['conversation_id'] ?? '')),
                    'status' => $this->statusString($item['status'] ?? 'open'),
                    'user_id' => !empty($user['id']) ? (int) $user['id'] : null,
                    'requester_name' => $user['name'] ?? null,
                    'requester_email' => $user['email'] ?? null,
                    'requester_phone' => $user['phone'] ?? null,
                    'requester_username' => $user['username'] ?? null,
                    'message' => trim((string) ($item['message'] ?? '')),
                    'history_json' => $this->encode($item['history'] ?? []),
                    'source_json' => $this->encode($item['source'] ?? []),
                    'notifications_json' => $this->encode($item['notifications'] ?? []),
                    'requester_notifications_json' => $this->encode($item['requester_notifications'] ?? []),
                    'created_at' => $item['created_at'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => $item['updated_at'] ?? ($item['created_at'] ?? date('Y-m-d H:i:s')),
                    'accepted_at' => $item['accepted_at'] ?? null,
                    'accepted_by' => !empty($acceptedBy['id']) ? (int) $acceptedBy['id'] : null,
                    'accepted_by_name' => $acceptedBy['name'] ?? null,
                    'closed_at' => $item['closed_at'] ?? null,
                    'closed_by' => !empty($closedBy['id']) ? (int) $closedBy['id'] : null,
                    'closed_by_name' => $closedBy['name'] ?? null
                ]);
            } catch (\Exception $e) {
            }
        }
    }

    /**
     * @return bool
     */
    private function tableAvailable(): bool
    {
        if (self::$tableAvailable !== null) {
            return self::$tableAvailable;
        }

        try {
            \Kotchasan\Model::createQuery()
                ->select('id')
                ->from(self::TABLE)
                ->limit(1)
                ->execute();
            self::$tableAvailable = true;
        } catch (\Exception $e) {
            self::$tableAvailable = false;
        }

        return self::$tableAvailable;
    }
}