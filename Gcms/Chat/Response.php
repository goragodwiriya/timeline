<?php
/**
 * @filesource Gcms/Chat/Response.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

/**
 * Normalized chat response DTO.
 *
 * The channel adapter formats this payload for its transport later.
 *
 * @since 1.0
 */
class Response
{
    /**
     * Response success flag.
     *
     * @var bool
     */
    public $success = true;

    /**
     * Channel name used to render the response.
     *
     * @var string
     */
    public $channel = 'web';

    /**
     * Conversation identifier.
     *
     * @var string
     */
    public $conversationId = '';

    /**
     * Plain text answer.
     *
     * @var string
     */
    public $message = '';

    /**
     * Tool name that handled the request.
     *
     * @var string
     */
    public $tool = '';

    /**
     * Optional rich cards for channels that support them.
     *
     * @var array
     */
    public $cards = [];

    /**
     * Optional quick actions / suggested replies.
     *
     * @var array
     */
    public $actions = [];

    /**
     * Additional metadata.
     *
     * @var array
     */
    public $metadata = [];

    /**
     * Create a text response with sensible defaults.
     *
     * @param string  $message
     * @param Message $request
     * @param string  $tool
     * @param array   $metadata
     * @param array   $cards
     * @param array   $actions
     *
     * @return self
     */
    public static function text($message, Message $request, $tool = '', array $metadata = [], array $cards = [], array $actions = [])
    {
        $response = new self();
        $response->channel = $request->channel;
        $response->conversationId = $request->conversationId !== '' ? $request->conversationId : self::newConversationId();
        $response->message = trim((string) $message);
        $response->tool = (string) $tool;
        $response->metadata = $metadata;
        $response->cards = $cards;
        $response->actions = $actions;

        return $response;
    }

    /**
     * Create a failed response while preserving channel context.
     *
     * @param string  $message
     * @param Message $request
     * @param array   $metadata
     *
     * @return self
     */
    public static function error($message, Message $request, array $metadata = [])
    {
        $response = self::text($message, $request, 'error', $metadata);
        $response->success = false;

        return $response;
    }

    /**
     * Generate a small conversation ID when the channel did not provide one.
     *
     * @return string
     */
    private static function newConversationId()
    {
        try {
            return 'chat-'.bin2hex(random_bytes(8));
        } catch (\Exception $e) {
            return 'chat-'.str_replace('.', '', uniqid('', true));
        }
    }
}