<?php
declare(strict_types=1);

namespace PodcastForge\Support;

/**
 * Evaluation of an episode's run log.
 *
 * A separate class because it works without WordPress and can therefore be
 * tested — `Recovery` itself cannot, since its class constants reference
 * WordPress constants.
 */
final class RunLog
{
    /** How a failed step can be recognised. */
    // German as before; English: the words the translated messages use for a
    // failure. Not the generic "could not" — it also appears in mere notes
    // ("Without music: the music could not be mixed in").
    private const FEHLERWORTE = '/fehlgeschlagen|nicht startbar|abgebrochen|nicht angenommen|nicht entfernbar|lässt sich nicht|\\bfailed\\b|could not be started|\\baborted\\b|not accepted|cannot be removed/iu';

    /**
     * Does this log entry sound like a failure?
     */
    public static function isFailure(string $text): bool
    {
        return preg_match(self::FEHLERWORTE, $text) === 1;
    }

    /**
     * The most recent step whose latest entry ended in an error.
     *
     * Only the last entry of each step is considered: if the synthesis runs
     * through again after a failure, the failure is resolved and should no
     * longer be reported. If it remains the last entry of its step, something
     * is stuck there — and that is reported immediately, not only after a
     * timeout has elapsed.
     *
     * @param list<array<string,mixed>> $entries
     *
     * @return array{schritt:string,zeit:string,text:string}|null
     */
    public static function lastFailure(array $entries): ?array
    {
        $letzte = [];

        foreach ($entries as $entry) {
            $schritt = trim((string) ($entry['schritt'] ?? ''));
            if ($schritt === '') {
                continue;
            }

            $letzte[$schritt] = [
                'schritt' => $schritt,
                'zeit'    => (string) ($entry['zeit'] ?? ''),
                'text'    => (string) ($entry['text'] ?? ''),
            ];
        }

        $treffer = null;

        foreach ($letzte as $entry) {
            if (preg_match(self::FEHLERWORTE, $entry['text']) !== 1) {
                continue;
            }

            // If several steps are stuck, the most recent one counts.
            if ($treffer === null || strcmp($entry['zeit'], $treffer['zeit']) > 0) {
                $treffer = $entry;
            }
        }

        return $treffer;
    }
}
