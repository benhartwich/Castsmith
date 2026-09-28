<?php
declare(strict_types=1);

namespace Castsmith\Tests\Unit;

use Castsmith\Audio\Id3Chapters;
use PHPUnit\Framework\TestCase;

/**
 * Chapter marks from the finished file — the reference for the timeline when
 * Auphonic added opener and bridges.
 */
final class Id3ChaptersTest extends TestCase
{
    /**
     * @return array<string,array{0:string}>
     */
    public static function files(): array
    {
        return [
            'ID3v2.3' => [__DIR__ . '/../fixtures/audio/chapters-v23.mp3'],
            'ID3v2.4' => [__DIR__ . '/../fixtures/audio/chapters-v24.mp3'],
        ];
    }

    /**
     * @dataProvider files
     */
    public function testReadsStartsAndTitles(string $file): void
    {
        $chapters = Id3Chapters::parse((string) file_get_contents($file));

        self::assertSame([0, 400], array_column($chapters, 'start_ms'));
        self::assertSame(['Anfang', 'Zweites Kapitel – Ü'], array_column($chapters, 'title'));
    }

    public function testFileWithoutTagHasNoChapters(): void
    {
        self::assertSame([], Id3Chapters::parse((string) file_get_contents(__DIR__ . '/../fixtures/audio/tone-stereo.mp3')));
    }
}
