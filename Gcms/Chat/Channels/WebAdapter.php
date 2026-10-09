<?php
/**
 * @filesource Gcms/Chat/Channels/WebAdapter.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat\Channels;

use Gcms\Chat\ChannelAdapterInterface;
use Gcms\Chat\Message;
use Gcms\Chat\Response;

/**
 * Web chat adapter.
 *
 * @since 1.0
 */
class WebAdapter implements ChannelAdapterInterface
{
    /**
     * @return string
     */
    public function name()
    {
        return 'web';
    }

    /**
     * @return string
     */
    public function description()
    {
        return 'Website chat widget or admin chat UI';
    }

    /**
     * @param array       $payload
     * @param object|null $user
     *
     * @return Message
     */
    public function normalize(array $payload, $user = null)
    {
        $payload['channel'] = 'web';

        return Message::fromArray($payload, $user);
    }

    /**
     * @param Response $response
     *
     * @return array
     */
    public function format(Response $response)
    {
        return [
            'type' => 'message',
            'message' => $response->message,
            'cards' => $response->cards,
            'actions' => $response->actions,
            'conversation_id' => $response->conversationId
        ];
    }
}