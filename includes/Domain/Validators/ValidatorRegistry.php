<?php
/**
 * Validation type registry.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Validators;

use ContentLatch\Domain\Contracts\ValidatorInterface;
use ContentLatch\Domain\Exception\UnknownValidatorException;

final class ValidatorRegistry
{
    /**
     * @var array<string, ValidatorInterface>
     */
    private array $validators = array();

    public function register(string $id, ValidatorInterface $validator): void
    {
        $this->validators[$id] = $validator;
    }

    public function has(string $id): bool
    {
        return isset($this->validators[$id]);
    }

    public function get(string $id): ValidatorInterface
    {
        if (!isset($this->validators[$id])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are application/domain data and are escaped at the presentation boundary.
            throw new UnknownValidatorException($id);
        }

        return $this->validators[$id];
    }

    public static function v1(): self
    {
        $registry = new self();
        $registry->register('required', new RequiredValidator());
        $registry->register('min_length', new MinLengthValidator());
        $registry->register('max_length', new MaxLengthValidator());
        $registry->register('allowed_values', new AllowedValuesValidator());

        return $registry;
    }
}
