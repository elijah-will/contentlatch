<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Infrastructure\ACF\AcfStoredValueProvider;
use ContentGuard\Infrastructure\ACF\AcfValueNormalizer;
use PHPUnit\Framework\TestCase;

final class AcfStoredValueProviderTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private array $fieldTypes = array(
        'field_type'     => 'select',
        'field_featured' => 'true_false',
        'field_body'     => 'wysiwyg',
    );

    public function testReadsUnformattedValueByFieldKey(): void
    {
        $calls = array();
        $provider = $this->provider(
            static function (string $key, int $postId) use (&$calls): mixed {
                $calls[] = array($key, $postId);
                return 'sauce';
            }
        );

        $this->assertTrue($provider->has('field_type'));
        $this->assertSame('sauce', $provider->get('field_type'));
        $this->assertSame(array(array('field_type', 15)), $calls);
    }

    public function testDoesNotReadDisallowedOrArbitraryKeys(): void
    {
        $called = false;
        $provider = $this->provider(
            static function () use (&$called): mixed {
                $called = true;
                return 'leaked';
            }
        );

        $this->assertFalse($provider->has('field_unknown'));
        $this->assertNull($provider->get('field_unknown'));
        $this->assertFalse($provider->has('admin_email'));
        $this->assertNull($provider->get('admin_email'));
        $this->assertFalse($called);
    }

    public function testNormalizesStoredBooleanAndWysiwyg(): void
    {
        $store = array(
            'field_featured' => 1,
            'field_body'     => '<p>Hello&amp;co</p>',
        );
        $provider = $this->provider(
            static function (string $key) use ($store): mixed {
                return $store[$key] ?? null;
            }
        );

        $this->assertTrue($provider->get('field_featured'));
        $this->assertSame('Hello&co', $provider->get('field_body'));
    }

    public function testMissingAcfReaderCannotResolve(): void
    {
        $provider = new AcfStoredValueProvider(
            1,
            new AcfValueNormalizer(),
            $this->fieldTypes,
            null
        );

        $this->assertFalse($provider->has('field_type'));
        $this->assertNull($provider->get('field_type'));
    }

    /**
     * @param callable(string $fieldKey, int $postId): mixed $reader
     */
    private function provider(callable $reader): AcfStoredValueProvider
    {
        return new AcfStoredValueProvider(
            15,
            new AcfValueNormalizer(),
            $this->fieldTypes,
            $reader
        );
    }
}
