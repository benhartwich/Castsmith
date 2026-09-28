<?php
declare(strict_types=1);

namespace Castsmith\Tests\Unit;

use Castsmith\Audio\AlignmentScaler;
use PHPUnit\Framework\TestCase;

final class AlignmentScalerTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function alignment(): array
    {
        return [
            'characters'                    => ['H', 'a', 'l', 'l', 'o'],
            'character_start_times_seconds' => [0.0, 0.1, 0.2, 0.3, 0.4],
            'character_end_times_seconds'   => [0.1, 0.2, 0.3, 0.4, 0.5],
        ];
    }

    public function testStretchesProportionally(): void
    {
        $scaled = AlignmentScaler::scale($this->alignment(), 500, 1000);

        self::assertSame([0.0, 0.2, 0.4, 0.6, 0.8], $scaled['character_start_times_seconds']);
        self::assertSame([0.2, 0.4, 0.6, 0.8, 1.0], $scaled['character_end_times_seconds']);
    }

    public function testCompressesProportionally(): void
    {
        $scaled = AlignmentScaler::scale($this->alignment(), 500, 250);

        self::assertSame(0.25, end($scaled['character_end_times_seconds']));
    }

    public function testCharacterCountIsUntouched(): void
    {
        $scaled = AlignmentScaler::scale($this->alignment(), 500, 900);

        self::assertSame($this->alignment()['characters'], $scaled['characters']);
        self::assertCount(5, $scaled['character_start_times_seconds']);
    }

    public function testMarksTheResultAsComputed(): void
    {
        $scaled = AlignmentScaler::scale($this->alignment(), 500, 900);

        self::assertTrue($scaled['gestreckt'], 'Gerechnete Zeiten müssen als solche erkennbar bleiben.');
    }

    public function testUnchangedDurationIsLeftAlone(): void
    {
        $scaled = AlignmentScaler::scale($this->alignment(), 500, 500);

        self::assertArrayNotHasKey('gestreckt', $scaled);
        self::assertSame($this->alignment(), $scaled);
    }

    public function testEmptyAlignmentSurvives(): void
    {
        self::assertSame([], AlignmentScaler::scale([], 500, 900));
    }

    public function testZeroDurationsAreRejected(): void
    {
        self::assertSame($this->alignment(), AlignmentScaler::scale($this->alignment(), 0, 900));
        self::assertSame($this->alignment(), AlignmentScaler::scale($this->alignment(), 500, 0));
    }

    public function testTimesStayMonotonic(): void
    {
        $scaled = AlignmentScaler::scale($this->alignment(), 500, 1234);

        $previous = -1.0;
        foreach ($scaled['character_start_times_seconds'] as $value) {
            self::assertGreaterThanOrEqual($previous, $value);
            $previous = $value;
        }
    }
}
