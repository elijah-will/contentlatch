<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Admin;

use ContentLatch\Application\Audit\AuditRuleImpact;
use ContentLatch\Application\Audit\AuditRun;
use ContentLatch\Application\Audit\AuditRunStatus;
use ContentLatch\Application\RulePresentation;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Domain\RuleStatus;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RulesListViewTest extends TestCase
{
    public function testEmptyStateUsesTheSharedEmptyComponent(): void
    {
        $html = $this->renderList(array());

        $this->assertStringNotContainsString('widefat', $html);
        $this->assertStringContainsString('contentlatch-empty', $html);
        $this->assertStringContainsString('No rules yet', $html);
        $this->assertStringContainsString('Create a rule to start validating content.', $html);
        $this->assertStringContainsString('Add Rule', $html);
        $this->assertStringContainsString('admin.php?page=contentlatch&amp;action=new', $html);
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
        $this->assertStringContainsString('contentlatch-rule-list', $html);
        $this->assertStringContainsString('<article', $html);
        $this->assertStringContainsString('<h3 class="contentlatch-rule-item__title"', $html);
        $this->assertStringContainsString('New Products need Page ID', $html);
        $this->assertStringContainsString('Applies to', $html);
        $this->assertStringContainsString('Product', $html);
        $this->assertStringContainsString('contentlatch-status--success', $html);
        $this->assertStringContainsString('Active', $html);
        $this->assertStringContainsString('contentlatch-status--danger', $html);
        $this->assertStringContainsString('Blocking', $html);
        $this->assertSame('Show "New" Tag is Yes', $summary['conditions']);
        $this->assertStringContainsString('Show &quot;New&quot; Tag is Yes', $html);
        $this->assertStringContainsString('PowerReviews Page ID is required', $html);
        $this->assertStringContainsString('<dt>WHEN</dt>', $html);
        $this->assertStringContainsString('<dt>THEN</dt>', $html);
        $this->assertStringContainsString('No completed audit yet', $html);
        $this->assertStringNotContainsString('View affected content', $html);
        $this->assertStringContainsString('contentlatch-button--link', $html);
        $this->assertStringContainsString('Applies to: Product', $html);
        $this->assertStringContainsString('aria-label="Edit: New Products need Page ID"', $html);
        $this->assertStringContainsString('aria-label="Deactivate: New Products need Page ID"', $html);
        $this->assertStringContainsString('aria-label="Delete: New Products need Page ID"', $html);
        $this->assertStringContainsString('admin.php?page=contentlatch&amp;rule=7', $html);
        $this->assertStringContainsString('admin-post.php?action=contentlatch_rule_status', $html);
        $this->assertStringContainsString('status=inactive', $html);
        $this->assertStringContainsString('admin-post.php?action=contentlatch_delete_rule', $html);
        $this->assertStringContainsString('_wpnonce=testnonce', $html);
        $this->assertStringContainsString('submitdelete contentlatch-button--destructive contentlatch-delete-rule', $html);
        $this->assertStringNotContainsString('onclick=', $html);
        $item = substr($html, (int) strpos($html, 'contentlatch-rule-item'));
        $this->assertLessThan(
            (int) strpos($item, 'submitdelete'),
            (int) strpos($item, 'aria-label="Edit: New Products need Page ID"')
        );
        $this->assertLessThan(
            (int) strpos($item, 'submitdelete'),
            (int) strpos($item, 'aria-label="Deactivate: New Products need Page ID"')
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
        $this->assertStringContainsString('contentlatch-rule-item', $html);
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
        $this->assertStringContainsString('contentlatch-status--success', $html);
        $this->assertStringContainsString('contentlatch-status--neutral', $html);
        $this->assertStringContainsString('Applies to', $html);
        $this->assertStringContainsString('Product', $html);
    }

    public function testListGroupsRulesIntoFourSectionsWithCounts(): void
    {
        $html = $this->renderList(
            array(
                RuleFactory::rule(array(
                    'id'       => 1,
                    'name'     => 'Inactive warning rule',
                    'status'   => RuleStatus::Inactive,
                    'severity' => RuleSeverity::Warning,
                )),
                RuleFactory::rule(array(
                    'id'       => 2,
                    'name'     => 'Active warning rule',
                    'status'   => RuleStatus::Active,
                    'severity' => RuleSeverity::Warning,
                )),
                RuleFactory::rule(array(
                    'id'       => 3,
                    'name'     => 'Inactive blocking rule',
                    'status'   => RuleStatus::Inactive,
                    'severity' => RuleSeverity::Fail,
                )),
                RuleFactory::rule(array(
                    'id'       => 4,
                    'name'     => 'Active blocking first',
                    'status'   => RuleStatus::Active,
                    'severity' => RuleSeverity::Fail,
                )),
                RuleFactory::rule(array(
                    'id'       => 5,
                    'name'     => 'Active blocking second',
                    'status'   => RuleStatus::Active,
                    'severity' => RuleSeverity::Fail,
                )),
            ),
            array(),
            array('product' => 'Product')
        );

        $activeBlocking  = strpos($html, 'id="contentlatch-rule-group-active-blocking"');
        $activeWarning   = strpos($html, 'id="contentlatch-rule-group-active-warning"');
        $inactiveBlocking = strpos($html, 'id="contentlatch-rule-group-inactive-blocking"');
        $inactiveWarning = strpos($html, 'id="contentlatch-rule-group-inactive-warning"');

        $this->assertNotFalse($activeBlocking);
        $this->assertNotFalse($activeWarning);
        $this->assertNotFalse($inactiveBlocking);
        $this->assertNotFalse($inactiveWarning);
        $this->assertLessThan($activeWarning, $activeBlocking);
        $this->assertLessThan($inactiveBlocking, $activeWarning);
        $this->assertLessThan($inactiveWarning, $inactiveBlocking);

        $this->assertLessThan(strpos($html, 'Active blocking first'), $activeBlocking);
        $this->assertLessThan(strpos($html, 'Active warning rule'), $activeWarning);
        $this->assertLessThan(strpos($html, 'Inactive blocking rule'), $inactiveBlocking);
        $this->assertLessThan(strpos($html, 'Inactive warning rule'), $inactiveWarning);

        $this->assertStringContainsString('Active Blocking', $html);
        $this->assertStringContainsString('Active Warning', $html);
        $this->assertStringContainsString('Inactive Blocking', $html);
        $this->assertStringContainsString('Inactive Warning', $html);
        $this->assertStringContainsString('2 rules', $html);
        $this->assertStringContainsString('1 rule', $html);
        $this->assertStringNotContainsString('0 rules', $html);

        $this->assertMatchesRegularExpression('/<details class="contentlatch-rule-group__details" open>/', $html);
        $this->assertStringContainsString('aria-label="Edit: Active blocking first"', $html);
        $this->assertStringContainsString('aria-label="Activate: Inactive warning rule"', $html);
        $this->assertStringContainsString('admin-post.php?action=contentlatch_delete_rule', $html);
    }

    public function testEmptyGroupsAreOmittedAndWarningOnlyListHidesBlocking(): void
    {
        $html = $this->renderList(
            array(
                RuleFactory::rule(array(
                    'id'       => 8,
                    'name'     => 'Live warning',
                    'status'   => RuleStatus::Active,
                    'severity' => RuleSeverity::Warning,
                )),
                RuleFactory::rule(array(
                    'id'       => 9,
                    'name'     => 'Off warning',
                    'status'   => RuleStatus::Inactive,
                    'severity' => RuleSeverity::Warning,
                )),
            ),
            array(),
            array('product' => 'Product')
        );

        $this->assertStringContainsString('Active Warning', $html);
        $this->assertStringContainsString('Inactive Warning', $html);
        $this->assertStringContainsString('Live warning', $html);
        $this->assertStringContainsString('Off warning', $html);
        $this->assertStringNotContainsString('Active Blocking', $html);
        $this->assertStringNotContainsString('Inactive Blocking', $html);
        $this->assertStringNotContainsString('contentlatch-rule-group-active-blocking', $html);
        $this->assertStringNotContainsString('0 rules', $html);
    }

    public function testActiveGroupsStartExpandedAndInactiveGroupsStartCollapsed(): void
    {
        $html = $this->renderList(
            array(
                RuleFactory::rule(array(
                    'id'       => 11,
                    'name'     => 'On blocking',
                    'status'   => RuleStatus::Active,
                    'severity' => RuleSeverity::Fail,
                )),
                RuleFactory::rule(array(
                    'id'       => 12,
                    'name'     => 'Off blocking',
                    'status'   => RuleStatus::Inactive,
                    'severity' => RuleSeverity::Fail,
                )),
            ),
            array(),
            array('product' => 'Product')
        );

        $this->assertMatchesRegularExpression(
            '/<section class="contentlatch-rule-group"[^>]*aria-labelledby="contentlatch-rule-group-active-blocking">\s*<details class="contentlatch-rule-group__details" open>/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<section class="contentlatch-rule-group"[^>]*aria-labelledby="contentlatch-rule-group-inactive-blocking">\s*<details class="contentlatch-rule-group__details">/',
            $html
        );
        $this->assertStringContainsString('<summary class="contentlatch-rule-group__summary">', $html);
        $this->assertStringContainsString('<h2 class="contentlatch-rule-group__title"', $html);

        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/css/rules.css');
        $this->assertStringContainsString('.contentlatch-rule-group__summary::before', $css);
        $this->assertStringContainsString(
            '.contentlatch-rule-group__details[open] > .contentlatch-rule-group__summary::before',
            $css
        );
        $this->assertStringContainsString('transform: rotate(90deg)', $css);
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
        $this->assertStringContainsString('admin.php?page=contentlatch-audit&amp;rule=9', $html);
    }

    /**
     * @param \ContentLatch\Domain\Rule[] $rules
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

        $view = CONTENTLATCH_DIR . 'admin/views/rules-list.php';
        ob_start();
        require $view;

        return (string) ob_get_clean();
    }
}
