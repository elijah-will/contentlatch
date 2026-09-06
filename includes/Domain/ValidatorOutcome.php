<?php
/**
 * Result of a single validator invocation.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

final class ValidatorOutcome
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly bool $passed,
        public readonly string $code,
        public readonly string $message,
        public readonly array $context = array(),
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function pass(string $code, array $context = array()): self
    {
        return new self(true, $code, '', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fail(string $code, string $message, array $context = array()): self
    {
        return new self(false, $code, $message, $context);
    }
}
