<?php
declare(strict_types=1);

namespace Sonoquill\Audio;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming and appending large audio files; WP_Filesystem would hold them in memory and is not set up in background jobs.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming and appending large audio files; WP_Filesystem would hold them in memory and is not set up in background jobs.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming and appending large audio files; WP_Filesystem would hold them in memory and is not set up in background jobs.

/**
 * MP3 files in plain PHP: frame parsing, duration, concatenation with silence.
 *
 * This is the assembly path for servers without ffmpeg. It relies on a
 * property of MP3: a stream is a sequence of independent frames, and frames
 * of the same format can simply be appended. The segments from the speech
 * synthesis all come in the same format (e.g. MPEG-1 Layer III, 44.1 kHz,
 * constant bitrate), so no re-encoding is needed.
 *
 * Pauses are frames without audio data: a Layer III frame whose side
 * information is all zeros carries no samples and decodes as digital silence.
 * They are built with the header of the surrounding frames, so the stream
 * keeps a constant bitrate and players compute the duration correctly.
 *
 * Tags (ID3v2 at the start, ID3v1 at the end) and the Xing/Info/VBRI frame
 * that encoders put first are dropped — in the middle of a stream they would
 * be noise or wrong metadata.
 */
final class Mp3
{
    private const BITRATES = [
        // [version][layer=3] in kbit/s; index 0 = free, 15 = bad
        1 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0],
        2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0],
    ];

    private const SAMPLE_RATES = [
        1   => [44100, 48000, 32000],
        2   => [22050, 24000, 16000],
        25  => [11025, 12000, 8000],
    ];

    /**
     * All audio frames of a file (without tags and without the info frame).
     *
     * @return list<array{offset:int,length:int,header:string,version:int,rate:int,mono:bool,samples:int,bitrate:int}>
     *
     * @throws \RuntimeException
     */
    public static function frames(string $data): array
    {
        $length = strlen($data);
        $pos = self::skipId3v2($data);
        $end = $length;
        if ($length >= 128 && substr($data, -128, 3) === 'TAG') {
            $end -= 128;
        }

        $frames = [];
        while ($pos + 4 <= $end) {
            $frame = self::parseHeader(substr($data, $pos, 4));
            if ($frame === null) {
                // Garbage between frames: resynchronise byte by byte.
                $pos++;
                continue;
            }
            if ($pos + $frame['length'] > $end) {
                break; // truncated last frame
            }

            $frame['offset'] = $pos;
            if ($frames === [] && self::isInfoFrame($data, $pos, $frame)) {
                $pos += $frame['length'];
                continue;
            }

            $frames[] = $frame;
            $pos += $frame['length'];
        }

        if ($frames === []) {
            throw new \RuntimeException('No MPEG audio frames found.');
        }

        return $frames;
    }

    /**
     * Duration in milliseconds, counted from the frames.
     */
    public static function durationMs(string $path): ?int
    {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            return null;
        }

        try {
            $frames = self::frames($data);
        } catch (\RuntimeException) {
            return null;
        }

        $samples = 0;
        foreach ($frames as $frame) {
            $samples += $frame['samples'];
        }

        return (int) round($samples * 1000 / $frames[0]['rate']);
    }

    /**
     * Appends files, with silence after each one.
     *
     * @param list<string>   $files       absolute paths, in order
     * @param array<int,int> $pausesAfter index => pause in milliseconds
     *
     * @return list<int> duration of each file plus its pause, in milliseconds, as actually
     *                   written; the parts add up exactly to the duration of the output
     *
     * @throws \RuntimeException when formats differ or a file is unreadable
     */
    public static function concat(array $files, array $pausesAfter, string $output): array
    {
        $out = fopen($output, 'wb');
        if ($out === false) {
            throw new \RuntimeException(sprintf('Cannot write %s.', $output));
        }

        $reference = null;
        $written = [];
        $total = 0; // samples written so far; parts are derived from it so they add up exactly

        try {
            foreach (array_values($files) as $index => $file) {
                $data = @file_get_contents($file);
                if ($data === false) {
                    throw new \RuntimeException(sprintf('Cannot read %s.', $file));
                }

                $frames = self::frames($data);
                $reference ??= $frames[0];
                self::assertCompatible($reference, $frames[0], $file);

                $samples = 0;
                foreach ($frames as $frame) {
                    fwrite($out, substr($data, $frame['offset'], $frame['length']));
                    $samples += $frame['samples'];
                }

                $pause = (int) ($pausesAfter[$index] ?? 0);
                $silent = $pause > 0 ? self::silenceFrames($reference, $pause) : ['data' => '', 'samples' => 0];
                fwrite($out, $silent['data']);

                $before = (int) round($total * 1000 / $reference['rate']);
                $total += $samples + $silent['samples'];
                $written[$index] = (int) round($total * 1000 / $reference['rate']) - $before;
            }
        } finally {
            fclose($out);
        }

        return $written;
    }

    /**
     * Silent frames in the format of $reference, as close to $ms as the frame grid allows.
     *
     * @param array{header:string,version:int,rate:int,mono:bool,samples:int,bitrate:int} $reference
     *
     * @return array{data:string,samples:int}
     */
    public static function silenceFrames(array $reference, int $ms): array
    {
        $count = max(1, (int) round($ms * $reference['rate'] / 1000 / $reference['samples']));

        // Header of the reference frame without CRC and without padding.
        $h = unpack('N', $reference['header'])[1];
        $h |= 0x00010000;   // protection bit set = no CRC
        $h &= ~0x00000200;  // padding bit cleared
        $header = pack('N', $h);

        $length = self::frameLength($reference['version'], $reference['bitrate'], $reference['rate'], false);
        $frame = $header . str_repeat("\0", $length - 4);

        return ['data' => str_repeat($frame, $count), 'samples' => $count * $reference['samples']];
    }

    /**
     * @return array{length:int,header:string,version:int,rate:int,mono:bool,samples:int,bitrate:int}|null
     */
    public static function parseHeader(string $bytes): ?array
    {
        if (strlen($bytes) < 4) {
            return null;
        }
        $h = unpack('N', $bytes)[1];

        if (($h & 0xFFE00000) !== 0xFFE00000) {
            return null;
        }
        $versionBits = ($h >> 19) & 0x3;
        $layerBits = ($h >> 17) & 0x3;
        $bitrateIndex = ($h >> 12) & 0xF;
        $rateIndex = ($h >> 10) & 0x3;
        $padding = (($h >> 9) & 0x1) === 1;
        $mono = (($h >> 6) & 0x3) === 3;

        if ($versionBits === 1 || $layerBits !== 1 || $bitrateIndex === 0 || $bitrateIndex === 15 || $rateIndex === 3) {
            return null; // reserved version, not Layer III, free/bad bitrate, reserved rate
        }

        $version = match ($versionBits) { 3 => 1, 2 => 2, default => 25 };
        $bitrate = self::BITRATES[$version === 1 ? 1 : 2][$bitrateIndex];
        $rate = self::SAMPLE_RATES[$version][$rateIndex];

        return [
            'length'  => self::frameLength($version, $bitrate, $rate, $padding),
            'header'  => substr($bytes, 0, 4),
            'version' => $version,
            'rate'    => $rate,
            'mono'    => $mono,
            'samples' => $version === 1 ? 1152 : 576,
            'bitrate' => $bitrate,
        ];
    }

    private static function frameLength(int $version, int $bitrate, int $rate, bool $padding): int
    {
        $factor = $version === 1 ? 144 : 72;

        return intdiv($factor * $bitrate * 1000, $rate) + ($padding ? 1 : 0);
    }

    private static function skipId3v2(string $data): int
    {
        $pos = 0;
        // Several tags in a row do occur.
        while (substr($data, $pos, 3) === 'ID3' && strlen($data) >= $pos + 10) {
            $size = (ord($data[$pos + 6]) << 21) | (ord($data[$pos + 7]) << 14) | (ord($data[$pos + 8]) << 7) | ord($data[$pos + 9]);
            $footer = (ord($data[$pos + 5]) & 0x10) !== 0 ? 10 : 0;
            $pos += 10 + $size + $footer;
        }

        return $pos;
    }

    /**
     * @param array{version:int,mono:bool} $frame
     */
    private static function isInfoFrame(string $data, int $pos, array $frame): bool
    {
        $side = $frame['version'] === 1 ? ($frame['mono'] ? 17 : 32) : ($frame['mono'] ? 9 : 17);
        $tag = substr($data, $pos + 4 + $side, 4);

        return $tag === 'Xing' || $tag === 'Info' || substr($data, $pos + 36, 4) === 'VBRI';
    }

    /**
     * @param array{version:int,rate:int,mono:bool} $reference
     * @param array{version:int,rate:int,mono:bool} $frame
     */
    private static function assertCompatible(array $reference, array $frame, string $file): void
    {
        if ($reference['version'] !== $frame['version'] || $reference['rate'] !== $frame['rate'] || $reference['mono'] !== $frame['mono']) {
            throw new \RuntimeException(sprintf(
                'Different MP3 format in %s (%d Hz, %s) — segments can only be joined without ffmpeg if they share sample rate and channels.',
                basename($file),
                $frame['rate'],
                $frame['mono'] ? 'mono' : 'stereo'
            ));
        }
    }
}
