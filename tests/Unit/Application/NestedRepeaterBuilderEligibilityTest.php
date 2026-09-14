<?php
/**
 * Phase 15C-1: Builder exposure for supported Repeater scalars.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\AcfIntegration;
use ContentGuard\Tests\Support\AcfHollandHouseRecipeFixtures as HH;
use ContentGuard\Tests\Support\AcfNestedRepeaterFixtures;
use ContentGuard\Tests\Support\AcfRepeaterFixtures;
use PHPUnit\Framework\TestCase;

final class NestedRepeaterBuilderEligibilityTest extends TestCase
{
    public function testOneLevelAndGroupRepeaterScalarsStayBuilderVisible(): void
    {
        $ids = $this->builderIds(new AcfIntegration(AcfRepeaterFixtures::recipeCatalog()), 'recipe');
        $this->assertContains(AcfRepeaterFixtures::INGREDIENT, $ids);

        $product = $this->builderFields(new AcfIntegration(AcfRepeaterFixtures::productCatalog()), 'product');
        $size    = $this->byKey($product, AcfRepeaterFixtures::PRODUCT_SIZE);
        $this->assertSame('repeater', $size['container']);
        $this->assertSame('Product Information → Item Size → Product Size', $size['breadcrumb']);
        $this->assertSame('Product Information → Item Size', $size['group_label']);
        $this->assertArrayNotHasKey(AcfRepeaterFixtures::ITEM_SIZE, $this->byKey($product));
    }

    public function testTwoLevelRepeaterScalarsBecomeBuilderVisible(): void
    {
        $fields = $this->builderFields(new AcfIntegration(AcfNestedRepeaterFixtures::recipeCatalog()), 'recipe');
        $name   = $this->byKey($fields, AcfNestedRepeaterFixtures::STEP_NAME);

        $this->assertSame('repeater', $name['container']);
        $this->assertSame('Directions → Steps → Name', $name['breadcrumb']);
        $this->assertSame('Directions → Steps', $name['group_label']);
        $this->assertSame(AcfNestedRepeaterFixtures::path(), $name['path']);
    }

    public function testHollandHouseFieldGroupRepeatersAreBuilderVisible(): void
    {
        $fields = $this->builderFields(new AcfIntegration(HH::catalog()), 'recipes');
        $byKey  = $this->byKey($fields);

        $this->assertSame('Directions → Section Title', $byKey[HH::SECTION_TITLE]['breadcrumb']);
        $this->assertSame('Recipes → Directions', $byKey[HH::SECTION_TITLE]['group_label']);
        $this->assertSame('Recipes', $byKey[HH::SECTION_TITLE]['field_group']);
        $this->assertSame(array(HH::DIRECTIONS, HH::SECTION_TITLE), $byKey[HH::SECTION_TITLE]['path']);

        $this->assertSame(
            'Directions → Section Directions → Direction',
            $byKey[HH::DIRECTION]['breadcrumb']
        );
        $this->assertSame(
            'Recipes → Directions → Section Directions',
            $byKey[HH::DIRECTION]['group_label']
        );
        $this->assertSame('Recipes', $byKey[HH::DIRECTION]['field_group']);
        $this->assertSame(
            array(HH::DIRECTIONS, HH::SECTION_DIRECTIONS, HH::DIRECTION),
            $byKey[HH::DIRECTION]['path']
        );

        $this->assertSame('Ingredients → Ingredient Title', $byKey[HH::INGREDIENT_TITLE]['breadcrumb']);
        $this->assertSame('Recipes → Ingredients', $byKey[HH::INGREDIENT_TITLE]['group_label']);
        $this->assertSame(
            'Ingredients → Section Ingredients → Ingredient',
            $byKey[HH::INGREDIENT]['breadcrumb']
        );
        $this->assertSame(
            'Recipes → Ingredients → Section Ingredients',
            $byKey[HH::INGREDIENT]['group_label']
        );

        $this->assertArrayNotHasKey(HH::FIELD_GROUP, $byKey);
        $this->assertArrayNotHasKey(HH::DIRECTIONS, $byKey);
        $this->assertArrayNotHasKey(HH::SECTION_DIRECTIONS, $byKey);
    }

    public function testUnsupportedNestedStructuresStayHiddenFromTheBuilder(): void
    {
        $acf  = new AcfIntegration($this->mixedRepeaterCatalog());
        $ids  = $this->builderIds($acf, 'product');
        $this->assertContains('field_outer_title', $ids);
        $this->assertContains('field_inner_title', $ids);
        $this->assertContains('field_row_clone_field_row_clone_title', $ids);
        $this->assertNotContains('field_row_group_title', $ids);
        $this->assertNotContains('field_row_flex_title', $ids);
        $this->assertNotContains('field_deep_title', $ids);
        $this->assertNotContains('field_gallery', $ids);
        $this->assertNotContains('field_relationship', $ids);
        $this->assertNotContains('field_inner_clone_title', $ids);
        $this->assertNotContains('field_inner_clone_field_inner_clone_title', $ids);
    }

    public function testSubmittedFakeMetadataCannotSelectAnUnsupportedField(): void
    {
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('product' => 'Product'),
            new AcfIntegration($this->mixedRepeaterCatalog())
        );

        foreach (array(
            'field_row_group_title',
            'field_row_flex_title',
            'field_deep_title',
            'field_gallery',
            'field_inner_clone_title',
        ) as $forged) {
            try {
                $factory->fromAdminInput(array(
                    'name'        => 'Forged nested field',
                    'post_type'   => 'product',
                    'validations' => array(
                        array(
                            'field_key'   => $forged,
                            'type'        => 'required',
                            'label'       => 'Title',
                            'breadcrumb'  => 'Outer → Title',
                            'container'   => 'repeater',
                            'path'        => array('field_outer', $forged),
                            'integration' => 'acf',
                        ),
                    ),
                ));
                $this->fail($forged . ' must remain unselectable');
            } catch (InvalidRuleException $exception) {
                $this->assertSame('Unsupported field.', $exception->getMessage());
            }
        }
    }

    public function testValidNestedFieldCreatesANormalRuleSchema(): void
    {
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('recipes' => 'Recipes'),
            new AcfIntegration(HH::catalog())
        );

        $rule = $factory->fromAdminInput(array(
            'name'        => 'Direction required',
            'post_type'   => 'recipes',
            'validations' => array(
                array(
                    'field_key'  => HH::DIRECTION,
                    'type'       => 'required',
                    'label'      => 'Forged Label',
                    'breadcrumb' => 'Forged → Breadcrumb',
                    'container'  => 'group',
                    'path'       => array('field_fake'),
                    'integration'=> 'acf',
                ),
            ),
        ));

        $field = $rule->validations[0]->field;
        $this->assertSame(1, $rule->schemaVersion);
        $this->assertSame(HH::DIRECTION, $field->key);
        $this->assertSame(HH::DIRECTION, $field->resolutionId());
        $this->assertSame('direction', $field->name);
        $this->assertSame('Directions → Section Directions → Direction', $field->label);
        $this->assertSame('repeater', $field->container);
        $this->assertSame(array(HH::DIRECTIONS, HH::SECTION_DIRECTIONS, HH::DIRECTION), $field->path);
        $this->assertSame('every', $rule->validations[0]->quantifier);
        $this->assertArrayNotHasKey('integration', $field->toArray());
        $this->assertArrayNotHasKey('repeater_chain', $field->toArray());
        $this->assertSame('', $field->layout);
        $this->assertSame('', $field->clone);
    }

    public function testTwoLevelRepeaterCanBeAWhenContainsCondition(): void
    {
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('recipes' => 'Recipes'),
            new AcfIntegration(HH::catalog())
        );

        $rule = $factory->fromAdminInput(array(
            'name'       => 'Flag chicken ingredients',
            'post_type'  => 'recipes',
            'conditions' => array(
                array(
                    'field_key' => HH::INGREDIENT_TITLE,
                    'operator'  => 'contains',
                    'operand'   => 'chicken',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => HH::SECTION_TITLE,
                    'type'      => 'required',
                ),
            ),
        ));

        $this->assertSame('contains', $rule->conditions[0]->operator);
        $this->assertSame('chicken', $rule->conditions[0]->operand);
        $this->assertSame(HH::INGREDIENT_TITLE, $rule->conditions[0]->field->key);
        $this->assertSame('repeater', $rule->conditions[0]->field->container);

        $nested = $factory->fromAdminInput(array(
            'name'       => 'Flag nested chicken',
            'post_type'  => 'recipes',
            'conditions' => array(
                array(
                    'field_key' => HH::INGREDIENT,
                    'operator'  => 'contains',
                    'operand'   => 'chicken',
                    'type'      => 'number',
                    'label'     => 'Forged',
                    'breadcrumb'=> 'Forged → Path',
                    'integration' => 'core',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => HH::SECTION_TITLE,
                    'type'      => 'required',
                ),
            ),
        ));
        $this->assertSame('contains', $nested->conditions[0]->operator);
        $this->assertSame('chicken', $nested->conditions[0]->operand);
        $this->assertSame(HH::INGREDIENT, $nested->conditions[0]->field->key);
        $this->assertSame('Ingredients → Section Ingredients → Ingredient', $nested->conditions[0]->field->label);
    }

    public function testTwoLevelRepeaterConditionOnlyContainsPersists(): void
    {
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('recipes' => 'Recipes'),
            new AcfIntegration(HH::catalog())
        );

        $rule = $factory->fromAdminInput(array(
            'name'       => 'Flag nested chicken without THEN',
            'post_type'  => 'recipes',
            'message'    => 'Avoid chicken in nested ingredients.',
            'conditions' => array(
                array(
                    'field_key' => HH::INGREDIENT,
                    'operator'  => 'contains',
                    'operand'   => 'chicken',
                    'label'     => 'Forged',
                    'breadcrumb'=> 'Forged → Path',
                    'integration' => 'core',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => '',
                    'type'      => 'required',
                ),
            ),
        ));

        $this->assertSame(array(), $rule->validations);
        $this->assertSame('contains', $rule->conditions[0]->operator);
        $this->assertSame(HH::INGREDIENT, $rule->conditions[0]->field->key);
        $this->assertSame('Avoid chicken in nested ingredients.', $rule->message);
        $this->assertSame(1, $rule->schemaVersion);
    }

    /**
     * @return list<string>
     */
    private function builderIds(AcfIntegration $acf, string $postType): array
    {
        return array_values(array_map(
            static fn (array $field): string => (string) ($field['resolution_id'] ?? $field['key'] ?? ''),
            $acf->fieldsForPostType($postType)
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function builderFields(AcfIntegration $acf, string $postType): array
    {
        return $acf->fieldsForPostType($postType);
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, mixed>|array<string, array<string, mixed>>
     */
    private function byKey(array $fields, ?string $key = null): array
    {
        $byKey = array();
        foreach ($fields as $field) {
            $id = (string) ($field['resolution_id'] ?? $field['key'] ?? '');
            $byKey[$id] = $field;
        }

        if ($key === null) {
            return $byKey;
        }

        $this->assertArrayHasKey($key, $byKey);

        return $byKey[$key];
    }

    private function mixedRepeaterCatalog(): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (): array {
                return array(
                    array(
                        'key'   => 'field_gallery',
                        'name'  => 'gallery',
                        'label' => 'Gallery',
                        'type'  => 'gallery',
                    ),
                    array(
                        'key'   => 'field_relationship',
                        'name'  => 'related',
                        'label' => 'Related',
                        'type'  => 'relationship',
                    ),
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
                                        'key'        => 'field_inner_clone',
                                        'name'       => 'inner_cloned',
                                        'label'      => 'Inner Cloned',
                                        'type'       => 'clone',
                                        'sub_fields' => array(
                                            array(
                                                'key'   => 'field_inner_clone_title',
                                                'name'  => 'title',
                                                'label' => 'Inner Clone Title',
                                                'type'  => 'text',
                                            ),
                                        ),
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
                                'name'    => 'modules',
                                'label'   => 'Modules',
                                'type'    => 'flexible_content',
                                'layouts' => array(
                                    array(
                                        'name'       => 'hero',
                                        'label'      => 'Hero',
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
                                'key'        => 'field_row_clone',
                                'name'       => 'cloned',
                                'label'      => 'Cloned',
                                'type'       => 'clone',
                                'sub_fields' => array(
                                    array(
                                        'key'   => 'field_row_clone_title',
                                        'name'  => 'title',
                                        'label' => 'Clone Title',
                                        'type'  => 'text',
                                    ),
                                ),
                            ),
                        ),
                    ),
                );
            }
        );
    }
}
