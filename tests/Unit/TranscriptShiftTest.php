<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Audio\Transcript;
use PHPUnit\Framework\TestCase;

final class TranscriptShiftTest extends TestCase
{
    public function testCuesMoveByTheDeltaOfTheirChapter(): void
    {
        $vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:03.500\nErstes Kapitel\n\n00:01:05.000 --> 00:01:07.000\nZweites Kapitel\n";

        $shifted = Transcript::shiftByChapters($vtt, [0, 60000], [0, 1250]);

        self::assertStringContainsString("00:00:01.000 --> 00:00:03.500\nErstes", $shifted);
        self::assertStringContainsString("00:01:06.250 --> 00:01:08.250\nZweites", $shifted);
    }
}
