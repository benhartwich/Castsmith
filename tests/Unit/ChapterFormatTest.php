<?php
declare(strict_types=1);

namespace PodcastForge\Tests\Unit;

use PodcastForge\Auphonic\ChapterFormat;
use PHPUnit\Framework\TestCase;

final class ChapterFormatTest extends TestCase
{
    public function testTimecodeMatchesTheExpectedShape(): void
    {
        self::assertSame('00:00:00.000', ChapterFormat::timecode(0));
        self::assertSame('00:01:31.500', ChapterFormat::timecode(91500));
        self::assertSame('01:02:03.004', ChapterFormat::timecode(3723004));
    }

    public function testNegativeTimeIsClamped(): void
    {
        self::assertSame('00:00:00.000', ChapterFormat::timecode(-1));
    }

    public function testBuildsOneLinePerChapter(): void
    {
        $text = ChapterFormat::toText([
            ['start_ms' => 0, 'titel' => 'Herbstbeginn'],
            ['start_ms' => 91500, 'titel' => 'Mondphasen'],
        ]);

        self::assertSame("00:00:00.000 Herbstbeginn\n00:01:31.500 Mondphasen", $text);
    }

    public function testChaptersWithoutTitleAreSkipped(): void
    {
        $text = ChapterFormat::toText([
            ['start_ms' => 0, 'titel' => 'Erstes'],
            ['start_ms' => 1000, 'titel' => '   '],
            ['start_ms' => 2000, 'titel' => 'Drittes'],
        ]);

        self::assertSame("00:00:00.000 Erstes\n00:00:02.000 Drittes", $text);
    }

    public function testEmptyListYieldsEmptyString(): void
    {
        self::assertSame('', ChapterFormat::toText([]));
    }

    public function testMissingStartCountsAsZero(): void
    {
        self::assertSame('00:00:00.000 Ohne Zeit', ChapterFormat::toText([['titel' => 'Ohne Zeit']]));
    }
}
