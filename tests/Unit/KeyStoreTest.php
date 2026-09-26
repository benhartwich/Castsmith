<?php
declare(strict_types=1);

namespace PodcastForge\Tests\Unit;

use PodcastForge\Support\Crypto;
use PodcastForge\Support\CryptoException;
use PodcastForge\Support\KeyStore;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Die Konstante AASPF_ENCRYPTION_KEY ist im Testlauf zunächst nicht gesetzt.
 * Die Fälle, die sie brauchen, laufen deshalb in eigenen Prozessen — eine
 * einmal definierte Konstante ließe sich sonst nicht wieder loswerden.
 */
final class KeyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        KeyStore::reset();
    }

    public function testReportsMissingConstant(): void
    {
        self::assertFalse(defined(KeyStore::CONSTANT), 'Vorbedingung: Konstante darf hier nicht gesetzt sein.');
        self::assertFalse(KeyStore::isConfigured());
    }

    public function testCryptoRefusesWithoutConstant(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessageMatches('/missing from wp-config\.php/');
        KeyStore::crypto();
    }

    public function testMissingKeyMessageNamesTheConstant(): void
    {
        self::assertStringContainsString(KeyStore::CONSTANT, KeyStore::missingKeyMessage());
    }

    public function testSuggestedConfigLineIsValidPhpAndCarriesAUsableKey(): void
    {
        $line = KeyStore::suggestedConfigLine();

        self::assertSame(1, preg_match(
            "/^define\( '" . KeyStore::CONSTANT . "', '([A-Za-z0-9+\/=]+)' \);$/",
            $line,
            $m
        ), 'Die Zeile muss so aussehen, dass sie sich unverändert einfügen lässt: ' . $line);

        $crypto = Crypto::fromBase64Key($m[1]);
        self::assertSame('probe', $crypto->decrypt($crypto->encrypt('probe')));
    }

    public function testSuggestedConfigLinesDiffer(): void
    {
        self::assertNotSame(KeyStore::suggestedConfigLine(), KeyStore::suggestedConfigLine());
    }

    #[RunInSeparateProcess]
    public function testConfiguredConstantYieldsWorkingCrypto(): void
    {
        define(KeyStore::CONSTANT, Crypto::generateKeyBase64());

        self::assertTrue(KeyStore::isConfigured());
        $crypto = KeyStore::crypto();
        self::assertSame('geheim', $crypto->decrypt($crypto->encrypt('geheim')));
        self::assertSame($crypto, KeyStore::crypto(), 'Die Instanz soll zwischengespeichert werden.');
    }

    #[RunInSeparateProcess]
    public function testUnusableConstantIsReportedWithTheConstantName(): void
    {
        define(KeyStore::CONSTANT, 'das-ist-kein-schluessel');

        self::assertTrue(KeyStore::isConfigured());

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessageMatches('/' . KeyStore::CONSTANT . '.*unusable/');
        KeyStore::crypto();
    }

    #[RunInSeparateProcess]
    public function testWhitespaceOnlyConstantCountsAsMissing(): void
    {
        define(KeyStore::CONSTANT, '   ');

        self::assertFalse(KeyStore::isConfigured());
    }
}
