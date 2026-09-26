<?php
declare(strict_types=1);

namespace PodcastForge\Support;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

/**
 * Symmetric encryption of the API credentials.
 *
 * Method: libsodium crypto_secretbox (XSalsa20-Poly1305), i.e.
 * authenticated encryption — a tampered ciphertext is detected and
 * is not decrypted.
 *
 * Envelope format:  v1.<base64( fingerprint[4] || nonce[24] || ciphertext )>
 *
 * The fingerprint is four bytes of a BLAKE2b hash of the key. It serves
 * diagnostic purposes only: if the key in wp-config.php is replaced,
 * "wrong key" can be distinguished from "corrupted data" instead of
 * dismissing both cases with the same meaningless message.
 *
 * This class does not know about WordPress and can be tested without WordPress.
 * For that reason, its exception messages are not wrapped in WordPress
 * translation calls; they still need to be translated to English at the
 * point where they are shown to the user.
 */
final class Crypto
{
    public const ENVELOPE_PREFIX = 'v1.';

    private const FINGERPRINT_BYTES = 4;

    private string $key;

    /**
     * @param string $key Raw key, exactly SODIUM_CRYPTO_SECRETBOX_KEYBYTES bytes.
     *
     * @throws CryptoException
     */
    public function __construct(string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new CryptoException(sprintf(
                'The encryption key must be %d bytes long, but has %d bytes.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                strlen($key)
            ));
        }

        $this->key = $key;
    }

    /**
     * Generates a new key in the form that belongs in wp-config.php.
     */
    public static function generateKeyBase64(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * Builds an instance from the Base64 notation as it appears in wp-config.php.
     *
     * @throws CryptoException
     */
    public static function fromBase64Key(string $base64Key): self
    {
        $trimmed = trim($base64Key);
        if ($trimmed === '') {
            throw new CryptoException('The encryption key is empty.');
        }

        $raw = base64_decode($trimmed, true);
        if ($raw === false) {
            throw new CryptoException('The encryption key is not valid Base64.');
        }

        return new self($raw);
    }

    /**
     * @throws CryptoException
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        } catch (\SodiumException $e) {
            throw new CryptoException('Encryption failed: ' . $e->getMessage(), 0, $e);
        }

        return self::ENVELOPE_PREFIX . base64_encode($this->fingerprint() . $nonce . $ciphertext);
    }

    /**
     * @throws CryptoException
     */
    public function decrypt(string $envelope): string
    {
        if (!str_starts_with($envelope, self::ENVELOPE_PREFIX)) {
            throw new CryptoException('Unknown envelope format — expected the prefix "' . self::ENVELOPE_PREFIX . '".');
        }

        $raw = base64_decode(substr($envelope, strlen(self::ENVELOPE_PREFIX)), true);
        if ($raw === false) {
            throw new CryptoException('The stored value is not valid Base64.');
        }

        $minimum = self::FINGERPRINT_BYTES + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if (strlen($raw) < $minimum) {
            throw new CryptoException('The stored value is too short and therefore damaged.');
        }

        $fingerprint = substr($raw, 0, self::FINGERPRINT_BYTES);
        if (!hash_equals($this->fingerprint(), $fingerprint)) {
            throw new CryptoException(
                'The key in wp-config.php does not match the stored credentials. '
                . 'Was AASPF_ENCRYPTION_KEY changed afterwards? Then the credentials must be entered again.'
            );
        }

        $nonce = substr($raw, self::FINGERPRINT_BYTES, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, self::FINGERPRINT_BYTES + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        } catch (\SodiumException $e) {
            throw new CryptoException('Decryption failed: ' . $e->getMessage(), 0, $e);
        }

        if ($plaintext === false) {
            throw new CryptoException('The stored value could not be decrypted — it was altered or is damaged.');
        }

        return $plaintext;
    }

    /**
     * Detects whether a stored value originates from this class at all.
     */
    public static function looksEncrypted(string $value): bool
    {
        return str_starts_with($value, self::ENVELOPE_PREFIX);
    }

    /**
     * BLAKE2b only supports output lengths of 16 bytes and above, so the
     * shortest permitted hash is computed and truncated to the required bytes.
     */
    private function fingerprint(): string
    {
        $hash = sodium_crypto_generichash($this->key, '', SODIUM_CRYPTO_GENERICHASH_BYTES_MIN);

        return substr($hash, 0, self::FINGERPRINT_BYTES);
    }
}
