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
