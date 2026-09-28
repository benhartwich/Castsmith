<?php
declare(strict_types=1);

namespace Castsmith\Tests\Unit;

use Castsmith\Segments\Segmenter;
use PHPUnit\Framework\TestCase;

/**
 * Absätze werden Segmente — auch wenn das Skript aus einem Browser-Textfeld
 * mit \r\n kommt, und nie länger, als ElevenLabs annimmt.
 */
final class SegmenterTest extends TestCase
{
    public function testWindowsLineEndingsStillSeparateParagraphs(): void
    {
        $script = "Erster Absatz.\r\n\r\nZweiter Absatz.\r\n\r\n<break time=\"1.5s\" />\r\n\r\nDritter Absatz.";

        $plan = Segmenter::plan($script);

        self::assertSame(['Erster Absatz.', 'Zweiter Absatz.', 'Dritter Absatz.'], array_column($plan, 'text'));
    }

    public function testLongParagraphIsSplitAtSentenceEnds(): void
    {
        $sentence = str_repeat('Wort ', 39) . 'Ende.';
        $paragraph = trim(str_repeat($sentence . ' ', 40));

        $pieces = Segmenter::limit($paragraph);

        self::assertGreaterThan(1, count($pieces));
        foreach ($pieces as $piece) {
            self::assertLessThanOrEqual(Segmenter::MAX_SEGMENT_CHARS, mb_strlen($piece));
            self::assertStringEndsWith('Ende.', $piece);
        }
        self::assertSame($paragraph, implode(' ', $pieces));
    }

    public function testShortParagraphStaysWhole(): void
    {
        self::assertSame(['Kurz und gut.'], Segmenter::limit('Kurz und gut.'));
    }

    public function testDisclosureGoesBeforeTheGoodbye(): void
    {
        $script = "Willkommen.\n\n<break time=\"1.5s\" />\n\nInhalt.\n\nClear Skies!";

        $plan = Segmenter::plan($script, [], 'Hinweis in eigener Sache.');

        self::assertSame(['Willkommen.', 'Inhalt.', 'Hinweis in eigener Sache.', 'Clear Skies!'], array_column($plan, 'text'));
        self::assertSame(['intro', 'kapitelbeginn', 'ki_hinweis', 'outro'], array_column($plan, 'kind'));
    }

    public function testDisclosureIsAppendedWhenTheLastSegmentStartsAChapter(): void
    {
        $plan = Segmenter::plan("Willkommen.\n\n<break time=\"1.5s\" />\n\nEin Satz zum Schluss.", [], 'Hinweis.');

        self::assertSame(['intro', 'kapitelbeginn', 'ki_hinweis'], array_column($plan, 'kind'));
    }

    public function testNoDisclosureWithoutText(): void
    {
        $plan = Segmenter::plan("Willkommen.\n\nClear Skies!", [], '   ');

        self::assertSame(['intro', 'outro'], array_column($plan, 'kind'));
    }
}
