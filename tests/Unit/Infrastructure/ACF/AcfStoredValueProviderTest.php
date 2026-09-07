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

    public function testGroupChildUsesLeafKeyWhenAvailable(): void
    {
        $this->fieldTypes['field_ingredients'] = 'textarea';
        $calls = array();
        $provider = $this->provider(
            static function (string $key) use (&$calls): mixed {
                $calls[] = $key;
                return $key === 'field_ingredients' ? 'Salt' : null;
            },
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            )
        );

        $this->assertSame('Salt', $provider->get('field_ingredients'));
        $this->assertSame(array('field_product_details', 'field_ingredients'), $calls);
    }

    public function testGroupChildPrefersParentValueOverLeafDefaultEmpty(): void
    {
        $this->fieldTypes['field_ingredients'] = 'textarea';
        $calls = array();
        $provider = $this->provider(
            static function (string $key) use (&$calls): mixed {
                $calls[] = $key;
                return match ($key) {
                    'field_ingredients' => '',
                    'field_product_details' => array(
                        'field_ingredients' => 'Tomatoes, salt',
                    ),
                    default => null,
                };
            },
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            )
        );

        $this->assertSame('Tomatoes, salt', $provider->get('field_ingredients'));
        $this->assertSame(array('field_product_details'), $calls);
    }

    public function testGroupChildPrefersNameKeyedParentOverLeafDefaultEmpty(): void
    {
        $this->fieldTypes['field_ingredients'] = 'textarea';
        $provider = $this->provider(
            static function (string $key): mixed {
                return match ($key) {
                    'field_ingredients' => '',
                    'field_product_details' => array(
                        'item_ingredients' => 'Garlic, oil',
                    ),
                    default => null,
                };
            },
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            ),
            array(
                'field_ingredients' => array('product_information', 'item_ingredients'),
            )
        );

        $this->assertSame('Garlic, oil', $provider->get('field_ingredients'));
    }

    public function testGroupChildFallsBackToWalkingTheParentValue(): void
    {
        $this->fieldTypes['field_ingredients'] = 'textarea';
        $calls = array();
        $provider = $this->provider(
            static function (string $key) use (&$calls): mixed {
                $calls[] = $key;
                return match ($key) {
                    'field_ingredients' => null,
                    'field_product_details' => array(
                        'field_ingredients' => 'Tomatoes',
                    ),
                    default => null,
                };
            },
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            )
        );

        $this->assertSame('Tomatoes', $provider->get('field_ingredients'));
        $this->assertSame(array('field_product_details'), $calls);
    }

    public function testNestedGroupFallsBackThroughTheStoredPath(): void
    {
        $this->fieldTypes['field_calories'] = 'number';
        $provider = $this->provider(
            static function (string $key): mixed {
                return match ($key) {
                    'field_calories' => false,
                    'field_product_details' => array(
                        'field_nutrition' => array(
                            'field_calories' => '90',
                        ),
                    ),
                    default => null,
                };
            },
            array(
                'field_calories' => array('field_product_details', 'field_nutrition', 'field_calories'),
            )
        );

        $this->assertSame('90', $provider->get('field_calories'));
    }

    public function testFallbackCanWalkNameKeyedGroupValues(): void
    {
        $this->fieldTypes['field_ingredients'] = 'textarea';
        $provider = $this->provider(
            static function (string $key): mixed {
                return match ($key) {
                    'field_ingredients' => null,
                    'field_product_details' => array(
                        'ingredients' => 'Garlic',
                    ),
                    default => null,
                };
            },
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            ),
            array(
                'field_ingredients' => array('product_details', 'ingredients'),
            )
        );

        $this->assertSame('Garlic', $provider->get('field_ingredients'));
    }

    public function testEmptyGroupChildNormalizesAsNull(): void
    {
        $this->fieldTypes['field_ingredients'] = 'textarea';
        $provider = $this->provider(
            static function (string $key): mixed {
                return match ($key) {
                    'field_ingredients' => null,
                    'field_product_details' => array(
                        'field_ingredients' => '',
                    ),
                    default => null,
                };
            },
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            )
        );

        $this->assertTrue($provider->has('field_ingredients'));
        $this->assertNull($provider->get('field_ingredients'));
    }

    /**
     * @param callable(string $fieldKey, int $postId): mixed $reader
     * @param array<string, list<string>> $fieldPaths
     * @param array<string, list<string>> $fieldPathNames
     * @param array<string, string> $repeaterKeys
     */
    private function provider(
        callable $reader,
        array $fieldPaths = array(),
        array $fieldPathNames = array(),
        array $repeaterKeys = array(),
    ): AcfStoredValueProvider {
        return new AcfStoredValueProvider(
            15,
            new AcfValueNormalizer(),
            $this->fieldTypes,
            $reader,
            $fieldPaths,
            $fieldPathNames,
            $repeaterKeys
        );
    }
}
