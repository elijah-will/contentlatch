<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\ACF;

use ContentLatch\Infrastructure\ACF\AcfIncomingValueProvider;
use ContentLatch\Infrastructure\ACF\AcfValueNormalizer;
use ContentLatch\Tests\Support\AcfRepeaterFixtures;
use PHPUnit\Framework\TestCase;

final class AcfRepeaterIncomingValueProviderTest extends TestCase
{
    public function testZeroRowsWhenRepeaterMissingOrEmpty(): void
    {
        foreach (array(array(), array(AcfRepeaterFixtures::INGREDIENT_LIST => ''), array(AcfRepeaterFixtures::INGREDIENT_LIST => '0')) as $payload) {
            $provider = $this->recipeProvider($payload);
            $this->assertSame(array(), $provider->instances(AcfRepeaterFixtures::INGREDIENT));
        }
    }

    public function testOneRowAndMultipleRowsPreservePostedKeys(): void
    {
        $provider = $this->recipeProvider(
            array(
                AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(AcfRepeaterFixtures::INGREDIENT => 'Salt'),
                    'row-1' => array(AcfRepeaterFixtures::INGREDIENT => ''),
                ),
            )
        );

        $instances = $provider->instances(AcfRepeaterFixtures::INGREDIENT);
        $this->assertCount(2, $instances);
        $this->assertSame('Salt', $instances[0]->value);
        $this->assertNull($instances[1]->value);
        $this->assertSame('row-0', $instances[0]->context['row_key']);
        $this->assertSame('row-1', $instances[1]->context['row_key']);
        $this->assertSame(1, $instances[0]->context['display_row']);
        $this->assertSame(2, $instances[1]->context['display_row']);
        $this->assertSame(
            'acf[' . AcfRepeaterFixtures::INGREDIENT_LIST . '][row-0][' . AcfRepeaterFixtures::INGREDIENT . ']',
            $instances[0]->context['input_name']
        );
        $this->assertSame(
            'acf[' . AcfRepeaterFixtures::INGREDIENT_LIST . '][row-1][' . AcfRepeaterFixtures::INGREDIENT . ']',
            $instances[1]->context['input_name']
        );
    }

    public function testDynamicallyAddedRowIdIsPreserved(): void
    {
        $provider = $this->recipeProvider(
            array(
                AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(AcfRepeaterFixtures::INGREDIENT => 'Salt'),
                    '67a1b2c3d4e5f' => array(AcfRepeaterFixtures::INGREDIENT => 'Oil'),
                ),
            )
        );

        $instances = $provider->instances(AcfRepeaterFixtures::INGREDIENT);
        $this->assertSame('67a1b2c3d4e5f', $instances[1]->context['row_key']);
        $this->assertSame(
            'acf[' . AcfRepeaterFixtures::INGREDIENT_LIST . '][67a1b2c3d4e5f][' . AcfRepeaterFixtures::INGREDIENT . ']',
            $instances[1]->context['input_name']
        );
    }

    public function testAcfcloneindexIsIgnored(): void
    {
        $provider = $this->recipeProvider(
            array(
                AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'acfcloneindex' => array(AcfRepeaterFixtures::INGREDIENT => 'Clone'),
                    'row-0' => array(AcfRepeaterFixtures::INGREDIENT => 'Salt'),
                ),
            )
        );

        $instances = $provider->instances(AcfRepeaterFixtures::INGREDIENT);
        $this->assertCount(1, $instances);
        $this->assertSame('Salt', $instances[0]->value);
        $this->assertSame('row-0', $instances[0]->context['row_key']);
    }

    public function testMissingChildInPostedRowIsStillAnInstance(): void
    {
        $provider = $this->recipeProvider(
            array(
                AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(),
                ),
            )
        );

        $instances = $provider->instances(AcfRepeaterFixtures::INGREDIENT);
        $this->assertCount(1, $instances);
        $this->assertNull($instances[0]->value);
    }

    public function testGroupRepeaterIncomingRows(): void
    {
        $provider = $this->productProvider(
            array(
                AcfRepeaterFixtures::PRODUCT_INFORMATION => array(
                    AcfRepeaterFixtures::ITEM_SIZE => array(
                        'row-0' => array(AcfRepeaterFixtures::PRODUCT_SIZE => '2.5oz'),
                        'row-1' => array(AcfRepeaterFixtures::PRODUCT_SIZE => ''),
                    ),
                ),
            )
        );

        $instances = $provider->instances(AcfRepeaterFixtures::PRODUCT_SIZE);
        $this->assertCount(2, $instances);
        $this->assertSame('2.5oz', $instances[0]->value);
        $this->assertNull($instances[1]->value);
        $this->assertSame(1, $instances[0]->context['display_row']);
        $this->assertSame(2, $instances[1]->context['display_row']);
        $this->assertArrayNotHasKey('repeater_rows', $instances[1]->context);
        $this->assertSame(
            'acf[' . AcfRepeaterFixtures::PRODUCT_INFORMATION . '][' . AcfRepeaterFixtures::ITEM_SIZE . '][row-0][' . AcfRepeaterFixtures::PRODUCT_SIZE . ']',
            $instances[0]->context['input_name']
        );
    }

    public function testGroupRepeaterCloneIndexIgnoredAndEmptyRepeaterIsZeroRows(): void
    {
        $empty = $this->productProvider(
            array(
                AcfRepeaterFixtures::PRODUCT_INFORMATION => array(
                    AcfRepeaterFixtures::ITEM_SIZE => '',
                ),
            )
        );
        $this->assertSame(array(), $empty->instances(AcfRepeaterFixtures::PRODUCT_SIZE));

        $cloneOnly = $this->productProvider(
            array(
                AcfRepeaterFixtures::PRODUCT_INFORMATION => array(
                    AcfRepeaterFixtures::ITEM_SIZE => array(
                        'acfcloneindex' => array(AcfRepeaterFixtures::PRODUCT_SIZE => '8oz'),
                    ),
                ),
            )
        );
        $this->assertSame(array(), $cloneOnly->instances(AcfRepeaterFixtures::PRODUCT_SIZE));
    }

    public function testExistingGroupAndTopLevelBehaviorUnchanged(): void
    {
        $this->fieldTypes['field_type'] = 'select';
        $this->fieldTypes['field_ingredients'] = 'textarea';
        $provider = new AcfIncomingValueProvider(
            array(
                'field_type' => 'sauce',
                'field_product_details' => array(
                    'field_ingredients' => 'Salt, tomatoes',
                ),
            ),
            new AcfValueNormalizer(),
            $this->fieldTypes,
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            )
        );

        $this->assertSame('sauce', $provider->get('field_type'));
        $this->assertSame('Salt, tomatoes', $provider->get('field_ingredients'));
        $this->assertCount(1, $provider->instances('field_ingredients'));
    }

    /**
     * @var array<string, string>
     */
    private array $fieldTypes = array();

    /**
     * @param array<string, mixed> $payload
     */
    private function recipeProvider(array $payload): AcfIncomingValueProvider
    {
        $this->fieldTypes[AcfRepeaterFixtures::INGREDIENT] = 'text';

        return new AcfIncomingValueProvider(
            $payload,
            new AcfValueNormalizer(),
            $this->fieldTypes,
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
     * @param array<string, mixed> $payload
     */
    private function productProvider(array $payload): AcfIncomingValueProvider
    {
        $this->fieldTypes[AcfRepeaterFixtures::PRODUCT_SIZE] = 'text';

        return new AcfIncomingValueProvider(
            $payload,
            new AcfValueNormalizer(),
            $this->fieldTypes,
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
