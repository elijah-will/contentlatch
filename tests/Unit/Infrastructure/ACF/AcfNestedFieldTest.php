<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\ACF;

use ContentLatch\Infrastructure\ACF\AcfNestedField;
use ContentLatch\Tests\Support\AcfNestedRepeaterFixtures;
use ContentLatch\Tests\Support\AcfRepeaterFixtures;
use PHPUnit\Framework\TestCase;

final class AcfNestedFieldTest extends TestCase
{
    public function testInstanceInputNameInsertsRowKeyAfterRepeater(): void
    {
        $this->assertSame(
            'acf[' . AcfRepeaterFixtures::INGREDIENT_LIST . '][row-0][' . AcfRepeaterFixtures::INGREDIENT . ']',
            AcfNestedField::instanceInputName(
                array(AcfRepeaterFixtures::INGREDIENT_LIST, AcfRepeaterFixtures::INGREDIENT),
                AcfRepeaterFixtures::INGREDIENT_LIST,
                'row-0'
            )
        );
        $this->assertSame(
            'acf[' . AcfRepeaterFixtures::PRODUCT_INFORMATION . '][' . AcfRepeaterFixtures::ITEM_SIZE . '][abc12][' . AcfRepeaterFixtures::PRODUCT_SIZE . ']',
            AcfNestedField::instanceInputName(
                array(
                    AcfRepeaterFixtures::PRODUCT_INFORMATION,
                    AcfRepeaterFixtures::ITEM_SIZE,
                    AcfRepeaterFixtures::PRODUCT_SIZE,
                ),
                AcfRepeaterFixtures::ITEM_SIZE,
                'abc12'
            )
        );
    }

    public function testRepeaterInputNameStopsAtTheRepeater(): void
    {
        $this->assertSame(
            'acf[' . AcfRepeaterFixtures::INGREDIENT_LIST . ']',
            AcfNestedField::repeaterInputName(
                array(AcfRepeaterFixtures::INGREDIENT_LIST, AcfRepeaterFixtures::INGREDIENT),
                AcfRepeaterFixtures::INGREDIENT_LIST
            )
        );
        $this->assertSame(
            'acf[' . AcfRepeaterFixtures::PRODUCT_INFORMATION . '][' . AcfRepeaterFixtures::ITEM_SIZE . ']',
            AcfNestedField::repeaterInputName(
                array(
                    AcfRepeaterFixtures::PRODUCT_INFORMATION,
                    AcfRepeaterFixtures::ITEM_SIZE,
                    AcfRepeaterFixtures::PRODUCT_SIZE,
                ),
                AcfRepeaterFixtures::ITEM_SIZE
            )
        );
    }

    public function testUnsafeRowKeysAreRejected(): void
    {
        $this->assertFalse(AcfNestedField::isSafeRowKey(''));
        $this->assertFalse(AcfNestedField::isSafeRowKey('acfcloneindex'));
        $this->assertFalse(AcfNestedField::isSafeRowKey('row 0'));
        $this->assertTrue(AcfNestedField::isSafeRowKey('row-0'));
        $this->assertTrue(AcfNestedField::isSafeRowKey('67a1b2c3d4e5f'));
    }

    public function testRowsIgnoreCloneIndexAndNormalizeIntegerKeys(): void
    {
        $rows = AcfNestedField::rows(array(
            'acfcloneindex' => array('x' => 'clone'),
            0 => array('x' => 'first'),
            'row-1' => array('x' => 'second'),
        ));

        $this->assertSame(array('row-0', 'row-1'), array_column($rows, 'key'));
    }

    public function testNestedRepeaterInputNameInsertsBothRowKeys(): void
    {
        $this->assertSame(
            'acf[' . AcfNestedRepeaterFixtures::DIRECTIONS . '][row-0]['
            . AcfNestedRepeaterFixtures::STEPS . '][row-1]['
            . AcfNestedRepeaterFixtures::STEP_NAME . ']',
            AcfNestedField::instanceInputNameForRepeaters(
                AcfNestedRepeaterFixtures::path(),
                array(
                    AcfNestedRepeaterFixtures::DIRECTIONS => 'row-0',
                    AcfNestedRepeaterFixtures::STEPS      => 'row-1',
                )
            )
        );
    }

    public function testNestedRepeaterCellsSkipEmptyOrScalarInnerRepeaters(): void
    {
        $cells = AcfNestedField::nestedRepeaterCells(
            array(
                'row-0' => array(
                    AcfNestedRepeaterFixtures::STEPS => array(
                        'row-0' => array(AcfNestedRepeaterFixtures::STEP_NAME => 'Cut'),
                    ),
                ),
                'row-1' => array(
                    AcfNestedRepeaterFixtures::STEPS => array(),
                ),
                'row-2' => array(
                    AcfNestedRepeaterFixtures::STEPS => 'not-rows',
                ),
            ),
            AcfNestedRepeaterFixtures::path(),
            AcfNestedRepeaterFixtures::pathNames(),
            AcfNestedRepeaterFixtures::chain()
        );

        $this->assertCount(1, $cells);
        $this->assertSame('Cut', $cells[0]['value']);
        $this->assertSame('row-0', $cells[0]['outer']['key']);
        $this->assertSame('row-0', $cells[0]['inner']['key']);
    }
}
