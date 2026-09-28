<?php
declare(strict_types=1);

namespace Castsmith\Tests\Unit;

use Castsmith\Ai\AnthropicResponse;
use Castsmith\Ai\Pricing;
use PHPUnit\Framework\TestCase;

/**
 * Kostenschätzung: bis 25.09.2026 pauschal mit Opus-5-Preisen und als Euro
 * angezeigt. Jetzt nach Modell, Cache und Batch, in US-Cent.
 */
final class PricingTest extends TestCase
{
    public function testOpusFiveMatchesTheOldFlatRate(): void
    {
        // Redigat der Folge Oktober 2026: 16964 ein, 20663 aus, 60,14 Cent.
        self::assertSame(60.14, Pricing::cents('claude-opus-5', 16964, 20663));
    }

    public function testOpusFiveFiveIsTwentyPercentCheaper(): void
    {
        self::assertSame(48.11, Pricing::cents('claude-opus-5-5', 16964, 20663));
    }

    public function testLongestPrefixWins(): void
    {
        self::assertSame([4.0, 20.0], Pricing::rates('claude-opus-5-5'));
        self::assertSame([5.0, 25.0], Pricing::rates('claude-opus-5'));
        self::assertSame([2.0, 10.0], Pricing::rates('claude-sonnet-5'));
    }

    public function testBatchHalvesAndCacheIsWeighted(): void
    {
        // 1 Mio. Eingabe als Cache-Lesen kostet ein Zehntel, im Batch die Hälfte davon.
        self::assertSame(20.0, Pricing::cents('claude-opus-5-5', 0, 0, 0, 1000000, true));
        self::assertSame(500.0, Pricing::cents('claude-opus-5-5', 0, 0, 1000000, 0, false));
    }

    public function testBatchAnswerIsBookedOnce(): void
    {
        $response = new AnthropicResponse('x', 1000000, 0, 'end_turn', 'claude-sonnet-5', 0, 0, true);

        self::assertSame(100.0, $response->centsToBook());
        self::assertSame(0.0, $response->markBooked()->centsToBook());
        self::assertSame(100.0, $response->markBooked()->estimatedCents());
    }
}
