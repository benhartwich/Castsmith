<?php
declare(strict_types=1);

namespace Castsmith\Health;

/**
 * Result of a single check.
 *
 * "skip" is deliberately not an error: credentials that have not been entered
 * yet are an open item, not a malfunction.
 */
final class Result
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const SKIP = 'skip';

    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly string $detail = ''
    ) {
    }

    public static function ok(string $message, string $detail = ''): self
    {
        return new self(self::OK, $message, $detail);
    }

    public static function warn(string $message, string $detail = ''): self
    {
        return new self(self::WARN, $message, $detail);
    }

    public static function fail(string $message, string $detail = ''): self
    {
        return new self(self::FAIL, $message, $detail);
    }

    public static function skip(string $message, string $detail = ''): self
    {
        return new self(self::SKIP, $message, $detail);
    }

    /**
     * @return array<string,string>
     */
    public function toArray(): array
    {
        return [
            'status'  => $this->status,
            'message' => $this->message,
            'detail'  => $this->detail,
        ];
    }
}
