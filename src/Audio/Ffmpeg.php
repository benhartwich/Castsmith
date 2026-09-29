<?php
declare(strict_types=1);

namespace Sonoquill\Audio;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use Sonoquill\Settings\Options;
use Sonoquill\Support\ProcessRunner;

/**
 * Thin wrapper around ffmpeg and ffprobe.
 *
 * Calls go through ProcessRunner because many PHP-FPM setups block
 * `proc_open` and only allow `exec`.
 */
final class Ffmpeg
{
    public static function ffmpegPath(): string
    {
        $path = Options::get('ffmpeg_path');

        return $path !== '' ? $path : 'ffmpeg';
    }

    public static function ffprobePath(): string
    {
        $path = Options::get('ffprobe_path');

        return $path !== '' ? $path : 'ffprobe';
    }

    /**
     * Measured properties of an audio file.
     *
     * Durations must be measured — the chapter times depend on them,
     * and estimated values add up over twenty segments to an audible offset.
     *
     * @return array{duration_ms:int,sample_rate:int,channels:int,channel_layout:string}|null
     */
    public static function probe(string $path): ?array
    {
        if (!is_readable($path)) {
            return null;
        }

        $result = ProcessRunner::run([
            self::ffprobePath(),
            '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=sample_rate,channels,channel_layout:format=duration',
            '-of', 'json',
            $path,
        ]);

        if (!$result['ok']) {
            return null;
        }

        $data = json_decode($result['output'], true);
        if (!is_array($data)) {
            return null;
        }

        $stream = $data['streams'][0] ?? [];
        $duration = (float) ($data['format']['duration'] ?? 0);

        return [
            'duration_ms'    => (int) round($duration * 1000),
            'sample_rate'    => (int) ($stream['sample_rate'] ?? 44100),
            'channels'       => (int) ($stream['channels'] ?? 1),
            'channel_layout' => (string) ($stream['channel_layout'] ?? ((int) ($stream['channels'] ?? 1) === 2 ? 'stereo' : 'mono')),
        ];
    }

    /**
     * The decoded duration, not the container duration.
     *
     * The difference is not a subtlety: an MP3 file includes the encoder
     * padding in its container duration — for these segments roughly
     * fifty-three milliseconds each. The montage decodes and joins only the
     * real samples. If the chapter times were computed from the container
     * durations, the episode would drift out of sync by more than a second
     * over twenty-five segments. Measured: with decoded durations only
     * forty-nine milliseconds of deviation remain, and they sit once at the
     * end of the finished file.
     */
    public static function durationMs(string $path): ?int
    {
        if (!is_readable($path)) {
            return null;
        }

        $result = ProcessRunner::run([
            self::ffmpegPath(),
            '-v', 'error',
            '-i', $path,
            '-f', 'null',
            '-progress', 'pipe:1',
            '-',
        ]);

        if ($result['ok'] && preg_match_all('/^out_time_us=(\d+)$/m', $result['output'], $matches) > 0) {
            $microseconds = (int) end($matches[1]);

            if ($microseconds > 0) {
                return (int) round($microseconds / 1000);
            }
        }

        // Fall back to the container duration if the measurement does not work.
        $probe = self::probe($path);

        return $probe === null ? null : $probe['duration_ms'];
    }

    /**
     * Cuts room tone out of the material.
     *
     * Digital silence between two speech passages sounds like a dropout.
     * Measured: the pauses the model inserts itself sit at about minus
     * sixty-five decibels, a pause generated with `anullsrc` at minus
     * ninety-one — a difference of twenty-six decibels, and that is exactly
     * what you hear.
     *
     * So instead of silence, a quiet passage is cut from the material itself
     * and used for the pauses. It has the same noise floor and the same
     * timbre as the rest.
     *
     * Searches the segments in order: not every one has a sufficiently long
     * quiet passage.
     *
     * @param list<string> $sources
     *
     * @return string|null Path to the room tone file, or null if no quiet
     *                     passage was found anywhere.
     */
    public static function extractRoomTone(array $sources, string $target, float $seconds = 0.4): ?string
    {
        foreach ($sources as $source) {
            if (!is_readable($source)) {
                continue;
            }

            $found = self::findQuietPassage($source, $seconds);
            if ($found === null) {
                continue;
            }

            $result = ProcessRunner::run([
                self::ffmpegPath(), '-y', '-nostdin',
                '-ss', number_format($found, 3, '.', ''),
                '-t', number_format($seconds, 3, '.', ''),
                '-i', $source,
                '-c:a', 'pcm_s16le',
                $target,
            ]);

            if ($result['ok'] && is_readable($target) && filesize($target) > 1000) {
                return $target;
            }
        }

        return null;
    }

    /**
     * Finds the start of a sufficiently long quiet passage.
     */
    private static function findQuietPassage(string $source, float $seconds): ?float
    {
        $result = ProcessRunner::run([
            self::ffmpegPath(), '-nostdin',
            '-i', $source,
            '-af', sprintf('silencedetect=noise=-45dB:d=%s', number_format($seconds + 0.2, 2, '.', '')),
            '-f', 'null', '-',
        ]);

        if (preg_match('/silence_start:\s*([0-9.]+)/', $result['output'], $m) !== 1) {
            return null;
        }

        // Keep some distance from the edge so no decay is cut in.
        return (float) $m[1] + 0.08;
    }

    /**
     * Joins files together and inserts silence at the given positions.
     *
     * Without crossfade. This is intentional: the chapter start time is then
     * the exact sum of the measured preceding durations. Crossfades belong
     * with re-recorded segments, which bring a different recording
     * characteristic — that is where they are needed and where the offset
     * has to be accounted for.
     *
     * @param list<string>    $files       Absolute paths in order.
     * @param array<int,int>  $pausesAfter Index => pause length in milliseconds.
     *
     * @throws \RuntimeException
     */
    /** Fade-out at the end of a segment, in seconds. */
    private const FADE_OUT = 0.10;

    /** Fade-in at the start of a segment, in seconds. */
    private const FADE_IN = 0.04;

    /**
     * Wraps opener, outro and separators around the finished speech montage, in stereo.
     *
     * The voice (mono) is placed on both channels and delayed by the opener
     * time; the opener fades out during the overlap; the outro starts after
     * the last word; the separator sits in the chapter pauses lengthened for
     * it. All music is brought to -21 LUFS, slightly below the voice —
     * Auphonic levels everything globally afterwards.
     *
     * @param array{speech:int,outro:int,total:int} $plan        from MusicBed::plan()
     * @param list<string>                          $separators  Separator files.
     * @param list<array{ms:int,datei:int,dauer:int}> $separatorAt Separator starts on the speech montage timeline, with index into $separators and duration.
     *
     * @throws \RuntimeException
     */
    public static function wrapWithMusic(string $speech, ?string $opener, ?string $outro, string $output, array $plan, int $openerMs, int $overlapMs, array $separators = [], array $separatorAt = [], int $bitrateKbps = 192): void
    {
        $command = [self::ffmpegPath(), '-y', '-nostdin', '-i', $speech];
        $filters = [];
        $mix = [];
        $index = 1;

        $delay = (string) $plan['speech'];
        $filters[] = sprintf('[0:a]aresample=44100,aformat=channel_layouts=stereo,adelay=%1$s|%1$s[s]', $delay);
        $mix[] = '[s]';

        if ($opener !== null) {
            $command[] = '-i';
            $command[] = $opener;
            $fadeStart = number_format(max(0, $openerMs - $overlapMs) / 1000, 3, '.', '');
            $fadeLength = number_format(min($overlapMs, $openerMs) / 1000, 3, '.', '');
            $filters[] = sprintf(
                '[%d:a]aresample=44100,aformat=channel_layouts=stereo,loudnorm=I=-21:TP=-3:LRA=11,afade=t=out:st=%s:d=%s[o]',
                $index++,
                $fadeStart,
                $fadeLength
            );
            $mix[] = '[o]';
        }

        if ($outro !== null && $plan['outro'] >= 0) {
            $command[] = '-i';
            $command[] = $outro;
            $filters[] = sprintf(
                '[%1$d:a]aresample=44100,aformat=channel_layouts=stereo,loudnorm=I=-21:TP=-3:LRA=11,adelay=%2$d|%2$d[e]',
                $index++,
                $plan['outro']
            );
            $mix[] = '[e]';
        }

        // Read each separator file once and normalise its loudness, then copy
        // and shift it for every chapter boundary where it is used.
        $uses = [];
        foreach (array_values($separatorAt) as $n => $at) {
            $uses[(int) $at['datei']][] = $n;
        }
        foreach ($uses as $file => $boundaries) {
            if (!isset($separators[$file])) {
                continue;
            }
            $command[] = '-i';
            $command[] = $separators[$file];
            $labels = array_map(static fn (int $n): string => '[tr' . $n . ']', $boundaries);
            // Fade out over the end so the bridge gets quieter under the
            // incoming voice (quadratic: quickly gone, softly trailing off).
            $dauer = (int) $separatorAt[$boundaries[0]]['dauer'];
            $fadeMs = min(\Sonoquill\Audio\MusicBed::SEPARATOR_FADE_MS, (int) ($dauer / 2));
            $filters[] = sprintf(
                '[%d:a]aresample=44100,aformat=channel_layouts=stereo,loudnorm=I=-21:TP=-3:LRA=11,aresample=44100,afade=t=in:d=0.05,afade=t=out:st=%s:d=%s:curve=qua%s',
                $index++,
                number_format(max(0, $dauer - $fadeMs) / 1000, 3, '.', ''),
                number_format($fadeMs / 1000, 3, '.', ''),
                count($labels) > 1 ? ',asplit=' . count($labels) . implode('', $labels) : $labels[0]
            );
            foreach ($boundaries as $n) {
                $ms = $plan['speech'] + (int) $separatorAt[$n]['ms'];
                $filters[] = sprintf('[tr%1$d]adelay=%2$d|%2$d[t%1$d]', $n, $ms);
                $mix[] = '[t' . $n . ']';
            }
        }

        // normalize=0: otherwise amix would divide every track by the number
        // of inputs, and the voice would be a third quieter.
        $filters[] = implode('', $mix) . sprintf('amix=inputs=%d:duration=longest:dropout_transition=0:normalize=0[m]', count($mix));

        $command = array_merge($command, [
            '-filter_complex', implode(';', $filters),
            '-map', '[m]',
            '-ar', '44100',
            '-ac', '2',
            '-c:a', 'libmp3lame',
            '-b:a', $bitrateKbps . 'k',
            $output,
        ]);

        $result = ProcessRunner::run($command);
        if (!$result['ok'] || !is_readable($output)) {
            throw new \RuntimeException(__('The music could not be mixed in: ', 'sonoquill') . mb_substr($result['output'] !== '' ? $result['output'] : $result['error'], -300));
        }
    }

    /**
     * @param list<string>   $files
     * @param array<int,int> $pausesAfter
     * @param array<int,int> $durationsMs Measured duration per file, for the fade-out.
     */
    public static function concat(
        array $files,
        array $pausesAfter,
        string $output,
        bool $normalize = false,
        ?string $roomTone = null,
        array $durationsMs = [],
        int $bitrateKbps = 192
    ): void {
        if ($files === []) {
            throw new \RuntimeException(__('There is nothing to assemble.', 'sonoquill'));
        }

        $reference = self::probe($files[0]);
        if ($reference === null) {
            /* translators: %s: path of the first audio file */
            throw new \RuntimeException(sprintf(__('The first file could not be read: %s', 'sonoquill'), $files[0]));
        }

        $rate = $reference['sample_rate'];
        $layout = $reference['channel_layout'] !== '' ? $reference['channel_layout'] : 'mono';

        $command = [self::ffmpegPath(), '-y', '-nostdin'];
        $labels = [];
        $speechInputs = [];
        $inputIndex = 0;

        foreach ($files as $position => $file) {
            $command[] = '-i';
            $command[] = $file;
            $speechInputs[$inputIndex] = $position;
            $labels[] = $inputIndex++;

            $pause = (int) ($pausesAfter[$position] ?? 0);
            if ($pause > 0) {
                $laenge = number_format($pause / 1000, 3, '.', '');

                if ($roomTone !== null && is_readable($roomTone)) {
                    // Room tone in a loop, trimmed to the pause length.
                    $command[] = '-stream_loop';
                    $command[] = '-1';
                    $command[] = '-t';
                    $command[] = $laenge;
                    $command[] = '-i';
                    $command[] = $roomTone;
                } else {
                    $command[] = '-f';
                    $command[] = 'lavfi';
                    $command[] = '-t';
                    $command[] = $laenge;
                    $command[] = '-i';
                    $command[] = sprintf('anullsrc=r=%d:cl=%s', $rate, $layout);
                }

                $labels[] = $inputIndex++;
            }
        }

        // Every input is brought to the same format, otherwise the concat
        // filter refuses to work.
        //
        // For re-recorded segments, loudness matching is added.
        // Auphonic levels globally later, but not within a single
        // file between two speaking styles. The matching does not change the
        // duration and therefore leaves the chapter times untouched — unlike
        // a crossfade, which would shorten every joint by its own length.
        $loudness = $normalize ? ',loudnorm=I=-19:TP=-2:LRA=7' : '';

        // Speech segments end in the middle of the decay — measured, the last
        // hundred and fifty milliseconds sit at minus thirty-eight decibels.
        // Without a fade-out the montage drops from there to the pause level
        // within a single sample, and exactly that edge is heard as a sudden
        // cut-off. The fade does not change the duration and leaves the
        // chapter times untouched.
        $filter = '';
        foreach ($labels as $order => $index) {
            $blende = '';

            if (isset($speechInputs[$index])) {
                $dauer = (int) ($durationsMs[$speechInputs[$index]] ?? 0);
                $blende = sprintf(',afade=t=in:st=0:d=%s', number_format(self::FADE_IN, 3, '.', ''));

                if ($dauer > (int) ((self::FADE_OUT + self::FADE_IN) * 1000) + 100) {
                    $blende .= sprintf(
                        ',afade=t=out:st=%s:d=%s',
                        number_format($dauer / 1000 - self::FADE_OUT, 3, '.', ''),
                        number_format(self::FADE_OUT, 3, '.', '')
                    );
                }
            }

            $filter .= sprintf(
                '[%d:a]aformat=sample_rates=%d:channel_layouts=%s%s%s[a%d];',
                $index,
                $rate,
                $layout,
                $loudness,
                $blende,
                $order
            );
        }
        foreach (array_keys($labels) as $order) {
            $filter .= sprintf('[a%d]', $order);
        }
        $filter .= sprintf('concat=n=%d:v=0:a=1[out]', count($labels));

        $command[] = '-filter_complex';
        $command[] = $filter;
        $command[] = '-map';
        $command[] = '[out]';
        $command[] = '-c:a';
        $command[] = 'libmp3lame';
        $command[] = '-b:a';
        $command[] = $bitrateKbps . 'k';
        $command[] = $output;

        $result = ProcessRunner::run($command);

        if (!$result['ok'] || !is_readable($output)) {
            throw new \RuntimeException(__('ffmpeg failed: ', 'sonoquill') . mb_substr($result['output'], -500));
        }
    }
}
