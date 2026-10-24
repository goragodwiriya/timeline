<?php
/**
 * @filesource Gcms/Chat/ToolInterface.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

/**
 * Contract for pluggable chat tools.
 *
 * @since 1.0
 */
interface ToolInterface
{
    /**
     * Stable tool identifier.
     *
     * @return string
     */
    public function name();

    /**
     * Human-readable description for discovery / admin UIs.
     *
     * @return string
     */
    public function description();

    /**
     * Return true when this tool should handle the message.
     *
     * @param Message $message
     *
     * @return bool
     */
    public function supports(Message $message);

    /**
     * Produce a normalized response.
     *
     * @param Message $message
     *
     * @return Response
     */
    public function handle(Message $message);
}