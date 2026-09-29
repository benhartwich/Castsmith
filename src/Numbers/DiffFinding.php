<?php
declare(strict_types=1);

namespace Sonoquill\Numbers;

/**
 * A single discrepancy between the fact script and the spoken script.
 */
final class DiffFinding
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $sourceCount,
        public readonly int $scriptCount,
        public readonly string $context,
        public readonly string $counterpart = ''
    ) {
    }

    /**
     * @return array<string,string|int>
     */
    public function toArray(): array
    {
        return [
            'key'         => $this->key,
            'label'       => $this->label,
            'sourceCount' => $this->sourceCount,
            'scriptCount' => $this->scriptCount,
            'context'     => $this->context,
            'counterpart' => $this->counterpart,
        ];
    }
}
