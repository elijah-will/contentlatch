<?php
/**
 * In-memory ACF Flexible Content fixtures modeled on dash2024 Pages → Modules.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Support;

use ContentGuard\Domain\FieldRef;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;

final class AcfFlexibleFixtures
{
    public const MODULES = 'field_660d684429de1';
    public const HERO_TITLE = 'field_66e48d6611345';
    public const HERO_EYEBROW = 'field_670abc33636a9';
    public const VIDEO_HERO_TITLE = 'field_685c2cb37106e';
    public const CONTENT_BLOCK_1 = 'field_66e9ab856937e';
    public const CONTENT_BLOCK_HEADLINE = 'field_6717b7e879943';
    public const ACCORDION_ITEMS = 'field_67058314da7a3';
    public const ACCORDION_TITLE = 'field_67058364da7a4';
    public const CTA_TITLE = 'field_cta_title_999';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function pageModulesFields(): array
    {
        return array(
            array(
                'key'     => self::MODULES,
                'name'    => 'modules',
                'label'   => 'Modules',
                'type'    => 'flexible_content',
                'layouts' => array(
                    array(
                        'key'        => 'layout_66e48d4511343',
                        'name'       => 'hero',
                        'label'      => 'Hero',
                        'sub_fields' => array(
                            array(
                                'key'   => self::HERO_TITLE,
                                'name'  => 'title',
                                'label' => 'Title',
                                'type'  => 'text',
                            ),
                            array(
                                'key'   => self::HERO_EYEBROW,
                                'name'  => 'eyebrow',
                                'label' => 'Eyebrow',
                                'type'  => 'text',
                            ),
                            array(
                                'key'        => 'field_66e48d8b11347',
                                'name'       => 'cta_buttons',
                                'label'      => 'CTA Buttons',
                                'type'       => 'repeater',
                                'sub_fields' => array(
                                    array(
                                        'key'   => 'field_66e48da011348',
                                        'name'  => 'cta_button',
                                        'label' => 'CTA Button',
                                        'type'  => 'text',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'key'        => 'layout_cta',
                        'name'       => 'cta',
                        'label'      => 'CTA',
                        'sub_fields' => array(
                            array(
                                'key'   => self::CTA_TITLE,
                                'name'  => 'title',
                                'label' => 'Title',
                                'type'  => 'text',
                            ),
                        ),
                    ),
                    array(
                        'key'        => 'layout_685c2cb371066',
                        'name'       => 'video_hero',
                        'label'      => 'Video Hero',
                        'sub_fields' => array(
                            array(
                                'key'   => self::VIDEO_HERO_TITLE,
                                'name'  => 'title',
                                'label' => 'Title',
                                'type'  => 'text',
                            ),
                        ),
                    ),
                    array(
                        'key'        => 'layout_66e8c9561d1ce',
                        'name'       => 'content_block',
                        'label'      => 'Content Block',
                        'sub_fields' => array(
                            array(
                                'key'        => self::CONTENT_BLOCK_1,
                                'name'       => 'content_block_1',
                                'label'      => 'Content Block 1',
                                'type'       => 'group',
                                'sub_fields' => array(
                                    array(
                                        'key'   => self::CONTENT_BLOCK_HEADLINE,
                                        'name'  => 'headline',
                                        'label' => 'Headline',
                                        'type'  => 'text',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'key'        => 'layout_6705816dda79f',
                        'name'       => 'accordion',
                        'label'      => 'Accordion',
                        'sub_fields' => array(
                            array(
                                'key'        => self::ACCORDION_ITEMS,
                                'name'       => 'accordion_items',
                                'label'      => 'Accordion Items',
                                'type'       => 'repeater',
                                'sub_fields' => array(
                                    array(
                                        'key'   => self::ACCORDION_TITLE,
                                        'name'  => 'title',
                                        'label' => 'Title',
                                        'type'  => 'text',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'key'        => 'layout_nested_flex',
                        'name'       => 'nested_flex',
                        'label'      => 'Nested Flex',
                        'sub_fields' => array(
                            array(
                                'key'     => 'field_inner_flex',
                                'name'    => 'inner',
                                'label'   => 'Inner',
                                'type'    => 'flexible_content',
                                'layouts' => array(
                                    array(
                                        'key'        => 'layout_inner',
                                        'name'       => 'inner',
                                        'label'      => 'Inner',
                                        'sub_fields' => array(
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
                        ),
                    ),
                    array(
                        'key'        => 'layout_clone',
                        'name'       => 'cloned',
                        'label'      => 'Cloned',
                        'sub_fields' => array(
                            array(
                                'key'   => 'field_cloned',
                                'name'  => 'cloned',
                                'label' => 'Cloned',
                                'type'  => 'clone',
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    public static function pageCatalog(): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (string $postType): array {
                return $postType === 'page' ? self::pageModulesFields() : array();
            }
        );
    }

    public static function heroTitleRef(): FieldRef
    {
        return new FieldRef(
            self::HERO_TITLE,
            'title',
            'Modules → Hero → Title',
            array(self::MODULES, self::HERO_TITLE),
            FieldRef::CONTAINER_FLEXIBLE,
            'hero'
        );
    }

    public static function contentBlockHeadlineRef(): FieldRef
    {
        return new FieldRef(
            self::CONTENT_BLOCK_HEADLINE,
            'headline',
            'Modules → Content Block → Content Block 1 → Headline',
            array(self::MODULES, self::CONTENT_BLOCK_1, self::CONTENT_BLOCK_HEADLINE),
            FieldRef::CONTAINER_FLEXIBLE,
            'content_block'
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public static function heroTitlePaths(): array
    {
        return array(
            self::HERO_TITLE => array(self::MODULES, self::HERO_TITLE),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public static function heroTitleNames(): array
    {
        return array(
            self::HERO_TITLE => array('modules', 'title'),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function heroTitleFlexKeys(): array
    {
        return array(
            self::HERO_TITLE => self::MODULES,
        );
    }

    /**
     * @return array<string, string>
     */
    public static function heroTitleLayouts(): array
    {
        return array(
            self::HERO_TITLE => 'hero',
        );
    }
}
