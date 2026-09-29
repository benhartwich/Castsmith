<?php
declare(strict_types=1);

namespace Sonoquill\Support;

/**
 * Signals any error related to storing the credentials:
 * missing key, unusable key, corrupted ciphertext.
 */
final class CryptoException extends \RuntimeException
{
}
