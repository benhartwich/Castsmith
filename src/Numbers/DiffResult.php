<?php
declare(strict_types=1);

namespace Castsmith\Numbers;

/**
 * Result of the number diff, split into three categories.
 *
 * This split is what keeps the gate usable in day-to-day work. A plain
 * multiset comparison would otherwise fire on every legitimate
 * repetition: the system prompt prescribes an intro that mentions the
 * month name a second time.
 *
 * - erfunden:   The value appears in the spoken script but nowhere in the
 *               source. This is the hallucination case and always blocks.
 * - fehlt:      The value appears in the source but nowhere in the spoken
 *               script. This also blocks, but can be deliberately
 *               overridden, e.g. when "152 Millionen" becomes
 *               "gut hundertfünfzig Millionen" ("a good hundred and fifty million").
 * - haeufigkeit: The value appears on both sides, just a different number
 *               of times. Does not block.
 */
final class DiffResult
{
    /**
     * @param list<DiffFinding> $invented
     * @param list<DiffFinding> $missing
     * @param list<DiffFinding> $changed
     * @param list<DiffFinding> $frequency
     */
    public function __construct(
        public readonly array $invented,
        public readonly array $missing,
        public readonly array $changed,
        public readonly array $frequency
    ) {
    }

    public function isClean(): bool
    {
        return $this->invented === [] && $this->missing === []
            && $this->changed === [] && $this->frequency === [];
    }

    /**
     * Blocks approval as long as nothing has been overridden.
     */
    public function isBlocking(): bool
    {
        return $this->invented !== [] || $this->missing !== [] || $this->changed !== [];
    }

    /**
     * Whether approval is still blocked once the given keys have been
     * deliberately acknowledged.
     *
     * Invented numbers remain blocking. They are the one case the gate was
     * built for, and nobody should be able to click them away by accident.
     *
     * @param list<string> $acknowledged
     */
    public function isBlockingAfter(array $acknowledged): bool
    {
        if ($this->invented !== []) {
            return true;
        }

        foreach (array_merge($this->missing, $this->changed) as $finding) {
            if (!in_array($finding->key, $acknowledged, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $map = static fn (array $list): array => array_map(
            static fn (DiffFinding $f): array => $f->toArray(),
            $list
        );

        return [
            'erfunden'    => $map($this->invented),
            'fehlt'       => $map($this->missing),
            'geaendert'   => $map($this->changed),
            'haeufigkeit' => $map($this->frequency),
        ];
    }
}
