<?php
/**
 * Holland House Recipes field group (group_64f8a42aa62f8).
 *
 * This is an ACF field group, not an ACF Group field. Ingredients and
 * Directions are top-level Repeaters on that group:
 *
 *   Recipes field group
 *     ├── Ingredients (Repeater) → Ingredient Title (text)
 *     │                         → Section Ingredients (Repeater) → Ingredient
 *     └── Directions (Repeater)  → Section Title (text)
 *                                → Section Directions (Repeater) → Direction
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Support;

use ContentLatch\Domain\FieldRef;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;

final class AcfHollandHouseRecipeFixtures
{
    public const FIELD_GROUP = 'group_64f8a42aa62f8';

    public const INGREDIENTS       = 'field_650070df8895a';
    public const INGREDIENT_TITLE  = 'field_65138eb24ed66';
    public const SECTION_INGREDIENTS = 'field_65138ec34ed67';
    public const INGREDIENT        = 'field_650071058895b';

    public const DIRECTIONS          = 'field_65007238dd468';
    public const SECTION_TITLE       = 'field_65011ffeae1ce';
    public const SECTION_DIRECTIONS  = 'field_65011f67ae1cc';
    public const DIRECTION           = 'field_65011fdfae1cd';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recipeFields(): array
    {
        return array_map(
            static function (array $field): array {
                $field['field_group'] = 'Recipes';

                return $field;
            },
            array(
            array(
                'key'        => self::INGREDIENTS,
                'name'       => 'ingredients',
                'label'      => 'Ingredients',
                'type'       => 'repeater',
                'sub_fields' => array(
                    array(
                        'key'   => self::INGREDIENT_TITLE,
                        'name'  => 'ingredient_title',
                        'label' => 'Ingredient Title',
                        'type'  => 'text',
                    ),
                    array(
                        'key'        => self::SECTION_INGREDIENTS,
                        'name'       => 'section_ingredients',
                        'label'      => 'Section Ingredients',
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
                ),
            ),
            array(
                'key'        => self::DIRECTIONS,
                'name'       => 'directions',
                'label'      => 'Directions',
                'type'       => 'repeater',
                'sub_fields' => array(
                    array(
                        'key'   => self::SECTION_TITLE,
                        'name'  => 'section_title',
                        'label' => 'Section Title',
                        'type'  => 'text',
                    ),
                    array(
                        'key'        => self::SECTION_DIRECTIONS,
                        'name'       => 'section_directions',
                        'label'      => 'Section Directions',
                        'type'       => 'repeater',
                        'sub_fields' => array(
                            array(
                                'key'   => self::DIRECTION,
                                'name'  => 'direction',
                                'label' => 'Direction',
                                'type'  => 'text',
                            ),
                        ),
                    ),
                ),
            ),
            )
        );
    }

    public static function catalog(): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (string $postType): array {
                return $postType === 'recipes' ? self::recipeFields() : array();
            }
        );
    }

    public static function ingredientTitleRef(): FieldRef
    {
        return new FieldRef(
            self::INGREDIENT_TITLE,
            'ingredient_title',
            'Ingredients → Ingredient Title',
            array(self::INGREDIENTS, self::INGREDIENT_TITLE),
            FieldRef::CONTAINER_REPEATER
        );
    }

    public static function ingredientRef(): FieldRef
    {
        return new FieldRef(
            self::INGREDIENT,
            'ingredient',
            'Ingredients → Section Ingredients → Ingredient',
            array(self::INGREDIENTS, self::SECTION_INGREDIENTS, self::INGREDIENT),
            FieldRef::CONTAINER_REPEATER
        );
    }

    public static function sectionTitleRef(): FieldRef
    {
        return new FieldRef(
            self::SECTION_TITLE,
            'section_title',
            'Directions → Section Title',
            array(self::DIRECTIONS, self::SECTION_TITLE),
            FieldRef::CONTAINER_REPEATER
        );
    }

    public static function directionRef(): FieldRef
    {
        return new FieldRef(
            self::DIRECTION,
            'direction',
            'Directions → Section Directions → Direction',
            array(self::DIRECTIONS, self::SECTION_DIRECTIONS, self::DIRECTION),
            FieldRef::CONTAINER_REPEATER
        );
    }

    /**
     * Incoming ACF save map: three Directions rows, middle Section Title empty.
     *
     * @return array<string, mixed>
     */
    public static function incomingDirectionsWithEmptySecondTitle(): array
    {
        return array(
            self::DIRECTIONS => array(
                'row-0' => array(self::SECTION_TITLE => 'Preheat oven'),
                'row-1' => array(self::SECTION_TITLE => ''),
                'row-2' => array(self::SECTION_TITLE => 'Serve'),
            ),
        );
    }

    /**
     * Stored get_field() shape for the same three Directions rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function storedDirectionsWithEmptySecondTitle(): array
    {
        return array(
            array(self::SECTION_TITLE => 'Preheat oven'),
            array(self::SECTION_TITLE => ''),
            array(self::SECTION_TITLE => 'Serve'),
        );
    }
}
