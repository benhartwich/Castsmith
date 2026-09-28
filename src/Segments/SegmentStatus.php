<?php
declare(strict_types=1);

namespace Castsmith\Segments;

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
            self::PENDING   => __( 'pending', 'castsmith' ),
            self::GENERATED => __( 'generated', 'castsmith' ),
            self::FLAGGED   => __( 'flagged', 'castsmith' ),
            self::PATCHED   => __( 're-recorded', 'castsmith' ),
            self::APPROVED  => __( 'approved', 'castsmith' ),
        ];
    }
}
