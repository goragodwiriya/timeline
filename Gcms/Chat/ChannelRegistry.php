<?php
/**
 * @filesource Gcms/Chat/ChannelRegistry.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

use Gcms\Chat\ChannelAdapterInterface;
use Gcms\Chat\Channels\LineAdapter;
use Gcms\Chat\Channels\TelegramAdapter;
use Gcms\Chat\Channels\WebAdapter;

/**
 * Registry for supported conversation channels.
 *
 * @since 1.0
 */
class ChannelRegistry
{
    /**
     * @var ChannelAdapterInterface[]
     */
    private $channels = [];

    /**
     * @param array $channels
     */
    public function __construct(array $channels = [])
    {
        foreach ($channels as $channel) {
            if ($channel instanceof ChannelAdapterInterface) {
                $this->register($channel);
            }
        }
    }

    /**
     * @param ChannelAdapterInterface $channel
     *
     * @return $this
     */
    public function register(ChannelAdapterInterface $channel)
    {
        $this->channels[$channel->name()] = $channel;

        return $this;
    }

    /**
     * @param string $name
     *
     * @return ChannelAdapterInterface|null
     */
    public function get($name)
    {
        $name = strtolower(trim((string) $name));

        return $this->channels[$name] ?? null;
    }

    /**
     * @return array
     */
    public function definitions()
    {
        $items = [];
        foreach ($this->channels as $channel) {
            $items[] = [
                'name' => $channel->name(),
                'description' => $channel->description()
            ];
        }

        return $items;
    }

    /**
     * Default channel set for this project.
     *
     * @return self
     */
    public static function defaults()
    {
        return new self([
            new WebAdapter(),
            new LineAdapter(),
            new TelegramAdapter()
        ]);
    }
}