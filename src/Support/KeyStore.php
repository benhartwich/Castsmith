<?php
declare(strict_types=1);

namespace Castsmith\Support;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

/**
 * Obtains the encryption key from wp-config.php.
 *
 * The key deliberately does not belong in the database: if it were stored there,
 * encrypting the credentials would be worthless against a database leak.
 *
 * If the constant is missing, there is no silent fallback to plain text. The plugin
 * then refuses to save and explains what needs to be done.
 */
final class KeyStore
{
    public const CONSTANT = 'AASPF_ENCRYPTION_KEY';

    private static ?Crypto $instance = null;

    public static function isConfigured(): bool
    {
        return defined(self::CONSTANT) && trim((string) constant(self::CONSTANT)) !== '';
    }

    /**
     * @throws CryptoException
     */
    public static function crypto(): Crypto
    {
        if (self::$instance instanceof Crypto) {
            return self::$instance;
        }

        if (!self::isConfigured()) {
            throw new CryptoException(self::missingKeyMessage());
        }

        try {
            self::$instance = Crypto::fromBase64Key((string) constant(self::CONSTANT));
        } catch (CryptoException $e) {
            throw new CryptoException(
                sprintf(
                    /* translators: 1: name of the constant, 2: underlying error message */
                    __('The constant %1$s in wp-config.php is unusable: %2$s Expected are 32 random bytes in Base64.', 'castsmith'),
                    self::CONSTANT,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }

        return self::$instance;
    }

    public static function missingKeyMessage(): string
    {
        return sprintf(
            /* translators: %s: name of the constant */
            __('The encryption key %s is missing from wp-config.php. Without it, no credentials will be saved.', 'castsmith'),
            self::CONSTANT
        );
    }

    /**
     * Ready-made line to insert into wp-config.php, with a freshly generated key.
     *
     * Generated anew on every call. The settings page therefore keeps the result
     * for the duration of a single page request, so that not every reload
     * shows a different suggestion.
     */
    public static function suggestedConfigLine(): string
    {
        return sprintf("define( '%s', '%s' );", self::CONSTANT, Crypto::generateKeyBase64());
    }

    /**
     * For tests only: discard the cached instance.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
