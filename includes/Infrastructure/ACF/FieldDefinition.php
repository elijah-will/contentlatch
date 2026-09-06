<?php
/**
 * Clean field metadata for the application. Not a raw ACF field array.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

use ContentGuard\Domain\FieldRef;

final class FieldDefinition
{
    /**
     * @param array<string, string> $choices Stored value => label.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly array $choices = array(),
    ) {
    }

    public function toFieldRef(): FieldRef
    {
        return new FieldRef($this->key, $this->name, $this->label);
    }
}
