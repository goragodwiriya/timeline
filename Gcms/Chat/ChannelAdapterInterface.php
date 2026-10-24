<?php
/**
 * @filesource Gcms/Chat/ChannelAdapterInterface.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

/**
 * Channel adapter contract.
 *
 * The adapter translates native payloads to Message and formats
 * Response back to transport-specific payloads.
 *
 * @since 1.0
 */
interface ChannelAdapterInterface
{
    /**
     * Stable channel identifier.
     *
     * @return string
     */
    public function name();

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function description();

    /**
     * Normalize a channel payload into the chat core shape.
     *
     * @param array       $payload
     * @param object|null $user
     *
     * @return Message
     */
    public function normalize(array $payload, $user = null);

    /**
     * Format a normalized response for the transport.
     *
     * @param Response $response
     *
     * @return array
     */
    public function format(Response $response);
}