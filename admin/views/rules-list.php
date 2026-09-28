<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- These files are include/extract template scopes; assignments are template locals, not plugin globals.
/**
 * ContentLatch rules list.
 *
 * @package ContentLatch
 *
 * @var \ContentLatch\Domain\Rule[] $rules
 * @var array<string, array{conditions: string, validations: string}> $summaries
 * @var \ContentLatch\Application\Audit\AuditRun|null $latestComplete
 * @var array<string, \ContentLatch\Application\Audit\AuditRuleImpact> $impacts
 * @var array<string, string> $postTypeLabels
 * @var array{type: string, message: string}|null $notice
 * @var bool $allInactive
 */

defined('ABSPATH') || exit;

use ContentLatch\Admin\AdminView;
use ContentLatch\Admin\AuditPage;
use ContentLatch\Admin\RulesController;
use ContentLatch\Admin\RulesPage;
use ContentLatch\Application\AdminNotice;
use ContentLatch\Application\AdminPresentation;
use ContentLatch\Application\RuleCommandService;
use ContentLatch\Domain\RuleStatus;

$newUrl         = admin_url('admin.php?page=' . RulesPage::SLUG . '&action=new');
$postTypeLabels = isset($postTypeLabels) && is_array($postTypeLabels) ? $postTypeLabels : array();
$rules          = isset($rules) && is_array($rules) ? $rules : array();
$summaries      = isset($summaries) && is_array($summaries) ? $summaries : array();
$impacts        = isset($impacts) && is_array($impacts) ? $impacts : array();
$allInactive    = isset($allInactive) ? (bool) $allInactive : RulesPage::allRulesInactive($rules);
$rules          = RulesPage::sortForList($rules);
?>
<div class="wrap contentlatch" id="contentlatch-rules">
    <?php
    AdminView::partial(
        'page-header',
        array(
            'title'       => __('Rules', 'contentlatch'),
            'description' => __('Define and manage the rules your content must follow.', 'contentlatch'),
            'primary'     => array(
                'label' => __('Add Rule', 'contentlatch'),
                'href'  => $newUrl,
            ),
        )
    );
    ?>

    <?php if ($notice !== null) : ?>
        <div class="<?php echo esc_attr(AdminNotice::cssClass($notice['type'])); ?>"><p><?php echo esc_html($notice['message']); ?></p></div>
    <?php endif; ?>

    <?php if ($rules === array()) : ?>
        <?php
        AdminView::partial(
            'empty-state',
            array(
                'heading' => __('No rules yet', 'contentlatch'),
                'text'    => __('Create a rule to start validating content.', 'contentlatch'),
                'primary' => array(
                    'label' => __('Add Rule', 'contentlatch'),
                    'href'  => $newUrl,
                ),
            )
        );
        ?>
    <?php else : ?>
        <?php if ($allInactive) : ?>
            <div class="notice notice-warning">
                <p><?php echo esc_html(RulesPage::allInactiveNotice()); ?></p>
            </div>
        <?php endif; ?>

        <div class="contentlatch-rule-list">
            <?php
            $renderRule = static function ($rule) use ($summaries, $impacts, $postTypeLabels, $latestComplete): void {
                if (!$rule instanceof \ContentLatch\Domain\Rule) {
                    return;
                }
                $edit = admin_url('admin.php?page=' . RulesPage::SLUG . '&rule=' . (int) $rule->id);
                $next = $rule->status === RuleStatus::Active ? RuleStatus::Inactive->value : RuleStatus::Active->value;
                $statusUrl = wp_nonce_url(
                    admin_url(
                        'admin-post.php?action=' . RulesController::ACTION_STATUS
                        . '&rule_id=' . (int) $rule->id
                        . '&status=' . $next
                    ),
                    RuleCommandService::NONCE_ACTION
                );
                $deleteUrl = wp_nonce_url(
                    admin_url(
                        'admin-post.php?action=' . RulesController::ACTION_DELETE
                        . '&rule_id=' . (int) $rule->id
                    ),
                    RuleCommandService::NONCE_ACTION
                );
                $summary = $summaries[(string) $rule->id] ?? array('conditions' => '', 'validations' => '');
                $impact  = $impacts[(string) $rule->id] ?? null;
                $impactLabel = RulesPage::ruleImpactLabel($latestComplete ?? null, $rule, $impact);
                $affectedUrl = admin_url(
                    'admin.php?page=' . AuditPage::SLUG . '&rule=' . rawurlencode((string) $rule->id)
                );
                AdminView::partial(
                    'rule-list-item',
                    array(
                        'rule'          => $rule,
                        'summary'       => $summary,
                        'postTypeLabel' => AdminPresentation::postTypeLabel($rule->postType, $postTypeLabels),
                        'impactLabel'   => $impactLabel,
                        'editUrl'       => $edit,
                        'statusUrl'     => $statusUrl,
                        'deleteUrl'     => $deleteUrl,
                        'affectedUrl'   => $affectedUrl,
                        'showAffected'  => $latestComplete !== null && $impact !== null && $impact->postCount > 0,
                    )
                );
            };

            foreach (RulesPage::groupsForList($rules) as $group) :
                $groupId    = (string) ($group['id'] ?? '');
                $groupTitle = (string) ($group['title'] ?? '');
                $groupRules = isset($group['rules']) && is_array($group['rules']) ? $group['rules'] : array();
                $count      = count($groupRules);
                $headingId  = 'contentlatch-rule-group-' . $groupId;
                $countLabel = sprintf(
                    /* translators: %d: Number of rules in this group. */
                    _n('%d rule', '%d rules', $count, 'contentlatch'),
                    $count
                );
                ?>
                <section class="contentlatch-rule-group" aria-labelledby="<?php echo esc_attr($headingId); ?>">
                    <details class="contentlatch-rule-group__details"<?php echo !empty($group['open']) ? ' open' : ''; ?>>
                        <summary class="contentlatch-rule-group__summary">
                            <h2 class="contentlatch-rule-group__title" id="<?php echo esc_attr($headingId); ?>">
                                <?php echo esc_html($groupTitle); ?>
                            </h2>
                            <span class="contentlatch-rule-group__count"><?php echo esc_html($countLabel); ?></span>
                        </summary>
                        <div class="contentlatch-rule-group__items">
                            <?php foreach ($groupRules as $rule) : ?>
                                <?php $renderRule($rule); ?>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
