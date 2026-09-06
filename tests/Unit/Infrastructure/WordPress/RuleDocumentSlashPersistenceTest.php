<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\WordPress;

use ContentGuard\Application\AdminNotice;
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Admin\RulesController;
use ContentGuard\Infrastructure\WordPress\PostTypeRuleRepository;
use ContentGuard\Infrastructure\WordPress\RuleDocumentCodec;
use ContentGuard\Infrastructure\WordPress\RulePostType;
use ContentGuard\Tests\Support\RuleFactory;
use ContentGuard\Tests\Support\WordPressLikeRulePostStore;
use PHPUnit\Framework\TestCase;

final class RuleDocumentSlashPersistenceTest extends TestCase
{
    public function testQuotedLabelRulePersistsValidCanonicalMetaAndReloads(): void
    {
        $store = new WordPressLikeRulePostStore();
        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $created = $this->controller($repository)->dispatch(
            RulesController::ACTION_SAVE,
            $this->quotedShowNewTagRequest()
        );

        $this->assertTrue($created['ok']);
        $this->assertSame('Rule added.', $created['message']);
        $this->assertSame('success', AdminNotice::queryArgs(true, $created['message'])['contentguard_notice']);

        $meta = $store->meta[1][RulePostType::DOCUMENT_META_KEY];
        $this->assertTrue(RuleDocumentCodec::isDocument($meta));
        $decoded = json_decode($meta, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Show "New" Tag', $decoded['conditions'][0]['field']['label']);
        $this->assertSame('1', $decoded['conditions'][0]['operand']);
        $this->assertSame('field_6a05f9e8420ea', $decoded['validations'][0]['field']['key']);
        $this->assertSame('PowerReviews Page ID', $decoded['validations'][0]['field']['label']);
        $this->assertSame('required', $decoded['validations'][0]['type']);
        $this->assertStringContainsString('Show \\"New\\" Tag', $meta);

        $loaded = $repository->find(1);
        $this->assertNotNull($loaded);
        $this->assertSame('Show "New" Tag', $loaded->conditions[0]->field->label);
        $this->assertSame('1', $loaded->conditions[0]->operand);
        $this->assertSame('PowerReviews Page ID', $loaded->validations[0]->field->label);
        $this->assertSame('required', $loaded->validations[0]->type);
    }

    public function testBackslashLabelRulePersistsAndReloads(): void
    {
        $request = $this->quotedShowNewTagRequest();
        $request['name'] = 'Path label rule';
        $request['conditions'][0]['field_key'] = 'field_path';

        $store = new WordPressLikeRulePostStore();
        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $created = $this->controller($repository)->dispatch(RulesController::ACTION_SAVE, $request);

        $this->assertTrue($created['ok']);
        $meta = $store->meta[1][RulePostType::DOCUMENT_META_KEY];
        $this->assertTrue(RuleDocumentCodec::isDocument($meta));
        $this->assertSame('C:\\Temp\\New', json_decode($meta, true)['conditions'][0]['field']['label']);
        $this->assertSame('C:\\Temp\\New', $repository->find(1)?->conditions[0]->field->label);
    }

    public function testUnquotedRuleStillPersistsAndReloads(): void
    {
        $store = new WordPressLikeRulePostStore();
        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $created = $this->controller($repository)->dispatch(
            RulesController::ACTION_SAVE,
            $this->simpleRequiredRequest()
        );

        $this->assertTrue($created['ok']);
        $this->assertTrue(RuleDocumentCodec::isDocument($store->meta[1][RulePostType::DOCUMENT_META_KEY]));
        $loaded = $repository->find(1);
        $this->assertNotNull($loaded);
        $this->assertSame(array(), $loaded->conditions);
        $this->assertSame('PowerReviews Page ID', $loaded->validations[0]->field->label);
    }

    public function testQuotedLabelRuleCanBeEditedAndReadBack(): void
    {
        $store = new WordPressLikeRulePostStore();
        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $controller = $this->controller($repository);
        $created = $controller->dispatch(RulesController::ACTION_SAVE, $this->quotedShowNewTagRequest());

        $edit = $this->quotedShowNewTagRequest();
        $edit['rule_id'] = (string) $created['rule_id'];
        $edit['name'] = 'New products still need a Page ID';
        $updated = $controller->dispatch(RulesController::ACTION_SAVE, $edit);

        $this->assertTrue($updated['ok']);
        $this->assertSame('Rule saved.', $updated['message']);
        $reloaded = $repository->find((int) $updated['rule_id']);
        $this->assertNotNull($reloaded);
        $this->assertSame('New products still need a Page ID', $reloaded->name);
        $this->assertSame('Show "New" Tag', $reloaded->conditions[0]->field->label);
        $this->assertSame('1', $reloaded->conditions[0]->operand);
        $this->assertTrue(RuleDocumentCodec::isDocument($store->meta[1][RulePostType::DOCUMENT_META_KEY]));
        $this->assertCount(1, $repository->findAll());
    }

    /**
     * @dataProvider specialDocumentStringProvider
     */
    public function testSpecialDocumentStringsSurviveWordPressMetaPersistence(string $value): void
    {
        $store = new WordPressLikeRulePostStore();
        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $saved = $repository->save(
            RuleFactory::rule(
                array(
                    'id'         => '',
                    'name'       => $value,
                    'conditions' => array(
                        RuleFactory::condition(array(
                            'field'    => RuleFactory::field('field_special', 'special', $value),
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
            )
        );

        $id = (int) $saved->id;
        $meta = $store->meta[$id][RulePostType::DOCUMENT_META_KEY];
        $this->assertTrue(RuleDocumentCodec::isDocument($meta));
        $this->assertSame($meta, RuleDocumentCodec::fromRawStorage($meta));
        $this->assertSame($meta, stripslashes(RuleDocumentCodec::forStoredMeta($meta)));

        $decoded = json_decode($meta, true);
        $this->assertIsArray($decoded);
        $this->assertSame($value, $decoded['name']);
        $this->assertSame($value, $decoded['conditions'][0]['field']['label']);
        $this->assertSame('1', $decoded['conditions'][0]['operand']);

        $loaded = $repository->find($id);
        $this->assertNotNull($loaded);
        $this->assertSame($value, $loaded->name);
        $this->assertSame($value, $loaded->conditions[0]->field->label);
        $this->assertSame('1', $loaded->conditions[0]->operand);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function specialDocumentStringProvider(): array
    {
        return array(
            'double quote'    => array('Show "New" Tag'),
            'apostrophe'      => array("O'Brien's Tag"),
            'backslash'       => array('C:\\Temp\\New'),
            'newline and tab' => array("Show\nNew\tTag"),
            'unicode'         => array('Mostrar «Nuevo» タグ 🆕'),
        );
    }

    public function testLegacyBrokenMetaFallsBackToValidPostContentAndHealsMeta(): void
    {
        $json = $this->quotedShowNewTagJson(13135);
        $store = new WordPressLikeRulePostStore();
        $store->seedRaw(
            13135,
            'New Products need Page ID',
            'publish',
            $json,
            'product',
            stripslashes($json)
        );

        $this->assertFalse(RuleDocumentCodec::isDocument($store->meta[13135][RulePostType::DOCUMENT_META_KEY]));
        $this->assertTrue(RuleDocumentCodec::isDocument($store->posts[13135]['content']));

        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $loaded = $repository->find(13135);

        $this->assertNotNull($loaded);
        $this->assertSame(13135, $loaded->id);
        $this->assertSame('Show "New" Tag', $loaded->conditions[0]->field->label);
        $this->assertSame('1', $loaded->conditions[0]->operand);
        $this->assertSame('PowerReviews Page ID', $loaded->validations[0]->field->label);
        $this->assertSame(1, $store->healedMetaWrites);
        $this->assertTrue(RuleDocumentCodec::isDocument($store->meta[13135][RulePostType::DOCUMENT_META_KEY]));
        $this->assertSame(
            'Show "New" Tag',
            json_decode($store->meta[13135][RulePostType::DOCUMENT_META_KEY], true)['conditions'][0]['field']['label']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function quotedShowNewTagRequest(): array
    {
        return array(
            '_wpnonce'         => 'ok',
            'name'             => 'New Products need Page ID',
            'target_post_type' => 'product',
            'status'           => 'active',
            'severity'         => 'fail',
            'conditions'       => array(
                array(
                    'field_key' => 'field_683097e0dc6d2',
                    'operator'  => 'equals',
                    'operand'   => '1',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => 'field_6a05f9e8420ea',
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
            'name'             => 'PowerReviews Page ID is required',
            'target_post_type' => 'product',
            'status'           => 'active',
            'severity'         => 'fail',
            'conditions'       => array(),
            'validations'      => array(
                array(
                    'field_key' => 'field_6a05f9e8420ea',
                    'type'      => 'required',
                ),
            ),
        );
    }

    private function quotedShowNewTagJson(int $id): string
    {
        $json = json_encode(
            RuleFactory::rule(
                array(
                    'id'         => $id,
                    'name'       => 'New Products need Page ID',
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
            )->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $this->assertIsString($json);

        return $json;
    }

    private function controller(PostTypeRuleRepository $repository): RulesController
    {
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('product' => 'Product'),
            static fn (): array => array(
                array('key' => 'field_683097e0dc6d2', 'name' => 'show_new_tag', 'label' => 'Show "New" Tag', 'type' => 'true_false'),
                array('key' => 'field_6a05f9e8420ea', 'name' => 'pr_page_id', 'label' => 'PowerReviews Page ID', 'type' => 'text'),
                array('key' => 'field_path', 'name' => 'path', 'label' => 'C:\\Temp\\New', 'type' => 'true_false'),
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
