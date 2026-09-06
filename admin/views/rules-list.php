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
 */

defined('ABSPATH') || exit;

use ContentGuard\Admin\AdminView;
use ContentGuard\Admin\AuditPage;
use ContentGuard\Admin\RulesController;
use ContentGuard\Admin\RulesPage;
use ContentGuard\Application\AdminPresentation;
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Application\StatusPresentation;
use ContentGuard\Domain\RuleStatus;

$newUrl         = admin_url('admin.php?page=' . RulesPage::SLUG . '&action=new');
$postTypeLabels = isset($postTypeLabels) && is_array($postTypeLabels) ? $postTypeLabels : array();
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
        <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div>
    <?php endif; ?>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php echo esc_html__('Rule', 'contentguard'); ?></th>
                <th><?php echo esc_html__('Post type', 'contentguard'); ?></th>
                <th><?php echo esc_html__('Severity', 'contentguard'); ?></th>
                <th><?php echo esc_html__('Status', 'contentguard'); ?></th>
                <th><?php echo esc_html__('WHEN', 'contentguard'); ?></th>
                <th><?php echo esc_html__('THEN', 'contentguard'); ?></th>
                <th><?php echo esc_html__('Latest audit', 'contentguard'); ?></th>
                <th><?php echo esc_html__('Actions', 'contentguard'); ?></th>
            </tr>
        </thead>
        <tbody>
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
                ?>
                <tr>
                    <td>
                        <strong><a href="<?php echo esc_url($edit); ?>"><?php echo esc_html($rule->name); ?></a></strong>
                    </td>
                    <td><?php echo esc_html(AdminPresentation::postTypeLabel($rule->postType, $postTypeLabels)); ?></td>
                    <td><?php AdminView::partial('status-pill', array('status' => StatusPresentation::fromSeverity($rule->severity))); ?></td>
                    <td><?php AdminView::partial('status-pill', array('status' => StatusPresentation::fromRuleStatus($rule->status))); ?></td>
                    <td><?php echo esc_html($summary['conditions']); ?></td>
                    <td><?php echo esc_html($summary['validations']); ?></td>
                    <td>
                        <?php echo esc_html($impactLabel); ?>
                        <?php if ($latestComplete !== null && $impact !== null && $impact->postCount > 0) : ?>
                            <br>
                            <a href="<?php echo esc_url($affectedUrl); ?>"><?php echo esc_html__('View affected content', 'contentguard'); ?></a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url($edit); ?>"><?php echo esc_html__('Edit', 'contentguard'); ?></a>
                        |
                        <a href="<?php echo esc_url($statusUrl); ?>">
                            <?php echo esc_html($rule->status === RuleStatus::Active ? __('Deactivate', 'contentguard') : __('Activate', 'contentguard')); ?>
                        </a>
                        |
                        <a href="<?php echo esc_url($deleteUrl); ?>" class="submitdelete" onclick="return confirm('<?php echo esc_js(__('Delete this rule? Audit findings for this rule will be kept.', 'contentguard')); ?>');">
                            <?php echo esc_html__('Delete', 'contentguard'); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rules === array()) : ?>
                <tr>
                    <td colspan="8"><?php echo esc_html__('No rules yet. Create a rule to start validating content.', 'contentguard'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
