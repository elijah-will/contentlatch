<?php
/**
 * In-memory ACF 6.8.9 Repeater fixtures modeled on Product 636 and Recipe 12325.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Support;

use ContentLatch\Domain\FieldRef;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;

final class AcfRepeaterFixtures
{
    public const PRODUCT_INFORMATION = 'field_661b02474af17';
    public const ITEM_SIZE           = 'field_661b02284af16';
    public const PRODUCT_SIZE        = 'field_661b03094af19';
    public const PRODUCT_TYPE        = 'field_product_type';

    public const INGREDIENT_LIST = 'field_66a7ff4394039';
    public const INGREDIENT      = 'field_66a800089403a';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function product636Fields(): array
    {
        return array(
            array(
                'key'     => self::PRODUCT_TYPE,
                'name'    => 'product_type',
                'label'   => 'Product Type',
                'type'    => 'select',
                'choices' => array(
                    'sauce' => 'Sauce',
                    'dip'   => 'Dip',
                ),
            ),
            array(
                'key'        => self::PRODUCT_INFORMATION,
                'name'       => 'product_information',
                'label'      => 'Product Information',
                'type'       => 'group',
                'sub_fields' => array(
                    array(
                        'key'        => self::ITEM_SIZE,
                        'name'       => 'item_size',
                        'label'      => 'Item Size',
                        'type'       => 'repeater',
                        'sub_fields' => array(
                            array(
                                'key'   => self::PRODUCT_SIZE,
                                'name'  => 'product_size',
                                'label' => 'Product Size',
                                'type'  => 'text',
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recipe12325Fields(): array
    {
        return array(
            array(
                'key'        => self::INGREDIENT_LIST,
                'name'       => 'ingredient_list',
                'label'      => 'Ingredient List',
                'type'       => 'repeater',
                'sub_fields' => array(
                    array(
                        'key'   => self::INGREDIENT,
                        'name'  => 'ingredient',
                        'label' => 'Ingredient',
                        'type'  => 'text',
                    ),
                ),
            ),
        );
    }

    public static function productCatalog(): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (string $postType): array {
                return $postType === 'product' ? self::product636Fields() : array();
            }
        );
    }

    public static function recipeCatalog(): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (string $postType): array {
                return $postType === 'recipe' ? self::recipe12325Fields() : array();
            }
        );
    }

    public static function productSizeRef(): FieldRef
    {
        return new FieldRef(
            self::PRODUCT_SIZE,
            'product_size',
            'Product Information → Item Size → Product Size',
            array(self::PRODUCT_INFORMATION, self::ITEM_SIZE, self::PRODUCT_SIZE),
            FieldRef::CONTAINER_REPEATER
        );
    }

    public static function ingredientRef(): FieldRef
    {
        return new FieldRef(
            self::INGREDIENT,
            'ingredient',
            'Ingredient List → Ingredient',
            array(self::INGREDIENT_LIST, self::INGREDIENT),
            FieldRef::CONTAINER_REPEATER
        );
    }
}
