<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Voice\TextToSpeech;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der Nachbartext ist modellabhängig.
 *
 * Eleven v3 lehnt `previous_text` und `next_text` mit HTTP 400 ab. Ohne diese
 * Fallunterscheidung schlägt nach einem Modellwechsel jeder einzelne Aufruf
 * fehl — und zwar erst zur Laufzeit, mitten in einer Folge.
 */
final class TextToSpeechModelTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function models(): array
    {
        return [
            'multilingual v2'   => ['eleven_multilingual_v2', true],
            'flash v2.5'        => ['eleven_flash_v2_5', true],
            'turbo v2.5'        => ['eleven_turbo_v2_5', true],
            'eleven v3'         => ['eleven_v3', false],
            'v3 conversational' => ['eleven_v3_conversational', false],
        ];
    }

    #[DataProvider('models')]
    public function testNeighbourTextSupport(string $model, bool $expected): void
    {
        self::assertSame($expected, TextToSpeech::modelSupportsNeighbourText($model));
    }

    public function testUnknownModelIsAssumedToSupportIt(): void
    {
        // Ein noch unbekanntes Modell wird wie v2 behandelt. Ein zu viel
        // gesendetes Feld fällt sofort auf; ein fehlendes verschlechtert die
        // Prosodie unbemerkt.
        self::assertTrue(TextToSpeech::modelSupportsNeighbourText('eleven_irgendwas_v9'));
    }
}
