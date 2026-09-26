<?php
declare(strict_types=1);

namespace PodcastForge\Support;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming and appending large audio files; WP_Filesystem would hold them in memory and is not set up in background jobs.

/**
 * Calls an external program and captures its output and exit code.
 *
 * This class exists because hosting setups often block `proc_open` but allow
 * `exec`, for example:
 *
 *     php_admin_value[disable_functions] = dl,passthru,shell_exec,system,proc_open,popen,show_source
 *
 * This restriction does not apply on the command line. A call that only
 * knows `proc_open` therefore works in tests and fails in the backend —
 * exactly the kind of difference you want to handle once, centrally, and
 * not again at every call site.
 *
 * `proc_open` is preferred because no shell sits in between.
 * Otherwise `exec` is used with properly escaped arguments.
 */
final class ProcessRunner
{
    public static function isAvailable(): bool
    {
        // If a function is blocked via disable_functions,
        // function_exists() reports it as nonexistent.
        return function_exists('proc_open') || function_exists('exec');
    }

    /**
     * @param list<string> $command Program and arguments, unescaped.
     *
     * @return array{ok:bool,exitCode:int,output:string,error:string}
     */
    public static function run(array $command): array
    {
        if ($command === []) {
            return self::failure(__('No program specified.', 'podcast-forge'));
        }

        if (function_exists('proc_open')) {
            return self::viaProcOpen($command);
        }

        if (function_exists('exec')) {
            return self::viaExec($command);
        }

        return self::failure(__('Neither proc_open nor exec is available — external programs could not be called.', 'podcast-forge'));
    }

    /**
     * @param list<string> $command
     *
     * @return array{ok:bool,exitCode:int,output:string,error:string}
     */
    private static function viaProcOpen(array $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- optional: runs ffmpeg for the assembly; without it the plugin uses the PHP path

        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- optional: runs ffmpeg for the assembly; without it the plugin uses the PHP path
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return self::failure(__('The program could not be started.', 'podcast-forge'));
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // ffmpeg writes its header line to stderr, so combine both.
        return [
            'ok'       => $exitCode === 0,
            'exitCode' => $exitCode,
            'output'   => trim($stdout . "\n" . $stderr),
            'error'    => '',
        ];
    }

    /**
     * @param list<string> $command
     *
     * @return array{ok:bool,exitCode:int,output:string,error:string}
     */
    private static function viaExec(array $command): array
    {
        $line = implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1';

        $output = [];
        $exitCode = 0;
        @exec($line, $output, $exitCode);

        return [
            'ok'       => $exitCode === 0,
            'exitCode' => (int) $exitCode,
            'output'   => trim(implode("\n", $output)),
            'error'    => '',
        ];
    }

    /**
     * @return array{ok:bool,exitCode:int,output:string,error:string}
     */
    private static function failure(string $message): array
    {
        return ['ok' => false, 'exitCode' => -1, 'output' => '', 'error' => $message];
    }
}
