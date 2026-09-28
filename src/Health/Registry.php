<?php
declare(strict_types=1);

namespace Castsmith\Health;

use Castsmith\Health\Checks\AnthropicCheck;
use Castsmith\Health\Checks\AuphonicCheck;
use Castsmith\Health\Checks\DictionaryCheck;
use Castsmith\Health\Checks\ElevenLabsCheck;
use Castsmith\Health\Checks\FfmpegCheck;
use Castsmith\Health\Checks\PodloveCheck;
use Castsmith\Health\Checks\StorageCheck;

final class Registry
{
    /**
     * @return array<string,CheckInterface>
     */
    public static function all(): array
    {
        $checks = [
            new ElevenLabsCheck(),
            new DictionaryCheck(),
            new AnthropicCheck(),
            new AuphonicCheck(),
            new PodloveCheck(),
            new \Castsmith\Health\Checks\MontageCheck(),
            new FfmpegCheck(),
            new StorageCheck(),
        ];

        /** Filter: additional checks from add-ons (CheckInterface objects). */
        foreach ((array) apply_filters('castsmith_health_checks', []) as $extra) {
            if ($extra instanceof CheckInterface) {
                $checks[] = $extra;
            }
        }

        $indexed = [];
        foreach ($checks as $check) {
            $indexed[$check->id()] = $check;
        }

        return $indexed;
    }

    public static function find(string $id): ?CheckInterface
    {
        return self::all()[$id] ?? null;
    }

    public const LAST_OPTION = 'aaspf_dienste_zuletzt';

    /**
     * Records the result of a check so that the overview can show the state
     * of the services without having to query seven services itself.
     */
    public static function remember(string $id, Result $result): void
    {
        $last = get_option(self::LAST_OPTION, []);
        $last = is_array($last) ? $last : [];
        $last[$id] = ['status' => $result->status, 'message' => $result->message, 'zeit' => time()];
        update_option(self::LAST_OPTION, $last, false);
    }

    /**
     * Runs all checks one after another and records the results. Used by the daily run.
     */
    public static function runAll(): void
    {
        foreach (self::all() as $check) {
            try {
                $result = $check->run();
            } catch (\Throwable $e) {
                $result = Result::fail(__('The check aborted with an error.', 'castsmith'), $e->getMessage());
            }
            self::remember($check->id(), $result);
        }
    }

    /**
     * @return array<string,array{status:string,message:string,zeit:int}>
     */
    public static function lastResults(): array
    {
        $last = get_option(self::LAST_OPTION, []);

        return is_array($last) ? array_intersect_key($last, self::all()) : [];
    }
}
