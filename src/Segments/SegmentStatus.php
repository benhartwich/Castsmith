<?php
declare(strict_types=1);

namespace Sonoquill\Segments;

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
            self::PENDING   => __( 'pending', 'sonoquill' ),
            self::GENERATED => __( 'generated', 'sonoquill' ),
            self::FLAGGED   => __( 'flagged', 'sonoquill' ),
            self::PATCHED   => __( 're-recorded', 'sonoquill' ),
            self::APPROVED  => __( 'approved', 'sonoquill' ),
        ];
    }
}
