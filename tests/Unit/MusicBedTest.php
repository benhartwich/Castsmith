<?php
declare(strict_types=1);

namespace Castsmith\Tests\Unit;

use Castsmith\Audio\MusicBed;
use PHPUnit\Framework\TestCase;

/**
 * Zeitplan für Opener, Stimme und Outro. Daran hängen Kapitel und Transkript.
 */
final class MusicBedTest extends TestCase
{
    public function testOpenerOverlapsTheFirstSentence(): void
    {
        // Audio-Logo 12,072 s, Stimme 40 s, Outro 16,04 s — am echten Material gemessen.
        $plan = MusicBed::plan(12072, 40000, 16040);

        self::assertSame(9072, $plan['speech']);
        self::assertSame(9072 + 40000 + 600, $plan['outro']);
        self::assertSame(9072 + 40000 + 600 + 16040, $plan['total']);
    }

    public function testWithoutMusicNothingMoves(): void
    {
        self::assertSame(['speech' => 0, 'outro' => -1, 'total' => 40000], MusicBed::plan(0, 40000, 0));
    }

    public function testShortOpenerNeverStartsSpeechBeforeZero(): void
    {
        $plan = MusicBed::plan(2000, 40000, 0);

        self::assertSame(0, $plan['speech']);
        self::assertSame(40000, $plan['total']);
    }

    public function testChapterGapMakesRoomForTheSeparator(): void
    {
        // Brücke 10 s: 0,4 s nach dem letzten Wort, nächstes Kapitel 1,5 s vor ihrem Ende.
        self::assertSame(['pause' => 8900, 'start' => 400], MusicBed::chapterGap(10000, 1500));
        // Eine sehr kurze Datei macht die Pause nie kürzer als die stille.
        self::assertSame(['pause' => 1500, 'start' => 400], MusicBed::chapterGap(2000, 1500));
    }

    public function testChapterGapWithoutSeparatorKeepsThePlainPause(): void
    {
        self::assertSame(['pause' => 1500, 'start' => -1], MusicBed::chapterGap(0, 1500));
    }

    public function testSeparatorsRotateInOrder(): void
    {
        $sequence = array_map(static fn (int $b): int => MusicBed::separatorFor($b, 3), range(0, 6));

        self::assertSame([0, 1, 2, 0, 1, 2, 0], $sequence);
        self::assertSame(-1, MusicBed::separatorFor(0, 0));
    }

    public function testFinalTimeAddsIntroShiftAndEarlierInserts(): void
    {
        $inserts = [['at_ms' => 60000, 'ms' => 9000], ['at_ms' => 120000, 'ms' => 11000]];

        self::assertSame(5000 + 7000, MusicBed::finalTime(5000, 7000, $inserts));
        self::assertSame(60300 + 7000 + 9000, MusicBed::finalTime(60300, 7000, $inserts));
        self::assertSame(130000 + 7000 + 9000 + 11000, MusicBed::finalTime(130000, 7000, $inserts));
    }
}
