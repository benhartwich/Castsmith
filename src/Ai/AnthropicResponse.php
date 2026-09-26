<?php
declare(strict_types=1);

namespace PodcastForge\Ai;

/**
 * Response from the Messages API, reduced to what the pipeline needs.
 */
final class AnthropicResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $stopReason,
        public readonly string $model,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly bool $batch = false,
        public readonly bool $alreadyBooked = false
    ) {
    }

    /**
     * The text block parsed as JSON. null if it is not JSON.
     *
     * @return array<string,mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Estimated cost in US cents, based on model, cache and batch.
     */
    public function estimatedCents(): float
    {
        return Pricing::cents(
            $this->model,
            $this->inputTokens,
            $this->outputTokens,
            $this->cacheWriteTokens,
            $this->cacheReadTokens,
            $this->batch
        );
    }

    /**
     * The amount to be charged to the episode.
     *
     * A step may read a batch response more than once — it restarts after the
     * batch and passes the same request again. The cost is only charged the
     * first time.
     */
    public function centsToBook(): float
    {
        return $this->alreadyBooked ? 0.0 : $this->estimatedCents();
    }

    /**
     * The same response, marked as charged.
     */
    public function markBooked(): self
    {
        return new self(
            $this->text,
            $this->inputTokens,
            $this->outputTokens,
            $this->stopReason,
            $this->model,
            $this->cacheWriteTokens,
            $this->cacheReadTokens,
            $this->batch,
            true
        );
    }
}
