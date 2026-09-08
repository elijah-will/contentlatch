<?php
/**
 * Test-only integration used to prove multi-adapter catalog/provider composition.
 * Never registered by Plugin.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Support;

use ContentGuard\Application\Integration\FieldCatalog;
use ContentGuard\Application\Integration\Integration;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\Contracts\FieldValueProviderInterface;
use ContentGuard\Domain\FieldRef;

final class FakeIntegration implements FieldCatalog
{
    public const ID     = 'test';
    public const LABEL  = 'Test Integration';
    public const TITLE  = 'test_title';
    public const NUMBER = 'test_number';

    /**
     * @param array<int, array<string, mixed>> $valuesByPost
     * @param list<string> $postTypes
     */
    public function __construct(
        private bool $available = true,
        private array $valuesByPost = array(),
        private array $postTypes = array('page'),
    ) {
    }

    public function descriptor(): Integration
    {
        return new Integration(self::ID, self::LABEL, $this->available);
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function fieldsForPostType(string $postType): array
    {
        if (!in_array($postType, $this->postTypes, true)) {
            return array();
        }

        return array(
            array(
                'key'          => self::TITLE,
                'name'         => self::TITLE,
                'label'        => 'Test Title',
                'type'         => 'text',
                'integration'  => self::ID,
            ),
            array(
                'key'          => self::NUMBER,
                'name'         => self::NUMBER,
                'label'        => 'Test Number',
                'type'         => 'number',
                'integration'  => self::ID,
            ),
        );
    }

    public function fieldTypesForPostType(string $postType): array
    {
        $types = array();
        foreach ($this->fieldsForPostType($postType) as $field) {
            $types[(string) $field['key']] = (string) $field['type'];
        }

        return $types;
    }

    public function storedProvider(int $postId): FieldValueProviderInterface
    {
        return new ArrayValueProvider($this->valuesByPost[$postId] ?? array());
    }

    public static function titleRef(): FieldRef
    {
        return new FieldRef(self::TITLE, self::TITLE, 'Test Title');
    }

    public static function numberRef(): FieldRef
    {
        return new FieldRef(self::NUMBER, self::NUMBER, 'Test Number');
    }
}
