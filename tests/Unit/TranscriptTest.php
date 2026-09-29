<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Audio\Transcript;
use PHPUnit\Framework\TestCase;

final class TranscriptTest extends TestCase
{
    /**
     * Baut eine Ausrichtung, in der jedes Zeichen genau zehn Millisekunden dauert.
     *
     * @return array{characters:list<string>,character_start_times_seconds:list<float>,character_end_times_seconds:list<float>}
     */
    private function alignment(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $starts = [];
        $ends = [];

        foreach (array_keys($characters) as $index) {
            $starts[] = $index * 0.01;
            $ends[] = ($index + 1) * 0.01;
        }

        return [
            'characters'                    => $characters,
            'character_start_times_seconds' => $starts,
            'character_end_times_seconds'   => $ends,
        ];
    }

    /**
     * @return list<array{offset_ms:int,alignment:array<string,mixed>,text:string,duration_ms:int}>
     */
    private function segment(string $text, int $offsetMs = 0): array
    {
        return [[
            'offset_ms'   => $offsetMs,
            'alignment'   => $this->alignment($text),
            'text'        => $text,
            'duration_ms' => mb_strlen($text) * 10,
        ]];
    }

    public function testTimecodeFormat(): void
    {
        self::assertSame('00:00:00.000', Transcript::timecode(0));
        self::assertSame('00:01:31.500', Transcript::timecode(91500));
        self::assertSame('01:02:03.004', Transcript::timecode(3723004));
    }

    public function testNegativeTimeIsClamped(): void
    {
        self::assertSame('00:00:00.000', Transcript::timecode(-5));
    }

    public function testSplitsAtSentenceEnd(): void
    {
        $cues = Transcript::cues($this->segment('Erster Satz. Zweiter Satz.'));

        self::assertCount(2, $cues);
        self::assertSame('Erster Satz.', $cues[0]['text']);
        self::assertSame('Zweiter Satz.', $cues[1]['text']);
    }

    public function testDoesNotSplitInsideADomainName(): void
    {
        // Der Anlass: "Astronomie.at" wurde mitten im Wort zerlegt.
        $cues = Transcript::cues($this->segment('Ausgabe des Astronomie.at Podcasts.'));

        self::assertCount(1, $cues);
        self::assertSame('Ausgabe des Astronomie.at Podcasts.', $cues[0]['text']);
    }

    public function testOffsetShiftsTimes(): void
    {
        $cues = Transcript::cues($this->segment('Hallo.', 5000));

        self::assertSame(5000, $cues[0]['start_ms']);
        self::assertSame(5060, $cues[0]['end_ms']);
    }

    public function testFallsBackToWholeParagraphWithoutAlignment(): void
    {
        $cues = Transcript::cues([[
            'offset_ms'   => 1000,
            'alignment'   => [],
            'text'        => 'Ohne Zeitmarken.',
            'duration_ms' => 2000,
        ]]);

        self::assertCount(1, $cues);
        self::assertSame('Ohne Zeitmarken.', $cues[0]['text']);
        self::assertSame(1000, $cues[0]['start_ms']);
        self::assertSame(3000, $cues[0]['end_ms']);
    }

    public function testLongSentenceIsBrokenUp(): void
    {
        $long = str_repeat('Wort ', 60) . 'Ende.';
        $cues = Transcript::cues($this->segment($long));

        self::assertGreaterThan(1, count($cues));
        foreach ($cues as $cue) {
            self::assertLessThan(220, mb_strlen($cue['text']));
        }
    }

    public function testWebvttHeaderAndStructure(): void
    {
        $vtt = Transcript::webvtt($this->segment('Ein Satz.'), 'Titel der Folge');

        self::assertStringStartsWith("WEBVTT\n", $vtt);
        self::assertStringContainsString("NOTE\nTitel der Folge", $vtt);
        self::assertStringContainsString('00:00:00.000 --> 00:00:00.090', $vtt);
        self::assertStringContainsString('Ein Satz.', $vtt);
    }

    public function testCuesNeverOverlapOrGoBackwards(): void
    {
        $cues = Transcript::cues($this->segment('Erster Satz. Zweiter Satz. Dritter Satz.'));

        $previousEnd = -1;
        foreach ($cues as $cue) {
            self::assertGreaterThanOrEqual($previousEnd, $cue['start_ms']);
            self::assertGreaterThanOrEqual($cue['start_ms'], $cue['end_ms']);
            $previousEnd = $cue['end_ms'];
        }
    }
}
