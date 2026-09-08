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
    public const CONTAINER_CLONE     = 'clone';

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
        public readonly string $clone = '',
    ) {
        if ($this->key === '') {
            throw new InvalidRuleException('Field key is required.');
        }

        $this->assertPath();
    }

    /**
     * Internal lookup id. Uncloned fields stay as the leaf key.
     */
    public function resolutionId(): string
    {
        return self::resolutionIdFor($this->clone, $this->key);
    }

    public static function resolutionIdFor(string $clone, string $key): string
    {
        return $clone !== '' ? $clone . '_' . $key : $key;
    }

    /**
     * @return array{clone: string, key: string}
     */
    public static function parseResolutionId(string $id): array
    {
        $id = trim($id);
        $pos = strrpos($id, '_field_');
        if ($pos === false || $pos < 1) {
            return array(
                'clone' => '',
                'key'   => $id,
            );
        }

        $clone = substr($id, 0, $pos);
        $key   = substr($id, $pos + 1);
        if (!self::isSafeFieldKey($clone) || !self::isSafeFieldKey($key)) {
            return array(
                'clone' => '',
                'key'   => $id,
            );
        }

        return array(
            'clone' => $clone,
            'key'   => $key,
        );
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

        if ($this->clone !== '') {
            $data['clone'] = $this->clone;
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
            (string) ($data['clone'] ?? ''),
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
        if ($this->clone !== '') {
            $this->assertClone();
        }

        if ($this->path === array()) {
            if ($this->container !== '') {
                throw new InvalidRuleException('Field container requires a path.');
            }

            if ($this->layout !== '') {
                throw new InvalidRuleException('Layout is only valid for Flexible Content fields.');
            }

            if ($this->clone !== '') {
                throw new InvalidRuleException('Clone fields require a path.');
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

        $last = $this->path[count($this->path) - 1];
        if ($last !== $this->key && !($this->clone !== '' && $last === $this->resolutionId())) {
            throw new InvalidRuleException('Invalid field path.');
        }

        if (
            $this->container !== self::CONTAINER_GROUP
            && $this->container !== self::CONTAINER_REPEATER
            && $this->container !== self::CONTAINER_FLEXIBLE
            && $this->container !== self::CONTAINER_CLONE
        ) {
            throw new InvalidRuleException('Unsupported field container.');
        }

        if ($this->container === self::CONTAINER_CLONE && $this->clone === '') {
            throw new InvalidRuleException('Clone fields require clone metadata.');
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

    private function assertClone(): void
    {
        if (!self::isSafeFieldKey($this->clone)) {
            throw new InvalidRuleException('Invalid clone field.');
        }

        if ($this->path !== array() && !in_array($this->clone, $this->path, true)) {
            throw new InvalidRuleException('Clone field must appear in the field path.');
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

    public function isCloneChild(): bool
    {
        return $this->clone !== '';
    }

    public static function isSafeLayoutName(string $layout): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]+$/', $layout);
    }

    public static function isSafeFieldKey(string $key): bool
    {
        return (bool) preg_match('/^field_[A-Za-z0-9_]+$/', $key);
    }
}
