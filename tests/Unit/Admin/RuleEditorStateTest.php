<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Admin\RuleEditorState;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleEditorStateTest extends TestCase
{
    public function testSubmittedStatePreservesInvalidBuilderRows(): void
    {
        $input = array(
            'id'         => '',
            'name'       => 'Sauce ingredients',
            'post_type'  => 'product',
            'severity'   => 'warning',
            'status'     => 'inactive',
            'message'    => '',
            'conditions' => array(
                array(
                    'id'        => 'c1',
                    'field_key' => 'field_type',
                    'operator'  => 'equals',
                    'operand'   => 'sauce',
                ),
            ),
            'validations' => array(
                array(
                    'id'        => 'v1',
                    'field_key' => 'field_ingredients',
                    'type'      => 'min_length',
                    'min'       => '',
                    'message'   => '',
                ),
            ),
        );

        $state = RuleEditorState::fromSubmitted($input);

        $this->assertTrue($state->isNew());
        $this->assertSame('Sauce ingredients', $state->name);
        $this->assertSame('product', $state->postType);
        $this->assertSame(RuleSeverity::Warning->value, $state->severity);
        $this->assertSame(RuleStatus::Inactive->value, $state->status);
        $this->assertSame('', $state->message);
        $this->assertSame('sauce', $state->conditions[0]['operand']);
        $this->assertSame('min_length', $state->validations[0]['type']);
        $this->assertSame('', $state->validations[0]['min']);
        $this->assertSame('', $state->validations[0]['message']);
    }

    public function testHydratePrefersDraftOverSavedRule(): void
    {
        $rule = RuleFactory::rule(array(
            'id'       => 12,
            'name'     => 'Saved name',
            'severity' => RuleSeverity::Fail,
        ));

        $state = RuleEditorState::hydrate(
            array(
                'id'          => '12',
                'name'        => 'Unsaved name',
                'post_type'   => 'product',
                'severity'    => 'warning',
                'status'      => 'active',
                'message'     => 'Please add ingredients.',
                'conditions'  => array(),
                'validations' => array(
                    array(
                        'field_key' => 'field_ingredients',
                        'type'      => 'required',
                        'message'   => 'Please add ingredients.',
                    ),
                ),
            ),
            $rule
        );

        $this->assertFalse($state->isNew());
        $this->assertSame(12, $state->id);
        $this->assertSame('Unsaved name', $state->name);
        $this->assertSame('warning', $state->severity);
        $this->assertSame('Please add ingredients.', $state->message);
        $this->assertSame('required', $state->validations[0]['type']);
    }

    public function testFromRuleMapsSavedDocument(): void
    {
        $rule = RuleFactory::rule(array(
            'id'          => 4,
            'name'        => 'Length check',
            'validations' => array(
                RuleFactory::validation(array(
                    'type'    => 'min_length',
                    'params'  => array('min' => 50),
                    'message' => 'Need more text.',
                )),
            ),
        ));

        $state = RuleEditorState::fromRule($rule);

        $this->assertSame('Length check', $state->name);
        $this->assertSame('50', $state->validations[0]['min']);
        $this->assertSame('Need more text.', $state->message);
    }

    public function testFromRuleUsesRuleMessageWhenThereAreNoValidations(): void
    {
        $rule = RuleFactory::rule(array(
            'name'        => 'Avoid healthy',
            'conditions'  => array(
                RuleFactory::condition(array(
                    'field'    => RuleFactory::field('content', 'post_content', 'Content'),
                    'operator' => 'contains',
                    'operand'  => 'healthy',
                )),
            ),
            'validations' => array(),
            'message'     => 'Please avoid the term "healthy" in recipe content.',
        ));

        $state = RuleEditorState::fromRule($rule);

        $this->assertSame('Please avoid the term "healthy" in recipe content.', $state->message);
        $this->assertSame(array(), $state->validations);
        $this->assertSame('contains', $state->conditions[0]['operator']);
        $this->assertSame('healthy', $state->conditions[0]['operand']);
    }

    public function testSnapshotOmitsNonceAndUnknownKeys(): void
    {
        $snapshot = RuleEditorState::snapshot(array(
            '_wpnonce'   => 'secret',
            'action'     => 'contentguard_save_rule',
            'name'       => 'Kept',
            'post_type'  => 'product',
            'extra'      => 'drop-me',
            'validations' => array(
                array(
                    'field_key' => 'field_ingredients',
                    'type'      => 'max_length',
                    'max'       => '50',
                ),
            ),
        ));

        $this->assertArrayNotHasKey('_wpnonce', $snapshot);
        $this->assertArrayNotHasKey('action', $snapshot);
        $this->assertArrayNotHasKey('extra', $snapshot);
        $this->assertSame('Kept', $snapshot['name']);
        $this->assertSame('50', $snapshot['validations'][0]['max']);
        $this->assertSame(
            'product',
            RuleEditorState::snapshot(array('target_post_type' => 'product'))['post_type']
        );
    }
}
