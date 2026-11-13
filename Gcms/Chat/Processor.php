<?php
/**
 * @filesource Gcms/Chat/Processor.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

use Gcms\Chat\ChatCommandCatalog;
use Gcms\Chat\ChatUiLabels;

/**
 * Shared processing layer for all incoming chat messages.
 *
 * @since 1.0
 */
class Processor extends \Kotchasan\KBase
{
    /**
     * @var ChannelRegistry
     */
    private $channels;

    /**
     * @var Orchestrator
     */
    private $orchestrator;

    /**
     * @var UserResolver
     */
    private $users;

    /**
     * @param ChannelRegistry|null $channels
     * @param UserResolver|null    $users
     * @param Orchestrator|null    $orchestrator
     */
    public function __construct(?ChannelRegistry $channels = null, ?UserResolver $users = null, ?Orchestrator $orchestrator = null)
    {
        $this->channels = $channels ?: ChannelRegistry::defaults();
        $this->users = $users ?: new UserResolver();
        $this->orchestrator = $orchestrator ?: new Orchestrator(null, $this->channels);
    }

    /**
     * Return current public capability metadata.
     *
     * @return array
     */
    public function capabilities()
    {
        $settings = new SettingsRepository();
        $connector = $settings->connector();

        return [
            'ai_enabled' => !empty($connector['ai_enabled']),
            'channels' => $this->channels->definitions(),
            'tools' => $this->orchestrator->tools()->definitions(),
            'messages' => [
                'starter_message' => (string) ($settings->messages()['starter_message'] ?? '')
            ],
            'chat_ui' => ChatUiLabels::forCapabilities(),
            'chat_commands' => ChatCommandCatalog::forApi(),
            'workflow' => $settings->workflow()
        ];
    }

    /**
     * Normalize one payload into a shared message.
     *
     * @param string      $channelName
     * @param array       $payload
     * @param object|null $user
     *
     * @return Message
     */
    public function normalize($channelName, array $payload, $user = null)
    {
        $adapter = $this->adapter($channelName);
        $message = $adapter->normalize($payload, $user);
        if ($message->user === null) {
            $message->user = $this->users->resolve($message);
        }

        return $message;
    }

    /**
     * Process a normalized message through the orchestrator.
     *
     * @param Message $message
     *
     * @return array
     */
    public function handleMessage(Message $message)
    {
        $adapter = $this->adapter($message->channel);
        $response = $this->orchestrator->handle($message);
        ResponsePresenter::finalize($response, $message->channel);

        return [
            'message' => $message,
            'response' => $response,
            'payload' => $adapter->format($response),
            'adapter' => $adapter
        ];
    }

    /**
     * Convenience method for the common normalize + handle flow.
     *
     * @param string      $channelName
     * @param array       $payload
     * @param object|null $user
     *
     * @return array
     */
    public function process($channelName, array $payload, $user = null)
    {
        $message = $this->normalize($channelName, $payload, $user);
        if ($message->text === '') {
            throw new \InvalidArgumentException('message is required');
        }

        return $this->handleMessage($message);
    }

    /**
     * Resolve a channel adapter or fail fast.
     *
     * @param string $channelName
     *
     * @return ChannelAdapterInterface
     */
    private function adapter($channelName)
    {
        $adapter = $this->channels->get($channelName);
        if ($adapter === null) {
            throw new \InvalidArgumentException('Unsupported channel');
        }

        return $adapter;
    }
}