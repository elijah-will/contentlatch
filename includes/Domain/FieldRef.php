<?php
/**
 * Canonical field identity plus UI snapshots.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

use ContentGuard\Domain\Exception\InvalidRuleException;

final class FieldRef
{
    public const CONTAINER_GROUP     = 'group';
    public const CONTAINER_REPEATER  = 'repeater';
    public const CONTAINER_FLEXIBLE  = 'flexible_content';

    /**
     * @param list<string> $path Root-to-leaf ACF field keys. Empty means top-level.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name = '',
        public readonly string $label = '',
        public readonly array $path = array(),
        public readonly string $container = '',
        public readonly string $layout = '',
    ) {
        if ($this->key === '') {
            throw new InvalidRuleException('Field key is required.');
        }

        $this->assertPath();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = array(
            'key'   => $this->key,
            'name'  => $this->name,
            'label' => $this->label,
        );

        if ($this->path !== array()) {
            $data['path']      = $this->path;
            $data['container'] = $this->container;
        }

        if ($this->container === self::CONTAINER_FLEXIBLE && $this->layout !== '') {
            $data['layout'] = $this->layout;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['key'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['label'] ?? ''),
            self::pathFromArray($data['path'] ?? array()),
            (string) ($data['container'] ?? ''),
            (string) ($data['layout'] ?? ''),
        );
    }

    public function isNested(): bool
    {
        return $this->path !== array();
    }

    /**
     * @param mixed $path
     * @return list<string>
     */
    private static function pathFromArray(mixed $path): array
    {
        if ($path === array() || $path === null) {
            return array();
        }

        if (!is_array($path) || $path !== array_values($path)) {
            throw new InvalidRuleException('Invalid field path.');
        }

        $normalized = array();
        foreach ($path as $segment) {
            if (!is_string($segment)) {
                throw new InvalidRuleException('Invalid field path.');
            }

            $normalized[] = $segment;
        }

        return $normalized;
    }

    private function assertPath(): void
    {
        if ($this->path === array()) {
            if ($this->container !== '') {
                throw new InvalidRuleException('Field container requires a path.');
            }

            if ($this->layout !== '') {
                throw new InvalidRuleException('Layout is only valid for Flexible Content fields.');
            }

            return;
        }

        if (count($this->path) < 2) {
            throw new InvalidRuleException('Invalid field path.');
        }

        foreach ($this->path as $segment) {
            if (!self::isSafeFieldKey($segment)) {
                throw new InvalidRuleException('Invalid field path.');
            }
        }

        if ($this->path[count($this->path) - 1] !== $this->key) {
            throw new InvalidRuleException('Invalid field path.');
        }

        if (
            $this->container !== self::CONTAINER_GROUP
            && $this->container !== self::CONTAINER_REPEATER
            && $this->container !== self::CONTAINER_FLEXIBLE
        ) {
            throw new InvalidRuleException('Unsupported field container.');
        }

        if ($this->container === self::CONTAINER_FLEXIBLE) {
            if (!self::isSafeLayoutName($this->layout)) {
                throw new InvalidRuleException('Flexible Content fields require a layout.');
            }

            return;
        }

        if ($this->layout !== '') {
            throw new InvalidRuleException('Layout is only valid for Flexible Content fields.');
        }
    }

    public function isRepeaterChild(): bool
    {
        return $this->container === self::CONTAINER_REPEATER;
    }

    public function isFlexibleChild(): bool
    {
        return $this->container === self::CONTAINER_FLEXIBLE;
    }

    public static function isSafeLayoutName(string $layout): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]+$/', $layout);
    }

    private static function isSafeFieldKey(string $key): bool
    {
        return (bool) preg_match('/^field_[A-Za-z0-9_]+$/', $key);
    }
}
