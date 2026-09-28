<?php
/**
 * Deterministic ACF 6.8.9 Clone fixtures. dash2024 has no live Clone fields.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Support;

use ContentLatch\Domain\FieldRef;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;
use ContentLatch\Infrastructure\ACF\FieldDefinition;

final class AcfCloneFixtures
{
    public const TITLE            = 'field_title';
    public const TITLE_NAME       = 'title';
    public const INGREDIENTS      = 'field_ingredients';
    public const PRODUCT_DETAILS  = 'field_product_details';

    public const CLONE_A          = 'field_clone_a';
    public const CLONE_A_NAME     = 'shared_a';
    public const CLONE_A_LABEL    = 'Shared Content';

    public const CLONE_B          = 'field_clone_b';
    public const CLONE_B_NAME     = 'shared_b';
    public const CLONE_B_LABEL    = 'Hero Clone';

    public const CLONE_GROUP      = 'field_clone_group';
    public const CLONE_GROUP_NAME = 'product_clone';

    public const REPEATER         = 'field_item_list';
    public const CLONE_REP        = 'field_clone_rep';

    public const FLEX             = 'field_modules';
    public const CLONE_FLEX       = 'field_clone_flex';
    public const LAYOUT_HERO      = 'hero';

    public static function cloneATitlePosted(): string
    {
        return self::CLONE_A . '_' . self::TITLE;
    }

    public static function cloneBTitleId(): string
    {
        return FieldRef::resolutionIdFor(self::CLONE_B, self::TITLE);
    }

    public static function cloneGroupDetailsPosted(): string
    {
        return self::CLONE_GROUP . '_' . self::PRODUCT_DETAILS;
    }

    public static function cloneRepTitlePosted(): string
    {
        return self::CLONE_REP . '_' . self::TITLE;
    }

    public static function cloneFlexTitlePosted(): string
    {
        return self::CLONE_FLEX . '_' . self::TITLE;
    }

    public static function cloneFlexGroupPosted(): string
    {
        return self::CLONE_FLEX . '_' . self::PRODUCT_DETAILS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function pageFields(): array
    {
        return array_merge(
            array(self::directTitle()),
            array(self::seamlessCloneA()),
            array(self::groupCloneB()),
            array(self::seamlessCloneOfGroup()),
            array(self::repeaterWithSeamlessClone()),
            array(self::flexWithCloneAndGroup()),
            array(self::unsupportedCloneChildren())
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function directTitle(): array
    {
        return array(
            'key'   => self::TITLE,
            'name'  => self::TITLE_NAME,
            'label' => 'Title',
            'type'  => 'text',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function seamlessCloneA(bool $prefixName = true, bool $prefixLabel = true): array
    {
        return array(
            'key'          => self::CLONE_A,
            'name'         => self::CLONE_A_NAME,
            'label'        => self::CLONE_A_LABEL,
            'type'         => 'clone',
            'display'      => 'seamless',
            'prefix_name'  => $prefixName ? 1 : 0,
            'prefix_label' => $prefixLabel ? 1 : 0,
            'sub_fields'   => array(
                self::clonedScalar(
                    self::CLONE_A,
                    self::TITLE,
                    $prefixName ? 'shared_a_title' : self::TITLE_NAME,
                    $prefixLabel ? 'Shared Title' : 'Title',
                    'text'
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function groupCloneB(): array
    {
        return array(
            'key'        => self::CLONE_B,
            'name'       => self::CLONE_B_NAME,
            'label'      => self::CLONE_B_LABEL,
            'type'       => 'clone',
            'display'    => 'group',
            'sub_fields' => array(
                array(
                    'key'     => self::TITLE,
                    'name'    => self::TITLE_NAME,
                    'label'   => 'Title',
                    'type'    => 'text',
                    '_clone'  => self::CLONE_B,
                    '__key'   => self::TITLE,
                    '__name'  => self::TITLE_NAME,
                    '__label' => 'Title',
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function seamlessCloneOfGroup(): array
    {
        return array(
            'key'        => self::CLONE_GROUP,
            'name'       => self::CLONE_GROUP_NAME,
            'label'      => 'Product Details',
            'type'       => 'clone',
            'display'    => 'seamless',
            'sub_fields' => array(
                array(
                    'key'        => self::cloneGroupDetailsPosted(),
                    'name'       => 'product_details',
                    'label'      => 'Product Details',
                    'type'       => 'group',
                    '_clone'     => self::CLONE_GROUP,
                    '__key'      => self::PRODUCT_DETAILS,
                    '__name'     => 'product_details',
                    '__label'    => 'Product Details',
                    'sub_fields' => array(
                        array(
                            'key'   => self::INGREDIENTS,
                            'name'  => 'ingredients',
                            'label' => 'Ingredients',
                            'type'  => 'textarea',
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function repeaterWithSeamlessClone(): array
    {
        return array(
            'key'        => self::REPEATER,
            'name'       => 'item_list',
            'label'      => 'Item List',
            'type'       => 'repeater',
            'sub_fields' => array(
                array(
                    'key'        => self::CLONE_REP,
                    'name'       => 'row_shared',
                    'label'      => 'Row Shared',
                    'type'       => 'clone',
                    'display'    => 'seamless',
                    'sub_fields' => array(
                        self::clonedScalar(
                            self::CLONE_REP,
                            self::TITLE,
                            'row_shared_title',
                            'Title',
                            'text'
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function flexWithCloneAndGroup(): array
    {
        return array(
            'key'     => self::FLEX,
            'name'    => 'modules',
            'label'   => 'Modules',
            'type'    => 'flexible_content',
            'layouts' => array(
                array(
                    'key'        => 'layout_hero',
                    'name'       => self::LAYOUT_HERO,
                    'label'      => 'Hero',
                    'sub_fields' => array(
                        array(
                            'key'        => self::CLONE_FLEX,
                            'name'       => 'hero_shared',
                            'label'      => 'Hero Shared',
                            'type'       => 'clone',
                            'display'    => 'seamless',
                            'sub_fields' => array(
                                self::clonedScalar(
                                    self::CLONE_FLEX,
                                    self::TITLE,
                                    'hero_shared_title',
                                    'Title',
                                    'text'
                                ),
                                array(
                                    'key'        => self::cloneFlexGroupPosted(),
                                    'name'       => 'product_details',
                                    'label'      => 'Product Details',
                                    'type'       => 'group',
                                    '_clone'     => self::CLONE_FLEX,
                                    '__key'      => self::PRODUCT_DETAILS,
                                    '__name'     => 'product_details',
                                    '__label'    => 'Product Details',
                                    'sub_fields' => array(
                                        array(
                                            'key'   => self::INGREDIENTS,
                                            'name'  => 'ingredients',
                                            'label' => 'Ingredients',
                                            'type'  => 'textarea',
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function unsupportedCloneChildren(): array
    {
        return array(
            'key'        => 'field_clone_bad',
            'name'       => 'bad_clone',
            'label'      => 'Bad Clone',
            'type'       => 'clone',
            'display'    => 'group',
            'sub_fields' => array(
                array(
                    'key'        => 'field_inner_clone',
                    'name'       => 'inner',
                    'label'      => 'Inner Clone',
                    'type'       => 'clone',
                    'sub_fields' => array(
                        self::clonedScalar('field_inner_clone', self::TITLE, 'title', 'Title', 'text'),
                    ),
                ),
                array(
                    'key'        => 'field_cloned_repeater',
                    'name'       => 'rows',
                    'label'      => 'Rows',
                    'type'       => 'repeater',
                    'sub_fields' => array(
                        array(
                            'key'   => 'field_cloned_row_title',
                            'name'  => 'title',
                            'label' => 'Title',
                            'type'  => 'text',
                        ),
                    ),
                ),
                array(
                    'key'     => 'field_cloned_flex',
                    'name'    => 'layout',
                    'label'   => 'Layout',
                    'type'    => 'flexible_content',
                    'layouts' => array(
                        array(
                            'key'        => 'layout_x',
                            'name'       => 'block',
                            'label'      => 'Block',
                            'sub_fields' => array(
                                array(
                                    'key'   => 'field_cloned_flex_title',
                                    'name'  => 'title',
                                    'label' => 'Title',
                                    'type'  => 'text',
                                ),
                            ),
                        ),
                    ),
                ),
                array(
                    'key'   => 'field_cloned_gallery',
                    'name'  => 'photos',
                    'label' => 'Photos',
                    'type'  => 'gallery',
                ),
            ),
        );
    }

    /**
     * Simulates ACF Seamless acf/get_fields splice.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function splicedSeamlessLeak(): array
    {
        $child = self::clonedScalar(
            self::CLONE_A,
            self::TITLE,
            'shared_a_title',
            'Shared Title',
            'text'
        );

        return array(
            self::directTitle(),
            $child,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function clonedScalar(
        string $cloneKey,
        string $originalKey,
        string $name,
        string $label,
        string $type
    ): array {
        return array(
            'key'     => $cloneKey . '_' . $originalKey,
            'name'    => $name,
            'label'   => $label,
            'type'    => $type,
            '_clone'  => $cloneKey,
            '__key'   => $originalKey,
            '__name'  => self::TITLE_NAME,
            '__label' => 'Title',
        );
    }

    public static function pageCatalog(): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (string $postType): array {
                return $postType === 'page' ? self::pageFields() : array();
            }
        );
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    public static function catalogFor(array $fields, string $postType = 'page'): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (string $type) use ($fields, $postType): array {
                return $type === $postType ? $fields : array();
            }
        );
    }

    /**
     * @return array<string, FieldDefinition>
     */
    public static function byResolutionId(AcfFieldCatalog $catalog, string $postType = 'page'): array
    {
        $byId = array();
        foreach ($catalog->fieldsForPostType($postType) as $field) {
            $byId[$field->resolutionId()] = $field;
        }

        return $byId;
    }

    public static function cloneATitleRef(): FieldRef
    {
        return new FieldRef(
            self::TITLE,
            'shared_a_title',
            'Shared Content → Title',
            array(self::CLONE_A, self::cloneATitlePosted()),
            FieldRef::CONTAINER_CLONE,
            '',
            self::CLONE_A
        );
    }

    public static function cloneBTitleRef(): FieldRef
    {
        return new FieldRef(
            self::TITLE,
            self::TITLE_NAME,
            'Hero Clone → Title',
            array(self::CLONE_B, self::TITLE),
            FieldRef::CONTAINER_CLONE,
            '',
            self::CLONE_B
        );
    }

    public static function cloneGroupIngredientsRef(): FieldRef
    {
        return new FieldRef(
            self::INGREDIENTS,
            'ingredients',
            'Product Details → Product Details → Ingredients',
            array(self::CLONE_GROUP, self::cloneGroupDetailsPosted(), self::INGREDIENTS),
            FieldRef::CONTAINER_CLONE,
            '',
            self::CLONE_GROUP
        );
    }

    public static function repeaterCloneTitleRef(): FieldRef
    {
        return new FieldRef(
            self::TITLE,
            'row_shared_title',
            'Item List → Row Shared → Title',
            array(self::REPEATER, self::CLONE_REP, self::cloneRepTitlePosted()),
            FieldRef::CONTAINER_REPEATER,
            '',
            self::CLONE_REP
        );
    }

    public static function flexCloneTitleRef(): FieldRef
    {
        return new FieldRef(
            self::TITLE,
            'hero_shared_title',
            'Modules → Hero → Hero Shared → Title',
            array(self::FLEX, self::CLONE_FLEX, self::cloneFlexTitlePosted()),
            FieldRef::CONTAINER_FLEXIBLE,
            self::LAYOUT_HERO,
            self::CLONE_FLEX
        );
    }

    public static function flexCloneIngredientsRef(): FieldRef
    {
        return new FieldRef(
            self::INGREDIENTS,
            'ingredients',
            'Modules → Hero → Hero Shared → Product Details → Ingredients',
            array(self::FLEX, self::CLONE_FLEX, self::cloneFlexGroupPosted(), self::INGREDIENTS),
            FieldRef::CONTAINER_FLEXIBLE,
            self::LAYOUT_HERO,
            self::CLONE_FLEX
        );
    }
}
