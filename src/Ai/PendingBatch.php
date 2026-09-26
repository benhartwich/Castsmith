<?php
declare(strict_types=1);

namespace PodcastForge\Ai;

/**
 * Not an error: the model's response is delivered as a batch and is still pending.
 *
 * Whoever catches this ends the step silently — without an error status and
 * without a failure entry in the run log. The step is triggered again as soon
 * as the batch is complete.
 */
final class PendingBatch extends \RuntimeException
{
}
