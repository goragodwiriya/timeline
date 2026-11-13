<?php
/**
 * @filesource Gcms/Chat/Dispatcher.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

/**
 * Send normalized chat responses through channel-specific transports.
 *
 * @since 1.0
 */
class Dispatcher
{
    /**
     * Dispatch the response back to the originating channel.
     *
     * Returns an empty string on success or an error message on failure.
     *
     * @param Message  $message
     * @param Response $response
     * @param array    $formattedPayload
     *
     * @return string
     */
    public function dispatch(Message $message, Response $response, array $formattedPayload = [])
    {
        switch ($response->channel) {
        case 'line':
            return $this->dispatchLine($message, $response, $formattedPayload);

        case 'telegram':
            return $this->dispatchTelegram($message, $response, $formattedPayload);

        default:
            return '';
        }
    }

    /**
     * @param Message  $message
     * @param Response $response
     * @param array    $formattedPayload
     *
     * @return string
     */
    private function dispatchLine(Message $message, Response $response, array $formattedPayload)
    {
        $replyToken = trim((string) ($message->metadata['reply_token'] ?? ''));
        if ($replyToken === '') {
            return 'LINE reply token is missing.';
        }

        $messages = isset($formattedPayload['messages']) && is_array($formattedPayload['messages']) ? $formattedPayload['messages'] : [];
        if (empty($messages)) {
            $messages = [[
                'type' => 'text',
                'text' => $response->message
            ]];
        }

        return \Gcms\Line::replyPayload($replyToken, $messages);
    }

    /**
     * @param Message  $message
     * @param Response $response
     * @param array    $formattedPayload
     *
     * @return string
     */
    private function dispatchTelegram(Message $message, Response $response, array $formattedPayload)
    {
        $chatId = trim((string) ($message->metadata['chat_id'] ?? $message->conversationId));
        if ($chatId === '') {
            return 'Telegram chat id is missing.';
        }

        $messages = isset($formattedPayload['messages']) && is_array($formattedPayload['messages']) ? $formattedPayload['messages'] : [];
        if (empty($messages)) {
            $text = trim((string) ($formattedPayload['text'] ?? $response->message));
            if ($text !== '') {
                $messages[] = [
                    'text' => $text
                ];
            }
        }

        if (empty($messages)) {
            return 'Telegram text payload is empty.';
        }

        return call_user_func([\Gcms\Telegram::class, 'sendPayload'], $chatId, $messages);
    }
}