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

use ContentGuard\Admin\AdminView;
use ContentGuard\Admin\RulesController;
use ContentGuard\Admin\RulesPage;
use ContentGuard\Application\AdminNotice;
use ContentGuard\Application\ConditionOperators;
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Application\RulePreview;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;

$isNew       = $editor->isNew();
$conditions  = $editor->conditions;
$validations = $editor->validations;
$newUrl      = admin_url('admin.php?page=' . RulesPage::SLUG . '&action=new');
$listUrl     = admin_url('admin.php?page=' . RulesPage::SLUG);
$fieldKeys   = array();
$fieldTypes  = array();
$fieldMeta   = array();
foreach ($fields as $field) {
    $key = (string) ($field['key'] ?? '');
    $label = (string) ($field['label'] ?? '') !== ''
        ? (string) $field['label']
        : (string) ($field['name'] ?? $key);
    $type = (string) ($field['type'] ?? '');
    $fieldKeys[$key] = true;
    $fieldTypes[$key] = $type;
    $fieldMeta[$key] = array(
        'label' => $label,
        'type'  => $type,
    );
}
$preview = RulePreview::fromEditor($conditions, $validations, $fieldMeta);

$validators = array(
    'required'       => __('is required', 'contentguard'),
    'min_length'     => __('Minimum length', 'contentguard'),
    'max_length'     => __('Maximum length', 'contentguard'),
    'allowed_values' => __('Allowed values', 'contentguard'),
);

$headerSecondary = array(
    array(
        'label' => __('Back to rules', 'contentguard'),
        'href'  => $listUrl,
        'class' => 'contentguard-button--link',
    ),
);
if (!$isNew) {
    $headerSecondary[] = array(
        'label' => __('Add another rule', 'contentguard'),
        'href'  => $newUrl,
        'class' => 'contentguard-button--link',
    );
}

/**
 * @param array<int, array<string, mixed>> $fields
 * @param array<string, true> $fieldKeys
 */
$renderFieldOptions = static function (array $fields, array $fieldKeys, string $selected): void {
    echo '<option value="">' . esc_html__('Choose a field', 'contentguard') . '</option>';
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
<div class="wrap contentguard" id="contentguard-rule-editor">
    <?php
    AdminView::partial(
        'page-header',
        array(
            'title'       => $isNew ? __('Add New Rule', 'contentguard') : __('Edit Rule', 'contentguard'),
            'description' => $isNew
                ? __('Create a rule that defines when content must meet a requirement.', 'contentguard')
                : __('Update the rule that defines when content must meet a requirement.', 'contentguard'),
            'secondary'   => $headerSecondary,
        )
    );
    ?>

    <?php if ($notice !== null) : ?>
        <div class="<?php echo esc_attr(AdminNotice::cssClass($notice['type'])); ?>">
            <p>
                <?php echo esc_html($notice['message']); ?>
                <?php if ($notice['type'] === 'success') : ?>
                    <a href="<?php echo esc_url($newUrl); ?>"><?php echo esc_html__('Add another rule', 'contentguard'); ?></a>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>
    <div id="contentguard-rule-client-notice" class="notice notice-error" hidden><p></p></div>

    <section class="contentguard-panel contentguard-rule-preview" aria-labelledby="contentguard-preview-heading">
        <h2 class="contentguard-builder-section__title" id="contentguard-preview-heading"><?php echo esc_html__('Rule preview', 'contentguard'); ?></h2>
        <p class="contentguard-rule-preview__text" id="contentguard-rule-preview" aria-live="polite"><?php echo esc_html($preview); ?></p>
    </section>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="contentguard-rule-form" class="contentguard-builder">
        <input type="hidden" name="action" value="<?php echo esc_attr(RulesController::ACTION_SAVE); ?>">
        <input type="hidden" name="rule_id" value="<?php echo $isNew ? '' : esc_attr((string) $editor->id); ?>">
        <?php wp_nonce_field(RuleCommandService::NONCE_ACTION); ?>

        <section class="contentguard-panel contentguard-builder-section" aria-labelledby="contentguard-details-heading">
            <h2 class="contentguard-builder-section__title" id="contentguard-details-heading"><?php echo esc_html__('Rule details', 'contentguard'); ?></h2>
            <p class="description"><?php echo esc_html__('Name the rule and choose the content it applies to.', 'contentguard'); ?></p>

            <div class="contentguard-builder-field">
                <label for="contentguard-rule-name"><?php echo esc_html__('Rule name', 'contentguard'); ?></label>
                <input type="text" class="regular-text" id="contentguard-rule-name" name="name" required value="<?php echo esc_attr($editor->name); ?>">
            </div>
            <div class="contentguard-builder-field">
                <label for="contentguard-rule-post-type"><?php echo esc_html__('Applies to', 'contentguard'); ?></label>
                <select id="contentguard-rule-post-type" name="target_post_type" required aria-describedby="contentguard-post-type-help">
                    <?php foreach ($postTypes as $slug => $label) : ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($editor->postType, $slug); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description" id="contentguard-post-type-help"><?php echo esc_html__('Changing this updates the fields you can use in WHEN and THEN.', 'contentguard'); ?></p>
            </div>
        </section>

        <section class="contentguard-panel contentguard-builder-section" aria-labelledby="contentguard-when-heading">
            <h2 class="contentguard-builder-section__title" id="contentguard-when-heading"><?php echo esc_html__('WHEN', 'contentguard'); ?></h2>
            <p class="description"><?php echo esc_html__('Leave empty to apply this rule to every post of the selected type. Multiple conditions use AND.', 'contentguard'); ?></p>
            <p class="description"><?php echo esc_html__('Fields inside Groups, Repeaters, and Flexible Content are not supported yet.', 'contentguard'); ?></p>
            <div id="contentguard-conditions" class="contentguard-rows">
                <?php foreach ($conditions as $index => $condition) : ?>
                    <?php
                    $needsValue  = ConditionOperators::requiresOperand($condition['operator']);
                    $fieldType   = $fieldTypes[$condition['field_key']] ?? '';
                    $operators   = ConditionOperators::labelsForFieldType($fieldType);
                    if ($condition['operator'] !== '' && !isset($operators[$condition['operator']])) {
                        $operators[$condition['operator']] = $condition['operator'];
                    }
                    $operandName = 'conditions[' . (int) $index . '][operand]';
                    $fieldId     = 'contentguard-condition-field-' . (int) $index;
                    $operatorId  = 'contentguard-condition-operator-' . (int) $index;
                    $operandId   = 'contentguard-condition-operand-' . (int) $index;
                    ?>
                    <div class="contentguard-row contentguard-builder-row" data-row="condition">
                        <input type="hidden" name="conditions[<?php echo (int) $index; ?>][id]" value="<?php echo esc_attr($condition['id']); ?>">
                        <div class="contentguard-builder-row__controls">
                            <label class="screen-reader-text" for="<?php echo esc_attr($fieldId); ?>"><?php echo esc_html__('WHEN field', 'contentguard'); ?></label>
                            <select id="<?php echo esc_attr($fieldId); ?>" name="conditions[<?php echo (int) $index; ?>][field_key]" class="contentguard-field">
                                <?php $renderFieldOptions($fields, $fieldKeys, $condition['field_key']); ?>
                            </select>
                            <label class="screen-reader-text" for="<?php echo esc_attr($operatorId); ?>"><?php echo esc_html__('Operator', 'contentguard'); ?></label>
                            <select id="<?php echo esc_attr($operatorId); ?>" name="conditions[<?php echo (int) $index; ?>][operator]" class="contentguard-operator">
                                <?php foreach ($operators as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($condition['operator'], $value); ?>><?php echo esc_html__($label, 'contentguard'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="screen-reader-text" for="<?php echo esc_attr($operandId); ?>"><?php echo esc_html__('Value', 'contentguard'); ?></label>
                            <?php if ($fieldType === 'true_false') : ?>
                                <select id="<?php echo esc_attr($operandId); ?>" class="contentguard-operand" name="<?php echo esc_attr($operandName); ?>" <?php echo $needsValue ? '' : 'hidden'; ?>>
                                    <option value="1" <?php selected($condition['operand'], '1'); ?>><?php echo esc_html__('Yes', 'contentguard'); ?></option>
                                    <option value="0" <?php selected($condition['operand'], '0'); ?>><?php echo esc_html__('No', 'contentguard'); ?></option>
                                </select>
                            <?php elseif (ConditionOperators::isNumericField($fieldType)) : ?>
                                <input id="<?php echo esc_attr($operandId); ?>" type="number" step="any" class="contentguard-operand" name="<?php echo esc_attr($operandName); ?>" value="<?php echo esc_attr($condition['operand']); ?>" <?php echo $needsValue ? '' : 'hidden'; ?>>
                            <?php else : ?>
                                <input id="<?php echo esc_attr($operandId); ?>" type="text" class="contentguard-operand" name="<?php echo esc_attr($operandName); ?>" value="<?php echo esc_attr($condition['operand']); ?>" <?php echo $needsValue ? '' : 'hidden'; ?>>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button contentguard-remove" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: condition number */ __('Remove condition %s', 'contentguard'), (string) ((int) $index + 1))); ?>">
                            <?php echo esc_html__('Remove', 'contentguard'); ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p><button type="button" class="button" id="contentguard-add-condition"><?php echo esc_html__('Add condition', 'contentguard'); ?></button></p>
        </section>

        <section class="contentguard-panel contentguard-builder-section" aria-labelledby="contentguard-then-heading">
            <h2 class="contentguard-builder-section__title" id="contentguard-then-heading"><?php echo esc_html__('THEN', 'contentguard'); ?></h2>
            <p class="description"><?php echo esc_html__('Choose what must be true. Minimum length and maximum length count characters, not words.', 'contentguard'); ?></p>
            <div id="contentguard-validations" class="contentguard-rows">
                <?php if ($validations === array()) : ?>
                    <?php $validations = array(array('id' => '', 'field_key' => '', 'type' => 'required', 'min' => '', 'max' => '', 'values' => '', 'message' => '')); ?>
                <?php endif; ?>
                <?php foreach ($validations as $index => $validation) : ?>
                    <?php
                    $fieldId     = 'contentguard-validation-field-' . (int) $index;
                    $typeId      = 'contentguard-validation-type-' . (int) $index;
                    $minId       = 'contentguard-validation-min-' . (int) $index;
                    $maxId       = 'contentguard-validation-max-' . (int) $index;
                    $valuesId    = 'contentguard-validation-values-' . (int) $index;
                    $messageId   = 'contentguard-validation-message-' . (int) $index;
                    $showMin     = $validation['type'] === 'min_length';
                    $showMax     = $validation['type'] === 'max_length';
                    $showValues  = $validation['type'] === 'allowed_values';
                    ?>
                    <div class="contentguard-row contentguard-builder-row" data-row="validation">
                        <input type="hidden" name="validations[<?php echo (int) $index; ?>][id]" value="<?php echo esc_attr($validation['id']); ?>">
                        <div class="contentguard-builder-row__controls">
                            <label class="screen-reader-text" for="<?php echo esc_attr($fieldId); ?>"><?php echo esc_html__('THEN field', 'contentguard'); ?></label>
                            <select id="<?php echo esc_attr($fieldId); ?>" name="validations[<?php echo (int) $index; ?>][field_key]" class="contentguard-field">
                                <?php $renderFieldOptions($fields, $fieldKeys, $validation['field_key']); ?>
                            </select>
                            <label class="screen-reader-text" for="<?php echo esc_attr($typeId); ?>"><?php echo esc_html__('Requirement', 'contentguard'); ?></label>
                            <select id="<?php echo esc_attr($typeId); ?>" name="validations[<?php echo (int) $index; ?>][type]" class="contentguard-validator">
                                <?php foreach ($validators as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($validation['type'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="contentguard-param-group contentguard-param-group--min" <?php echo $showMin ? '' : 'hidden'; ?>>
                                <label class="screen-reader-text" for="<?php echo esc_attr($minId); ?>"><?php echo esc_html__('Minimum length', 'contentguard'); ?></label>
                                <input id="<?php echo esc_attr($minId); ?>" type="number" min="0" class="contentguard-param contentguard-min" name="validations[<?php echo (int) $index; ?>][min]" value="<?php echo esc_attr($showMin ? $validation['min'] : ''); ?>" <?php disabled(!$showMin); ?>>
                                <span class="contentguard-param-suffix"><?php echo esc_html__('characters', 'contentguard'); ?></span>
                            </span>
                            <span class="contentguard-param-group contentguard-param-group--max" <?php echo $showMax ? '' : 'hidden'; ?>>
                                <label class="screen-reader-text" for="<?php echo esc_attr($maxId); ?>"><?php echo esc_html__('Maximum length', 'contentguard'); ?></label>
                                <input id="<?php echo esc_attr($maxId); ?>" type="number" min="0" class="contentguard-param contentguard-max" name="validations[<?php echo (int) $index; ?>][max]" value="<?php echo esc_attr($showMax ? $validation['max'] : ''); ?>" <?php disabled(!$showMax); ?>>
                                <span class="contentguard-param-suffix"><?php echo esc_html__('characters', 'contentguard'); ?></span>
                            </span>
                            <span class="contentguard-param-group contentguard-param-group--values" <?php echo $showValues ? '' : 'hidden'; ?>>
                                <label class="screen-reader-text" for="<?php echo esc_attr($valuesId); ?>"><?php echo esc_html__('Allowed values', 'contentguard'); ?></label>
                                <input id="<?php echo esc_attr($valuesId); ?>" type="text" class="contentguard-param contentguard-values" name="validations[<?php echo (int) $index; ?>][values]" value="<?php echo esc_attr($showValues ? $validation['values'] : ''); ?>" placeholder="<?php echo esc_attr__('value1, value2', 'contentguard'); ?>" <?php disabled(!$showValues); ?>>
                            </span>
                            <label class="screen-reader-text" for="<?php echo esc_attr($messageId); ?>"><?php echo esc_html__('Custom message (optional)', 'contentguard'); ?></label>
                            <input id="<?php echo esc_attr($messageId); ?>" type="text" class="contentguard-validation-message" name="validations[<?php echo (int) $index; ?>][message]" value="<?php echo esc_attr($validation['message']); ?>" placeholder="<?php echo esc_attr__('Custom message (optional)', 'contentguard'); ?>">
                        </div>
                        <button type="button" class="button contentguard-remove" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: requirement number */ __('Remove requirement %s', 'contentguard'), (string) ((int) $index + 1))); ?>">
                            <?php echo esc_html__('Remove', 'contentguard'); ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p><button type="button" class="button" id="contentguard-add-validation"><?php echo esc_html__('Add requirement', 'contentguard'); ?></button></p>
        </section>

        <section class="contentguard-panel contentguard-builder-section" aria-labelledby="contentguard-behavior-heading">
            <h2 class="contentguard-builder-section__title" id="contentguard-behavior-heading"><?php echo esc_html__('Rule behavior', 'contentguard'); ?></h2>

            <fieldset class="contentguard-choice-group">
                <legend><?php echo esc_html__('Severity', 'contentguard'); ?></legend>
                <label class="contentguard-choice">
                    <input type="radio" name="severity" value="<?php echo esc_attr(RuleSeverity::Fail->value); ?>" <?php checked($editor->severity === RuleSeverity::Fail->value); ?>>
                    <span>
                        <span class="contentguard-choice__label"><?php echo esc_html__('Blocking', 'contentguard'); ?></span>
                        <span class="description"><?php echo esc_html__('Prevents publishing when the rule fails.', 'contentguard'); ?></span>
                    </span>
                </label>
                <label class="contentguard-choice">
                    <input type="radio" name="severity" value="<?php echo esc_attr(RuleSeverity::Warning->value); ?>" <?php checked($editor->severity === RuleSeverity::Warning->value); ?>>
                    <span>
                        <span class="contentguard-choice__label"><?php echo esc_html__('Warning', 'contentguard'); ?></span>
                        <span class="description"><?php echo esc_html__('Reports an issue but does not prevent publishing.', 'contentguard'); ?></span>
                    </span>
                </label>
            </fieldset>

            <fieldset class="contentguard-choice-group">
                <legend><?php echo esc_html__('Status', 'contentguard'); ?></legend>
                <label class="contentguard-choice">
                    <input type="radio" name="status" value="<?php echo esc_attr(RuleStatus::Active->value); ?>" <?php checked($editor->status === RuleStatus::Active->value); ?>>
                    <span>
                        <span class="contentguard-choice__label"><?php echo esc_html__('Active', 'contentguard'); ?></span>
                        <span class="description"><?php echo esc_html__('Enforced and included in audits.', 'contentguard'); ?></span>
                    </span>
                </label>
                <label class="contentguard-choice">
                    <input type="radio" name="status" value="<?php echo esc_attr(RuleStatus::Inactive->value); ?>" <?php checked($editor->status === RuleStatus::Inactive->value); ?>>
                    <span>
                        <span class="contentguard-choice__label"><?php echo esc_html__('Inactive', 'contentguard'); ?></span>
                        <span class="description"><?php echo esc_html__('Not enforced and not included in audits.', 'contentguard'); ?></span>
                    </span>
                </label>
            </fieldset>

            <div class="contentguard-builder-field">
                <label for="contentguard-rule-message"><?php echo esc_html__('Custom failure message', 'contentguard'); ?></label>
                <input type="text" class="regular-text" id="contentguard-rule-message" name="message" value="<?php echo esc_attr($editor->message); ?>">
                <p class="description"><?php echo esc_html__('Used for validations that do not have their own message. Optional — leave blank to use the default message.', 'contentguard'); ?></p>
            </div>
        </section>

        <div class="contentguard-builder-actions">
            <button type="submit" class="button button-primary">
                <?php echo esc_html($isNew ? __('Save Rule', 'contentguard') : __('Update Rule', 'contentguard')); ?>
            </button>
            <a class="button" href="<?php echo esc_url($listUrl); ?>"><?php echo esc_html__('Back to rules', 'contentguard'); ?></a>
            <?php if (!$isNew) : ?>
                <a class="button" href="<?php echo esc_url($newUrl); ?>"><?php echo esc_html__('Add another rule', 'contentguard'); ?></a>
            <?php endif; ?>
        </div>
    </form>
</div>
