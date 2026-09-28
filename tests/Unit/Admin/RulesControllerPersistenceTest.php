<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Admin;

use ContentLatch\Admin\RulesController;
use ContentLatch\Application\AdminNotice;
use ContentLatch\Application\RuleCommandService;
use ContentLatch\Application\RuleDocumentFactory;
use ContentLatch\Application\RuleDocumentValidator;
use ContentLatch\Infrastructure\WordPress\PostTypeRuleRepository;
use ContentLatch\Infrastructure\WordPress\RuleDocumentCodec;
use ContentLatch\Infrastructure\WordPress\RulePostType;
use ContentLatch\Tests\Support\WordPressLikeRulePostStore;
use PHPUnit\Framework\TestCase;

final class RulesControllerPersistenceTest extends TestCase
{
    public function testShowNewTagYesRequiresPageIdPersistsAndReloads(): void
    {
        $store = new WordPressLikeRulePostStore();
        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $controller = $this->controller($repository);

        $created = $controller->dispatch(RulesController::ACTION_SAVE, $this->showNewTagRequest('1'));

        $this->assertTrue($created['ok']);
        $this->assertSame('Rule added.', $created['message']);
        $this->assertSame('success', AdminNotice::queryArgs(true, $created['message'])['contentlatch_notice']);
        $this->assertSame(1, $created['rule_id']);
        $this->assertSame('1', $created['rule']['conditions'][0]['operand']);

        $reloaded = $repository->find((int) $created['rule_id']);
        $this->assertNotNull($reloaded);
        $this->assertSame('field_show_new_tag', $reloaded->conditions[0]->field->key);
        $this->assertSame('1', $reloaded->conditions[0]->operand);
        $this->assertSame('field_page_id', $reloaded->validations[0]->field->key);
        $this->assertSame('required', $reloaded->validations[0]->type);
        $this->assertSame(RulePostType::POST_TYPE, $store->posts[1]['type']);
        $this->assertTrue(RuleDocumentCodec::isDocument($store->meta[1][RulePostType::DOCUMENT_META_KEY]));
    }

    public function testSimpleRequiredRulePersistsAndReloads(): void
    {
        $store = new WordPressLikeRulePostStore();
        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $result = $this->controller($repository)->dispatch(
            RulesController::ACTION_SAVE,
            $this->simpleRequiredRequest()
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('Rule added.', $result['message']);
        $loaded = $repository->find((int) $result['rule_id']);
        $this->assertNotNull($loaded);
        $this->assertSame(array(), $loaded->conditions);
        $this->assertSame('field_page_id', $loaded->validations[0]->field->key);
    }

    public function testDifferentTrueFalseConditionPersistsAndReloads(): void
    {
        $request = $this->showNewTagRequest('1');
        $request['conditions'][0]['field_key'] = 'field_repair_upc';

        $store = new WordPressLikeRulePostStore();
        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $result = $this->controller($repository)->dispatch(RulesController::ACTION_SAVE, $request);

        $this->assertTrue($result['ok']);
        $loaded = $repository->find((int) $result['rule_id']);
        $this->assertNotNull($loaded);
        $this->assertSame('field_repair_upc', $loaded->conditions[0]->field->key);
        $this->assertSame('1', $loaded->conditions[0]->operand);
    }

    public function testEditPersistsAndReloads(): void
    {
        $store = new WordPressLikeRulePostStore();
        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $controller = $this->controller($repository);
        $created = $controller->dispatch(RulesController::ACTION_SAVE, $this->showNewTagRequest('1'));

        $edit = $this->showNewTagRequest('0');
        $edit['rule_id'] = (string) $created['rule_id'];
        $edit['name'] = 'Page ID is required when Show New Tag is No';
        $updated = $controller->dispatch(RulesController::ACTION_SAVE, $edit);

        $this->assertTrue($updated['ok']);
        $this->assertSame('Rule saved.', $updated['message']);
        $this->assertSame((int) $created['rule_id'], $updated['rule_id']);
        $reloaded = $repository->find((int) $updated['rule_id']);
        $this->assertNotNull($reloaded);
        $this->assertSame('0', $reloaded->conditions[0]->operand);
        $this->assertSame('Page ID is required when Show New Tag is No', $reloaded->name);
        $this->assertCount(1, $repository->findAll());
    }

    public function testWrongInsertedPostTypeIsCorrectedSoFindSucceeds(): void
    {
        $store = new WordPressLikeRulePostStore();
        $store->insertTypeOverride = 'product';
        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $result = $this->controller($repository)->dispatch(
            RulesController::ACTION_SAVE,
            $this->showNewTagRequest('1')
        );

        $this->assertTrue($result['ok']);
        $this->assertNotNull($repository->find((int) $result['rule_id']));
        $this->assertSame(RulePostType::POST_TYPE, $store->posts[1]['type']);
    }

    public function testUnchangedMetaWriteIsNotAPersistenceFailure(): void
    {
        $store = new WordPressLikeRulePostStore();
        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $controller = $this->controller($repository);
        $created = $controller->dispatch(RulesController::ACTION_SAVE, $this->showNewTagRequest('1'));
        $edit = $this->showNewTagRequest('1');
        $edit['rule_id'] = (string) $created['rule_id'];
        $edit['name'] = 'Page ID is required when Show New Tag is Yes';

        $updated = $controller->dispatch(RulesController::ACTION_SAVE, $edit);

        $this->assertTrue($updated['ok']);
        $this->assertGreaterThan(0, $store->unchangedMetaWrites);
        $this->assertNotNull($repository->find((int) $updated['rule_id']));
    }

    /**
     * @return array<string, mixed>
     */
    private function showNewTagRequest(string $operand): array
    {
        return array(
            '_wpnonce'         => 'ok',
            'name'             => 'Page ID is required when Show New Tag is Yes',
            'target_post_type' => 'product',
            'status'           => 'active',
            'severity'         => 'fail',
            'conditions'       => array(
                array(
                    'field_key' => 'field_show_new_tag',
                    'operator'  => 'equals',
                    'operand'   => $operand,
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => 'field_page_id',
                    'type'      => 'required',
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function simpleRequiredRequest(): array
    {
        return array(
            '_wpnonce'         => 'ok',
            'name'             => 'Page ID is required',
            'target_post_type' => 'product',
            'status'           => 'active',
            'severity'         => 'fail',
            'conditions'       => array(),
            'validations'      => array(
                array(
                    'field_key' => 'field_page_id',
                    'type'      => 'required',
                ),
            ),
        );
    }

    private function controller(PostTypeRuleRepository $repository): RulesController
    {
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('product' => 'Product'),
            static fn (): array => array(
                array('key' => 'field_show_new_tag', 'name' => 'show_new_tag', 'label' => 'Show New Tag', 'type' => 'true_false'),
                array('key' => 'field_page_id', 'name' => 'page_id', 'label' => 'Page ID', 'type' => 'text'),
                array('key' => 'field_repair_upc', 'name' => 'repair_upc', 'label' => 'Repair UPC', 'type' => 'true_false'),
            )
        );
        $commands = new RuleCommandService(
            $repository,
            static fn (): bool => true,
            static fn (string $nonce): bool => $nonce === 'ok'
        );

        return new RulesController(
            $repository,
            $commands,
            $factory,
            static fn (): bool => true,
            static fn (string $nonce): bool => $nonce === 'ok'
        );
    }
}
