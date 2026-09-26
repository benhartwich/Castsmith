<?php
declare(strict_types=1);

namespace PodcastForge\Tests\Unit;

use PodcastForge\Audio\Mp3;
use PHPUnit\Framework\TestCase;

/**
 * Joining MP3 segments without ffmpeg: frames, duration, silence, formats.
 */
final class Mp3Test extends TestCase
{
    private const MONO = __DIR__ . '/../fixtures/audio/tone-mono.mp3';
    private const STEREO = __DIR__ . '/../fixtures/audio/tone-stereo.mp3';

    private string $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/pf-mp3-' . bin2hex(random_bytes(4)) . '.mp3';
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
    }

    public function testTagAndInfoFrameAreSkippedAndDurationIsCounted(): void
    {
        $frames = Mp3::frames((string) file_get_contents(self::MONO));

        self::assertSame(44100, $frames[0]['rate']);
        self::assertTrue($frames[0]['mono']);
        self::assertSame(192, $frames[0]['bitrate']);
        // 0.5 s plus encoder delay/padding frames: within two frames of the nominal length.
        self::assertEqualsWithDelta(500, Mp3::durationMs(self::MONO), 60);
    }

    public function testConcatWritesPausesOnTheFrameGrid(): void
    {
        $single = Mp3::durationMs(self::MONO);
        $written = Mp3::concat([self::MONO, self::MONO], [0 => 450], $this->out);

        // 450 ms at 26.12 ms per frame = 17 frames = 444 ms (±1 ms from rounding).
        self::assertEqualsWithDelta($single + 444, $written[0], 1);
        self::assertEqualsWithDelta($single, $written[1], 1);
        self::assertSame(array_sum($written), Mp3::durationMs($this->out));
        self::assertStringStartsNotWith('ID3', (string) file_get_contents($this->out));
    }

    public function testSilenceFramesKeepTheReferenceFormat(): void
    {
        $reference = Mp3::frames((string) file_get_contents(self::MONO))[0];
        $silence = Mp3::silenceFrames($reference, 1000);
        $frames = Mp3::frames($silence['data']);

        self::assertCount(38, $frames);
        foreach ($frames as $frame) {
            self::assertSame(192, $frame['bitrate']);
            self::assertTrue($frame['mono']);
        }
    }

    public function testDifferentChannelCountsAreRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Different MP3 format/');

        Mp3::concat([self::MONO, self::STEREO], [], $this->out);
    }

    public function testNonMp3InputIsNotMistakenForAudio(): void
    {
        self::assertNull(Mp3::durationMs(__FILE__));
    }
}
