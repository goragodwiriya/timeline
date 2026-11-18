<?php
/**
 * @filesource Gcms/Chat/UserResolver.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

/**
 * Resolve an internal user from channel-specific identity metadata.
 *
 * @since 1.0
 */
class UserResolver
{
    /**
     * Resolve a user for the normalized message.
     *
     * @param Message $message
     *
     * @return object|null
     */
    public function resolve(Message $message)
    {
        if ($message->user !== null) {
            return $message->user;
        }

        switch ($message->channel) {
        case 'line':
            $identifier = trim((string) ($message->metadata['source']['userId'] ?? ''));
            if ($identifier === '') {
                return null;
            }

            return $this->findByField('line_uid', $identifier);

        case 'telegram':
            $identifier = trim((string) ($message->metadata['chat']['id'] ?? $message->metadata['chat_id'] ?? ''));
            if ($identifier === '') {
                return null;
            }

            return $this->findByField('telegram_id', $identifier);

        default:
            return null;
        }
    }

    /**
     * Find one user by a social identity field.
     *
     * @param string $field
     * @param string $value
     *
     * @return object|null
     */
    private function findByField($field, $value)
    {
        return \Kotchasan\Model::createQuery()
            ->select()
            ->from('user')
            ->where([$field, $value])
            ->first();
    }
}