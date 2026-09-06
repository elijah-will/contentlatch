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
        $this->assertSame('Minimum length is not configured.', $result['message']);
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
        ?InMemoryRuleRepository $repository = null,
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
