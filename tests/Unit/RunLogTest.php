<?php
declare(strict_types=1);

namespace PodcastForge\Tests\Unit;

use PodcastForge\Support\RunLog;
use PHPUnit\Framework\TestCase;

/**
 * Der Fall, der die erste Folge drei Stunden lang still hängen ließ.
 */
final class RunLogTest extends TestCase
{
    public function testAFailedStepIsFoundImmediately(): void
    {
        $treffer = RunLog::lastFailure([
            ['zeit' => '2026-09-01 05:52:25', 'schritt' => 'auphonic', 'text' => 'Produktion gestartet, 19 MB hochgeladen.'],
            ['zeit' => '2026-09-01 05:52:53', 'schritt' => 'auphonic', 'text' => 'Rückruf erhalten, Zustand "Done".'],
            ['zeit' => '2026-09-01 05:53:03', 'schritt' => 'podlove',  'text' => 'Fehlgeschlagen: Herunterladen fehlgeschlagen, HTTP 403.'],
        ]);

        self::assertNotNull($treffer);
        self::assertSame('podlove', $treffer['schritt']);
        self::assertSame('2026-09-01 05:53:03', $treffer['zeit']);
    }

    public function testAStepThatRanThroughAfterwardsIsNoLongerReported(): void
    {
        // Genau das unterscheidet "hängt" von "hat unterwegs gehakt".
        $treffer = RunLog::lastFailure([
            ['zeit' => '2026-09-01 05:53:03', 'schritt' => 'podlove', 'text' => 'Fehlgeschlagen: Herunterladen fehlgeschlagen, HTTP 403.'],
            ['zeit' => '2026-09-01 06:03:52', 'schritt' => 'podlove', 'text' => 'Entwurf #28705 angelegt. Datei AASPodcastSeptember2026.mp3 (19 MB).'],
        ]);

        self::assertNull($treffer);
    }

    public function testTheYoungestOfSeveralStuckStepsWins(): void
    {
        $treffer = RunLog::lastFailure([
            ['zeit' => '2026-09-01 05:10:00', 'schritt' => 'synthese', 'text' => 'Segment 4 fehlgeschlagen: Zeitüberschreitung.'],
            ['zeit' => '2026-09-01 05:53:03', 'schritt' => 'podlove',  'text' => 'Fehlgeschlagen: HTTP 403.'],
        ]);

        self::assertNotNull($treffer);
        self::assertSame('podlove', $treffer['schritt']);
    }

    public function testACleanRunReportsNothing(): void
    {
        $treffer = RunLog::lastFailure([
            ['zeit' => '2026-09-01 05:43:16', 'schritt' => 'montage', 'text' => 'Fertig: 25 Segmente, 6 Kapitel, Dauer 13:43.'],
            ['zeit' => '2026-09-01 06:03:52', 'schritt' => 'podlove', 'text' => 'Entwurf #28705 angelegt.'],
        ]);

        self::assertNull($treffer);
    }

    public function testEntriesWithoutAStepAreSkipped(): void
    {
        self::assertNull(RunLog::lastFailure([['zeit' => '2026-09-01 05:00:00', 'text' => 'fehlgeschlagen']]));
        self::assertNull(RunLog::lastFailure([]));
    }

    /**
     * @dataProvider fehlerformulierungen
     */
    public function testTheUsualWordingsForAFailureAreRecognised(string $text): void
    {
        $treffer = RunLog::lastFailure([['zeit' => '2026-09-01 05:00:00', 'schritt' => 'x', 'text' => $text]]);

        self::assertNotNull($treffer, $text);
    }

    /**
     * Wortlaute, die im Code tatsächlich vorkommen.
     *
     * @return array<string,array{string}>
     */
    public static function fehlerformulierungen(): array
    {
        return [
            'Publish'   => ['Fehlgeschlagen: Herunterladen fehlgeschlagen, HTTP 403.'],
            'Synthese'  => ['Segment 7 fehlgeschlagen: Verbindungsfehler.'],
            'Startfehler' => ['Nicht startbar: Es ist kein Wörterbuch eingetragen.'],
            'Serie'     => ['Nach drei Fehlern abgebrochen.'],
            'Schreiben' => ['Datei lässt sich nicht schreiben: /pfad/zur/datei.mp3'],
        ];
    }
}
