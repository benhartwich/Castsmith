<?php
declare(strict_types=1);

namespace Castsmith\Db;

/**
 * The states of an episode along the pipeline.
 *
 * Not all of these states are reachable yet; only the first ones are.
 */
final class EpisodeStatus
{
    public const NEW              = 'neu';
    /** A source is preparing the text (for example the sky preview). */
    public const SOURCE_RUNNING   = 'quelle_laeuft';
    public const SOURCE_FAILED    = 'quelle_fehler';
    public const PARSED           = 'eingelesen';
    public const REDIGAT_RUNNING  = 'redigat_laeuft';
    public const REDIGAT_FAILED   = 'redigat_fehler';
    public const GATE_FAILED      = 'zahlen_abweichung';
    public const AWAITING_TEXT    = 'wartet_auf_textfreigabe';
    public const TEXT_APPROVED    = 'text_freigegeben';
    public const PRODUCING        = 'auphonic_laeuft';
    public const AWAITING_AUDIO   = 'wartet_auf_audiofreigabe';
    public const DONE             = 'abgeschlossen';

    /** States in which there is no approved text yet. */
    public const BEFORE_TEXT_APPROVAL = [
        self::NEW,
        self::SOURCE_RUNNING,
        self::SOURCE_FAILED,
        self::PARSED,
        self::REDIGAT_RUNNING,
        self::REDIGAT_FAILED,
        self::GATE_FAILED,
        self::AWAITING_TEXT,
    ];

    /**
     * @return array<string,string>
     */
    public static function labels(): array
    {
        return [
            self::NEW             => __('New', 'castsmith'),
            self::SOURCE_RUNNING     => __('Preparing source', 'castsmith'),
            self::SOURCE_FAILED      => __('Preparation failed', 'castsmith'),
            self::PARSED          => __('Imported', 'castsmith'),
            self::REDIGAT_RUNNING => __('Editing in progress', 'castsmith'),
            self::REDIGAT_FAILED  => __('Editing failed', 'castsmith'),
            self::GATE_FAILED     => __('Numbers differ', 'castsmith'),
            self::AWAITING_TEXT   => __('Awaiting text approval', 'castsmith'),
            self::TEXT_APPROVED   => __('Text approved', 'castsmith'),
            self::PRODUCING       => __('Auphonic is producing', 'castsmith'),
            self::AWAITING_AUDIO  => __('Awaiting audio approval', 'castsmith'),
            self::DONE            => __('Completed', 'castsmith'),
        ];
    }

    public static function label(string $status): string
    {
        return self::labels()[$status] ?? $status;
    }
}
