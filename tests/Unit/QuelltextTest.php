<?php
declare(strict_types=1);

namespace PodcastForge\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Prüfungen über den Quelltext selbst.
 *
 * Anlass: ein maskiertes Dollarzeichen in einem sprintf-Platzhalter
 * (`%1\$s` statt `%1$s`) hat die Folgenansicht zum Absturz gebracht — und
 * zwar nur in einem Zustand, den kein Test erreicht hatte. So ein Fehler
 * entsteht leicht bei Bearbeitungen über Skripte und fällt sonst erst im
 * Betrieb auf.
 */
final class QuelltextTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function testThereAreSourceFilesToCheck(): void
    {
        self::assertGreaterThan(50, count($this->sourceFiles()));
    }

    public function testNoEscapedDollarInPositionalPlaceholders(): void
    {
        $broken = [];

        foreach ($this->sourceFiles() as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $number => $line) {
                if (preg_match('/%\d+\\\\\$[sd]/', $line) === 1) {
                    $broken[] = basename($file) . ':' . ($number + 1);
                }
            }
        }

        self::assertSame([], $broken, 'Maskiertes Dollarzeichen im Platzhalter — sprintf bricht damit ab.');
    }

    public function testEveryFileDeclaresStrictTypes(): void
    {
        $missing = [];

        foreach ($this->sourceFiles() as $file) {
            $head = (string) file_get_contents($file);
            if (!str_contains($head, 'declare(strict_types=1);')) {
                $missing[] = basename($file);
            }
        }

        self::assertSame([], $missing);
    }

    public function testNoLeftoverDebugCalls(): void
    {
        $found = [];

        foreach ($this->sourceFiles() as $file) {
            $content = (string) file_get_contents($file);

            foreach (['var_dump', 'print_r', 'var_export'] as $needle) {
                // var_export steht legitim in Fehlermeldungen des Health-Checks.
                if ($needle === 'var_export' && str_contains($file, 'Health')) {
                    continue;
                }

                if (preg_match('/(?<![\w>])' . $needle . '\s*\(/', $content) === 1) {
                    $found[] = basename($file) . ' → ' . $needle;
                }
            }
        }

        self::assertSame([], $found, 'Vergessene Debug-Ausgabe im Quelltext.');
    }

    public function testDirectDieIsAlwaysTheWordPressVariant(): void
    {
        // wp_die() gibt eine ordentliche Seite aus und lässt sich in Tests
        // abfangen; ein nacktes die() beendet den Prozess wortlos.
        $found = [];

        foreach ($this->sourceFiles() as $file) {
            $content = (string) file_get_contents($file);
            if (preg_match('/(?<![\w_])die\s*\(/', $content) === 1) {
                $found[] = basename($file);
            }
        }

        self::assertSame([], $found, 'Nacktes die() statt wp_die().');
    }
}
