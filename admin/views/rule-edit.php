<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- These files are include/extract template scopes; assignments are template locals, not plugin globals.
/**
 * ContentLatch rule editor.
 *
 * @package ContentLatch
 *
 * @var \ContentLatch\Admin\RuleEditorState $editor
 * @var array<string, string> $postTypes
 * @var array<int, array<string, mixed>> $fields
 * @var array{type: string, message: string}|null $notice
 */

defined('ABSPATH') || exit;

use ContentLatch\Admin\AdminView;
use ContentLatch\Admin\RulesController;
use ContentLatch\Admin\RulesPage;
use ContentLatch\Application\AdminNotice;
use ContentLatch\Application\ConditionOperators;
use ContentLatch\Application\RuleBuilderFieldLabels;
use ContentLatch\Application\RuleCommandService;
use ContentLatch\Application\RulePreview;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Domain\RuleStatus;

$isNew       = $editor->isNew();
$conditions  = $editor->conditions;
$validations = $editor->validations;
$newUrl      = admin_url('admin.php?page=' . RulesPage::SLUG . '&action=new');
$listUrl     = admin_url('admin.php?page=' . RulesPage::SLUG);
$fieldKeys   = array();
$fieldTypes  = array();
$fieldMeta   = array();

foreach ($fields as $field) {
    $key = (string) ($field['resolution_id'] ?? $field['key'] ?? '');
    $type = (string) ($field['type'] ?? '');
    $fieldKeys[$key] = true;
    $fieldTypes[$key] = $type;
    $fieldMeta[$key] = array(
        'label' => RuleBuilderFieldLabels::previewLabel($field),
        'type'  => $type,
    );
}
$preview = RulePreview::fromEditor($conditions, $validations, $fieldMeta, $editor->severity);

$validators = array(
    'required'       => __('is required', 'contentlatch'),
    'min_length'     => __('Minimum length', 'contentlatch'),
    'max_length'     => __('Maximum length', 'contentlatch'),
    'allowed_values' => __('Allowed values', 'contentlatch'),
);

$headerSecondary = array(
    array(
        'label' => __('Back to rules', 'contentlatch'),
        'href'  => $listUrl,
        'class' => 'contentlatch-button--link',
    ),
);
if (!$isNew) {
    $headerSecondary[] = array(
        'label' => __('Add another rule', 'contentlatch'),
        'href'  => $newUrl,
        'class' => 'contentlatch-button--link',
    );
}

/**
 * @param array<int, array<string, mixed>> $fields
 * @param array<string, true> $fieldKeys
 */
$renderFieldOptions = static function (array $fields, array $fieldKeys, string $selected): void {
    echo '<option value="">' . esc_html__('Choose a field', 'contentlatch') . '</option>';

    $ungrouped = array();
    $groups    = array();
    foreach ($fields as $field) {
        $group = RuleBuilderFieldLabels::groupLabel($field);
        if ($group === '') {
            $ungrouped[] = $field;
        } else {
            $groups[$group][] = $field;
        }
    }

    $renderOption = static function (array $field, string $selected): void {
        $key   = (string) ($field['resolution_id'] ?? $field['key'] ?? '');
        $type  = (string) ($field['type'] ?? '');
        $label = RuleBuilderFieldLabels::optionLabel($field);
        echo '<option value="' . esc_attr($key) . '" data-type="' . esc_attr($type) . '" ' . selected($selected, $key, false) . '>'
            . esc_html($label)
            . '</option>';
    };

    foreach ($ungrouped as $field) {
        $renderOption($field, $selected);
    }

    foreach ($groups as $groupLabel => $groupFields) {
        echo '<optgroup label="' . esc_attr((string) $groupLabel) . '">';
        foreach ($groupFields as $field) {
            $renderOption($field, $selected);
        }
        echo '</optgroup>';
    }

    if ($selected !== '' && !isset($fieldKeys[$selected])) {
        echo '<option value="' . esc_attr($selected) . '" selected>' . esc_html($selected) . '</option>';
    }
};
?>
<div class="wrap contentlatch" id="contentlatch-rule-editor">
    <?php
    AdminView::partial(
        'page-header',
        array(
            'title'       => $isNew ? __('Add New Rule', 'contentlatch') : __('Edit Rule', 'contentlatch'),
            'description' => $isNew
                ? __('Create a rule that defines when content must meet a requirement.', 'contentlatch')
                : __('Update the rule that defines when content must meet a requirement.', 'contentlatch'),
            'secondary'   => $headerSecondary,
        )
    );
    ?>

    <?php if ($notice !== null) : ?>
        <div
            id="<?php echo esc_attr(AdminNotice::TARGET_ID); ?>"
            class="<?php echo esc_attr(AdminNotice::cssClass($notice['type'])); ?> inline"
            <?php if ($notice['type'] === 'error') : ?>
                role="alert"
                tabindex="-1"
            <?php endif; ?>
        >
            <?php if ($notice['type'] === 'error') : ?>
                <p class="contentlatch-notice-label"><strong><?php echo esc_html__('Warning:', 'contentlatch'); ?></strong></p>
            <?php endif; ?>
            <p class="contentlatch-notice-message">
                <?php echo esc_html($notice['message']); ?>
                <?php if ($notice['type'] === 'success') : ?>
                    <a href="<?php echo esc_url($newUrl); ?>"><?php echo esc_html__('Add another rule', 'contentlatch'); ?></a>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>
    <div id="contentlatch-rule-client-notice" class="notice notice-error inline" hidden>
        <p class="contentlatch-notice-label"><strong><?php echo esc_html__('Warning:', 'contentlatch'); ?></strong></p>
        <p class="contentlatch-notice-message"></p>
    </div>

    <section class="contentlatch-panel contentlatch-rule-preview" aria-labelledby="contentlatch-preview-heading">
        <h2 class="contentlatch-builder-section__title" id="contentlatch-preview-heading"><?php echo esc_html__('Rule preview', 'contentlatch'); ?></h2>
        <p class="contentlatch-rule-preview__text" id="contentlatch-rule-preview" aria-live="polite"><?php echo esc_html($preview); ?></p>
    </section>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="contentlatch-rule-form" class="contentlatch-builder">
        <input type="hidden" name="action" value="<?php echo esc_attr(RulesController::ACTION_SAVE); ?>">
        <input type="hidden" name="rule_id" value="<?php echo $isNew ? '' : esc_attr((string) $editor->id); ?>">
        <?php wp_nonce_field(RuleCommandService::NONCE_ACTION); ?>

        <section class="contentlatch-panel contentlatch-builder-section" aria-labelledby="contentlatch-details-heading">
            <h2 class="contentlatch-builder-section__title" id="contentlatch-details-heading"><?php echo esc_html__('Rule details', 'contentlatch'); ?></h2>
            <p class="description"><?php echo esc_html__('Name the rule and choose the content it applies to.', 'contentlatch'); ?></p>

            <div class="contentlatch-builder-field">
                <label for="contentlatch-rule-name"><?php echo esc_html__('Rule name', 'contentlatch'); ?></label>
                <input type="text" class="regular-text" id="contentlatch-rule-name" name="name" required value="<?php echo esc_attr($editor->name); ?>">
            </div>
            <div class="contentlatch-builder-field">
                <label for="contentlatch-rule-post-type"><?php echo esc_html__('Applies to', 'contentlatch'); ?></label>
                <select id="contentlatch-rule-post-type" name="target_post_type" required aria-describedby="contentlatch-post-type-help">
                    <?php foreach ($postTypes as $slug => $label) : ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($editor->postType, $slug); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description" id="contentlatch-post-type-help"><?php echo esc_html__('Changing this updates the fields you can use in WHEN and THEN.', 'contentlatch'); ?></p>
            </div>
        </section>

        <section class="contentlatch-panel contentlatch-builder-section" aria-labelledby="contentlatch-when-heading">
            <h2 class="contentlatch-builder-section__title" id="contentlatch-when-heading"><?php echo esc_html__('WHEN', 'contentlatch'); ?></h2>
            <p class="description"><?php echo esc_html__('Leave empty to apply this rule to every post of the selected type. Multiple conditions use AND.', 'contentlatch'); ?></p>
            <p class="description"><?php echo esc_html__('Repeater and Flexible Content fields can be used in WHEN and THEN. WHEN applies when any matching row meets the condition. THEN requirements apply to every matching row.', 'contentlatch'); ?></p>
            <div id="contentlatch-conditions" class="contentlatch-rows">
                <?php foreach ($conditions as $index => $condition) : ?>
                    <?php
                    $needsValue  = ConditionOperators::requiresOperand($condition['operator']);
                    $fieldType   = $fieldTypes[$condition['field_key']] ?? '';
                    $operators   = ConditionOperators::labelsForFieldType($fieldType);
                    if ($condition['operator'] !== '' && !isset($operators[$condition['operator']])) {
                        $operators[$condition['operator']] = $condition['operator'];
                    }
                    $operandName = 'conditions[' . (int) $index . '][operand]';
                    $fieldId     = 'contentlatch-condition-field-' . (int) $index;
                    $operatorId  = 'contentlatch-condition-operator-' . (int) $index;
                    $operandId   = 'contentlatch-condition-operand-' . (int) $index;
                    ?>
                    <div class="contentlatch-row contentlatch-builder-row" data-row="condition">
                        <input type="hidden" name="conditions[<?php echo (int) $index; ?>][id]" value="<?php echo esc_attr($condition['id']); ?>">
                        <div class="contentlatch-builder-row__controls">
                            <label class="screen-reader-text" for="<?php echo esc_attr($fieldId); ?>"><?php echo esc_html__('WHEN field', 'contentlatch'); ?></label>
                            <select id="<?php echo esc_attr($fieldId); ?>" name="conditions[<?php echo (int) $index; ?>][field_key]" class="contentlatch-field">
                                <?php $renderFieldOptions($fields, $fieldKeys, $condition['field_key']); ?>
                            </select>
                            <label class="screen-reader-text" for="<?php echo esc_attr($operatorId); ?>"><?php echo esc_html__('Operator', 'contentlatch'); ?></label>
                            <select id="<?php echo esc_attr($operatorId); ?>" name="conditions[<?php echo (int) $index; ?>][operator]" class="contentlatch-operator">
                                <?php foreach ($operators as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($condition['operator'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="screen-reader-text" for="<?php echo esc_attr($operandId); ?>"><?php echo esc_html__('Value', 'contentlatch'); ?></label>
                            <?php if ($fieldType === 'true_false') : ?>
                                <select id="<?php echo esc_attr($operandId); ?>" class="contentlatch-operand" name="<?php echo esc_attr($operandName); ?>" <?php echo $needsValue ? '' : 'hidden'; ?>>
                                    <option value="1" <?php selected($condition['operand'], '1'); ?>><?php echo esc_html__('Yes', 'contentlatch'); ?></option>
                                    <option value="0" <?php selected($condition['operand'], '0'); ?>><?php echo esc_html__('No', 'contentlatch'); ?></option>
                                </select>
                            <?php elseif (ConditionOperators::isNumericField($fieldType)) : ?>
                                <input id="<?php echo esc_attr($operandId); ?>" type="number" step="any" class="contentlatch-operand" name="<?php echo esc_attr($operandName); ?>" value="<?php echo esc_attr($condition['operand']); ?>" <?php echo $needsValue ? '' : 'hidden'; ?>>
                            <?php else : ?>
                                <input id="<?php echo esc_attr($operandId); ?>" type="text" class="contentlatch-operand" name="<?php echo esc_attr($operandName); ?>" value="<?php echo esc_attr($condition['operand']); ?>" <?php echo $needsValue ? '' : 'hidden'; ?>>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button contentlatch-remove" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: condition number */ __('Remove condition %s', 'contentlatch'), (string) ((int) $index + 1))); ?>">
                            <?php echo esc_html__('Remove', 'contentlatch'); ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p><button type="button" class="button" id="contentlatch-add-condition"><?php echo esc_html__('Add condition', 'contentlatch'); ?></button></p>
        </section>

        <section class="contentlatch-panel contentlatch-builder-section" aria-labelledby="contentlatch-then-heading">
            <h2 class="contentlatch-builder-section__title" id="contentlatch-then-heading"><?php echo esc_html__('THEN', 'contentlatch'); ?></h2>
            <p class="description"><?php echo esc_html__('Optional. Leave the field unselected if matching the WHEN condition itself should fail the rule. Minimum length and maximum length count characters, not words.', 'contentlatch'); ?></p>
            <div id="contentlatch-validations" class="contentlatch-rows">
                <?php if ($validations === array()) : ?>
                    <?php $validations = array(array('id' => '', 'field_key' => '', 'type' => 'required', 'min' => '', 'max' => '', 'values' => '', 'message' => '')); ?>
                <?php endif; ?>
                <?php foreach ($validations as $index => $validation) : ?>
                    <?php
                    $fieldId     = 'contentlatch-validation-field-' . (int) $index;
                    $typeId      = 'contentlatch-validation-type-' . (int) $index;
                    $minId       = 'contentlatch-validation-min-' . (int) $index;
                    $maxId       = 'contentlatch-validation-max-' . (int) $index;
                    $valuesId    = 'contentlatch-validation-values-' . (int) $index;
                    $messageId   = 'contentlatch-validation-message-' . (int) $index;
                    $showMin     = $validation['type'] === 'min_length';
                    $showMax     = $validation['type'] === 'max_length';
                    $showValues  = $validation['type'] === 'allowed_values';
                    ?>
                    <div class="contentlatch-row contentlatch-builder-row" data-row="validation">
                        <input type="hidden" name="validations[<?php echo (int) $index; ?>][id]" value="<?php echo esc_attr($validation['id']); ?>">
                        <div class="contentlatch-builder-row__controls">
                            <label class="screen-reader-text" for="<?php echo esc_attr($fieldId); ?>"><?php echo esc_html__('THEN field', 'contentlatch'); ?></label>
                            <select id="<?php echo esc_attr($fieldId); ?>" name="validations[<?php echo (int) $index; ?>][field_key]" class="contentlatch-field">
                                <?php $renderFieldOptions($fields, $fieldKeys, $validation['field_key']); ?>
                            </select>
                            <label class="screen-reader-text" for="<?php echo esc_attr($typeId); ?>"><?php echo esc_html__('Requirement', 'contentlatch'); ?></label>
                            <select id="<?php echo esc_attr($typeId); ?>" name="validations[<?php echo (int) $index; ?>][type]" class="contentlatch-validator">
                                <?php foreach ($validators as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($validation['type'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="contentlatch-param-group contentlatch-param-group--min" <?php echo $showMin ? '' : 'hidden'; ?>>
                                <label class="screen-reader-text" for="<?php echo esc_attr($minId); ?>"><?php echo esc_html__('Minimum length', 'contentlatch'); ?></label>
                                <input id="<?php echo esc_attr($minId); ?>" type="number" min="0" class="contentlatch-param contentlatch-min" name="validations[<?php echo (int) $index; ?>][min]" value="<?php echo esc_attr($showMin ? $validation['min'] : ''); ?>" <?php disabled(!$showMin); ?>>
                                <span class="contentlatch-param-suffix"><?php echo esc_html__('characters', 'contentlatch'); ?></span>
                            </span>
                            <span class="contentlatch-param-group contentlatch-param-group--max" <?php echo $showMax ? '' : 'hidden'; ?>>
                                <label class="screen-reader-text" for="<?php echo esc_attr($maxId); ?>"><?php echo esc_html__('Maximum length', 'contentlatch'); ?></label>
                                <input id="<?php echo esc_attr($maxId); ?>" type="number" min="0" class="contentlatch-param contentlatch-max" name="validations[<?php echo (int) $index; ?>][max]" value="<?php echo esc_attr($showMax ? $validation['max'] : ''); ?>" <?php disabled(!$showMax); ?>>
                                <span class="contentlatch-param-suffix"><?php echo esc_html__('characters', 'contentlatch'); ?></span>
                            </span>
                            <span class="contentlatch-param-group contentlatch-param-group--values" <?php echo $showValues ? '' : 'hidden'; ?>>
                                <label class="screen-reader-text" for="<?php echo esc_attr($valuesId); ?>"><?php echo esc_html__('Allowed values', 'contentlatch'); ?></label>
                                <input id="<?php echo esc_attr($valuesId); ?>" type="text" class="contentlatch-param contentlatch-values" name="validations[<?php echo (int) $index; ?>][values]" value="<?php echo esc_attr($showValues ? $validation['values'] : ''); ?>" placeholder="<?php echo esc_attr__('value1, value2', 'contentlatch'); ?>" <?php disabled(!$showValues); ?>>
                            </span>
                            <label class="screen-reader-text" for="<?php echo esc_attr($messageId); ?>"><?php echo esc_html__('Custom message (optional)', 'contentlatch'); ?></label>
                            <input id="<?php echo esc_attr($messageId); ?>" type="text" class="contentlatch-validation-message" name="validations[<?php echo (int) $index; ?>][message]" value="<?php echo esc_attr($validation['message']); ?>" placeholder="<?php echo esc_attr__('Custom message (optional)', 'contentlatch'); ?>">
                        </div>
                        <button type="button" class="button contentlatch-remove" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: requirement number */ __('Remove requirement %s', 'contentlatch'), (string) ((int) $index + 1))); ?>">
                            <?php echo esc_html__('Remove', 'contentlatch'); ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p><button type="button" class="button" id="contentlatch-add-validation"><?php echo esc_html__('Add requirement', 'contentlatch'); ?></button></p>
        </section>

        <section class="contentlatch-panel contentlatch-builder-section" aria-labelledby="contentlatch-behavior-heading">
            <h2 class="contentlatch-builder-section__title" id="contentlatch-behavior-heading"><?php echo esc_html__('Rule behavior', 'contentlatch'); ?></h2>

            <fieldset class="contentlatch-choice-group">
                <legend><?php echo esc_html__('Severity', 'contentlatch'); ?></legend>
                <label class="contentlatch-choice">
                    <input type="radio" name="severity" value="<?php echo esc_attr(RuleSeverity::Fail->value); ?>" <?php checked($editor->severity === RuleSeverity::Fail->value); ?>>
                    <span>
                        <span class="contentlatch-choice__label"><?php echo esc_html__('Blocking', 'contentlatch'); ?></span>
                        <span class="description"><?php echo esc_html__('Prevents publishing when the rule fails.', 'contentlatch'); ?></span>
                    </span>
                </label>
                <label class="contentlatch-choice">
                    <input type="radio" name="severity" value="<?php echo esc_attr(RuleSeverity::Warning->value); ?>" <?php checked($editor->severity === RuleSeverity::Warning->value); ?>>
                    <span>
                        <span class="contentlatch-choice__label"><?php echo esc_html__('Warning', 'contentlatch'); ?></span>
                        <span class="description"><?php echo esc_html__('Reports an issue but does not prevent publishing.', 'contentlatch'); ?></span>
                    </span>
                </label>
            </fieldset>

            <fieldset class="contentlatch-choice-group">
                <legend><?php echo esc_html__('Status', 'contentlatch'); ?></legend>
                <label class="contentlatch-choice">
                    <input type="radio" name="status" value="<?php echo esc_attr(RuleStatus::Active->value); ?>" <?php checked($editor->status === RuleStatus::Active->value); ?>>
                    <span>
                        <span class="contentlatch-choice__label"><?php echo esc_html__('Active', 'contentlatch'); ?></span>
                        <span class="description"><?php echo esc_html__('Enforced and included in audits.', 'contentlatch'); ?></span>
                    </span>
                </label>
                <label class="contentlatch-choice">
                    <input type="radio" name="status" value="<?php echo esc_attr(RuleStatus::Inactive->value); ?>" <?php checked($editor->status === RuleStatus::Inactive->value); ?>>
                    <span>
                        <span class="contentlatch-choice__label"><?php echo esc_html__('Inactive', 'contentlatch'); ?></span>
                        <span class="description"><?php echo esc_html__('Not enforced and not included in audits.', 'contentlatch'); ?></span>
                    </span>
                </label>
            </fieldset>

            <div class="contentlatch-builder-field">
                <label for="contentlatch-rule-message"><?php echo esc_html__('Custom failure message', 'contentlatch'); ?></label>
                <input type="text" class="regular-text" id="contentlatch-rule-message" name="message" value="<?php echo esc_attr($editor->message); ?>">
                <p class="description"><?php echo esc_html__('Used when this rule fails, including when a WHEN condition matches without a THEN requirement. Optional — leave blank to use the default message.', 'contentlatch'); ?></p>
            </div>
        </section>

        <div class="contentlatch-builder-actions">
            <button type="submit" class="button button-primary">
                <?php echo esc_html($isNew ? __('Save Rule', 'contentlatch') : __('Update Rule', 'contentlatch')); ?>
            </button>
            <a class="button" href="<?php echo esc_url($listUrl); ?>"><?php echo esc_html__('Back to rules', 'contentlatch'); ?></a>
            <?php if (!$isNew) : ?>
                <a class="button" href="<?php echo esc_url($newUrl); ?>"><?php echo esc_html__('Add another rule', 'contentlatch'); ?></a>
            <?php endif; ?>
        </div>
    </form>
</div>
