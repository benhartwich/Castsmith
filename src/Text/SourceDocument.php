<?php
declare(strict_types=1);

namespace Sonoquill\Text;

/**
 * The imported fact script as an ordered list of blocks.
 */
final class SourceDocument
{
    /**
     * @param list<SourceBlock> $blocks
     */
    public function __construct(public readonly array $blocks)
    {
    }

    /**
     * The plain body text without headings.
     *
     * This is the side the number diff runs against.
     */
    public function bodyText(): string
    {
        $parts = [];
        foreach ($this->blocks as $block) {
            if (!$block->isHeading()) {
                $parts[] = $block->text;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $headings = [];
        foreach ($this->blocks as $block) {
            if ($block->isHeading()) {
                $headings[] = $block->text;
            }
        }

        return $headings;
    }

    /**
     * Template for the edited script: headings remain recognisable so that the
     * model sees the structure without carrying it over into the spoken text.
     */
    public function forPrompt(): string
    {
        $parts = [];
        foreach ($this->blocks as $block) {
            $parts[] = $block->isHeading()
                ? '## ' . $block->text
                : $block->text;
        }

        return implode("\n\n", $parts);
    }

    public function characterCount(): int
    {
        return mb_strlen($this->bodyText());
    }

    public function isEmpty(): bool
    {
        return trim($this->bodyText()) === '';
    }

    /**
     * @return list<array{type:string,text:string,level:int}>
     */
    public function toArray(): array
    {
        return array_map(static fn (SourceBlock $b): array => $b->toArray(), $this->blocks);
    }

    /**
     * @param list<array<string,mixed>> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn (array $item): SourceBlock => SourceBlock::fromArray($item),
            $data
        ));
    }
}
