<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Infrastructure\ACF\AcfStoredValueProvider;
use ContentGuard\Infrastructure\ACF\AcfValueNormalizer;
use ContentGuard\Tests\Support\AcfRepeaterFixtures;
use PHPUnit\Framework\TestCase;

final class AcfRepeaterStoredValueProviderTest extends TestCase
{
    public function testTopLevelRepeaterOnePopulatedRow(): void
    {
        $calls = array();
        $provider = $this->recipeProvider(
            static function (string $key) use (&$calls): mixed {
                $calls[] = $key;
                return match ($key) {
                    AcfRepeaterFixtures::INGREDIENT_LIST => array(
                        array(AcfRepeaterFixtures::INGREDIENT => 'Salt'),
                    ),
                    AcfRepeaterFixtures::INGREDIENT => '',
                    default => null,
                };
            }
        );

        $instances = $provider->instances(AcfRepeaterFixtures::INGREDIENT);
        $this->assertCount(1, $instances);
        $this->assertSame('Salt', $instances[0]->value);
        $this->assertSame('row-0', $instances[0]->context['row_key']);
        $this->assertSame(0, $instances[0]->context['row_index']);
        $this->assertSame(1, $instances[0]->context['display_row']);
        $this->assertSame(
            'acf[' . AcfRepeaterFixtures::INGREDIENT_LIST . '][row-0][' . AcfRepeaterFixtures::INGREDIENT . ']',
            $instances[0]->context['input_name']
        );
        $this->assertSame(array(AcfRepeaterFixtures::INGREDIENT_LIST), $calls);
    }

    public function testTopLevelRepeaterMultipleRowsIncludingEmpty(): void
    {
        $provider = $this->recipeProvider(
            static function (string $key): mixed {
                return $key === AcfRepeaterFixtures::INGREDIENT_LIST
                    ? array(
                        array(AcfRepeaterFixtures::INGREDIENT => 'Salt'),
                        array(AcfRepeaterFixtures::INGREDIENT => ''),
                        array(AcfRepeaterFixtures::INGREDIENT => 'Pepper'),
                    )
                    : '';
            }
        );

        $instances = $provider->instances(AcfRepeaterFixtures::INGREDIENT);
        $this->assertCount(3, $instances);
        $this->assertSame('Salt', $instances[0]->value);
        $this->assertNull($instances[1]->value);
        $this->assertSame('Pepper', $instances[2]->value);
        $this->assertSame(array(1, 2, 3), array_map(
            static fn ($instance): int => (int) $instance->context['display_row'],
            $instances
        ));
    }

    public function testZeroRowsReturnNoInstances(): void
    {
        foreach (array(array(), '', 0, false, null) as $empty) {
            $provider = $this->recipeProvider(
                static function (string $key) use ($empty): mixed {
                    return $key === AcfRepeaterFixtures::INGREDIENT_LIST ? $empty : '8oz';
                }
            );
            $this->assertSame(array(), $provider->instances(AcfRepeaterFixtures::INGREDIENT));
        }
    }

    public function testNameKeyedFallbackRows(): void
    {
        $provider = $this->recipeProvider(
            static function (string $key): mixed {
                return $key === AcfRepeaterFixtures::INGREDIENT_LIST
                    ? array(
                        array('ingredient' => 'Garlic'),
                    )
                    : '';
            }
        );

        $instances = $provider->instances(AcfRepeaterFixtures::INGREDIENT);
        $this->assertCount(1, $instances);
        $this->assertSame('Garlic', $instances[0]->value);
    }

    public function testGroupRepeaterKeyKeyedUnformattedRows(): void
    {
        $calls = array();
        $provider = $this->productProvider(
            static function (string $key) use (&$calls): mixed {
                $calls[] = $key;
                return match ($key) {
                    AcfRepeaterFixtures::PRODUCT_INFORMATION => array(
                        AcfRepeaterFixtures::ITEM_SIZE => array(
                            array(AcfRepeaterFixtures::PRODUCT_SIZE => '2.5oz'),
                            array(AcfRepeaterFixtures::PRODUCT_SIZE => ''),
                        ),
                    ),
                    AcfRepeaterFixtures::PRODUCT_SIZE => '8oz',
                    default => null,
                };
            }
        );

        $instances = $provider->instances(AcfRepeaterFixtures::PRODUCT_SIZE);
        $this->assertCount(2, $instances);
        $this->assertSame('2.5oz', $instances[0]->value);
        $this->assertNull($instances[1]->value);
        $this->assertSame(1, $instances[0]->context['display_row']);
        $this->assertSame(2, $instances[1]->context['display_row']);
        $this->assertArrayNotHasKey('repeater_rows', $instances[1]->context);
        $this->assertSame(
            'acf[' . AcfRepeaterFixtures::PRODUCT_INFORMATION . '][' . AcfRepeaterFixtures::ITEM_SIZE . '][row-1][' . AcfRepeaterFixtures::PRODUCT_SIZE . ']',
            $instances[1]->context['input_name']
        );
        $this->assertSame(array(AcfRepeaterFixtures::PRODUCT_INFORMATION), $calls);
    }

    public function testGroupRepeaterNameKeyedFallback(): void
    {
        $provider = $this->productProvider(
            static function (string $key): mixed {
                return match ($key) {
                    AcfRepeaterFixtures::PRODUCT_INFORMATION => array(
                        'item_size' => array(
                            array('product_size' => '12oz'),
                        ),
                    ),
                    AcfRepeaterFixtures::PRODUCT_SIZE => '8oz',
                    default => null,
                };
            }
        );

        $instances = $provider->instances(AcfRepeaterFixtures::PRODUCT_SIZE);
        $this->assertCount(1, $instances);
        $this->assertSame('12oz', $instances[0]->value);
    }

    public function testLeafGetFieldDefaultDoesNotOverridePopulatedParentWalk(): void
    {
        $provider = $this->productProvider(
            static function (string $key): mixed {
                return match ($key) {
                    AcfRepeaterFixtures::PRODUCT_SIZE => '8oz',
                    AcfRepeaterFixtures::PRODUCT_INFORMATION => array(
                        AcfRepeaterFixtures::ITEM_SIZE => array(
                            array(AcfRepeaterFixtures::PRODUCT_SIZE => '2.5oz'),
                        ),
                    ),
                    default => null,
                };
            }
        );

        $instances = $provider->instances(AcfRepeaterFixtures::PRODUCT_SIZE);
        $this->assertSame(array('2.5oz'), array_map(
            static fn ($instance): mixed => $instance->value,
            $instances
        ));
    }

    public function testTrueFalseZeroIsPreservedPerRow(): void
    {
        $this->fieldTypes[AcfRepeaterFixtures::INGREDIENT] = 'true_false';
        $provider = $this->recipeProvider(
            static function (string $key): mixed {
                return $key === AcfRepeaterFixtures::INGREDIENT_LIST
                    ? array(
                        array(AcfRepeaterFixtures::INGREDIENT => 0),
                        array(AcfRepeaterFixtures::INGREDIENT => 1),
                    )
                    : null;
            }
        );

        $instances = $provider->instances(AcfRepeaterFixtures::INGREDIENT);
        $this->assertFalse($instances[0]->value);
        $this->assertTrue($instances[1]->value);
    }

    public function testExistingGroupResolutionStillWorks(): void
    {
        $this->fieldTypes['field_ingredients'] = 'textarea';
        $provider = new AcfStoredValueProvider(
            15,
            new AcfValueNormalizer(),
            $this->fieldTypes,
            static function (string $key): mixed {
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
        $this->assertCount(1, $provider->instances('field_ingredients'));
        $this->assertSame('Tomatoes, salt', $provider->instances('field_ingredients')[0]->value);
    }

    /**
     * @var array<string, string>
     */
    private array $fieldTypes = array();

    /**
     * @param callable(string $fieldKey, int $postId): mixed $reader
     */
    private function recipeProvider(callable $reader): AcfStoredValueProvider
    {
        $this->fieldTypes[AcfRepeaterFixtures::INGREDIENT] = $this->fieldTypes[AcfRepeaterFixtures::INGREDIENT] ?? 'text';

        return new AcfStoredValueProvider(
            12325,
            new AcfValueNormalizer(),
            $this->fieldTypes,
            $reader,
            array(
                AcfRepeaterFixtures::INGREDIENT => array(
                    AcfRepeaterFixtures::INGREDIENT_LIST,
                    AcfRepeaterFixtures::INGREDIENT,
                ),
            ),
            array(
                AcfRepeaterFixtures::INGREDIENT => array('ingredient_list', 'ingredient'),
            ),
            array(
                AcfRepeaterFixtures::INGREDIENT => AcfRepeaterFixtures::INGREDIENT_LIST,
            )
        );
    }

    /**
     * @param callable(string $fieldKey, int $postId): mixed $reader
     */
    private function productProvider(callable $reader): AcfStoredValueProvider
    {
        $this->fieldTypes[AcfRepeaterFixtures::PRODUCT_SIZE] = 'text';

        return new AcfStoredValueProvider(
            636,
            new AcfValueNormalizer(),
            $this->fieldTypes,
            $reader,
            array(
                AcfRepeaterFixtures::PRODUCT_SIZE => array(
                    AcfRepeaterFixtures::PRODUCT_INFORMATION,
                    AcfRepeaterFixtures::ITEM_SIZE,
                    AcfRepeaterFixtures::PRODUCT_SIZE,
                ),
            ),
            array(
                AcfRepeaterFixtures::PRODUCT_SIZE => array(
                    'product_information',
                    'item_size',
                    'product_size',
                ),
            ),
            array(
                AcfRepeaterFixtures::PRODUCT_SIZE => AcfRepeaterFixtures::ITEM_SIZE,
            )
        );
    }
}
