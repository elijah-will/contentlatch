<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Admin\RuleEditorState;
use ContentGuard\Application\RulePreview;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleEditorViewTest extends TestCase
{
    public function testNewRuleBuilderRendersSectionsPreviewAndExistingFieldNames(): void
    {
        $html = $this->renderEditor(RuleEditorState::fromRule(null), array());

        $this->assertStringContainsString('Add New Rule', $html);
        $this->assertStringContainsString('id="contentguard-rule-form"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="target_post_type"', $html);
        $this->assertStringContainsString('name="severity"', $html);
        $this->assertStringContainsString('name="status"', $html);
        $this->assertStringContainsString('name="message"', $html);
        $this->assertStringContainsString('name="rule_id"', $html);
        $this->assertStringContainsString('name="validations[0][field_key]"', $html);
        $this->assertStringContainsString('name="validations[0][type]"', $html);
        $this->assertStringContainsString('Rule details', $html);
        $this->assertStringContainsString('>WHEN<', $html);
        $this->assertStringContainsString('>THEN<', $html);
        $this->assertStringContainsString('Rule behavior', $html);
        $this->assertStringContainsString('Rule preview', $html);
        $this->assertStringContainsString(RulePreview::needThenMessage(), $html);
        $this->assertStringContainsString('Prevents publishing when the rule fails.', $html);
        $this->assertStringContainsString('Reports an issue but does not prevent publishing.', $html);
        $this->assertStringContainsString('Enforced and included in audits.', $html);
        $this->assertStringContainsString('Not enforced and not included in audits.', $html);
        $this->assertStringContainsString('Save Rule', $html);
        $this->assertStringContainsString('admin.php?page=contentguard', $html);
        $this->assertStringNotContainsString('Add another rule', $html);
        $this->assertStringContainsString('value="fail"', $html);
        $this->assertStringContainsString('value="warning"', $html);
        $this->assertStringContainsString('value="active"', $html);
        $this->assertStringContainsString('value="inactive"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('id="contentguard-rule-client-notice"', $html);
        $this->assertStringContainsString('<strong>Warning:</strong>', $html);
        $this->assertStringContainsString('class="contentguard-notice-label"', $html);
        $this->assertStringContainsString('class="contentguard-notice-message"', $html);
        $this->assertStringContainsString('contentguardPendingNoticeScroll', $html);
        $this->assertStringContainsString('Repeater and Flexible Content children can be used in THEN', $html);
        $this->assertStringContainsString('They cannot be used in WHEN', $html);
        $this->assertStringContainsString('Top-level Clone fields can be used in WHEN.', $html);
        $this->assertStringNotContainsString('Clone fields are not supported yet.', $html);
        $this->assertStringNotContainsString('Flexible Content and Clone fields are not supported yet.', $html);
    }

    public function testNestedFieldsUseBreadcrumbLabelsAndHideRawKeys(): void
    {
        $html = $this->renderEditor(
            RuleEditorState::fromSubmitted(array(
                'name'             => 'Sauce ingredients',
                'target_post_type' => 'product',
                'conditions'       => array(
                    array(
                        'field_key' => 'field_type',
                        'operator'  => 'equals',
                        'operand'   => 'sauce',
                    ),
                ),
                'validations' => array(
                    array(
                        'field_key' => 'field_ingredients',
                        'type'      => 'required',
                    ),
                ),
            )),
            array(
                array(
                    'key'   => 'field_type',
                    'name'  => 'product_type',
                    'label' => 'Product Type',
                    'type'  => 'select',
                ),
                array(
                    'key'        => 'field_ingredients',
                    'name'       => 'ingredients',
                    'label'      => 'Ingredients',
                    'type'       => 'textarea',
                    'path'       => array('field_product_details', 'field_ingredients'),
                    'container'  => 'group',
                    'breadcrumb' => 'Product Details → Ingredients',
                    'group_label'=> 'Product Details',
                ),
                array(
                    'key'        => 'field_calories',
                    'name'       => 'calories',
                    'label'      => 'Calories',
                    'type'       => 'number',
                    'path'       => array('field_product_details', 'field_nutrition', 'field_calories'),
                    'container'  => 'group',
                    'breadcrumb' => 'Product Details → Nutrition → Calories',
                    'group_label'=> 'Product Details → Nutrition',
                ),
            )
        );

        $this->assertStringContainsString('Product Details → Ingredients', $html);
        $this->assertStringContainsString('Product Details → Nutrition → Calories', $html);
        $this->assertStringContainsString('<optgroup label="Product Details">', $html);
        $this->assertStringContainsString('<optgroup label="Product Details → Nutrition">', $html);
        $this->assertStringContainsString('When Product Type is sauce, Product Details → Ingredients is required.', $html);
        $this->assertStringNotContainsString('field_product_details.field_ingredients', $html);
        $this->assertStringNotContainsString('>field_ingredients<', $html);
    }

    public function testWordPressCoreFieldsAppearInAnOptgroupWithoutExposingIds(): void
    {
        $html = $this->renderEditor(
            RuleEditorState::fromSubmitted(array(
                'name'             => 'Title required',
                'target_post_type' => 'post',
                'validations'      => array(
                    array(
                        'field_key' => 'title',
                        'type'      => 'required',
                    ),
                ),
            )),
            array(
                array(
                    'key'         => 'title',
                    'name'        => 'post_title',
                    'label'       => 'Title',
                    'type'        => 'text',
                    'group_label' => 'WordPress',
                    'integration' => 'core',
                ),
                array(
                    'key'         => 'content',
                    'name'        => 'post_content',
                    'label'       => 'Content',
                    'type'        => 'wysiwyg',
                    'group_label' => 'WordPress',
                    'integration' => 'core',
                ),
                array(
                    'key'   => 'field_ingredients',
                    'name'  => 'ingredients',
                    'label' => 'Ingredients',
                    'type'  => 'textarea',
                ),
                array(
                    'key'         => 'field_nested',
                    'name'        => 'nested',
                    'label'       => 'Nested',
                    'type'        => 'text',
                    'path'        => array('field_group', 'field_nested'),
                    'container'   => 'group',
                    'breadcrumb'  => 'Details → Nested',
                    'group_label' => 'Details',
                    'integration' => 'acf',
                ),
            )
        );

        $this->assertStringContainsString('<optgroup label="WordPress">', $html);
        $this->assertStringContainsString('value="title"', $html);
        $this->assertStringContainsString('>Title<', $html);
        $this->assertStringContainsString('>Content<', $html);
        $this->assertStringContainsString('value="field_ingredients"', $html);
        $this->assertStringContainsString('>Ingredients<', $html);
        $this->assertStringContainsString('<optgroup label="Details">', $html);
        $this->assertStringContainsString('Details → Nested', $html);
        $this->assertStringNotContainsString('>title<', $html);
        $this->assertStringNotContainsString('core:title', $html);
        $this->assertStringNotContainsString('wp:title', $html);
        $this->assertStringContainsString('selected', $html);
        $this->assertStringNotContainsString('>field_calories<', $html);
        $this->assertStringContainsString('Repeater and Flexible Content children can be used in THEN', $html);
    }

    public function testRepeaterChildAppearsInThenWithEveryRowAndNotInWhen(): void
    {
        $html = $this->renderEditor(
            RuleEditorState::fromSubmitted(array(
                'name'             => 'Sauce sizes',
                'target_post_type' => 'product',
                'conditions'       => array(
                    array(
                        'field_key' => 'field_type',
                        'operator'  => 'equals',
                        'operand'   => 'sauce',
                    ),
                ),
                'validations' => array(
                    array(
                        'field_key' => 'field_product_size',
                        'type'      => 'required',
                    ),
                ),
            )),
            array(
                array(
                    'key'   => 'field_type',
                    'name'  => 'product_type',
                    'label' => 'Product Type',
                    'type'  => 'select',
                ),
                array(
                    'key'        => 'field_product_size',
                    'name'       => 'product_size',
                    'label'      => 'Product Size',
                    'type'       => 'text',
                    'path'       => array('field_product_information', 'field_item_size', 'field_product_size'),
                    'container'  => 'repeater',
                    'breadcrumb' => 'Product Information → Item Size → Product Size',
                    'group_label'=> 'Product Information → Item Size',
                ),
            )
        );

        $this->assertStringContainsString('Product Information → Item Size → Product Size (every row)', $html);
        $this->assertStringContainsString('<optgroup label="Product Information → Item Size">', $html);
        $this->assertMatchesRegularExpression(
            '/name="validations\[0\]\[field_key\]"[\s\S]*Product Information → Item Size → Product Size \(every row\)/',
            $html
        );
        if (preg_match('/name="conditions\[0\]\[field_key\]"[^>]*>([\s\S]*?)<\/select>/', $html, $match) !== 1) {
            $this->fail('WHEN field select missing');
        }
        $this->assertStringNotContainsString('field_product_size', $match[1]);
        $this->assertStringNotContainsString('(every row)', $match[1]);
        $this->assertStringContainsString('field_type', $match[1]);
    }

    public function testTwoLevelRepeaterChildAppearsInThenWithExistingBreadcrumbGrouping(): void
    {
        $html = $this->renderEditor(
            RuleEditorState::fromSubmitted(array(
                'name'             => 'Direction required',
                'target_post_type' => 'recipes',
                'conditions'       => array(
                    array(
                        'field_key' => '',
                        'operator'  => '',
                    ),
                ),
                'validations'      => array(
                    array(
                        'field_key' => \ContentGuard\Tests\Support\AcfHollandHouseRecipeFixtures::DIRECTION,
                        'type'      => 'required',
                    ),
                ),
            )),
            array(
                array(
                    'key'         => \ContentGuard\Tests\Support\AcfHollandHouseRecipeFixtures::SECTION_TITLE,
                    'name'        => 'section_title',
                    'label'       => 'Section Title',
                    'type'        => 'text',
                    'path'        => array(
                        \ContentGuard\Tests\Support\AcfHollandHouseRecipeFixtures::DIRECTIONS,
                        \ContentGuard\Tests\Support\AcfHollandHouseRecipeFixtures::SECTION_TITLE,
                    ),
                    'container'   => 'repeater',
                    'breadcrumb'  => 'Directions → Section Title',
                    'group_label' => 'Directions',
                ),
                array(
                    'key'         => \ContentGuard\Tests\Support\AcfHollandHouseRecipeFixtures::DIRECTION,
                    'name'        => 'direction',
                    'label'       => 'Direction',
                    'type'        => 'text',
                    'path'        => array(
                        \ContentGuard\Tests\Support\AcfHollandHouseRecipeFixtures::DIRECTIONS,
                        \ContentGuard\Tests\Support\AcfHollandHouseRecipeFixtures::SECTION_DIRECTIONS,
                        \ContentGuard\Tests\Support\AcfHollandHouseRecipeFixtures::DIRECTION,
                    ),
                    'container'   => 'repeater',
                    'breadcrumb'  => 'Directions → Section Directions → Direction',
                    'group_label' => 'Directions → Section Directions',
                ),
            )
        );

        $this->assertStringContainsString('Directions → Section Title (every row)', $html);
        $this->assertStringContainsString('Directions → Section Directions → Direction (every row)', $html);
        $this->assertStringContainsString('<optgroup label="Directions">', $html);
        $this->assertStringContainsString('<optgroup label="Directions → Section Directions">', $html);
        if (preg_match('/name="conditions\[0\]\[field_key\]"[^>]*>([\s\S]*?)<\/select>/', $html, $match) !== 1) {
            $this->fail('WHEN field select missing');
        }
        $this->assertStringNotContainsString(
            \ContentGuard\Tests\Support\AcfHollandHouseRecipeFixtures::DIRECTION,
            $match[1]
        );
        $this->assertStringNotContainsString(
            \ContentGuard\Tests\Support\AcfHollandHouseRecipeFixtures::SECTION_TITLE,
            $match[1]
        );
    }

    public function testFlexibleChildAppearsInThenWithEveryLayoutRowAndNotInWhen(): void
    {
        $html = $this->renderEditor(
            RuleEditorState::fromSubmitted(array(
                'name'             => 'Hero titles',
                'target_post_type' => 'page',
                'conditions'       => array(
                    array(
                        'field_key' => '',
                        'operator'  => '',
                    ),
                ),
                'validations'      => array(
                    array(
                        'field_key' => \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
                        'type'      => 'required',
                    ),
                ),
            )),
            array(
                array(
                    'key'   => 'field_page_type',
                    'name'  => 'page_type',
                    'label' => 'Page Type',
                    'type'  => 'select',
                ),
                array(
                    'key'          => \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
                    'name'         => 'title',
                    'label'        => 'Title',
                    'type'         => 'text',
                    'path'         => array(
                        \ContentGuard\Tests\Support\AcfFlexibleFixtures::MODULES,
                        \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
                    ),
                    'container'    => 'flexible_content',
                    'layout'       => 'hero',
                    'layout_label' => 'Hero',
                    'breadcrumb'   => 'Modules → Hero → Title',
                    'group_label'  => 'Modules → Hero',
                ),
            )
        );

        $this->assertStringContainsString('Modules → Hero → Title (every Hero row)', $html);
        $this->assertStringContainsString('<optgroup label="Modules → Hero">', $html);
        $this->assertStringNotContainsString('field_660d684429de1', $html);
        $this->assertStringNotContainsString('layout_66e48d4511343', $html);
        if (preg_match('/name="conditions\[0\]\[field_key\]"[^>]*>([\s\S]*?)<\/select>/', $html, $match) !== 1) {
            $this->fail('WHEN field select missing');
        }
        $this->assertStringNotContainsString(\ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE, $match[1]);
        $this->assertStringNotContainsString('(every Hero row)', $match[1]);
    }

    public function testEditRuleLoadsQuotedWhenThenAndAddAnotherRule(): void
    {
        $rule = RuleFactory::rule(array(
            'id'         => 7,
            'name'       => 'New Products need Page ID',
            'status'     => RuleStatus::Active,
            'severity'   => RuleSeverity::Fail,
            'conditions' => array(
                RuleFactory::condition(array(
                    'field'    => RuleFactory::field('field_683097e0dc6d2', 'show_new_tag', 'Show "New" Tag'),
                    'operator' => 'equals',
                    'operand'  => '1',
                )),
            ),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => RuleFactory::field('field_6a05f9e8420ea', 'pr_page_id', 'PowerReviews Page ID'),
                    'type'  => 'required',
                )),
            ),
        ));

        $html = $this->renderEditor(
            RuleEditorState::fromRule($rule),
            array(
                array('key' => 'field_683097e0dc6d2', 'name' => 'show_new_tag', 'label' => 'Show "New" Tag', 'type' => 'true_false'),
                array('key' => 'field_6a05f9e8420ea', 'name' => 'pr_page_id', 'label' => 'PowerReviews Page ID', 'type' => 'text'),
            )
        );

        $this->assertStringContainsString('Edit Rule', $html);
        $this->assertStringContainsString('Update Rule', $html);
        $this->assertStringContainsString('New Products need Page ID', $html);
        $this->assertStringContainsString('name="conditions[0][field_key]"', $html);
        $this->assertStringContainsString('name="conditions[0][operator]"', $html);
        $this->assertStringContainsString('name="conditions[0][operand]"', $html);
        $this->assertStringContainsString('Show &quot;New&quot; Tag', $html);
        $this->assertStringContainsString('When Show &quot;New&quot; Tag is Yes, PowerReviews Page ID is required.', $html);
        $this->assertStringContainsString('Add another rule', $html);
        $this->assertStringContainsString('admin.php?page=contentguard&amp;action=new', $html);
        $this->assertStringContainsString('name="rule_id"', $html);
        $this->assertStringContainsString('value="7"', $html);
        $this->assertStringContainsString('value="1"', $html);
        $this->assertStringContainsString('Yes', $html);
    }

    public function testDraftValuesArePreservedAfterValidationFailure(): void
    {
        $html = $this->renderEditor(
            RuleEditorState::fromSubmitted(array(
                'name'             => 'Unsaved draft',
                'target_post_type' => 'product',
                'severity'         => 'warning',
                'status'           => 'inactive',
                'conditions'       => array(
                    array(
                        'field_key' => 'field_683097e0dc6d2',
                        'operator'  => 'equals',
                        'operand'   => '0',
                    ),
                ),
                'validations' => array(
                    array(
                        'field_key' => 'field_6a05f9e8420ea',
                        'type'      => 'required',
                    ),
                ),
            )),
            array(
                array('key' => 'field_683097e0dc6d2', 'name' => 'show_new_tag', 'label' => 'Show "New" Tag', 'type' => 'true_false'),
                array('key' => 'field_6a05f9e8420ea', 'name' => 'pr_page_id', 'label' => 'PowerReviews Page ID', 'type' => 'text'),
            ),
            array('type' => 'error', 'message' => 'We could not add this rule. A condition value is required.')
        );

        $this->assertStringContainsString('Unsaved draft', $html);
        $this->assertStringContainsString('<strong>Warning:</strong>', $html);
        $this->assertStringContainsString('class="contentguard-notice-label"', $html);
        $this->assertStringContainsString('class="contentguard-notice-message"', $html);
        $this->assertStringContainsString('We could not add this rule.', $html);
        $this->assertStringContainsString('id="contentguard-rule-notice"', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('contentguardPendingNoticeScroll', $html);
        $this->assertStringContainsString('#contentguard-rule-notice', $html);
        $this->assertStringContainsString('id="contentguard-rule-client-notice"', $html);
        $this->assertStringContainsString('value="warning"', $html);
        $this->assertStringContainsString('value="inactive"', $html);
        $this->assertStringContainsString('When Show &quot;New&quot; Tag is No, PowerReviews Page ID is required.', $html);
    }

    public function testFailedUpdatePreservesSubmittedValuesAndErrorTarget(): void
    {
        $html = $this->renderEditor(
            RuleEditorState::fromSubmitted(array(
                'rule_id'          => '7',
                'name'             => 'Updated draft name',
                'target_post_type' => 'product',
                'severity'         => 'warning',
                'validations'      => array(
                    array(
                        'field_key' => 'field_6a05f9e8420ea',
                        'type'      => 'min_length',
                        'min'       => '',
                    ),
                ),
            )),
            array(
                array('key' => 'field_6a05f9e8420ea', 'name' => 'pr_page_id', 'label' => 'PowerReviews Page ID', 'type' => 'text'),
            ),
            array('type' => 'error', 'message' => 'We could not save this rule. Minimum length is not configured.')
        );

        $this->assertStringContainsString('Updated draft name', $html);
        $this->assertStringContainsString('<strong>Warning:</strong>', $html);
        $this->assertStringContainsString('class="contentguard-notice-label"', $html);
        $this->assertStringContainsString('class="contentguard-notice-message"', $html);
        $this->assertStringContainsString('We could not save this rule.', $html);
        $this->assertStringContainsString('id="contentguard-rule-notice"', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('value="7"', $html);
        $this->assertThenParam($html, 0, 'min', true);
    }

    public function testRequiredThenHidesUnrelatedConfiguration(): void
    {
        $html = $this->renderValidationEditor('required', array('min' => '50', 'max' => '10', 'values' => 'a, b'));

        $this->assertThenParam($html, 0, 'min', false);
        $this->assertThenParam($html, 0, 'max', false);
        $this->assertThenParam($html, 0, 'values', false);
        $this->assertStringNotContainsString('name="validations[0][min]" value="50"', $html);
    }

    public function testMinimumLengthShowsOnlyItsConfiguration(): void
    {
        $html = $this->renderValidationEditor('min_length', array('min' => '50', 'max' => '10', 'values' => 'a'));

        $this->assertThenParam($html, 0, 'min', true);
        $this->assertThenParam($html, 0, 'max', false);
        $this->assertThenParam($html, 0, 'values', false);
        $this->assertStringContainsString('name="validations[0][min]" value="50"', $html);
    }

    public function testMaximumLengthShowsOnlyItsConfiguration(): void
    {
        $html = $this->renderValidationEditor('max_length', array('min' => '50', 'max' => '10', 'values' => 'a'));

        $this->assertThenParam($html, 0, 'min', false);
        $this->assertThenParam($html, 0, 'max', true);
        $this->assertThenParam($html, 0, 'values', false);
        $this->assertStringContainsString('name="validations[0][max]" value="10"', $html);
    }

    public function testAllowedValuesShowsOnlyItsConfiguration(): void
    {
        $html = $this->renderValidationEditor('allowed_values', array('min' => '50', 'max' => '10', 'values' => 'sauce, rub'));

        $this->assertThenParam($html, 0, 'min', false);
        $this->assertThenParam($html, 0, 'max', false);
        $this->assertThenParam($html, 0, 'values', true);
        $this->assertStringContainsString('name="validations[0][values]" value="sauce, rub"', $html);
    }

    public function testMultipleValidatorsShowOnlyRelevantConfiguration(): void
    {
        $html = $this->renderEditor(
            RuleEditorState::fromSubmitted(array(
                'name'             => 'Mixed THEN',
                'target_post_type' => 'product',
                'validations'      => array(
                    array(
                        'field_key' => 'field_ingredients',
                        'type'      => 'required',
                        'min'       => '50',
                    ),
                    array(
                        'field_key' => 'field_ingredients',
                        'type'      => 'min_length',
                        'min'       => '12',
                        'max'       => '99',
                    ),
                ),
            )),
            array(
                array('key' => 'field_ingredients', 'name' => 'ingredients', 'label' => 'Ingredients', 'type' => 'textarea'),
            )
        );

        $this->assertThenParam($html, 0, 'min', false);
        $this->assertThenParam($html, 0, 'max', false);
        $this->assertThenParam($html, 1, 'min', true);
        $this->assertThenParam($html, 1, 'max', false);
        $this->assertStringContainsString('name="validations[1][min]" value="12"', $html);
        $this->assertStringNotContainsString('name="validations[0][min]" value="50"', $html);
    }

    public function testNumberFieldsOfferNumericOperatorsAndTextFieldsDoNot(): void
    {
        $html = $this->renderEditor(
            RuleEditorState::fromSubmitted(array(
                'name'             => 'Cook Time rule',
                'target_post_type' => 'recipe',
                'conditions'       => array(
                    array(
                        'field_key' => 'field_cook_time',
                        'operator'  => 'greater_than',
                        'operand'   => '30',
                    ),
                    array(
                        'field_key' => 'field_ingredients',
                        'operator'  => 'equals',
                        'operand'   => 'salt',
                    ),
                ),
                'validations' => array(
                    array(
                        'field_key' => 'field_ingredients',
                        'type'      => 'required',
                    ),
                ),
            )),
            array(
                array('key' => 'field_cook_time', 'name' => 'cook_time', 'label' => 'Cook Time', 'type' => 'number'),
                array('key' => 'field_ingredients', 'name' => 'ingredients', 'label' => 'Ingredients', 'type' => 'textarea'),
            )
        );

        $this->assertStringContainsString('value="greater_than"', $html);
        $this->assertStringContainsString('is greater than', $html);
        $this->assertStringContainsString('When Cook Time is greater than 30 and Ingredients is salt, Ingredients is required.', $html);
        $this->assertStringContainsString('type="number"', $html);
        $this->assertMatchesRegularExpression(
            '/name="conditions\[1\]\[operator\]"[^>]*>[\s\S]*?<\/select>/',
            $html
        );
        if (preg_match('/name="conditions\[1\]\[operator\]"[^>]*>([\s\S]*?)<\/select>/', $html, $match) !== 1) {
            $this->fail('Text field operator select missing');
        }
        $this->assertStringNotContainsString('greater_than', $match[1]);
        $this->assertStringContainsString('value="equals"', $match[1]);
        $this->assertStringContainsString('value="is_empty"', $match[1]);
    }

    public function testCloneFieldsAreSelectableAndDistinguishableAndRespectWhenRules(): void
    {
        $rule = RuleFactory::rule(array(
            'id'          => 12,
            'name'        => 'Shared title',
            'postType'    => 'page',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => \ContentGuard\Tests\Support\AcfCloneFixtures::cloneATitleRef(),
                )),
            ),
        ));

        $editor = RuleEditorState::fromRule($rule);
        $html   = $this->renderEditor(
            new RuleEditorState(
                $editor->id,
                $editor->name,
                $editor->postType,
                $editor->severity,
                $editor->status,
                $editor->message,
                array(
                    array(
                        'id'        => '',
                        'field_key' => '',
                        'operator'  => '',
                        'operand'   => '',
                    ),
                ),
                $editor->validations
            ),
            array(
                array(
                    'key'           => 'field_title',
                    'name'          => 'title',
                    'label'         => 'Title',
                    'type'          => 'text',
                ),
                array(
                    'key'           => 'field_title',
                    'name'          => 'shared_a_title',
                    'label'         => 'Title',
                    'type'          => 'text',
                    'path'          => array('field_clone_a', 'field_clone_a_field_title'),
                    'container'     => 'clone',
                    'breadcrumb'    => 'Shared Content → Title',
                    'group_label'   => 'Shared Content',
                    'clone'         => 'field_clone_a',
                    'resolution_id' => 'field_clone_a_field_title',
                ),
                array(
                    'key'           => 'field_title',
                    'name'          => 'title',
                    'label'         => 'Title',
                    'type'          => 'text',
                    'path'          => array('field_clone_b', 'field_title'),
                    'container'     => 'clone',
                    'breadcrumb'    => 'Hero Clone → Title',
                    'group_label'   => 'Hero Clone',
                    'clone'         => 'field_clone_b',
                    'resolution_id' => 'field_clone_b_field_title',
                ),
                array(
                    'key'           => 'field_title',
                    'name'          => 'row_shared_title',
                    'label'         => 'Title',
                    'type'          => 'text',
                    'path'          => array('field_item_list', 'field_clone_rep', 'field_clone_rep_field_title'),
                    'container'     => 'repeater',
                    'breadcrumb'    => 'Item List → Row Shared → Title',
                    'group_label'   => 'Item List → Row Shared',
                    'clone'         => 'field_clone_rep',
                    'resolution_id' => 'field_clone_rep_field_title',
                ),
            )
        );

        $this->assertSame(
            'field_clone_a_field_title',
            RuleEditorState::fromRule($rule)->validations[0]['field_key']
        );
        $this->assertStringContainsString('value="field_clone_a_field_title"', $html);
        $this->assertStringContainsString('value="field_clone_b_field_title"', $html);
        $this->assertStringContainsString('Shared Content → Title', $html);
        $this->assertStringContainsString('Hero Clone → Title', $html);
        $this->assertStringNotContainsString('field_clone_a"', $html);

        if (preg_match('/name="conditions\[0\]\[field_key\]"[^>]*>([\s\S]*?)<\/select>/', $html, $match) !== 1) {
            $this->fail('WHEN field select missing');
        }
        $this->assertStringContainsString('field_clone_a_field_title', $match[1]);
        $this->assertStringContainsString('field_clone_b_field_title', $match[1]);
        $this->assertStringNotContainsString('field_clone_rep_field_title', $match[1]);
        $this->assertStringContainsString('Shared Content → Title', $match[1]);
    }

    /**
     * @param array<string, string> $params
     */
    private function renderValidationEditor(string $type, array $params): string
    {
        return $this->renderEditor(
            RuleEditorState::fromSubmitted(array(
                'name'             => 'THEN config',
                'target_post_type' => 'product',
                'validations'      => array(
                    array(
                        'field_key' => 'field_ingredients',
                        'type'      => $type,
                        'min'       => $params['min'] ?? '',
                        'max'       => $params['max'] ?? '',
                        'values'    => $params['values'] ?? '',
                    ),
                ),
            )),
            array(
                array('key' => 'field_ingredients', 'name' => 'ingredients', 'label' => 'Ingredients', 'type' => 'textarea'),
            )
        );
    }

    private function assertThenParam(string $html, int $index, string $kind, bool $visible): void
    {
        $name = 'validations[' . $index . '][' . $kind . ']';
        $this->assertSame(1, preg_match(
            '/<span class="contentguard-param-group contentguard-param-group--' . preg_quote($kind, '/') . '"([^>]*)>\s*<label[^>]*>[^<]*<\/label>\s*<input[^>]*name="' . preg_quote($name, '/') . '"([^>]*)>/',
            $html,
            $match
        ), $name . ' group missing');

        $groupAttrs = $match[1];
        $inputAttrs = $match[2];
        if ($visible) {
            $this->assertStringNotContainsString('hidden', $groupAttrs);
            $this->assertStringNotContainsString('disabled', $inputAttrs);
        } else {
            $this->assertStringContainsString('hidden', $groupAttrs);
            $this->assertStringContainsString('disabled', $inputAttrs);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array{type: string, message: string}|null $notice
     */
    private function renderEditor(RuleEditorState $editor, array $fields, ?array $notice = null): string
    {
        require_once dirname(__DIR__, 2) . '/Support/wordpress-admin-functions.php';

        $postTypes = array('product' => 'Product');
        $view      = CONTENTGUARD_DIR . 'admin/views/rule-edit.php';
        ob_start();
        require $view;

        return (string) ob_get_clean();
    }
}
