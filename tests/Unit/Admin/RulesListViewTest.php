<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Application\Audit\AuditRuleImpact;
use ContentGuard\Application\Audit\AuditRun;
use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Application\RulePresentation;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RulesListViewTest extends TestCase
{
    public function testEmptyStateUsesTheSharedEmptyComponent(): void
    {
        $html = $this->renderList(array());

        $this->assertStringNotContainsString('widefat', $html);
        $this->assertStringContainsString('contentguard-empty', $html);
        $this->assertStringContainsString('No rules yet', $html);
        $this->assertStringContainsString('Create a rule to start validating content.', $html);
        $this->assertStringContainsString('Add Rule', $html);
        $this->assertStringContainsString('admin.php?page=contentguard&amp;action=new', $html);
        $this->assertStringNotContainsString('None of these rules are active', $html);
    }

    public function testStackedListShowsNamePillsWhenThenAndHierarchicalActions(): void
    {
        $rule = RuleFactory::rule(
            array(
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
            )
        );
        $summary = array(
            'conditions'  => RulePresentation::conditionsSummary($rule, array('field_683097e0dc6d2' => 'true_false')),
            'validations' => RulePresentation::validationsSummary($rule),
        );

        $html = $this->renderList(
            array($rule),
            array('7' => $summary),
            array('product' => 'Product')
        );

        $this->assertStringNotContainsString('widefat', $html);
        $this->assertStringContainsString('contentguard-rule-list', $html);
        $this->assertStringContainsString('<article', $html);
        $this->assertStringContainsString('<h2 class="contentguard-rule-item__title"', $html);
        $this->assertStringContainsString('New Products need Page ID', $html);
        $this->assertStringContainsString('Applies to', $html);
        $this->assertStringContainsString('Product', $html);
        $this->assertStringContainsString('contentguard-status--success', $html);
        $this->assertStringContainsString('Active', $html);
        $this->assertStringContainsString('contentguard-status--danger', $html);
        $this->assertStringContainsString('Blocking', $html);
        $this->assertSame('Show "New" Tag is Yes', $summary['conditions']);
        $this->assertStringContainsString('Show &quot;New&quot; Tag is Yes', $html);
        $this->assertStringContainsString('PowerReviews Page ID is required', $html);
        $this->assertStringContainsString('<dt>WHEN</dt>', $html);
        $this->assertStringContainsString('<dt>THEN</dt>', $html);
        $this->assertStringContainsString('No completed audit yet', $html);
        $this->assertStringNotContainsString('View affected content', $html);
        $this->assertStringContainsString('class="button button-primary"', $html);
        $this->assertStringContainsString('aria-label="Edit: New Products need Page ID"', $html);
        $this->assertStringContainsString('aria-label="Deactivate: New Products need Page ID"', $html);
        $this->assertStringContainsString('aria-label="Delete: New Products need Page ID"', $html);
        $this->assertStringContainsString('admin.php?page=contentguard&amp;rule=7', $html);
        $this->assertStringContainsString('admin-post.php?action=contentguard_rule_status', $html);
        $this->assertStringContainsString('status=inactive', $html);
        $this->assertStringContainsString('admin-post.php?action=contentguard_delete_rule', $html);
        $this->assertStringContainsString('_wpnonce=testnonce', $html);
        $this->assertStringContainsString('submitdelete contentguard-button--destructive', $html);
        $item = substr($html, (int) strpos($html, 'contentguard-rule-item'));
        $this->assertLessThan(
            (int) strpos($item, 'submitdelete'),
            (int) strpos($item, 'button button-primary')
        );
    }

    public function testAllInactiveNoticeStillListsRules(): void
    {
        $rule = RuleFactory::rule(array(
            'id'     => 3,
            'name'   => 'Inactive page ID',
            'status' => RuleStatus::Inactive,
        ));

        $html = $this->renderList(array($rule), array(), array('product' => 'Product'), true);

        $this->assertStringContainsString('None of these rules are active', $html);
        $this->assertStringContainsString('not currently being enforced', $html);
        $this->assertStringContainsString('Inactive page ID', $html);
        $this->assertStringContainsString('Inactive', $html);
        $this->assertStringContainsString('aria-label="Activate: Inactive page ID"', $html);
        $this->assertStringContainsString('status=active', $html);
        $this->assertStringContainsString('contentguard-rule-item', $html);
    }

    public function testActiveRulesRenderBeforeInactiveRules(): void
    {
        $inactive = RuleFactory::rule(array(
            'id'     => 4,
            'name'   => 'Inactive page ID rule',
            'status' => RuleStatus::Inactive,
        ));
        $active = RuleFactory::rule(array(
            'id'     => 5,
            'name'   => 'Active page ID rule',
            'status' => RuleStatus::Active,
        ));

        $html = $this->renderList(
            array($inactive, $active),
            array(),
            array('product' => 'Product')
        );

        $activePos   = strpos($html, 'Active page ID rule');
        $inactivePos = strpos($html, 'Inactive page ID rule');

        $this->assertNotFalse($activePos);
        $this->assertNotFalse($inactivePos);
        $this->assertLessThan($inactivePos, $activePos);
        $this->assertStringContainsString('contentguard-status--success', $html);
        $this->assertStringContainsString('contentguard-status--neutral', $html);
        $this->assertStringContainsString('Applies to', $html);
        $this->assertStringContainsString('Product', $html);
    }

    public function testViewAffectedContentAppearsOnlyWhenTheLatestAuditHasMatches(): void
    {
        $rule = RuleFactory::rule(array('id' => 9, 'name' => 'Failing products'));
        $run  = new AuditRun(
            4,
            AuditRunStatus::Complete,
            '2026-09-05 00:00:00',
            '2026-09-05 00:05:00',
            '2026-09-05 00:05:00',
            10,
            4,
            3,
            3,
            0,
            10,
            10,
            1,
            array('product'),
            null
        );

        $html = $this->renderList(
            array($rule),
            array(),
            array('product' => 'Product'),
            false,
            $run,
            array('9' => new AuditRuleImpact(9, 3, 2))
        );

        $this->assertStringContainsString('2 content items failing', $html);
        $this->assertStringContainsString('View affected content', $html);
        $this->assertStringContainsString('aria-label="View affected content: Failing products"', $html);
        $this->assertStringContainsString('admin.php?page=contentguard-audit&amp;rule=9', $html);
    }

    /**
     * @param \ContentGuard\Domain\Rule[] $rules
     * @param array<string, array{conditions: string, validations: string}> $summaries
     * @param array<string, string> $postTypeLabels
     * @param array<string, AuditRuleImpact> $impacts
     */
    private function renderList(
        array $rules,
        array $summaries = array(),
        array $postTypeLabels = array(),
        bool $allInactive = false,
        ?AuditRun $latestComplete = null,
        array $impacts = array(),
        ?array $notice = null,
    ): string {
        require_once dirname(__DIR__, 2) . '/Support/wordpress-admin-functions.php';

        $view = CONTENTGUARD_DIR . 'admin/views/rules-list.php';
        ob_start();
        require $view;

        return (string) ob_get_clean();
    }
}
