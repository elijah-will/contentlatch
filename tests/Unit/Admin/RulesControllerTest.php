<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Admin\RuleEditorDraftStore;
use ContentGuard\Admin\RulesController;
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\RuleStatus;
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RulesControllerTest extends TestCase
{
    public function testUnauthorizedAndInvalidNonceAreRejected(): void
    {
        $denied = $this->controller(false, true)->dispatch(
            RulesController::ACTION_SAVE,
            $this->validRequest()
        );
        $this->assertFalse($denied['ok']);
        $this->assertSame('You are not allowed to manage ContentGuard rules.', $denied['message']);

        $badNonce = $this->controller(true, false)->dispatch(
            RulesController::ACTION_SAVE,
            $this->validRequest()
        );
        $this->assertFalse($badNonce['ok']);
        $this->assertSame('Invalid rule management nonce.', $badNonce['message']);
    }

    public function testValidCreateAndEditPreserveId(): void
    {
        $controller = $this->controller(true, true);
        $created = $controller->dispatch(RulesController::ACTION_SAVE, $this->validRequest());

        $this->assertTrue($created['ok']);
        $this->assertSame(1, $created['rule_id']);
        $this->assertSame('Rule added.', $created['message']);
        $this->assertSame('Sauce Products Must Have Ingredients', $created['rule']['name']);
        $this->assertCount(1, $created['rule']['conditions']);
        $this->assertCount(1, $created['rule']['validations']);

        $edit = $this->validRequest();
        $edit['id'] = '1';
        $edit['name'] = 'Updated sauces';
        $edit['severity'] = 'warning';
        $edit['conditions'][0]['operand'] = 'rub';
        $edit['validations'][0]['type'] = 'min_length';
        $edit['validations'][0]['min'] = '5';

        $updated = $controller->dispatch(RulesController::ACTION_SAVE, $edit);
        $this->assertTrue($updated['ok']);
        $this->assertSame(1, $updated['rule_id']);
        $this->assertSame('Rule saved.', $updated['message']);
        $this->assertSame('Updated sauces', $updated['rule']['name']);
        $this->assertSame('warning', $updated['rule']['severity']);
        $this->assertSame('rub', $updated['rule']['conditions'][0]['operand']);
        $this->assertSame('min_length', $updated['rule']['validations'][0]['type']);
        $this->assertSame(5, $updated['rule']['validations'][0]['params']['min']);
    }

    public function testUpdateDoesNotCreateADuplicate(): void
    {
        $repository = new InMemoryRuleRepository(array(), RuleDocumentValidator::v1());
        $controller = $this->controller(true, true, $repository);
        $controller->dispatch(RulesController::ACTION_SAVE, $this->validRequest());

        $edit = $this->validRequest();
        $edit['id'] = '1';
        $edit['name'] = 'Renamed';
        $controller->dispatch(RulesController::ACTION_SAVE, $edit);

        $this->assertCount(1, $repository->findAll());
        $this->assertSame('Renamed', $repository->find(1)?->name);
    }

    public function testActivationTogglesParticipation(): void
    {
        $repository = new InMemoryRuleRepository(array(), RuleDocumentValidator::v1());
        $controller = $this->controller(true, true, $repository);
        $controller->dispatch(RulesController::ACTION_SAVE, $this->validRequest());

        $this->assertCount(1, $repository->findActiveForPostType('product'));

        $deactivated = $controller->dispatch(
            RulesController::ACTION_STATUS,
            array(
                '_wpnonce' => 'ok',
                'rule_id'  => '1',
                'status'   => RuleStatus::Inactive->value,
            )
        );
        $this->assertTrue($deactivated['ok']);
        $this->assertCount(0, $repository->findActiveForPostType('product'));
        $this->assertSame(RuleStatus::Inactive, $repository->find(1)?->status);

        $activated = $controller->dispatch(
            RulesController::ACTION_STATUS,
            array(
                '_wpnonce' => 'ok',
                'rule_id'  => '1',
                'status'   => RuleStatus::Active->value,
            )
        );
        $this->assertTrue($activated['ok']);
        $this->assertCount(1, $repository->findActiveForPostType('product'));
    }

    public function testDeleteRemovesRule(): void
    {
        $repository = new InMemoryRuleRepository(array(), RuleDocumentValidator::v1());
        $controller = $this->controller(true, true, $repository);
        $controller->dispatch(RulesController::ACTION_SAVE, $this->validRequest());

        $deleted = $controller->dispatch(
            RulesController::ACTION_DELETE,
            array('_wpnonce' => 'ok', 'rule_id' => '1')
        );
        $this->assertTrue($deleted['ok']);
        $this->assertNull($repository->find(1));
    }

    public function testInvalidSavePreservesSubmittedDraftAndStillValidates(): void
    {
        $store = array();
        $drafts = new RuleEditorDraftStore(
            static function (string $key, mixed $value, int $ttl) use (&$store): void {
                $store[$key] = $value;
            },
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
            static function (string $key) use (&$store): void {
                unset($store[$key]);
            },
            static fn (): int => 3
        );

        $controller = $this->controller(true, true, null, $drafts);
        $request = $this->validRequest();
        $request['name'] = 'Keep this rule name';
        $request['severity'] = 'warning';
        $request['status'] = 'inactive';
        $request['message'] = '';
        $request['conditions'][0]['operand'] = 'sauce';
        $request['validations'][0]['type'] = 'min_length';

        $result = $controller->dispatch(RulesController::ACTION_SAVE, $request);

        $this->assertFalse($result['ok']);
        $this->assertSame('We could not add this rule. Minimum length is not configured.', $result['message']);
        $this->assertSame(0, $result['rule_id']);
        $this->assertSame('Keep this rule name', $result['draft']['name']);
        $this->assertSame('warning', $result['draft']['severity']);
        $this->assertSame('inactive', $result['draft']['status']);
        $this->assertSame('sauce', $result['draft']['conditions'][0]['operand']);
        $this->assertSame('min_length', $result['draft']['validations'][0]['type']);
        $this->assertSame($result['draft'], $drafts->get(0));

        $request['validations'][0]['min'] = '50';
        $saved = $controller->dispatch(RulesController::ACTION_SAVE, $request);
        $this->assertTrue($saved['ok']);
        $this->assertSame(50, $saved['rule']['validations'][0]['params']['min']);
        $this->assertNull($drafts->get(0));
    }

    public function testInvalidUpdatePreservesSubmittedDraft(): void
    {
        $store = array();
        $drafts = new RuleEditorDraftStore(
            static function (string $key, mixed $value, int $ttl) use (&$store): void {
                $store[$key] = $value;
            },
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
            static function (string $key) use (&$store): void {
                unset($store[$key]);
            },
            static fn (): int => 3
        );

        $controller = $this->controller(true, true, null, $drafts);
        $created = $controller->dispatch(RulesController::ACTION_SAVE, $this->validRequest());
        $this->assertTrue($created['ok']);

        $request = $this->validRequest();
        $request['rule_id'] = '1';
        $request['name'] = 'Keep the updated name';
        $request['validations'][0]['type'] = 'min_length';
        unset($request['validations'][0]['min']);

        $result = $controller->dispatch(RulesController::ACTION_SAVE, $request);

        $this->assertFalse($result['ok']);
        $this->assertSame('We could not save this rule. Minimum length is not configured.', $result['message']);
        $this->assertSame(1, $result['rule_id']);
        $this->assertSame('Keep the updated name', $result['draft']['name']);
        $this->assertSame('min_length', $result['draft']['validations'][0]['type']);
        $this->assertSame($result['draft'], $drafts->get(1));
        $this->assertStringContainsString(
            '#contentguard-rule-notice',
            \ContentGuard\Application\AdminNotice::appendTarget(
                'admin.php?page=contentguard&rule=1',
                false
            )
        );
    }

    public function testEmptyCustomMessageDoesNotBlockAValidSave(): void
    {
        $request = $this->validRequest();
        $request['message'] = '';
        $request['validations'][0]['message'] = '';

        $result = $this->controller(true, true)->dispatch(RulesController::ACTION_SAVE, $request);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['rule']['validations'][0]['message']);
    }

    public function testInvalidFieldAndPostTypeAreRejectedEvenWhenAuthorized(): void
    {
        $controller = $this->controller(true, true);

        $badType = $this->validRequest();
        $badType['post_type'] = 'secret';
        $this->assertFalse($controller->dispatch(RulesController::ACTION_SAVE, $badType)['ok']);

        $badField = $this->validRequest();
        $badField['validations'][0]['field_key'] = 'field_unknown';
        $this->assertFalse($controller->dispatch(RulesController::ACTION_SAVE, $badField)['ok']);
    }

    public function testFieldsAjaxRejectsUnknownPostType(): void
    {
        $controller = $this->controller(true, true);
        $denied = $controller->dispatch(
            RulesController::ACTION_FIELDS,
            array('_wpnonce' => 'ok', 'post_type' => 'secret')
        );
        $this->assertFalse($denied['ok']);

        $ok = $controller->dispatch(
            RulesController::ACTION_FIELDS,
            array('_wpnonce' => 'ok', 'post_type' => 'product')
        );
        $this->assertTrue($ok['ok']);
        $this->assertSame('field_type', $ok['fields'][0]['key']);
    }

    public function testShowNewTagYesRequiresPageId(): void
    {
        $repository = new InMemoryRuleRepository(array(), RuleDocumentValidator::v1());
        $result = $this->controller(true, true, $repository)->dispatch(
            RulesController::ACTION_SAVE,
            $this->showNewTagRequest('1')
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('Rule added.', $result['message']);
        $this->assertSame('success', \ContentGuard\Application\AdminNotice::queryArgs(true, $result['message'])['contentguard_notice']);
        $this->assertSame('1', $result['rule']['conditions'][0]['operand']);
        $this->assertSame('field_show_new_tag', $result['rule']['conditions'][0]['field']['key']);
        $this->assertSame('field_page_id', $result['rule']['validations'][0]['field']['key']);
        $this->assertSame('required', $result['rule']['validations'][0]['type']);
        $this->assertNotNull($repository->find((int) $result['rule_id']));
        $this->assertCount(1, $repository->findAll());
    }

    public function testShowNewTagNoRequiresPageId(): void
    {
        $result = $this->controller(true, true)->dispatch(
            RulesController::ACTION_SAVE,
            $this->showNewTagRequest('0')
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('Rule added.', $result['message']);
        $this->assertSame('success', \ContentGuard\Application\AdminNotice::queryArgs(true, $result['message'])['contentguard_notice']);
        $this->assertSame('0', $result['rule']['conditions'][0]['operand']);
        $this->assertSame('field_page_id', $result['rule']['validations'][0]['field']['key']);
    }

    public function testTrueFalseYesWithAnotherTextRequired(): void
    {
        $request = $this->showNewTagRequest('1');
        $request['validations'][0]['field_key'] = 'field_ingredients';

        $result = $this->controller(true, true)->dispatch(RulesController::ACTION_SAVE, $request);

        $this->assertTrue($result['ok']);
        $this->assertSame('field_ingredients', $result['rule']['validations'][0]['field']['key']);
    }

    public function testAnotherTrueFalseYesRequiresPageId(): void
    {
        $request = $this->showNewTagRequest('1');
        $request['conditions'][0]['field_key'] = 'field_repair_upc';

        $result = $this->controller(true, true)->dispatch(RulesController::ACTION_SAVE, $request);

        $this->assertTrue($result['ok']);
        $this->assertSame('field_repair_upc', $result['rule']['conditions'][0]['field']['key']);
        $this->assertSame('1', $result['rule']['conditions'][0]['operand']);
    }

    public function testUnreadAfterSaveRemainsARealCreateError(): void
    {
        $store = array();
        $drafts = new RuleEditorDraftStore(
            static function (string $key, mixed $value, int $ttl) use (&$store): void {
                $store[$key] = $value;
            },
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
            static function (string $key) use (&$store): void {
                unset($store[$key]);
            },
            static fn (): int => 3
        );

        $inner = new InMemoryRuleRepository(array(), RuleDocumentValidator::v1());
        $repository = new class ($inner) implements \ContentGuard\Application\RuleRepositoryInterface {
            public function __construct(private InMemoryRuleRepository $inner)
            {
            }

            public function findActiveForPostType(string $postType): array
            {
                return $this->inner->findActiveForPostType($postType);
            }

            public function findActivePostTypes(): array
            {
                return $this->inner->findActivePostTypes();
            }

            public function find(int|string $id): ?\ContentGuard\Domain\Rule
            {
                return null;
            }

            public function findAll(): array
            {
                return $this->inner->findAll();
            }

            public function save(\ContentGuard\Domain\Rule $rule): \ContentGuard\Domain\Rule
            {
                return $this->inner->save($rule);
            }

            public function delete(int|string $id): bool
            {
                return $this->inner->delete($id);
            }
        };

        $result = $this->controller(true, true, $repository, $drafts)->dispatch(
            RulesController::ACTION_SAVE,
            $this->showNewTagRequest('1')
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(
            'We could not add this rule. The rule could not be read after saving. Your entered values have been preserved so you can try again.',
            $result['message']
        );
        $this->assertStringNotContainsString('Rule added.', $result['message']);
        $notice = \ContentGuard\Application\AdminNotice::queryArgs(false, $result['message']);
        $this->assertSame('error', $notice['contentguard_notice']);
        $this->assertSame('1', $result['draft']['conditions'][0]['operand']);
        $this->assertSame('field_page_id', $result['draft']['validations'][0]['field_key']);
        $this->assertNotNull($inner->find(1));
        $this->assertSame($result['draft'], $drafts->get(0));
    }

    public function testContradictoryEmptyRequiredUsesAHumanMessage(): void
    {
        $request = $this->showNewTagRequest('1');
        $request['conditions'][0]['field_key'] = 'field_page_id';
        $request['conditions'][0]['operator'] = 'is_empty';
        unset($request['conditions'][0]['operand']);

        $result = $this->controller(true, true)->dispatch(RulesController::ACTION_SAVE, $request);

        $this->assertFalse($result['ok']);
        $this->assertSame(
            'We could not add this rule. ' . \ContentGuard\Application\RuleDocumentValidator::MSG_EMPTY_AND_REQUIRED,
            $result['message']
        );
        $this->assertSame('error', \ContentGuard\Application\AdminNotice::queryArgs(false, $result['message'])['contentguard_notice']);
        $this->assertStringNotContainsString('InvalidRuleException', $result['message']);
        $this->assertStringNotContainsString('JSON', $result['message']);
    }

    public function testFailedCreateNeverUsesASuccessNotice(): void
    {
        $request = $this->showNewTagRequest('1');
        $request['name'] = '';

        $result = $this->controller(true, true)->dispatch(RulesController::ACTION_SAVE, $request);

        $this->assertFalse($result['ok']);
        $this->assertStringStartsWith('We could not add this rule.', $result['message']);
        $this->assertStringNotContainsString('Rule added.', $result['message']);
        $notice = \ContentGuard\Application\AdminNotice::queryArgs(false, $result['message']);
        $this->assertSame('error', $notice['contentguard_notice']);
        $this->assertSame('notice notice-error is-dismissible', \ContentGuard\Application\AdminNotice::cssClass('error'));
        $this->assertStringNotContainsString('notice-success', \ContentGuard\Application\AdminNotice::cssClass('error'));
    }

    public function testTrueFalseRequiredRuleCanBeCreated(): void
    {
        $repository = new InMemoryRuleRepository(array(), RuleDocumentValidator::v1());
        $result = $this->controller(true, true, $repository)->dispatch(
            RulesController::ACTION_SAVE,
            $this->trueFalseRequest()
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['rule_id']);
        $this->assertSame('Repair UPC is required', $result['rule']['name']);
        $this->assertSame('field_repair_upc', $result['rule']['validations'][0]['field']['key']);
        $this->assertSame('required', $result['rule']['validations'][0]['type']);
        $this->assertNotNull($repository->find(1));
        $this->assertCount(1, $repository->findAll());
    }

    public function testUnknownRuleIdOnCreateDoesNotCreateAnOrphanAndPreservesDraft(): void
    {
        $store = array();
        $drafts = new RuleEditorDraftStore(
            static function (string $key, mixed $value, int $ttl) use (&$store): void {
                $store[$key] = $value;
            },
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
            static function (string $key) use (&$store): void {
                unset($store[$key]);
            },
            static fn (): int => 3
        );

        $repository = new InMemoryRuleRepository(array(), RuleDocumentValidator::v1());
        $request = $this->trueFalseRequest();
        $request['id'] = '5';

        $result = $this->controller(true, true, $repository, $drafts)->dispatch(
            RulesController::ACTION_SAVE,
            $request
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(5, $result['rule_id']);
        $this->assertStringContainsString('could not save this rule', $result['message']);
        $this->assertStringContainsString('no longer available', $result['message']);
        $this->assertStringNotContainsString('Rule added.', $result['message']);
        $this->assertSame('', $result['draft']['id']);
        $this->assertSame('Repair UPC is required', $result['draft']['name']);
        $this->assertSame('field_repair_upc', $result['draft']['validations'][0]['field_key']);
        $this->assertNull($repository->find(5));
        $this->assertSame(array(), $repository->findAll());
        $this->assertSame($result['draft'], $drafts->get(0));
        $this->assertSame($result['draft'], $drafts->get(5));
    }

    public function testSubmittedRuleIdPrefersRuleIdAndIgnoresEmptyId(): void
    {
        $this->assertSame(0, RulesController::submittedRuleId(array()));
        $this->assertSame(0, RulesController::submittedRuleId(array('id' => '')));
        $this->assertSame(12, RulesController::submittedRuleId(array('id' => '12')));
        $this->assertSame(9, RulesController::submittedRuleId(array('rule_id' => '9', 'id' => '12')));
    }

    public function testSeededRuleCanBeLoadedById(): void
    {
        $existing = RuleFactory::rule(array('id' => 9, 'name' => 'Existing'));
        $repository = new InMemoryRuleRepository(array($existing), RuleDocumentValidator::v1());

        $this->assertSame('Existing', $repository->find(9)?->name);
        $this->assertSame(9, $repository->find(9)?->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function showNewTagRequest(string $operand): array
    {
        return array(
            '_wpnonce'   => 'ok',
            'name'       => 'Page ID is required when Show New Tag is Yes',
            'post_type'  => 'product',
            'status'     => 'active',
            'severity'   => 'fail',
            'conditions' => array(
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
                    'min'       => '',
                    'max'       => '',
                    'values'    => '',
                    'message'   => '',
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function trueFalseRequest(): array
    {
        return array(
            '_wpnonce'   => 'ok',
            'name'       => 'Repair UPC is required',
            'post_type'  => 'product',
            'status'     => 'active',
            'severity'   => 'fail',
            'conditions' => array(),
            'validations' => array(
                array(
                    'field_key' => 'field_repair_upc',
                    'type'      => 'required',
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validRequest(): array
    {
        return array(
            '_wpnonce'  => 'ok',
            'name'      => 'Sauce Products Must Have Ingredients',
            'post_type' => 'product',
            'status'    => 'active',
            'severity'  => 'fail',
            'conditions' => array(
                array(
                    'field_key' => 'field_type',
                    'operator'  => 'equals',
                    'operand'   => 'sauce',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => 'field_ingredients',
                    'type'      => 'required',
                ),
            ),
        );
    }

    private function controller(
        bool $canManage,
        bool $validNonce,
        ?RuleRepositoryInterface $repository = null,
        ?RuleEditorDraftStore $drafts = null,
    ): RulesController {
        $repository ??= new InMemoryRuleRepository(array(), RuleDocumentValidator::v1());
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('product' => 'Product'),
            static fn (): array => array(
                array(
                    'key'   => 'field_type',
                    'name'  => 'product_type',
                    'label' => 'Product Type',
                    'type'  => 'select',
                ),
                array(
                    'key'   => 'field_ingredients',
                    'name'  => 'ingredients',
                    'label' => 'Ingredients',
                    'type'  => 'textarea',
                ),
                array(
                    'key'   => 'field_repair_upc',
                    'name'  => 'repair_upc',
                    'label' => 'Repair UPC',
                    'type'  => 'true_false',
                ),
                array(
                    'key'   => 'field_show_new_tag',
                    'name'  => 'show_new_tag',
                    'label' => 'Show New Tag',
                    'type'  => 'true_false',
                ),
                array(
                    'key'   => 'field_page_id',
                    'name'  => 'page_id',
                    'label' => 'Page ID',
                    'type'  => 'text',
                ),
            )
        );
        $commands = new RuleCommandService(
            $repository,
            static fn (): bool => $canManage,
            static fn (string $nonce): bool => $validNonce && $nonce === 'ok'
        );

        return new RulesController(
            $repository,
            $commands,
            $factory,
            static fn (): bool => $canManage,
            static fn (string $nonce): bool => $validNonce && $nonce === 'ok',
            $drafts
        );
    }
}
