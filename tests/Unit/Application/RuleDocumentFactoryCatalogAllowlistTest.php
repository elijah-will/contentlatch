<?php
/**
 * Phase 11D: Rule Builder allowlists by catalog membership, not field_*.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\Integration\CompositeFieldCatalog;
use ContentGuard\Application\Integration\CompositeValueProvider;
use ContentGuard\Application\Integration\FieldCatalog;
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Application\RulePresentation;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\FieldRef;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\AcfIntegration;
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
use ContentGuard\Tests\Support\AcfCloneFixtures;
use ContentGuard\Tests\Support\AcfFlexibleFixtures;
use ContentGuard\Tests\Support\AcfRepeaterFixtures;
use ContentGuard\Tests\Support\FakeIntegration;
use ContentGuard\Tests\Support\InMemoryAuditLock;
use ContentGuard\Tests\Support\InMemoryAuditPostScanner;
use ContentGuard\Tests\Support\InMemoryAuditStore;
use PHPUnit\Framework\TestCase;

final class RuleDocumentFactoryCatalogAllowlistTest extends TestCase
{
    public function testAcfFieldPrefixStillPersistsThroughCatalogMembership(): void
    {
        $rule = $this->acfFactory()->fromAdminInput(array(
            'name'        => 'Ingredients required',
            'post_type'   => 'product',
            'validations' => array(
                array(
                    'field_key' => 'field_ingredients',
                    'type'      => 'required',
                ),
            ),
        ));

        $this->assertSame(1, $rule->schemaVersion);
        $this->assertSame('field_ingredients', $rule->validations[0]->field->key);
        $this->assertSame('field_ingredients', $rule->validations[0]->field->resolutionId());
        $this->assertArrayNotHasKey('integration', $rule->validations[0]->field->toArray());
        $this->assertStringNotContainsString('acf:', $rule->validations[0]->field->resolutionId());
    }

    public function testFakeTitleAndNumberPersistWithoutIntegrationMetadata(): void
    {
        $factory = $this->compositeFactory();

        $title = $factory->fromAdminInput($this->fakeRequiredInput(FakeIntegration::TITLE));
        $this->assertSame(FakeIntegration::TITLE, $title->validations[0]->field->key);
        $this->assertSame(FakeIntegration::TITLE, $title->validations[0]->field->name);
        $this->assertSame('Test Title', $title->validations[0]->field->label);
        $this->assertSame(array(), $title->validations[0]->field->path);
        $this->assertSame('', $title->validations[0]->field->container);
        $this->assertArrayNotHasKey('integration', $title->validations[0]->field->toArray());
        $this->assertArrayNotHasKey('clone', $title->validations[0]->field->toArray());
        $this->assertStringNotContainsString('acf:', json_encode($title->toArray()) ?: '');

        $number = $factory->fromAdminInput(array(
            'name'       => 'When test number',
            'post_type'  => 'page',
            'conditions' => array(
                array(
                    'field_key' => FakeIntegration::NUMBER,
                    'operator'  => 'greater_than',
                    'operand'   => '3',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => FakeIntegration::TITLE,
                    'type'      => 'required',
                ),
            ),
        ));
        $this->assertSame(FakeIntegration::NUMBER, $number->conditions[0]->field->key);
        $this->assertSame('greater_than', $number->conditions[0]->operator);
        $this->assertSame('3', $number->conditions[0]->operand);
        $this->assertSame(1, $number->schemaVersion);
        $this->assertSame('Test Number is greater than 3', RulePresentation::conditionsSummary($number));
        $this->assertSame('Test Title is required', RulePresentation::validationsSummary($number));
    }

    public function testArbitraryUnknownFieldIsRejectedEvenWhenItLooksLikeAcf(): void
    {
        $factory = $this->compositeFactory();

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Unsupported field.');
        $factory->fromAdminInput(array(
            'name'        => 'Forged',
            'post_type'   => 'page',
            'validations' => array(
                array(
                    'field_key' => 'field_whatever',
                    'type'      => 'required',
                ),
            ),
        ));
    }

    public function testFieldFromAnotherPostTypeIsRejected(): void
    {
        $factory = $this->compositeFactory();

        try {
            $factory->fromAdminInput(array(
                'name'        => 'Wrong type fake',
                'post_type'   => 'product',
                'validations' => array(
                    array(
                        'field_key' => FakeIntegration::TITLE,
                        'type'      => 'required',
                    ),
                ),
            ));
            $this->fail('Expected fake field to be rejected for product');
        } catch (InvalidRuleException $exception) {
            $this->assertSame('Unsupported field.', $exception->getMessage());
        }

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Unsupported field.');
        $factory->fromAdminInput(array(
            'name'        => 'Wrong type ACF',
            'post_type'   => 'page',
            'validations' => array(
                array(
                    'field_key' => AcfRepeaterFixtures::PRODUCT_SIZE,
                    'type'      => 'required',
                ),
            ),
        ));
    }

    public function testSubmittedPathContainerLayoutAndCloneCannotOverrideCatalog(): void
    {
        $factory = $this->compositeFactory();
        $rule = $factory->fromAdminInput(array(
            'name'        => 'Forged metadata',
            'post_type'   => 'page',
            'validations' => array(
                array(
                    'field_key'   => FakeIntegration::TITLE,
                    'type'        => 'required',
                    'path'        => array('field_forged', 'test_title'),
                    'container'   => FieldRef::CONTAINER_GROUP,
                    'layout'      => 'hero',
                    'clone'       => 'field_clone_forged',
                    'key'         => 'field_stolen',
                    'integration' => 'acf',
                    'label'       => 'Forged Label',
                    'breadcrumb'  => 'Forged → Breadcrumb',
                    'group_label' => 'Forged Group',
                    'field_group' => 'Forged Field Group',
                    'type_label'  => 'Forged Type',
                ),
            ),
        ));

        $field = $rule->validations[0]->field;
        $this->assertSame(FakeIntegration::TITLE, $field->key);
        $this->assertSame(FakeIntegration::TITLE, $field->name);
        $this->assertNotSame('Forged Label', $field->label);
        $this->assertSame(array(), $field->path);
        $this->assertSame('', $field->container);
        $this->assertSame('', $field->layout);
        $this->assertSame('', $field->clone);
        $this->assertArrayNotHasKey('integration', $field->toArray());
        $this->assertArrayNotHasKey('path', $field->toArray());
        $this->assertArrayNotHasKey('breadcrumb', $field->toArray());
        $this->assertArrayNotHasKey('group_label', $field->toArray());
        $this->assertArrayNotHasKey('field_group', $field->toArray());

        $clone = $factory->fromAdminInput(array(
            'name'        => 'Forged clone metadata',
            'post_type'   => 'page',
            'validations' => array(
                array(
                    'field_key'   => AcfCloneFixtures::cloneATitlePosted(),
                    'type'        => 'required',
                    'path'        => array('field_forged'),
                    'container'   => FieldRef::CONTAINER_GROUP,
                    'layout'      => 'hero',
                    'clone'       => 'field_other_clone',
                    'integration' => FakeIntegration::ID,
                ),
            ),
        ));
        $this->assertEquals(AcfCloneFixtures::cloneATitleRef(), $clone->validations[0]->field);
        $this->assertArrayNotHasKey('integration', $clone->validations[0]->field->toArray());
    }

    public function testSubmittedTypeCannotEnableContainsOnNumberFields(): void
    {
        $factory = $this->compositeFactory();

        try {
            $factory->fromAdminInput(array(
                'name'       => 'Forged contains on number',
                'post_type'  => 'page',
                'conditions' => array(
                    array(
                        'field_key' => FakeIntegration::NUMBER,
                        'operator'  => 'contains',
                        'operand'   => 'healthy',
                        'type'      => 'text',
                        'label'     => 'Forged Text',
                    ),
                ),
                'validations' => array(
                    array(
                        'field_key' => FakeIntegration::TITLE,
                        'type'      => 'required',
                    ),
                ),
            ));
            $this->fail('Expected contains-on-number rejection');
        } catch (InvalidRuleException $exception) {
            $this->assertSame('Contains conditions can only be used with text fields.', $exception->getMessage());
        }

        $rule = $factory->fromAdminInput(array(
            'name'       => 'Contains on text',
            'post_type'  => 'page',
            'conditions' => array(
                array(
                    'field_key' => FakeIntegration::TITLE,
                    'operator'  => 'contains',
                    'operand'   => 'healthy',
                    'type'      => 'number',
                ),
            ),
            'validations' => array(
                array(
                    'field_key' => FakeIntegration::TITLE,
                    'type'      => 'required',
                    'message'   => 'Please avoid the term "healthy".',
                ),
            ),
        ));
        $this->assertSame('contains', $rule->conditions[0]->operator);
        $this->assertSame(FakeIntegration::TITLE, $rule->conditions[0]->field->key);
    }

    public function testFakeRuleDocumentSavesLoadsAndEvaluates(): void
    {
        $factory = $this->compositeFactory();
        $created = $factory->fromAdminInput($this->fakeRequiredInput(FakeIntegration::TITLE));
        $this->assertSame(1, $created->schemaVersion);

        $repository = new InMemoryRuleRepository(array(), RuleDocumentValidator::v1());
        $commands = new RuleCommandService(
            $repository,
            static fn (): bool => true,
            static fn (string $nonce): bool => $nonce === 'ok'
        );
        $saved = $commands->save($created, 'ok');
        $loaded = $repository->find($saved->id);
        $this->assertNotNull($loaded);
        $this->assertSame(1, $loaded->schemaVersion);
        $this->assertSame(FakeIntegration::TITLE, $loaded->validations[0]->field->resolutionId());
        $this->assertArrayNotHasKey('integration', $loaded->validations[0]->field->toArray());
        $this->assertSame($created->toArray()['validations'], $loaded->toArray()['validations']);

        $roundTrip = RuleDocumentValidator::v1()->validateArray($loaded->toArray());
        $this->assertSame(FakeIntegration::TITLE, $roundTrip->validations[0]->field->key);

        $provider = new CompositeValueProvider(array(
            new ArrayValueProvider(array(AcfCloneFixtures::TITLE => 'ACF')),
            new ArrayValueProvider(array(FakeIntegration::TITLE => '')),
        ));
        $this->assertTrue(RuleEngine::v1()->evaluate(array($loaded), $provider, 9)->isFailed());

        $filled = new CompositeValueProvider(array(
            new ArrayValueProvider(array(AcfCloneFixtures::TITLE => 'ACF')),
            new ArrayValueProvider(array(FakeIntegration::TITLE => 'Hello')),
        ));
        $this->assertTrue(RuleEngine::v1()->evaluate(array($loaded), $filled, 9)->isPassed());
    }

    public function testFactoryCreatedFakeRuleAppearsInAuditFindings(): void
    {
        $factory = $this->compositeFactory();
        $rule = $factory->fromAdminInput($this->fakeRequiredInput(FakeIntegration::TITLE));
        $document = $rule->toArray();
        $document['id'] = 12;
        $rule = RuleDocumentValidator::v1()->validateArray($document);

        $repository = new InMemoryRuleRepository(array($rule));
        $store = new InMemoryAuditStore();
        $catalog = $this->compositeCatalog();
        $service = new ContentAuditService(
            $store,
            new InMemoryAuditPostScanner(array(
                array('id' => 9, 'postType' => 'page', 'status' => 'publish'),
            )),
            new InMemoryAuditLock(),
            $repository,
            new ContentEvaluator($repository, RuleEngine::v1()),
            $catalog,
            static fn (): CompositeValueProvider => new CompositeValueProvider(array(
                new ArrayValueProvider(array(FakeIntegration::TITLE => '')),
            )),
            static function (array $ids): void {
                unset($ids);
            },
            static fn (): int => 1_000_000,
            1
        );

        $run = $service->start(1);
        $service->processBatch($run->id);
        $keys = array_map(static fn ($finding): string => $finding->fieldKey, $service->getFindings($run->id));

        $this->assertContains(FakeIntegration::TITLE, $keys);
        $this->assertNotContains('acf:' . FakeIntegration::TITLE, $keys);
    }

    public function testCollidingResolutionIdsAreNotSelectableOrPersistable(): void
    {
        $acf = new AcfIntegration(AcfCloneFixtures::catalogFor(array(AcfCloneFixtures::directTitle())));
        $fake = $this->catalog(array(
            array(
                'key'         => AcfCloneFixtures::TITLE,
                'name'        => 'test_title',
                'label'       => 'Test Title',
                'type'        => 'text',
                'integration' => FakeIntegration::ID,
            ),
        ));
        $composite = new CompositeFieldCatalog(array($acf, $fake));
        $this->assertCount(2, $composite->fieldsForPostType('page'));

        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('page' => 'Page'),
            $composite
        );
        $selectable = array_map(
            static fn (array $field): string => (string) ($field['resolution_id'] ?? $field['key'] ?? ''),
            $factory->fieldsForPostType('page')
        );
        $this->assertNotContains(AcfCloneFixtures::TITLE, $selectable);

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Unsupported field.');
        $factory->fromAdminInput(array(
            'name'        => 'Ambiguous',
            'post_type'   => 'page',
            'validations' => array(
                array(
                    'field_key' => AcfCloneFixtures::TITLE,
                    'type'      => 'required',
                ),
            ),
        ));
    }

    public function testExistingAcfNestedIdentitiesRemainUnchanged(): void
    {
        $group = $this->nestedGroupCatalog();
        $cases = array(
            array($this->acfFactory(), 'product', 'field_ingredients', 'field_ingredients', '', array()),
            array(
                RuleDocumentFactory::v1(static fn (): array => array('product' => 'Product'), $group),
                'product',
                'field_city',
                'field_city',
                FieldRef::CONTAINER_GROUP,
                array('field_address', 'field_city'),
            ),
            array(
                RuleDocumentFactory::v1(static fn (): array => array('product' => 'Product'), $group),
                'product',
                'field_notes',
                'field_notes',
                FieldRef::CONTAINER_GROUP,
                array('field_address', 'field_details', 'field_notes'),
            ),
            array(
                $this->repeaterFactory(),
                'product',
                AcfRepeaterFixtures::PRODUCT_SIZE,
                AcfRepeaterFixtures::PRODUCT_SIZE,
                FieldRef::CONTAINER_REPEATER,
                array(
                    AcfRepeaterFixtures::PRODUCT_INFORMATION,
                    AcfRepeaterFixtures::ITEM_SIZE,
                    AcfRepeaterFixtures::PRODUCT_SIZE,
                ),
            ),
            array(
                $this->flexibleFactory(),
                'page',
                AcfFlexibleFixtures::HERO_TITLE,
                AcfFlexibleFixtures::HERO_TITLE,
                FieldRef::CONTAINER_FLEXIBLE,
                array(AcfFlexibleFixtures::MODULES, AcfFlexibleFixtures::HERO_TITLE),
            ),
            array(
                $this->flexibleFactory(),
                'page',
                AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE,
                AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE,
                FieldRef::CONTAINER_FLEXIBLE,
                array(
                    AcfFlexibleFixtures::MODULES,
                    AcfFlexibleFixtures::CONTENT_BLOCK_1,
                    AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE,
                ),
            ),
            array(
                $this->cloneFactory(),
                'page',
                AcfCloneFixtures::cloneATitlePosted(),
                AcfCloneFixtures::TITLE,
                FieldRef::CONTAINER_CLONE,
                array(AcfCloneFixtures::CLONE_A, AcfCloneFixtures::cloneATitlePosted()),
            ),
            array(
                $this->cloneFactory(),
                'page',
                FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_GROUP, AcfCloneFixtures::INGREDIENTS),
                AcfCloneFixtures::INGREDIENTS,
                FieldRef::CONTAINER_CLONE,
                array(
                    AcfCloneFixtures::CLONE_GROUP,
                    AcfCloneFixtures::cloneGroupDetailsPosted(),
                    AcfCloneFixtures::INGREDIENTS,
                ),
            ),
            array(
                $this->cloneFactory(),
                'page',
                FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_REP, AcfCloneFixtures::TITLE),
                AcfCloneFixtures::TITLE,
                FieldRef::CONTAINER_REPEATER,
                array(
                    AcfCloneFixtures::REPEATER,
                    AcfCloneFixtures::CLONE_REP,
                    AcfCloneFixtures::cloneRepTitlePosted(),
                ),
            ),
            array(
                $this->cloneFactory(),
                'page',
                FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_FLEX, AcfCloneFixtures::TITLE),
                AcfCloneFixtures::TITLE,
                FieldRef::CONTAINER_FLEXIBLE,
                array(
                    AcfCloneFixtures::FLEX,
                    AcfCloneFixtures::CLONE_FLEX,
                    AcfCloneFixtures::cloneFlexTitlePosted(),
                ),
            ),
        );

        foreach ($cases as $case) {
            [$factory, $postType, $submitted, $leaf, $container, $path] = $case;
            $rule = $factory->fromAdminInput(array(
                'name'        => 'Nested identity ' . $submitted,
                'post_type'   => $postType,
                'validations' => array(
                    array(
                        'field_key' => $submitted,
                        'type'      => 'required',
                    ),
                ),
            ));
            $field = $rule->validations[0]->field;
            $this->assertSame(1, $rule->schemaVersion);
            $this->assertSame($leaf, $field->key);
            $this->assertSame($submitted, $field->resolutionId());
            $this->assertSame($container, $field->container);
            $this->assertSame($path, $field->path);
            $this->assertArrayNotHasKey('integration', $field->toArray());
            $this->assertStringNotContainsString('acf:', $field->resolutionId());
        }
    }

    public function testPrefixedCloneNamePersistsFromCatalog(): void
    {
        $rule = $this->cloneFactory()->fromAdminInput(array(
            'name'        => 'Prefixed clone',
            'post_type'   => 'page',
            'validations' => array(
                array(
                    'field_key' => AcfCloneFixtures::cloneATitlePosted(),
                    'type'      => 'required',
                ),
            ),
        ));

        $this->assertSame('shared_a_title', $rule->validations[0]->field->name);
        $this->assertSame('Shared Content → Title', $rule->validations[0]->field->label);
        $this->assertSame(AcfCloneFixtures::CLONE_A, $rule->validations[0]->field->clone);
    }

    public function testExistingRuleBuilderValidationStillAppliesToFakeFields(): void
    {
        $factory = $this->compositeFactory();

        try {
            $factory->fromAdminInput(array(
                'name'        => '',
                'post_type'   => 'page',
                'validations' => array(
                    array(
                        'field_key' => FakeIntegration::TITLE,
                        'type'      => 'required',
                    ),
                ),
            ));
            $this->fail('Expected missing name rejection');
        } catch (InvalidRuleException $exception) {
            $this->assertSame('Rule name is required.', $exception->getMessage());
        }

        try {
            $factory->fromAdminInput(array(
                'name'       => 'Bad operator',
                'post_type'  => 'page',
                'conditions' => array(
                    array(
                        'field_key' => FakeIntegration::TITLE,
                        'operator'  => 'starts_with',
                        'operand'   => 'x',
                    ),
                ),
                'validations' => array(
                    array(
                        'field_key' => FakeIntegration::TITLE,
                        'type'      => 'required',
                    ),
                ),
            ));
            $this->fail('Expected unknown operator rejection');
        } catch (InvalidRuleException $exception) {
            $this->assertStringContainsString('starts_with', $exception->getMessage());
        }

        try {
            $factory->fromAdminInput(array(
                'name'       => 'Numeric on text',
                'post_type'  => 'page',
                'conditions' => array(
                    array(
                        'field_key' => FakeIntegration::TITLE,
                        'operator'  => 'greater_than',
                        'operand'   => '1',
                    ),
                ),
                'validations' => array(
                    array(
                        'field_key' => FakeIntegration::TITLE,
                        'type'      => 'required',
                    ),
                ),
            ));
            $this->fail('Expected numeric-on-text rejection');
        } catch (InvalidRuleException $exception) {
            $this->assertSame('Numeric comparisons can only be used with number fields.', $exception->getMessage());
        }

        try {
            $factory->fromAdminInput(array(
                'name'        => 'Inactive',
                'post_type'   => 'page',
                'status'      => 'archived',
                'validations' => array(
                    array(
                        'field_key' => FakeIntegration::TITLE,
                        'type'      => 'required',
                    ),
                ),
            ));
            $this->fail('Expected invalid status rejection');
        } catch (InvalidRuleException $exception) {
            $this->assertSame('Invalid rule status.', $exception->getMessage());
        }

        $inactive = $factory->fromAdminInput(array(
            'name'        => 'Inactive fake',
            'post_type'   => 'page',
            'status'      => 'inactive',
            'severity'    => 'warning',
            'validations' => array(
                array(
                    'field_key' => FakeIntegration::TITLE,
                    'type'      => 'min_length',
                    'min'       => '4',
                    'message'   => 'Title must be longer.',
                ),
            ),
        ));
        $this->assertSame('inactive', $inactive->status->value);
        $this->assertSame('warning', $inactive->severity->value);
        $this->assertSame(4, $inactive->validations[0]->params['min']);
        $this->assertSame('Title must be longer.', $inactive->validations[0]->message);
    }

    public function testExistingAcfDocumentsRoundTripWithoutGainingIntegration(): void
    {
        $document = array(
            'schema_version'  => Rule::SCHEMA_VERSION,
            'id'              => 21,
            'name'            => 'Shared title required',
            'post_type'       => 'page',
            'status'          => 'active',
            'severity'        => 'fail',
            'condition_logic' => 'and',
            'conditions'      => array(),
            'validations'     => array(
                array(
                    'id'      => 'v1',
                    'field'   => AcfCloneFixtures::cloneATitleRef()->toArray(),
                    'type'    => 'required',
                    'params'  => array(),
                    'message' => '',
                ),
            ),
        );

        $loaded = RuleDocumentValidator::v1()->validateArray($document);
        $encoded = $loaded->toArray();
        $this->assertSame(1, $encoded['schema_version']);
        $this->assertArrayNotHasKey('integration', $encoded['validations'][0]['field']);
        $this->assertSame(AcfCloneFixtures::TITLE, $encoded['validations'][0]['field']['key']);
        $this->assertSame(AcfCloneFixtures::CLONE_A, $encoded['validations'][0]['field']['clone']);
        $this->assertSame(
            array(AcfCloneFixtures::CLONE_A, AcfCloneFixtures::cloneATitlePosted()),
            $encoded['validations'][0]['field']['path']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeRequiredInput(string $fieldKey): array
    {
        return array(
            'name'        => 'Test title required',
            'post_type'   => 'page',
            'status'      => 'active',
            'severity'    => 'fail',
            'validations' => array(
                array(
                    'field_key' => $fieldKey,
                    'type'      => 'required',
                ),
            ),
        );
    }

    private function compositeFactory(): RuleDocumentFactory
    {
        return RuleDocumentFactory::v1(
            static fn (): array => array('page' => 'Page', 'product' => 'Product'),
            $this->compositeCatalog()
        );
    }

    private function compositeCatalog(): CompositeFieldCatalog
    {
        return new CompositeFieldCatalog(array(
            new AcfIntegration(AcfCloneFixtures::pageCatalog()),
            new AcfIntegration(AcfRepeaterFixtures::productCatalog()),
            new FakeIntegration(),
        ));
    }

    private function acfFactory(): RuleDocumentFactory
    {
        return RuleDocumentFactory::v1(
            static fn (): array => array('product' => 'Product'),
            static fn (): array => array(
                array(
                    'key'   => 'field_ingredients',
                    'name'  => 'ingredients',
                    'label' => 'Ingredients',
                    'type'  => 'textarea',
                ),
            )
        );
    }

    private function cloneFactory(): RuleDocumentFactory
    {
        return RuleDocumentFactory::v1(
            static fn (): array => array('page' => 'Page'),
            new AcfIntegration(AcfCloneFixtures::pageCatalog())
        );
    }

    private function repeaterFactory(): RuleDocumentFactory
    {
        return RuleDocumentFactory::v1(
            static fn (): array => array('product' => 'Product'),
            new AcfIntegration(AcfRepeaterFixtures::productCatalog())
        );
    }

    private function flexibleFactory(): RuleDocumentFactory
    {
        return RuleDocumentFactory::v1(
            static fn (): array => array('page' => 'Page'),
            new AcfIntegration(AcfFlexibleFixtures::pageCatalog())
        );
    }

    private function nestedGroupCatalog(): AcfIntegration
    {
        return new AcfIntegration(new AcfFieldCatalog(
            static function (string $postType): array {
                return $postType === 'product'
                    ? array(
                        array(
                            'key'        => 'field_address',
                            'name'       => 'address',
                            'label'      => 'Address',
                            'type'       => 'group',
                            'sub_fields' => array(
                                array(
                                    'key'   => 'field_city',
                                    'name'  => 'city',
                                    'label' => 'City',
                                    'type'  => 'text',
                                ),
                                array(
                                    'key'        => 'field_details',
                                    'name'       => 'details',
                                    'label'      => 'Details',
                                    'type'       => 'group',
                                    'sub_fields' => array(
                                        array(
                                            'key'   => 'field_notes',
                                            'name'  => 'notes',
                                            'label' => 'Notes',
                                            'type'  => 'text',
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    )
                    : array();
            }
        ));
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function catalog(array $fields): FieldCatalog
    {
        return new class ($fields) implements FieldCatalog {
            /**
             * @param list<array<string, mixed>> $fields
             */
            public function __construct(private array $fields)
            {
            }

            public function fieldsForPostType(string $postType): array
            {
                unset($postType);

                return $this->fields;
            }

            public function fieldTypesForPostType(string $postType): array
            {
                $types = array();
                foreach ($this->fieldsForPostType($postType) as $field) {
                    $id = (string) ($field['resolution_id'] ?? $field['key'] ?? '');
                    $types[$id] = (string) $field['type'];
                }

                return $types;
            }
        };
    }
}
