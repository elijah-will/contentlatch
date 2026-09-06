<?php
/**
 * ContentGuard rule editor.
 *
 * @package ContentGuard
 *
 * @var \ContentGuard\Admin\RuleEditorState $editor
 * @var array<string, string> $postTypes
 * @var array<int, array<string, mixed>> $fields
 * @var array{type: string, message: string}|null $notice
 */

defined('ABSPATH') || exit;

use ContentGuard\Admin\RulesController;
use ContentGuard\Admin\RulesPage;
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;

$isNew       = $editor->isNew();
$conditions  = $editor->conditions;
$validations = $editor->validations;
$fieldKeys  = array();
$fieldTypes = array();
foreach ($fields as $field) {
    $key = (string) ($field['key'] ?? '');
    $fieldKeys[$key] = true;
    $fieldTypes[$key] = (string) ($field['type'] ?? '');
}

$operators = array(
    'equals'       => __('equals', 'contentguard'),
    'not_equals'   => __('does not equal', 'contentguard'),
    'is_empty'     => __('is empty', 'contentguard'),
    'is_not_empty' => __('is not empty', 'contentguard'),
);
$validators = array(
    'required'       => __('required', 'contentguard'),
    'min_length'     => __('Minimum length (characters)', 'contentguard'),
    'max_length'     => __('Maximum length (characters)', 'contentguard'),
    'allowed_values' => __('allowed values', 'contentguard'),
);

/**
 * @param array<int, array<string, mixed>> $fields
 * @param array<string, true> $fieldKeys
 */
$renderFieldOptions = static function (array $fields, array $fieldKeys, string $selected): void {
    echo '<option value="">' . esc_html__('Field', 'contentguard') . '</option>';
    foreach ($fields as $field) {
        $key = (string) ($field['key'] ?? '');
        $type = (string) ($field['type'] ?? '');
        $label = (string) ($field['label'] ?? '') !== ''
            ? (string) $field['label']
            : (string) ($field['name'] ?? $key);
        echo '<option value="' . esc_attr($key) . '" data-type="' . esc_attr($type) . '" ' . selected($selected, $key, false) . '>'
            . esc_html($label)
            . '</option>';
    }
    if ($selected !== '' && !isset($fieldKeys[$selected])) {
        echo '<option value="' . esc_attr($selected) . '" selected>' . esc_html($selected) . '</option>';
    }
};
?>
<div class="wrap" id="contentguard-rule-editor">
    <h1><?php echo esc_html($isNew ? __('Add New Rule', 'contentguard') : __('Edit Rule', 'contentguard')); ?></h1>
    <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=' . RulesPage::SLUG)); ?>">
            <?php echo esc_html__('Back to rules', 'contentguard'); ?>
        </a>
    </p>

    <?php if ($notice !== null) : ?>
        <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="contentguard-rule-form">
        <input type="hidden" name="action" value="<?php echo esc_attr(RulesController::ACTION_SAVE); ?>">
        <input type="hidden" name="id" value="<?php echo $isNew ? '' : esc_attr((string) $editor->id); ?>">
        <?php wp_nonce_field(RuleCommandService::NONCE_ACTION); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="contentguard-rule-name"><?php echo esc_html__('Rule Name', 'contentguard'); ?></label></th>
                <td>
                    <input type="text" class="regular-text" id="contentguard-rule-name" name="name" required value="<?php echo esc_attr($editor->name); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="contentguard-rule-post-type"><?php echo esc_html__('Applies to', 'contentguard'); ?></label></th>
                <td>
                    <select id="contentguard-rule-post-type" name="post_type" required>
                        <?php foreach ($postTypes as $slug => $label) : ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($editor->postType, $slug); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Severity', 'contentguard'); ?></th>
                <td>
                    <label>
                        <input type="radio" name="severity" value="<?php echo esc_attr(RuleSeverity::Fail->value); ?>" <?php checked($editor->severity === RuleSeverity::Fail->value); ?>>
                        <?php echo esc_html__('Fail', 'contentguard'); ?>
                    </label>
                    &nbsp;
                    <label>
                        <input type="radio" name="severity" value="<?php echo esc_attr(RuleSeverity::Warning->value); ?>" <?php checked($editor->severity === RuleSeverity::Warning->value); ?>>
                        <?php echo esc_html__('Warning', 'contentguard'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Status', 'contentguard'); ?></th>
                <td>
                    <label>
                        <input type="radio" name="status" value="<?php echo esc_attr(RuleStatus::Active->value); ?>" <?php checked($editor->status === RuleStatus::Active->value); ?>>
                        <?php echo esc_html__('Active', 'contentguard'); ?>
                    </label>
                    &nbsp;
                    <label>
                        <input type="radio" name="status" value="<?php echo esc_attr(RuleStatus::Inactive->value); ?>" <?php checked($editor->status === RuleStatus::Inactive->value); ?>>
                        <?php echo esc_html__('Inactive', 'contentguard'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <h2><?php echo esc_html__('WHEN', 'contentguard'); ?></h2>
        <p class="description"><?php echo esc_html__('Leave empty to apply this rule to every post of the selected type. Multiple conditions use AND.', 'contentguard'); ?></p>
        <p class="description"><?php echo esc_html__('Fields inside Groups, Repeaters, and Flexible Content are not supported yet.', 'contentguard'); ?></p>
        <div id="contentguard-conditions" class="contentguard-rows">
            <?php foreach ($conditions as $index => $condition) : ?>
                <?php
                $needsValue = in_array($condition['operator'], array('equals', 'not_equals'), true);
                $fieldType  = $fieldTypes[$condition['field_key']] ?? '';
                $operandName = 'conditions[' . (int) $index . '][operand]';
                ?>
                <div class="contentguard-row" data-row="condition">
                    <input type="hidden" name="conditions[<?php echo (int) $index; ?>][id]" value="<?php echo esc_attr($condition['id']); ?>">
                    <select name="conditions[<?php echo (int) $index; ?>][field_key]" class="contentguard-field">
                        <?php $renderFieldOptions($fields, $fieldKeys, $condition['field_key']); ?>
                    </select>
                    <select name="conditions[<?php echo (int) $index; ?>][operator]" class="contentguard-operator">
                        <?php foreach ($operators as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($condition['operator'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($fieldType === 'true_false') : ?>
                        <select class="contentguard-operand" name="<?php echo esc_attr($operandName); ?>" <?php echo $needsValue ? '' : 'hidden'; ?>>
                            <option value="1" <?php selected($condition['operand'], '1'); ?>><?php echo esc_html__('Yes', 'contentguard'); ?></option>
                            <option value="0" <?php selected($condition['operand'], '0'); ?>><?php echo esc_html__('No', 'contentguard'); ?></option>
                        </select>
                    <?php else : ?>
                        <input type="text" class="contentguard-operand" name="<?php echo esc_attr($operandName); ?>" value="<?php echo esc_attr($condition['operand']); ?>" <?php echo $needsValue ? '' : 'hidden'; ?>>
                    <?php endif; ?>
                    <button type="button" class="button contentguard-remove"><?php echo esc_html__('Remove', 'contentguard'); ?></button>
                </div>
            <?php endforeach; ?>
        </div>
        <p><button type="button" class="button" id="contentguard-add-condition"><?php echo esc_html__('Add Condition', 'contentguard'); ?></button></p>

        <h2><?php echo esc_html__('THEN', 'contentguard'); ?></h2>
        <p class="description"><?php echo esc_html__('Minimum length and maximum length count characters, not words.', 'contentguard'); ?></p>
        <div id="contentguard-validations" class="contentguard-rows">
            <?php if ($validations === array()) : ?>
                <div class="contentguard-row" data-row="validation">
                    <input type="hidden" name="validations[0][id]" value="">
                    <select name="validations[0][field_key]" class="contentguard-field">
                        <?php $renderFieldOptions($fields, $fieldKeys, ''); ?>
                    </select>
                    <select name="validations[0][type]" class="contentguard-validator">
                        <?php foreach ($validators as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" min="0" class="contentguard-param contentguard-min" name="validations[0][min]" placeholder="<?php echo esc_attr__('Min (characters)', 'contentguard'); ?>" hidden>
                    <input type="number" min="0" class="contentguard-param contentguard-max" name="validations[0][max]" placeholder="<?php echo esc_attr__('Max (characters)', 'contentguard'); ?>" hidden>
                    <input type="text" class="contentguard-param contentguard-values" name="validations[0][values]" placeholder="<?php echo esc_attr__('value1, value2', 'contentguard'); ?>" hidden>
                    <input type="text" class="contentguard-validation-message" name="validations[0][message]" placeholder="<?php echo esc_attr__('Custom message (optional)', 'contentguard'); ?>">
                    <button type="button" class="button contentguard-remove"><?php echo esc_html__('Remove', 'contentguard'); ?></button>
                </div>
            <?php endif; ?>
            <?php foreach ($validations as $index => $validation) : ?>
                <div class="contentguard-row" data-row="validation">
                    <input type="hidden" name="validations[<?php echo (int) $index; ?>][id]" value="<?php echo esc_attr($validation['id']); ?>">
                    <select name="validations[<?php echo (int) $index; ?>][field_key]" class="contentguard-field">
                        <?php $renderFieldOptions($fields, $fieldKeys, $validation['field_key']); ?>
                    </select>
                    <select name="validations[<?php echo (int) $index; ?>][type]" class="contentguard-validator">
                        <?php foreach ($validators as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($validation['type'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" min="0" class="contentguard-param contentguard-min" name="validations[<?php echo (int) $index; ?>][min]" value="<?php echo esc_attr($validation['min']); ?>" placeholder="<?php echo esc_attr__('Min (characters)', 'contentguard'); ?>" <?php echo $validation['type'] === 'min_length' ? '' : 'hidden'; ?>>
                    <input type="number" min="0" class="contentguard-param contentguard-max" name="validations[<?php echo (int) $index; ?>][max]" value="<?php echo esc_attr($validation['max']); ?>" placeholder="<?php echo esc_attr__('Max (characters)', 'contentguard'); ?>" <?php echo $validation['type'] === 'max_length' ? '' : 'hidden'; ?>>
                    <input type="text" class="contentguard-param contentguard-values" name="validations[<?php echo (int) $index; ?>][values]" value="<?php echo esc_attr($validation['values']); ?>" placeholder="<?php echo esc_attr__('value1, value2', 'contentguard'); ?>" <?php echo $validation['type'] === 'allowed_values' ? '' : 'hidden'; ?>>
                    <input type="text" class="contentguard-validation-message" name="validations[<?php echo (int) $index; ?>][message]" value="<?php echo esc_attr($validation['message']); ?>" placeholder="<?php echo esc_attr__('Custom message (optional)', 'contentguard'); ?>">
                    <button type="button" class="button contentguard-remove"><?php echo esc_html__('Remove', 'contentguard'); ?></button>
                </div>
            <?php endforeach; ?>
        </div>
        <p><button type="button" class="button" id="contentguard-add-validation"><?php echo esc_html__('Add Validation', 'contentguard'); ?></button></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="contentguard-rule-message"><?php echo esc_html__('Custom failure message', 'contentguard'); ?></label></th>
                <td>
                    <input type="text" class="regular-text" id="contentguard-rule-message" name="message" value="<?php echo esc_attr($editor->message); ?>">
                    <p class="description"><?php echo esc_html__('Used for validations that do not have their own message. Optional — leave blank to use the default message.', 'contentguard'); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button($isNew ? __('Save Rule', 'contentguard') : __('Update Rule', 'contentguard')); ?>
    </form>
</div>
