<?php
declare(strict_types=1);

namespace Sonoquill\Audio;

/**
 * Reads the chapter marks (ID3v2 CHAP frames) from the start of an MP3 file.
 *
 * Needed for the assembly without ffmpeg: Auphonic adds intro and inserts
 * and writes correctly shifted chapters into the finished file, while its API
 * reports the chapters unshifted, as they were sent (tested 2026-09-26). The
 * file is therefore the reference.
 */
final class Id3Chapters
{
    /**
     * @return list<array{start_ms:int,end_ms:int,title:string}> in file order
     */
    public static function parse(string $data): array
    {
        if (strlen($data) < 10 || substr($data, 0, 3) !== 'ID3') {
            return [];
        }

        $major = ord($data[3]);
        if ($major !== 3 && $major !== 4) {
            return [];
        }
        $size = self::syncsafe(substr($data, 6, 4));
        $end = min(strlen($data), 10 + $size);
        $pos = 10;

        // Extended header, if flagged.
        if ((ord($data[5]) & 0x40) !== 0) {
            $ext = $major === 4 ? self::syncsafe(substr($data, 10, 4)) : unpack('N', substr($data, 10, 4))[1] + 4;
            $pos += $ext;
        }

        $chapters = [];
        while ($pos + 10 <= $end) {
            $id = substr($data, $pos, 4);
            if (trim($id, "\0") === '') {
                break; // padding
            }
            $frameSize = $major === 4 ? self::syncsafe(substr($data, $pos + 4, 4)) : unpack('N', substr($data, $pos + 4, 4))[1];
            $body = substr($data, $pos + 10, $frameSize);
            $pos += 10 + $frameSize;

            if ($id !== 'CHAP') {
                continue;
            }

            $zero = strpos($body, "\0");
            if ($zero === false || strlen($body) < $zero + 17) {
                continue;
            }
            [, $start, $stop] = unpack('N2', substr($body, $zero + 1, 8)) + [0, 0, 0];
            $chapters[] = [
                'start_ms' => $start,
                'end_ms'   => $stop,
                'title'    => self::title(substr($body, $zero + 17), $major),
            ];
        }

        usort($chapters, static fn (array $a, array $b): int => $a['start_ms'] <=> $b['start_ms']);

        return $chapters;
    }

    /**
     * The TIT2 sub-frame of a CHAP frame.
     */
    private static function title(string $subframes, int $major): string
    {
        $pos = 0;
        while ($pos + 10 <= strlen($subframes)) {
            $id = substr($subframes, $pos, 4);
            $size = $major === 4 ? self::syncsafe(substr($subframes, $pos + 4, 4)) : unpack('N', substr($subframes, $pos + 4, 4))[1];
            if ($id === 'TIT2') {
                return self::text(substr($subframes, $pos + 10, $size));
            }
            $pos += 10 + $size;
        }

        return '';
    }

    private static function text(string $frame): string
    {
        $encoding = ord($frame[0] ?? "\0");
        $raw = substr($frame, 1);
        $text = match ($encoding) {
            1       => (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16'),
            2       => (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE'),
            3       => $raw,
            default => (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1'),
        };

        return trim($text, "\0 \u{FEFF}");
    }

    private static function syncsafe(string $bytes): int
    {
        $b = array_values(unpack('C4', str_pad($bytes, 4, "\0")));

        return ($b[0] << 21) | ($b[1] << 14) | ($b[2] << 7) | $b[3];
    }
}
