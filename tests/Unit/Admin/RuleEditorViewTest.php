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
        $this->assertStringContainsString('We could not add this rule.', $html);
        $this->assertStringContainsString('value="warning"', $html);
        $this->assertStringContainsString('value="inactive"', $html);
        $this->assertStringContainsString('When Show &quot;New&quot; Tag is No, PowerReviews Page ID is required.', $html);
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
