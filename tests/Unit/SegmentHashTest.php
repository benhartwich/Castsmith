<?php
declare(strict_types=1);

namespace PodcastForge\Tests\Unit;

use PodcastForge\Segments\SegmentHash;
use PHPUnit\Framework\TestCase;

/**
 * Der Fingerabdruck entscheidet, was neu erzeugt und damit bezahlt wird.
 * Er darf weder zu viel noch zu wenig auslösen.
 */
final class SegmentHashTest extends TestCase
{
    /**
     * @return list<array{grapheme:string,type:string,value:string}>
     */
    private function rules(string $fomalhaut = 'Fohmalhaut'): array
    {
        return [
            ['grapheme' => 'Fomalhaut', 'type' => 'alias', 'value' => $fomalhaut],
            ['grapheme' => 'Algedi',    'type' => 'alias', 'value' => 'Algeddi'],
            ['grapheme' => 'Wega',      'type' => 'alias', 'value' => 'Wehga'],
        ];
    }

    public function testOnlyMatchingRulesApply(): void
    {
        $hits = SegmentHash::applicableRules('Tief im Süden steht Fomalhaut.', $this->rules());

        self::assertCount(1, $hits);
        self::assertSame('Fomalhaut', $hits[0]['grapheme']);
    }

    public function testTextWithoutAnyTermHasTheEmptyFingerprint(): void
    {
        self::assertSame('leer', SegmentHash::dictionaryFingerprint('Der Mond geht auf.', $this->rules()));
    }

    public function testChangedPronunciationChangesTheFingerprint(): void
    {
        // Der Anlass: die Fomalhaut-Regel wurde von "Fohmalhaut" auf
        // "Fomal-hut" umgestellt. Liefe der Fingerabdruck nur über die
        // Graphem-Namen, bliebe er gleich — und das Segment behielte sein
        // altes Audio mit der alten, falschen Aussprache.
        $text = 'Tief im Süden steht Fomalhaut.';

        self::assertNotSame(
            SegmentHash::dictionaryFingerprint($text, $this->rules('Fohmalhaut')),
            SegmentHash::dictionaryFingerprint($text, $this->rules('Fomal-hut')),
            'Eine geänderte Aussprache muss das Segment ungültig machen.'
        );
    }

    public function testChangeToAnUnrelatedRuleLeavesTheFingerprintAlone(): void
    {
        $text = 'Tief im Süden steht Fomalhaut.';
        $andere = $this->rules();
        $andere[2]['value'] = 'Weega';

        self::assertSame(
            SegmentHash::dictionaryFingerprint($text, $this->rules()),
            SegmentHash::dictionaryFingerprint($text, $andere),
            'Eine Regel, die diesen Text nicht betrifft, darf nichts auslösen.'
        );
    }

    public function testRuleOrderDoesNotMatter(): void
    {
        $text = 'Fomalhaut und Wega stehen am Himmel.';

        self::assertSame(
            SegmentHash::dictionaryFingerprint($text, $this->rules()),
            SegmentHash::dictionaryFingerprint($text, array_reverse($this->rules()))
        );
    }

    public function testMatchingRespectsWordBoundaries(): void
    {
        // ElevenLabs vergleicht nur an Wortgrenzen. Der Fingerabdruck muss
        // dieselbe Teilmenge treffen wie der Dienst.
        self::assertSame([], SegmentHash::applicableRules('Wegastaub am Himmel.', $this->rules()));
        self::assertCount(1, SegmentHash::applicableRules('Wega am Himmel.', $this->rules()));
    }

    public function testMatchingRespectsCase(): void
    {
        self::assertSame([], SegmentHash::applicableRules('tief steht wega.', $this->rules()));
    }

    public function testGraphemeListStillWorksForThePrompt(): void
    {
        self::assertSame(
            ['Fomalhaut'],
            SegmentHash::applicableGraphemes('Tief im Süden steht Fomalhaut.', ['Fomalhaut', 'Algedi'])
        );
    }
}
