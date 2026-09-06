<?php
/**
 * ContentGuard rules list.
 *
 * @package ContentGuard
 *
 * @var \ContentGuard\Domain\Rule[] $rules
 * @var array<string, array{conditions: string, validations: string}> $summaries
 * @var \ContentGuard\Application\Audit\AuditRun|null $latestComplete
 * @var array<string, \ContentGuard\Application\Audit\AuditRuleImpact> $impacts
 * @var array<string, string> $postTypeLabels
 * @var array{type: string, message: string}|null $notice
 * @var bool $allInactive
 */

defined('ABSPATH') || exit;

use ContentGuard\Admin\AdminView;
use ContentGuard\Admin\AuditPage;
use ContentGuard\Admin\RulesController;
use ContentGuard\Admin\RulesPage;
use ContentGuard\Application\AdminNotice;
use ContentGuard\Application\AdminPresentation;
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Domain\RuleStatus;

$newUrl         = admin_url('admin.php?page=' . RulesPage::SLUG . '&action=new');
$postTypeLabels = isset($postTypeLabels) && is_array($postTypeLabels) ? $postTypeLabels : array();
$rules          = isset($rules) && is_array($rules) ? $rules : array();
$summaries      = isset($summaries) && is_array($summaries) ? $summaries : array();
$impacts        = isset($impacts) && is_array($impacts) ? $impacts : array();
$allInactive    = isset($allInactive) ? (bool) $allInactive : RulesPage::allRulesInactive($rules);
$rules          = RulesPage::sortForList($rules);
?>
<div class="wrap contentguard" id="contentguard-rules">
    <?php
    AdminView::partial(
        'page-header',
        array(
            'title'       => __('Rules', 'contentguard'),
            'description' => __('Define and manage the rules your content must follow.', 'contentguard'),
            'primary'     => array(
                'label' => __('Add Rule', 'contentguard'),
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
                'heading' => __('No rules yet', 'contentguard'),
                'text'    => __('Create a rule to start validating content.', 'contentguard'),
                'primary' => array(
                    'label' => __('Add Rule', 'contentguard'),
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

        <div class="contentguard-rule-list">
            <?php foreach ($rules as $rule) : ?>
                <?php
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
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
