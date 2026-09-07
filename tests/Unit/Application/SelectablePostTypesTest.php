<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use PHPUnit\Framework\TestCase;

final class SelectablePostTypesTest extends TestCase
{
    public function testOnlyPostTypesWithCatalogSupportedFieldsAreSelectable(): void
    {
        $factory = $this->factory();
        $selectable = $factory->selectablePostTypes();

        $this->assertSame(
            array(
                'product'  => 'Product',
                'recipe'   => 'Recipe',
                'colorful' => 'Colorful',
            ),
            $selectable
        );
        $this->assertArrayHasKey('product', $selectable);
        $this->assertArrayNotHasKey('gallery', $selectable);
        $this->assertArrayNotHasKey('empty', $selectable);
        $this->assertArrayNotHasKey('media', $selectable);
    }

    public function testColorPickerCountsAsASupportedField(): void
    {
        $this->assertArrayHasKey('colorful', $this->factory()->selectablePostTypes());
        $this->assertSame('color_picker', $this->factory()->fieldsForPostType('colorful')[0]['type']);
    }

    public function testExistingSupportedPostTypesRemainAvailable(): void
    {
        $selectable = $this->factory()->selectablePostTypes();

        $this->assertSame('Product', $selectable['product']);
        $this->assertSame('Recipe', $selectable['recipe']);
        $this->assertNotEmpty($this->factory()->fieldsForPostType('product'));
        $this->assertNotEmpty($this->factory()->fieldsForPostType('recipe'));
    }

    private function factory(): RuleDocumentFactory
    {
        $catalog = new AcfFieldCatalog(
            static function (string $postType): array {
                return match ($postType) {
                    'product' => array(
                        array(
                            'key'   => 'field_name',
                            'name'  => 'product_name',
                            'label' => 'Product Name',
                            'type'  => 'text',
                        ),
                    ),
                    'recipe' => array(
                        array(
                            'key'   => 'field_title',
                            'name'  => 'recipe_title',
                            'label' => 'Title',
                            'type'  => 'text',
                        ),
                        array(
                            'key'   => 'field_group',
                            'name'  => 'details',
                            'label' => 'Details',
                            'type'  => 'group',
                            'sub_fields' => array(
                                array(
                                    'key'   => 'field_nested',
                                    'name'  => 'nested',
                                    'label' => 'Nested',
                                    'type'  => 'text',
                                ),
                            ),
                        ),
                    ),
                    'colorful' => array(
                        array(
                            'key'   => 'field_swatch',
                            'name'  => 'brand_color',
                            'label' => 'Brand Color',
                            'type'  => 'color_picker',
                        ),
                    ),
                    'gallery' => array(
                        array(
                            'key'   => 'field_gallery',
                            'name'  => 'photos',
                            'label' => 'Photos',
                            'type'  => 'gallery',
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
                        ),
                        array(
                            'key'   => 'field_clone',
                            'name'  => 'cloned',
                            'label' => 'Cloned',
                            'type'  => 'clone',
                        ),
                        array(
                            'key'   => 'field_image',
                            'name'  => 'hero',
                            'label' => 'Hero',
                            'type'  => 'image',
                        ),
                        array(
                            'key'   => 'field_related',
                            'name'  => 'related',
                            'label' => 'Related',
                            'type'  => 'relationship',
                        ),
                    ),
                    default => array(),
                };
            }
        );

        return RuleDocumentFactory::v1(
            static fn (): array => array(
                'product'  => 'Product',
                'recipe'   => 'Recipe',
                'colorful' => 'Colorful',
                'gallery'  => 'Gallery',
                'empty'    => 'Empty',
                'media'    => 'Media',
            ),
            static function (string $postType) use ($catalog): array {
                $fields = array();
                foreach ($catalog->fieldsForPostType($postType) as $field) {
                    $fields[] = $field->toCatalogArray();
                }

                return $fields;
            }
        );
    }
}
