<?php
declare(strict_types=1);

namespace Sonoquill\Support;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

/**
 * Reads a pronunciation dictionary in PLS format (W3C Pronunciation Lexicon).
 *
 * Two tasks, both important:
 *
 * 1. The list of graphemes. It is injected into the system prompt,
 *    so that the model knows which terms are already covered and does not write
 *    its own phonetic spellings into the spoken text. Otherwise those end up in
 *    the published transcript.
 * 2. The number of phoneme rules. ElevenLabs discards them silently, without any
 *    error message, in models that do not support phonemes. The health check is
 *    meant to make exactly these silent failures visible.
 *
 * The class does not know WordPress and can be tested without WordPress.
 */
final class PlsDocument
{
    /** @var list<array{grapheme:string,type:string,value:string}> */
    private array $rules;

    /**
     * @param list<array{grapheme:string,type:string,value:string}> $rules
     */
    private function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    /**
     * @throws PlsException
     */
    public static function fromString(string $xml): self
    {
        if (trim($xml) === '') {
            throw new PlsException('The PLS file is empty.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument();
        // LIBXML_NONET forbids network access while parsing.
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        $errors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            $first = $errors[0] ?? null;
            throw new PlsException(
                'The PLS file is not valid XML'
                . ($first !== null ? ': ' . trim($first->message) : '.')
            );
        }

        return new self(self::extractRules($dom));
    }

    /**
     * @return list<array{grapheme:string,type:string,value:string}>
     */
    private static function extractRules(\DOMDocument $dom): array
    {
        $rules = [];

        foreach ($dom->getElementsByTagName('*') as $element) {
            if ($element->localName !== 'lexeme') {
                continue;
            }

            $graphemes = [];
            $entries = [];

            foreach ($element->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }

                $value = trim($child->textContent);
                if ($value === '') {
                    continue;
                }

                if ($child->localName === 'grapheme') {
                    $graphemes[] = $value;
                } elseif ($child->localName === 'phoneme' || $child->localName === 'alias') {
                    $entries[] = ['type' => $child->localName, 'value' => $value];
                }
            }

            // A lexeme without a grapheme or without a pronunciation is not an effective rule.
            foreach ($graphemes as $grapheme) {
                foreach ($entries as $entry) {
                    $rules[] = [
                        'grapheme' => $grapheme,
                        'type'     => $entry['type'],
                        'value'    => $entry['value'],
                    ];
                }
            }
        }

        return $rules;
    }

    /**
     * Graphemes in document order, without duplicates.
     *
     * The spelling is preserved: by default, ElevenLabs matches
     * case-sensitively.
     *
     * @return list<string>
     */
    public function graphemes(): array
    {
        $seen = [];
        foreach ($this->rules as $rule) {
            $seen[$rule['grapheme']] = true;
        }

        return array_keys($seen);
    }

    /**
     * @return list<array{grapheme:string,type:string,value:string}>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function ruleCount(): int
    {
        return count($this->rules);
    }

    public function phonemeRuleCount(): int
    {
        return $this->countByType('phoneme');
    }

    public function aliasRuleCount(): int
    {
        return $this->countByType('alias');
    }

    /**
     * @return list<string> Graphemes whose rule is a phoneme.
     */
    public function phonemeGraphemes(): array
    {
        $seen = [];
        foreach ($this->rules as $rule) {
            if ($rule['type'] === 'phoneme') {
                $seen[$rule['grapheme']] = true;
            }
        }

        return array_keys($seen);
    }

    private function countByType(string $type): int
    {
        $count = 0;
        foreach ($this->rules as $rule) {
            if ($rule['type'] === $type) {
                $count++;
            }
        }

        return $count;
    }
}
