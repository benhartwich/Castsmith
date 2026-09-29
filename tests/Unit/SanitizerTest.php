<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Text\Sanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Admin texts are cleaned with an allowlist: only the pause tag survives.
 */
final class SanitizerTest extends TestCase
{
    public function testKeepsPauseTagsInAFixedForm(): void
    {
        self::assertSame(
            "Erster Satz.\n<break time=\"1.5s\" />\nZweiter Satz. <break time=\"1.0s\" /> Dritter.",
            Sanitizer::script("Erster Satz.\r\n<break time=\"1.5s\"/>\r\nZweiter Satz. <BREAK time='1.0s' onclick=\"x()\"> Dritter.")
        );
    }

    public function testRemovesAllOtherMarkupWithScriptContent(): void
    {
        self::assertSame(
            'x fett  y Link',
            Sanitizer::script('x <b>fett</b> <script>alert(1)</script> y <a href="javascript:x">Link</a><!-- Kommentar -->')
        );
    }

    public function testLoneAngleBracketsAndAmpersandsStayText(): void
    {
        self::assertSame('Wert <5 % oder 1<2, heller als < 3 mag & > 2', Sanitizer::plain('Wert <5 % oder 1<2, heller als < 3 mag & > 2'));
    }

    public function testPlainTextKeepsNoPauseTags(): void
    {
        self::assertSame('Satz. Nächster.', Sanitizer::plain('Satz. <break time="1.5s" />Nächster.'));
    }

    public function testControlCharactersGoLineBreaksAndTabsStay(): void
    {
        self::assertSame("a\tb\nc", Sanitizer::plain("a\tb\u{0000}\u{200B}\nc\u{0007}"));
    }

    public function testMarkersInTheInputCannotSmuggleATag(): void
    {
        self::assertSame('<break time="1.5s" /> x0', Sanitizer::script("<break time=\"1.5s\" /> x\u{E000}0\u{E001}"));
    }
}
