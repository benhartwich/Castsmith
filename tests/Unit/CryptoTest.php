<?php
declare(strict_types=1);

namespace PodcastForge\Tests\Unit;

use PodcastForge\Support\Crypto;
use PodcastForge\Support\CryptoException;
use PHPUnit\Framework\TestCase;

/**
 * Die Testdaten sind erfundene Zeichenketten. Es steht bewusst kein echter
 * API-Key in dieser Datei.
 */
final class CryptoTest extends TestCase
{
    private function crypto(): Crypto
    {
        return new Crypto(str_repeat("\x01", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function testRoundTrip(): void
    {
        $c = $this->crypto();
        $secret = 'sk-beispiel-kein-echter-key-0123456789';

        self::assertSame($secret, $c->decrypt($c->encrypt($secret)));
    }

    public function testRoundTripEmptyString(): void
    {
        $c = $this->crypto();

        self::assertSame('', $c->decrypt($c->encrypt('')));
    }

    public function testRoundTripUnicodeAndBinary(): void
    {
        $c = $this->crypto();
        $secret = "Grüße vom Kahlenberg — ☄\n\x00\xff binär";

        self::assertSame($secret, $c->decrypt($c->encrypt($secret)));
    }

    public function testRoundTripLongValue(): void
    {
        $c = $this->crypto();
        $secret = random_bytes(64 * 1024);

        self::assertSame($secret, $c->decrypt($c->encrypt($secret)));
    }

    public function testCiphertextDiffersAcrossCallsBecauseOfTheNonce(): void
    {
        $c = $this->crypto();

        self::assertNotSame($c->encrypt('gleicher Text'), $c->encrypt('gleicher Text'));
    }

    public function testEnvelopeCarriesVersionPrefix(): void
    {
        $envelope = $this->crypto()->encrypt('irgendwas');

        self::assertStringStartsWith(Crypto::ENVELOPE_PREFIX, $envelope);
        self::assertTrue(Crypto::looksEncrypted($envelope));
        self::assertFalse(Crypto::looksEncrypted('irgendwas'));
    }

    public function testPlaintextIsNotContainedInTheEnvelope(): void
    {
        $secret = 'streng-geheimer-marker-4711';

        self::assertStringNotContainsString($secret, $this->crypto()->encrypt($secret));
    }

    public function testWrongKeyIsReportedAsWrongKey(): void
    {
        $envelope = $this->crypto()->encrypt('geheim');
        $other = new Crypto(str_repeat("\x02", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessageMatches('/does not match the stored credentials/');
        $other->decrypt($envelope);
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $c = $this->crypto();
        $raw = base64_decode(substr($c->encrypt('geheim'), strlen(Crypto::ENVELOPE_PREFIX)), true);
        self::assertIsString($raw);

        // Letztes Byte kippen: Fingerabdruck und Nonce bleiben gültig,
        // nur der Chiffretext ist verfälscht — das muss der MAC fangen.
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === "\x00" ? "\x01" : "\x00";

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessageMatches('/altered or is damaged/');
        $c->decrypt(Crypto::ENVELOPE_PREFIX . base64_encode($raw));
    }

    public function testTruncatedEnvelopeIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessageMatches('/too short/');
        $this->crypto()->decrypt(Crypto::ENVELOPE_PREFIX . base64_encode('zu-kurz'));
    }

    public function testMissingPrefixIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessageMatches('/envelope format/');
        $this->crypto()->decrypt('einfach-klartext');
    }

    public function testInvalidBase64IsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessageMatches('/Base64/');
        $this->crypto()->decrypt(Crypto::ENVELOPE_PREFIX . 'kein base64 !!!');
    }

    public function testKeyOfWrongLengthIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessageMatches('/32 bytes long/');
        new Crypto('zu kurz');
    }

    public function testGeneratedKeyIsUsable(): void
    {
        $key = Crypto::generateKeyBase64();
        $c = Crypto::fromBase64Key($key);

        self::assertSame(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            strlen((string) base64_decode($key, true))
        );
        self::assertSame('probe', $c->decrypt($c->encrypt('probe')));
    }

    public function testGeneratedKeysDiffer(): void
    {
        self::assertNotSame(Crypto::generateKeyBase64(), Crypto::generateKeyBase64());
    }

    public function testEmptyKeyIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessageMatches('/empty/');
        Crypto::fromBase64Key('   ');
    }

    public function testNonBase64KeyIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessageMatches('/Base64/');
        Crypto::fromBase64Key('!!! kein base64 !!!');
    }

    public function testKeyIsAcceptedWithSurroundingWhitespace(): void
    {
        $key = Crypto::generateKeyBase64();

        self::assertSame('x', Crypto::fromBase64Key("  \n" . $key . "\n ")->decrypt(
            Crypto::fromBase64Key($key)->encrypt('x')
        ));
    }
}
