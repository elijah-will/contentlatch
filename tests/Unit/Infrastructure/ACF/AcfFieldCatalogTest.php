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
                        'key'   => 'field_repeater',
                        'name'  => 'items',
                        'label' => 'Items',
                        'type'  => 'repeater',
                    ),
                    array(
                        'key'   => 'field_flex',
                        'name'  => 'layout',
                        'label' => 'Layout',
                        'type'  => 'flexible_content',
                    ),
                    array(
                        'key'   => 'field_group',
                        'name'  => 'meta',
                        'label' => 'Meta',
                        'type'  => 'group',
                    ),
                    array(
                        'key'   => 'field_rel',
                        'name'  => 'related',
                        'label' => 'Related',
                        'type'  => 'relationship',
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
            array('field_type', 'field_ingredients', 'field_video'),
            $keys
        );
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
            ),
            $types
        );
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
    }
}
