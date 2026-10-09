<?php
/**
 * @filesource Gcms/Chat/Tools/HelpTool.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat\Tools;

use Gcms\Chat\ChatCommandCatalog;
use Gcms\Chat\Message;
use Gcms\Chat\Response;
use Gcms\Chat\ToolInterface;
use Kotchasan\Language;

/**
 * Lists slash commands from {@see ChatCommandCatalog} with tappable compose hints.
 *
 * @since 1.0
 */
class HelpTool implements ToolInterface
{
    /**
     * @return string
     */
    public function name()
    {
        return 'help';
    }

    /**
     * @return string
     */
    public function description()
    {
        return 'List chat slash commands and usage';
    }

    /**
     * @param Message $message
     *
     * @return bool
     */
    public function supports(Message $message)
    {
        return ChatCommandCatalog::messageMatchesCommand($message->text, 'help');
    }

    /**
     * @param Message $message
     *
     * @return Response
     */
    public function handle(Message $message)
    {
        $intro = Language::get('AI chat help intro', 'You can use these commands:');
        $lines = ChatCommandCatalog::helpBodyLines();
        $body = $lines !== [] ? implode("\n", $lines) : Language::get('AI chat help empty', 'No commands registered.');
        $isSocial = in_array($message->channel, ['line', 'telegram'], true);
        $footer = Language::get(
            $isSocial ? 'AI chat help footer social' : 'AI chat help footer',
            $isSocial
                ? 'Tap a shortcut below to send that command.'
                : 'Tap a shortcut to insert text into the box (you can edit it before sending).'
        );

        $text = $intro."\n\n".$body."\n\n".$footer;

        return Response::text(
            $text,
            $message,
            $this->name(),
            [],
            [],
            ChatCommandCatalog::helpPromptActions()
        );
    }
}
