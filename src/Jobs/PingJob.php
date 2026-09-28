<?php
declare(strict_types=1);

namespace Castsmith\Jobs;

/**
 * Example job. It does nothing except record that it ran.
 *
 * It stores the token it was scheduled with. This way the proof is not
 * "something ran at some point", but "exactly this one scheduled job was
 * actually executed".
 */
final class PingJob
{
    public static function run(string $token = ''): void
    {
        $state = Scheduler::state();

        $state['ran_at']    = time();
        $state['ran_token'] = $token;

        update_option(Scheduler::STATE_OPTION, $state, false);
    }
}
