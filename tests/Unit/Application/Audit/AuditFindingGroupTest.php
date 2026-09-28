<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application\Audit;

use ContentLatch\Application\Audit\AuditFinding;
use ContentLatch\Application\Audit\AuditFindingGroup;
use ContentLatch\Domain\RuleSeverity;
use PHPUnit\Framework\TestCase;

final class AuditFindingGroupTest extends TestCase
{
    public function testSingleFindingStaysAlone(): void
    {
        $finding = $this->finding(1, 'This field is required.', 'v1');
        $groups  = AuditFindingGroup::group(array($finding));

        $this->assertCount(1, $groups);
        $this->assertFalse($groups[0]->isMultiple());
        $this->assertSame(1, $groups[0]->count());
        $this->assertSame($finding, $groups[0]->first());
        $this->assertSame($finding->validationId, $groups[0]->first()->validationId);
        $this->assertSame($finding->id, $groups[0]->findings[0]->id);
    }

    public function testDifferentFieldsOnTheSameContentAndRuleAreGrouped(): void
    {
        $prep  = $this->finding(1, 'This field is required.', 'v1', array('fieldKey' => 'field_prep'));
        $total = $this->finding(2, 'This field is required.', 'v2', array('fieldKey' => 'field_total'));

        $groups = AuditFindingGroup::group(array($prep, $total));

        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]->isMultiple());
        $this->assertSame(array($prep, $total), $groups[0]->findings);
        $this->assertSame(array('v1', 'v2'), array_map(static fn (AuditFinding $finding): string => $finding->validationId, $groups[0]->findings));
        $this->assertSame(
            array('field_prep', 'field_total'),
            array_keys($groups[0]->findingsByField())
        );
        $this->assertSame(
            array(
                array(
                    'label'    => 'Prep Time',
                    'fieldKey' => 'field_prep',
                    'messages' => array('This field is required.'),
                ),
                array(
                    'label'    => 'Total Time',
                    'fieldKey' => 'field_total',
                    'messages' => array('This field is required.'),
                ),
            ),
            $groups[0]->fieldIssues(array(
                '15:field_prep'  => 'Prep Time',
                '15:field_total' => 'Total Time',
            ))
        );
    }

    public function testMultipleValidationsOnTheSameFieldStaySeparateRows(): void
    {
        $required = $this->finding(1, 'Page ID is required.', 'v1');
        $length   = $this->finding(2, 'Page ID must be at least 10 characters.', 'v2', array(
            'code' => 'min_length',
        ));

        $group = AuditFindingGroup::group(array($required, $length))[0];

        $this->assertSame(array($required, $length), $group->findings);
        $this->assertSame('v1', $group->findings[0]->validationId);
        $this->assertSame('v2', $group->findings[1]->validationId);
        $this->assertSame(
            array(
                array(
                    'label'    => 'Page ID',
                    'fieldKey' => 'field_page',
                    'messages' => array('Page ID is required.', 'Page ID must be at least 10 characters.'),
                ),
            ),
            $group->fieldIssues(array('15:field_page' => 'Page ID'))
        );
    }

    public function testMultipleFieldsAndValidationsStayUnderstandable(): void
    {
        $findings = array(
            $this->finding(1, 'Prep Time is required.', 'v1', array('fieldKey' => 'field_prep')),
            $this->finding(2, 'Must be at least 5 characters.', 'v2', array('fieldKey' => 'field_prep')),
            $this->finding(3, 'Total Time is required.', 'v3', array('fieldKey' => 'field_total')),
        );

        $group = AuditFindingGroup::group($findings)[0];

        $this->assertSame(3, $group->count());
        $this->assertSame(
            array(
                array(
                    'label'    => 'Prep Time',
                    'fieldKey' => 'field_prep',
                    'messages' => array('Prep Time is required.', 'Must be at least 5 characters.'),
                ),
                array(
                    'label'    => 'Total Time',
                    'fieldKey' => 'field_total',
                    'messages' => array('Total Time is required.'),
                ),
            ),
            $group->fieldIssues(array(
                '15:field_prep'  => 'Prep Time',
                '15:field_total' => 'Total Time',
            ))
        );
    }

    public function testAllWarningAndAllBlockingGroups(): void
    {
        $warnings = AuditFindingGroup::group(array(
            $this->finding(1, 'Thin prep.', 'v1', array(
                'fieldKey' => 'field_prep',
                'severity' => RuleSeverity::Warning,
            )),
            $this->finding(2, 'Thin total.', 'v2', array(
                'fieldKey' => 'field_total',
                'severity' => RuleSeverity::Warning,
            )),
        ))[0];

        $this->assertSame(array('warning'), $warnings->statuses());
        $this->assertSame(0, $warnings->blockingCount());
        $this->assertSame(2, $warnings->warningCount());

        $blocking = AuditFindingGroup::group(array(
            $this->finding(3, 'Required prep.', 'v1', array('fieldKey' => 'field_prep')),
            $this->finding(4, 'Required total.', 'v2', array('fieldKey' => 'field_total')),
        ))[0];

        $this->assertSame(array('blocking'), $blocking->statuses());
        $this->assertSame(2, $blocking->blockingCount());
        $this->assertSame(0, $blocking->warningCount());
    }

    public function testMixedSeverityKeepsBothLabels(): void
    {
        $fail    = $this->finding(1, 'Required.', 'v1', array('fieldKey' => 'field_prep'));
        $warning = $this->finding(2, 'Looks thin.', 'v2', array(
            'fieldKey' => 'field_total',
            'severity' => RuleSeverity::Warning,
        ));

        $group = AuditFindingGroup::group(array($fail, $warning))[0];

        $this->assertSame(array('blocking', 'warning'), $group->statuses());
        $this->assertSame('blocking', $group->primaryStatus());
        $this->assertSame(1, $group->blockingCount());
        $this->assertSame(1, $group->warningCount());
    }

    public function testSeverityFilterShowsOnlyTheDisplayedFindings(): void
    {
        $fail    = $this->finding(1, 'Required.', 'v1', array('fieldKey' => 'field_prep'));
        $warning = $this->finding(2, 'Looks thin.', 'v2', array(
            'fieldKey' => 'field_total',
            'severity' => RuleSeverity::Warning,
        ));

        $filtered = AuditFindingGroup::group(array($fail));

        $this->assertCount(1, $filtered);
        $this->assertSame(array($fail), $filtered[0]->findings);
        $this->assertSame(array('blocking'), $filtered[0]->statuses());
        $this->assertSame(array('Looks thin.'), AuditFindingGroup::group(array($warning))[0]->messages());
        $this->assertNotContains($warning, $filtered[0]->findings);
    }

    public function testDifferentRulesAndPostsStaySeparate(): void
    {
        $groups = AuditFindingGroup::group(array(
            $this->finding(1, 'Prep required.', 'v1', array('fieldKey' => 'field_prep')),
            $this->finding(2, 'Other rule.', 'v1', array('ruleId' => 22, 'fieldKey' => 'field_prep')),
            $this->finding(3, 'Other post.', 'v1', array('postId' => 9, 'fieldKey' => 'field_prep')),
        ));

        $this->assertCount(3, $groups);
        $this->assertSame(15, $groups[0]->ruleId);
        $this->assertSame(22, $groups[1]->ruleId);
        $this->assertSame(9, $groups[2]->postId);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function finding(int $id, string $message, string $validationId, array $overrides = array()): AuditFinding
    {
        return new AuditFinding(
            $id,
            7,
            (int) ($overrides['postId'] ?? 42),
            'recipe',
            $overrides['ruleId'] ?? 15,
            (string) ($overrides['fieldKey'] ?? 'field_page'),
            $validationId,
            (string) ($overrides['code'] ?? 'required'),
            $overrides['severity'] ?? RuleSeverity::Fail,
            $message,
            '2026-01-01 00:00:00'
        );
    }
}
