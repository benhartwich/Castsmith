<?php
declare(strict_types=1);

namespace Castsmith\Ai;

use Castsmith\Settings\Options;
use Castsmith\Voice\PronunciationDictionary;

/**
 * Assembles the prompt for the spoken script together with the dictionary list
 * and provides the prompt directory. Which version of a prompt applies is
 * handled by Prompts.
 */
final class PromptLoader
{
    /**
     * The configured prompt directory, otherwise the one bundled with the plugin.
     */
    public static function directory(): string
    {
        $configured = trim(Options::get('prompt_dir'));
        if ($configured !== '') {
            return untrailingslashit($configured);
        }

        return untrailingslashit(AASPF_PLUGIN_DIR) . '/prompts';
    }

    /**
     * Prompt for the spoken script including the dictionary section, exactly as it is sent to the model.
     *
     * @throws AnthropicException
     */
    public static function scriptSystemPrompt(): string
    {
        return Prompts::get('script') . "\n\n---\n\n" . PronunciationDictionary::promptSection();
    }
}
