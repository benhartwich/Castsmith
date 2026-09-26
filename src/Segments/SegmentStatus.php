<?php
declare(strict_types=1);

namespace PodcastForge\Segments;

final class SegmentStatus
{
    public const PENDING   = 'offen';
    public const GENERATED = 'erzeugt';
    public const FLAGGED   = 'beanstandet';
    public const PATCHED   = 'nachgesprochen';
    public const APPROVED  = 'abgenommen';

    /**
     * @return array<string,string>
     */
    public static function labels(): array
    {
        return [
            self::PENDING   => __( 'pending', 'podcast-forge' ),
            self::GENERATED => __( 'generated', 'podcast-forge' ),
            self::FLAGGED   => __( 'flagged', 'podcast-forge' ),
            self::PATCHED   => __( 're-recorded', 'podcast-forge' ),
            self::APPROVED  => __( 'approved', 'podcast-forge' ),
        ];
    }
}
