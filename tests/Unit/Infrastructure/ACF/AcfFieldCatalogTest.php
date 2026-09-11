<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use PHPUnit\Framework\TestCase;

final class AcfFieldCatalogTest extends TestCase
{
    public function testReturnsSupportedFieldsAndExcludesUnsupportedTypes(): void
    {
        $catalog = new AcfFieldCatalog(
            static function (): array {
                return array(
                    array(
                        'key'     => 'field_type',
                        'name'    => 'product_type',
                        'label'   => 'Product Type',
                        'type'    => 'select',
                        'choices' => array(
                            'sauce' => 'Sauce',
                            'dip'   => 'Dip',
                        ),
                    ),
                    array(
                        'key'   => 'field_ingredients',
                        'name'  => 'ingredients',
                        'label' => 'Ingredients',
                        'type'  => 'textarea',
                    ),
                    array(
                        'key'   => 'field_video',
                        'name'  => 'video_url',
                        'label' => 'Video URL',
                        'type'  => 'url',
                    ),
                    array(
                        'key'   => 'field_swatch',
                        'name'  => 'brand_color',
                        'label' => 'Brand Color',
                        'type'  => 'color_picker',
                    ),
                    array(
                        'key'   => 'field_repeater',
                        'name'  => 'items',
                        'label' => 'Items',
                        'type'  => 'repeater',
                        'sub_fields' => array(
                            array(
                                'key'   => 'field_repeater_title',
                                'name'  => 'title',
                                'label' => 'Title',
                                'type'  => 'text',
                            ),
                        ),
                    ),
                    array(
                        'key'   => 'field_flex',
                        'name'  => 'layout',
                        'label' => 'Layout',
                        'type'  => 'flexible_content',
                        'layouts' => array(
                            array(
                                'sub_fields' => array(
                                    array(
                                        'key'   => 'field_flex_title',
                                        'name'  => 'title',
                                        'label' => 'Title',
                                        'type'  => 'text',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'key'   => 'field_group',
                        'name'  => 'meta',
                        'label' => 'Meta',
                        'type'  => 'group',
                        'sub_fields' => array(
                            array(
                                'key'   => 'field_group_title',
                                'name'  => 'title',
                                'label' => 'Title',
                                'type'  => 'text',
                            ),
                        ),
                    ),
                    array(
                        'key'   => 'field_clone',
                        'name'  => 'cloned',
                        'label' => 'Cloned',
                        'type'  => 'clone',
                    ),
                    array(
                        'key'   => 'field_rel',
                        'name'  => 'related',
                        'label' => 'Related',
                        'type'  => 'relationship',
                    ),
                    array(
                        'key'   => 'field_post',
                        'name'  => 'related_post',
                        'label' => 'Related Post',
                        'type'  => 'post_object',
                    ),
                    array(
                        'key'   => 'field_page',
                        'name'  => 'linked_page',
                        'label' => 'Linked Page',
                        'type'  => 'page_link',
                    ),
                    array(
                        'key'   => 'field_tax',
                        'name'  => 'topic',
                        'label' => 'Topic',
                        'type'  => 'taxonomy',
                    ),
                    array(
                        'key'   => 'field_user',
                        'name'  => 'author_extra',
                        'label' => 'Author',
                        'type'  => 'user',
                    ),
                    array(
                        'key'      => 'field_multi',
                        'name'     => 'tags',
                        'label'    => 'Tags',
                        'type'     => 'select',
                        'multiple' => 1,
                    ),
                    array(
                        'key'   => 'field_check',
                        'name'  => 'options',
                        'label' => 'Options',
                        'type'  => 'checkbox',
                    ),
                    array(
                        'key'   => 'field_gallery',
                        'name'  => 'photos',
                        'label' => 'Photos',
                        'type'  => 'gallery',
                    ),
                    array(
                        'key'   => 'field_image',
                        'name'  => 'hero',
                        'label' => 'Hero',
                        'type'  => 'image',
                    ),
                    array(
                        'key'   => 'field_file',
                        'name'  => 'download',
                        'label' => 'Download',
                        'type'  => 'file',
                    ),
                    array(
                        'key'   => 'field_link',
                        'name'  => 'cta',
                        'label' => 'CTA',
                        'type'  => 'link',
                    ),
                    array(
                        'key'   => 'field_map',
                        'name'  => 'location',
                        'label' => 'Location',
                        'type'  => 'google_map',
                    ),
                    array(
                        'key'   => 'field_embed',
                        'name'  => 'video',
                        'label' => 'Video',
                        'type'  => 'oembed',
                    ),
                    array(
                        'key'   => 'not_a_field_key',
                        'name'  => 'bad',
                        'label' => 'Bad',
                        'type'  => 'text',
                    ),
                );
            }
        );

        $fields = $catalog->fieldsForPostType('product');
        $keys   = array_map(static fn ($field): string => $field->key, $fields);

        $this->assertSame(
            array('field_type', 'field_ingredients', 'field_video', 'field_swatch', 'field_repeater_title', 'field_group_title'),
            $keys
        );
        $this->assertNotContains('field_group', $keys);
        $this->assertContains('field_group_title', $keys);
        $this->assertNotContains('field_repeater', $keys);
        $this->assertContains('field_repeater_title', $keys);
        $this->assertNotContains('field_flex', $keys);
        $this->assertNotContains('field_flex_title', $keys);
        $this->assertNotContains('field_clone', $keys);
        $this->assertNotContains('field_rel', $keys);
        $this->assertNotContains('field_post', $keys);
        $this->assertNotContains('field_page', $keys);
        $this->assertNotContains('field_tax', $keys);
        $this->assertNotContains('field_user', $keys);
        $this->assertNotContains('field_multi', $keys);
        $this->assertNotContains('field_check', $keys);
        $this->assertNotContains('field_gallery', $keys);
        $this->assertNotContains('field_image', $keys);
        $this->assertNotContains('field_file', $keys);
        $this->assertNotContains('field_link', $keys);
        $this->assertNotContains('field_map', $keys);
        $this->assertNotContains('field_embed', $keys);
        $this->assertSame('select', $fields[0]->type);
        $this->assertSame('Sauce', $fields[0]->choices['sauce']);
        $this->assertSame('product_type', $fields[0]->name);
        $this->assertSame('Product Type', $fields[0]->label);

        $types = $catalog->fieldTypesForPostType('product');
        $this->assertSame(
            array(
                'field_type'        => 'select',
                'field_ingredients' => 'textarea',
                'field_video'       => 'url',
                'field_swatch'         => 'color_picker',
                'field_repeater_title' => 'text',
                'field_group_title'    => 'text',
            ),
            $types
        );

        $repeaterChild = $fields[4];
        $this->assertSame('field_repeater_title', $repeaterChild->key);
        $this->assertSame('repeater', $repeaterChild->container);
        $this->assertSame('field_repeater', $repeaterChild->repeaterKey);
        $this->assertSame('Items → Title', $repeaterChild->breadcrumb());

        $groupChild = $fields[5];
        $this->assertSame('field_group_title', $groupChild->key);
        $this->assertSame('title', $groupChild->name);
        $this->assertSame('Title', $groupChild->label);
        $this->assertSame(array('field_group', 'field_group_title'), $groupChild->path);
        $this->assertSame('group', $groupChild->container);
        $this->assertSame('Meta → Title', $groupChild->breadcrumb());
        $this->assertSame('Meta', $groupChild->groupLabel());
    }

    public function testNestedGroupChildIsCataloguedAndUnsupportedChildrenAreNot(): void
    {
        $catalog = new AcfFieldCatalog(
            static function (): array {
                return array(
                    array(
                        'key'   => 'field_title',
                        'name'  => 'title',
                        'label' => 'Title',
                        'type'  => 'text',
                    ),
                    array(
                        'key'        => 'field_product_details',
                        'name'       => 'product_details',
                        'label'      => 'Product Details',
                        'type'       => 'group',
                        'sub_fields' => array(
                            array(
                                'key'   => 'field_ingredients',
                                'name'  => 'ingredients',
                                'label' => 'Ingredients',
                                'type'  => 'textarea',
                            ),
                            array(
                                'key'   => 'field_gallery',
                                'name'  => 'photos',
                                'label' => 'Photos',
                                'type'  => 'gallery',
                            ),
                            array(
                                'key'        => 'field_nutrition',
                                'name'       => 'nutrition',
                                'label'      => 'Nutrition',
                                'type'       => 'group',
                                'sub_fields' => array(
                                    array(
                                        'key'   => 'field_calories',
                                        'name'  => 'calories',
                                        'label' => 'Calories',
                                        'type'  => 'number',
                                    ),
                                ),
                            ),
                            array(
                                'key'        => 'field_nested_repeater',
                                'name'       => 'rows',
                                'label'      => 'Rows',
                                'type'       => 'repeater',
                                'sub_fields' => array(
                                    array(
                                        'key'   => 'field_row_title',
                                        'name'  => 'title',
                                        'label' => 'Row Title',
                                        'type'  => 'text',
                                    ),
                                ),
                            ),
                        ),
                    ),
                );
            }
        );

        $fields = $catalog->fieldsForPostType('product');
        $byKey  = array();
        foreach ($fields as $field) {
            $byKey[$field->key] = $field;
        }

        $this->assertArrayHasKey('field_title', $byKey);
        $this->assertSame(array(), $byKey['field_title']->path);
        $this->assertSame('', $byKey['field_title']->container);
        $this->assertSame('Title', $byKey['field_title']->breadcrumb());

        $this->assertArrayHasKey('field_ingredients', $byKey);
        $this->assertSame(array('field_product_details', 'field_ingredients'), $byKey['field_ingredients']->path);
        $this->assertSame('group', $byKey['field_ingredients']->container);
        $this->assertSame('Product Details → Ingredients', $byKey['field_ingredients']->breadcrumb());

        $this->assertArrayHasKey('field_calories', $byKey);
        $this->assertSame(
            array('field_product_details', 'field_nutrition', 'field_calories'),
            $byKey['field_calories']->path
        );
        $this->assertSame('Product Details → Nutrition → Calories', $byKey['field_calories']->breadcrumb());
        $this->assertSame('Product Details → Nutrition', $byKey['field_calories']->groupLabel());

        $this->assertArrayNotHasKey('field_product_details', $byKey);
        $this->assertArrayNotHasKey('field_nutrition', $byKey);
        $this->assertArrayNotHasKey('field_gallery', $byKey);
        $this->assertArrayNotHasKey('field_nested_repeater', $byKey);
        $this->assertArrayHasKey('field_row_title', $byKey);
        $this->assertSame('repeater', $byKey['field_row_title']->container);
        $this->assertSame('field_nested_repeater', $byKey['field_row_title']->repeaterKey);
        $this->assertSame(
            array('field_product_details', 'field_nested_repeater', 'field_row_title'),
            $byKey['field_row_title']->path
        );
        $this->assertSame('Product Details → Rows → Row Title', $byKey['field_row_title']->breadcrumb());
    }

    public function testAllSupportedTypesAreIncluded(): void
    {
        $raw = array();
        foreach (AcfFieldCatalog::SUPPORTED_TYPES as $index => $type) {
            $raw[] = array(
                'key'   => 'field_' . $type,
                'name'  => $type,
                'label' => $type,
                'type'  => $type,
            );
        }

        $catalog = new AcfFieldCatalog(
            static function () use ($raw): array {
                return $raw;
            }
        );

        $this->assertCount(
            count(AcfFieldCatalog::SUPPORTED_TYPES),
            $catalog->fieldsForPostType('post')
        );
    }

    public function testMissingAcfSourceReturnsEmpty(): void
    {
        $catalog = new AcfFieldCatalog();
        $this->assertSame(array(), $catalog->fieldsForPostType('product'));
    }

    public function testToFieldRefUsesKeyAsCanonicalIdentity(): void
    {
        $catalog = new AcfFieldCatalog(
            static function (): array {
                return array(
                    array(
                        'key'   => 'field_abc',
                        'name'  => 'cta_url',
                        'label' => 'CTA URL',
                        'type'  => 'url',
                    ),
                );
            }
        );

        $ref = $catalog->fieldsForPostType('page')[0]->toFieldRef();
        $this->assertSame('field_abc', $ref->key);
        $this->assertSame('cta_url', $ref->name);
        $this->assertSame('CTA URL', $ref->label);
        $this->assertSame(array(), $ref->path);
        $this->assertSame('', $ref->container);
    }

    public function testNestedRepeaterScalarIsCataloguedAndUnsupportedRepeaterChildrenRemainExcluded(): void
    {
        $catalog = new AcfFieldCatalog(
            static function (): array {
                return array(
                    array(
                        'key'        => 'field_outer',
                        'name'       => 'outer',
                        'label'      => 'Outer',
                        'type'       => 'repeater',
                        'sub_fields' => array(
                            array(
                                'key'   => 'field_outer_title',
                                'name'  => 'title',
                                'label' => 'Title',
                                'type'  => 'text',
                            ),
                            array(
                                'key'        => 'field_inner',
                                'name'       => 'inner',
                                'label'      => 'Inner',
                                'type'       => 'repeater',
                                'sub_fields' => array(
                                    array(
                                        'key'   => 'field_inner_title',
                                        'name'  => 'title',
                                        'label' => 'Inner Title',
                                        'type'  => 'text',
                                    ),
                                ),
                            ),
                            array(
                                'key'        => 'field_row_group',
                                'name'       => 'meta',
                                'label'      => 'Meta',
                                'type'       => 'group',
                                'sub_fields' => array(
                                    array(
                                        'key'   => 'field_row_group_title',
                                        'name'  => 'title',
                                        'label' => 'Group Title',
                                        'type'  => 'text',
                                    ),
                                ),
                            ),
                            array(
                                'key'     => 'field_row_flex',
                                'name'    => 'layout',
                                'label'   => 'Layout',
                                'type'    => 'flexible_content',
                                'layouts' => array(
                                    array(
                                        'sub_fields' => array(
                                            array(
                                                'key'   => 'field_row_flex_title',
                                                'name'  => 'title',
                                                'label' => 'Flex Title',
                                                'type'  => 'text',
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                            array(
                                'key'   => 'field_row_clone',
                                'name'  => 'cloned',
                                'label' => 'Cloned',
                                'type'  => 'clone',
                            ),
                        ),
                    ),
                );
            }
        );

        $keys = array_map(
            static fn ($field): string => $field->key,
            $catalog->fieldsForPostType('product')
        );

        $this->assertSame(array('field_outer_title', 'field_inner_title'), $keys);
        $this->assertNotContains('field_outer', $keys);
        $this->assertNotContains('field_inner', $keys);
        $this->assertNotContains('field_row_group', $keys);
        $this->assertNotContains('field_row_group_title', $keys);
        $this->assertNotContains('field_row_flex', $keys);
        $this->assertNotContains('field_row_flex_title', $keys);
        $this->assertNotContains('field_row_clone', $keys);

        $byKey = array();
        foreach ($catalog->fieldsForPostType('product') as $field) {
            $byKey[$field->key] = $field;
        }

        $inner = $byKey['field_inner_title'];
        $this->assertSame(array('field_outer', 'field_inner', 'field_inner_title'), $inner->path);
        $this->assertSame(array('field_outer', 'field_inner'), $inner->repeaterChain());
        $this->assertSame('field_outer', $inner->repeaterKey);
        $this->assertTrue($inner->isNestedRepeaterChild());
        $this->assertTrue($inner->isBuilderSelectable());
        $this->assertSame('repeater', $inner->container);
        $this->assertTrue($byKey['field_outer_title']->isBuilderSelectable());
        $this->assertFalse($byKey['field_outer_title']->isNestedRepeaterChild());

        $maps = $catalog->nestedResolutionMaps('product', $catalog->fieldTypesForPostType('product'));
        $this->assertSame('field_outer', $maps['repeater_keys']['field_inner_title']);
        $this->assertSame(array('field_outer', 'field_inner'), $maps['repeater_chains']['field_inner_title']);
        $this->assertSame(array('field_outer'), $maps['repeater_chains']['field_outer_title']);
    }

    public function testThirdRepeaterLevelRemainsExcluded(): void
    {
        $catalog = new AcfFieldCatalog(
            static function (): array {
                return array(
                    array(
                        'key'        => 'field_outer',
                        'name'       => 'outer',
                        'label'      => 'Outer',
                        'type'       => 'repeater',
                        'sub_fields' => array(
                            array(
                                'key'        => 'field_inner',
                                'name'       => 'inner',
                                'label'      => 'Inner',
                                'type'       => 'repeater',
                                'sub_fields' => array(
                                    array(
                                        'key'        => 'field_deep',
                                        'name'       => 'deep',
                                        'label'      => 'Deep',
                                        'type'       => 'repeater',
                                        'sub_fields' => array(
                                            array(
                                                'key'   => 'field_deep_title',
                                                'name'  => 'title',
                                                'label' => 'Deep Title',
                                                'type'  => 'text',
                                            ),
                                        ),
                                    ),
                                    array(
                                        'key'   => 'field_inner_title',
                                        'name'  => 'title',
                                        'label' => 'Inner Title',
                                        'type'  => 'text',
                                    ),
                                ),
                            ),
                        ),
                    ),
                );
            }
        );

        $keys = array_map(
            static fn ($field): string => $field->key,
            $catalog->fieldsForPostType('recipe')
        );

        $this->assertSame(array('field_inner_title'), $keys);
        $this->assertNotContains('field_deep_title', $keys);
    }

    public function testProduct636AndRecipe12325RepeaterChildrenAreCatalogued(): void
    {
        $product = \ContentGuard\Tests\Support\AcfRepeaterFixtures::productCatalog();
        $recipe  = \ContentGuard\Tests\Support\AcfRepeaterFixtures::recipeCatalog();

        $productFields = $product->fieldsForPostType('product');
        $byKey         = array();
        foreach ($productFields as $field) {
            $byKey[$field->key] = $field;
        }

        $this->assertArrayHasKey(\ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_TYPE, $byKey);
        $this->assertSame('', $byKey[\ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_TYPE]->container);
        $this->assertArrayNotHasKey(\ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_INFORMATION, $byKey);
        $this->assertArrayNotHasKey(\ContentGuard\Tests\Support\AcfRepeaterFixtures::ITEM_SIZE, $byKey);
        $this->assertArrayHasKey(\ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE, $byKey);
        $size = $byKey[\ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE];
        $this->assertSame('repeater', $size->container);
        $this->assertSame(\ContentGuard\Tests\Support\AcfRepeaterFixtures::ITEM_SIZE, $size->repeaterKey);
        $this->assertSame('Product Information → Item Size → Product Size', $size->breadcrumb());
        $this->assertSame(
            array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_INFORMATION,
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::ITEM_SIZE,
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE,
            ),
            $size->path
        );

        $maps = $product->nestedResolutionMaps(
            'product',
            $product->fieldTypesForPostType('product')
        );
        $this->assertSame(
            \ContentGuard\Tests\Support\AcfRepeaterFixtures::ITEM_SIZE,
            $maps['repeater_keys'][\ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE]
        );

        $ingredient = $recipe->fieldsForPostType('recipe')[0];
        $this->assertSame(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT, $ingredient->key);
        $this->assertSame('repeater', $ingredient->container);
        $this->assertSame('Ingredient List → Ingredient', $ingredient->breadcrumb());
        $this->assertSame(
            array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST,
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT,
            ),
            $ingredient->path
        );
        $this->assertArrayNotHasKey(
            'quantifier',
            $ingredient->toCatalogArray()
        );
        $this->assertSame(
            array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST),
            $ingredient->repeaterChain()
        );
        $this->assertFalse($ingredient->isNestedRepeaterChild());
        $this->assertTrue($ingredient->isBuilderSelectable());
        $this->assertTrue($size->isBuilderSelectable());
    }

    public function testRecipeNestedRepeaterLeafIsCataloguedWithATwoLevelChain(): void
    {
        $catalog = \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::recipeCatalog();
        $name    = $catalog->fieldsForPostType('recipe')[0];

        $this->assertSame(\ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME, $name->key);
        $this->assertSame(\ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::path(), $name->path);
        $this->assertSame(\ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::chain(), $name->repeaterChain());
        $this->assertSame('Directions → Steps → Name', $name->breadcrumb());
        $this->assertTrue($name->isNestedRepeaterChild());
        $this->assertTrue($name->isBuilderSelectable());
    }
}
