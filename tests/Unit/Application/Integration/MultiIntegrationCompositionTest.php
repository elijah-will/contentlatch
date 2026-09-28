<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application\Integration;

use ContentLatch\Application\Audit\ContentAuditService;
use ContentLatch\Application\ContentEvaluator;
use ContentLatch\Application\Integration\CompositeFieldCatalog;
use ContentLatch\Application\Integration\CompositeValueProvider;
use ContentLatch\Application\Integration\Integration;
use ContentLatch\Application\Integration\IntegrationRegistry;
use ContentLatch\Application\RuleDocumentFactory;
use ContentLatch\Domain\ArrayValueProvider;
use ContentLatch\Domain\EvaluationStatus;
use ContentLatch\Domain\FieldInstance;
use ContentLatch\Domain\RuleEngine;
use ContentLatch\Infrastructure\ACF\AcfIntegration;
use ContentLatch\Tests\Support\InMemoryRuleRepository;
use ContentLatch\Tests\Support\AcfCloneFixtures;
use ContentLatch\Tests\Support\FakeIntegration;
use ContentLatch\Tests\Support\InMemoryAuditLock;
use ContentLatch\Tests\Support\InMemoryAuditPostScanner;
use ContentLatch\Tests\Support\InMemoryAuditStore;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class MultiIntegrationCompositionTest extends TestCase
{
    public function testRegistryHoldsAcfAndTheFakeIntegration(): void
    {
        $acf  = new AcfIntegration(AcfCloneFixtures::pageCatalog(), true);
        $fake = new FakeIntegration();
        $registry = new IntegrationRegistry(array(
            $acf->descriptor(),
            $fake->descriptor(),
        ));

        $this->assertTrue($registry->has(Integration::ACF));
        $this->assertTrue($registry->has(FakeIntegration::ID));
        $this->assertTrue($registry->isAvailable(Integration::ACF));
        $this->assertTrue($registry->isAvailable(FakeIntegration::ID));
        $this->assertSame('ACF', $registry->get(Integration::ACF)?->label);
        $this->assertSame('Test Integration', $registry->get(FakeIntegration::ID)?->label);
        $this->assertCount(2, $registry->all());
        $this->assertFalse($registry->has('yoast'));
    }

    public function testUnavailableFakeStaysRegistered(): void
    {
        $registry = new IntegrationRegistry(array(
            (new AcfIntegration(AcfCloneFixtures::pageCatalog(), true))->descriptor(),
            (new FakeIntegration(false))->descriptor(),
        ));

        $this->assertTrue($registry->has(FakeIntegration::ID));
        $this->assertFalse($registry->isAvailable(FakeIntegration::ID));
        $this->assertTrue($registry->isAvailable(Integration::ACF));
    }

    public function testCompositeCatalogCombinesAcfAndFakeFieldsWithoutColliding(): void
    {
        $acf  = new AcfIntegration(AcfCloneFixtures::pageCatalog());
        $fake = new FakeIntegration();
        $composite = new CompositeFieldCatalog(array($acf, $fake));

        $page = $composite->fieldsForPostType('page');
        $byId = $this->byId($page);

        $this->assertSame(Integration::ACF, $byId[AcfCloneFixtures::cloneATitlePosted()]['integration']);
        $this->assertSame('Shared Content → Title', $byId[AcfCloneFixtures::cloneATitlePosted()]['breadcrumb']);
        $this->assertSame(FakeIntegration::ID, $byId[FakeIntegration::TITLE]['integration']);
        $this->assertSame('Test Title', $byId[FakeIntegration::TITLE]['label']);
        $this->assertSame('Test Number', $byId[FakeIntegration::NUMBER]['label']);
        $this->assertSame('text', $composite->fieldTypesForPostType('page')[FakeIntegration::TITLE]);
        $this->assertSame('number', $composite->fieldTypesForPostType('page')[FakeIntegration::NUMBER]);
        $this->assertSame('text', $composite->fieldTypesForPostType('page')[AcfCloneFixtures::cloneATitlePosted()]);

        $recipe = $composite->fieldsForPostType('recipe');
        $this->assertSame(array(), $recipe);
        $this->assertArrayNotHasKey(FakeIntegration::TITLE, $this->byId($recipe));
    }

    public function testCompositeProviderRoutesEachIdToItsOwningIntegration(): void
    {
        $acf  = new AcfIntegration(AcfCloneFixtures::pageCatalog());
        $fake = new FakeIntegration(true, array(
            9 => array(
                FakeIntegration::TITLE  => 'Fake title',
                FakeIntegration::NUMBER => 12,
            ),
        ));
        $types = $acf->fieldTypesForPostType('page');
        $reader = static function (string $key): mixed {
            return match ($key) {
                AcfCloneFixtures::CLONE_A => array(
                    AcfCloneFixtures::cloneATitlePosted() => 'ACF clone',
                ),
                AcfCloneFixtures::TITLE => 'ACF source',
                default => 'should-not-leak',
            };
        };

        $composite = new CompositeValueProvider(array(
            $acf->storedProvider(9, 'page', $types, $reader),
            $fake->storedProvider(9),
        ));

        $cloneId = AcfCloneFixtures::cloneATitlePosted();
        $this->assertTrue($composite->has($cloneId));
        $this->assertTrue($composite->has(FakeIntegration::TITLE));
        $this->assertTrue($composite->has(FakeIntegration::NUMBER));
        $this->assertSame('ACF clone', $composite->get($cloneId));
        $this->assertSame('ACF source', $composite->get(AcfCloneFixtures::TITLE));
        $this->assertSame('Fake title', $composite->get(FakeIntegration::TITLE));
        $this->assertSame(12, $composite->get(FakeIntegration::NUMBER));
        $this->assertEquals(array(new FieldInstance('Fake title')), $composite->instances(FakeIntegration::TITLE));
        $this->assertEquals(array(new FieldInstance('ACF clone')), $composite->instances($cloneId));
        $this->assertFalse($composite->has('unknown_field'));
        $this->assertNull($composite->get('unknown_field'));
        $this->assertSame(array(), $composite->instances('unknown_field'));
        $this->assertFalse($composite->has('yoast_title'));
    }

    public function testAcfProviderDoesNotClaimFakeIds(): void
    {
        $acf = new AcfIntegration(AcfCloneFixtures::pageCatalog());
        $provider = $acf->storedProvider(
            9,
            'page',
            $acf->fieldTypesForPostType('page'),
            static fn (): mixed => 'leaked'
        );

        $this->assertFalse($provider->has(FakeIntegration::TITLE));
        $this->assertNull($provider->get(FakeIntegration::TITLE));
        $this->assertSame(array(), $provider->instances(FakeIntegration::TITLE));
    }

    public function testRuleEngineEvaluatesBothSourcesWithoutIntegrationConditionals(): void
    {
        $acfRule = RuleFactory::rule(array(
            'id'          => 1,
            'postType'    => 'page',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'   => AcfCloneFixtures::cloneATitleRef(),
                    'message' => 'ACF clone is required.',
                )),
            ),
        ));
        $fakeRule = RuleFactory::rule(array(
            'id'          => 2,
            'postType'    => 'page',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'   => FakeIntegration::titleRef(),
                    'message' => 'Test title is required.',
                )),
            ),
        ));

        $acf  = new AcfIntegration(AcfCloneFixtures::pageCatalog());
        $fake = new FakeIntegration(true, array(
            9 => array(FakeIntegration::TITLE => 'Present'),
        ));
        $composite = new CompositeValueProvider(array(
            $acf->storedProvider(
                9,
                'page',
                $acf->fieldTypesForPostType('page'),
                static function (string $key): mixed {
                    return $key === AcfCloneFixtures::CLONE_A
                        ? array(AcfCloneFixtures::cloneATitlePosted() => 'Present')
                        : null;
                }
            ),
            $fake->storedProvider(9),
        ));

        $results = RuleEngine::v1()->evaluate(array($acfRule, $fakeRule), $composite, 9)->results;
        $byRule  = array();
        foreach ($results as $result) {
            $byRule[$result->ruleId] = $result;
        }

        $this->assertTrue($byRule[1]->isPassed());
        $this->assertTrue($byRule[2]->isPassed());
        $this->assertSame(AcfCloneFixtures::cloneATitlePosted(), $byRule[1]->fieldId);
        $this->assertSame(FakeIntegration::TITLE, $byRule[2]->fieldId);
    }

    public function testUnavailableFakeIsOmittedFromEvaluationSoAcfDoesNotFalseFail(): void
    {
        $acf  = new AcfIntegration(AcfCloneFixtures::pageCatalog(), true);
        $fake = new FakeIntegration(false, array(
            9 => array(FakeIntegration::TITLE => ''),
        ));
        $registry = new IntegrationRegistry(array($acf->descriptor(), $fake->descriptor()));
        $this->assertFalse($registry->isAvailable(FakeIntegration::ID));

        $catalogs = array();
        $providers = array();
        if ($acf->isAvailable()) {
            $catalogs[] = $acf;
            $providers[] = $acf->storedProvider(
                9,
                'page',
                $acf->fieldTypesForPostType('page'),
                static function (string $key): mixed {
                    return $key === AcfCloneFixtures::CLONE_A
                        ? array(AcfCloneFixtures::cloneATitlePosted() => 'ACF ok')
                        : null;
                }
            );
        }
        if ($fake->isAvailable()) {
            $catalogs[] = $fake;
            $providers[] = $fake->storedProvider(9);
        }

        $catalog = new CompositeFieldCatalog($catalogs);
        $this->assertArrayNotHasKey(FakeIntegration::TITLE, $this->byId($catalog->fieldsForPostType('page')));
        $this->assertArrayHasKey(AcfCloneFixtures::cloneATitlePosted(), $this->byId($catalog->fieldsForPostType('page')));

        $composite = new CompositeValueProvider($providers);
        $this->assertFalse($composite->has(FakeIntegration::TITLE));

        $acfRule = RuleFactory::rule(array(
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => AcfCloneFixtures::cloneATitleRef(),
                )),
            ),
        ));
        $evaluation = RuleEngine::v1()->evaluate(array($acfRule), $composite, 9);
        $this->assertTrue($evaluation->results[0]->isPassed());
    }

    public function testProviderContractCannotDistinguishUnavailableFromMissing(): void
    {
        $fake = new FakeIntegration(false, array(
            9 => array(FakeIntegration::TITLE => ''),
        ));
        $composite = new CompositeValueProvider(array(
            new ArrayValueProvider(array('field_title' => 'ACF')),
        ));

        $this->assertFalse($composite->has(FakeIntegration::TITLE));
        $this->assertNull($composite->get(FakeIntegration::TITLE));

        $rule = RuleFactory::rule(array(
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'   => FakeIntegration::titleRef(),
                    'message' => 'Test title is required.',
                )),
            ),
        ));
        $result = RuleEngine::v1()->evaluate(array($rule), $composite, 9)->results[0];
        $this->assertSame(EvaluationStatus::Failed, $result->status);
        $this->assertSame('required', $result->code);
    }

    public function testFakeFieldRefsAreNotWrittenOntoAcfDocuments(): void
    {
        $this->assertArrayNotHasKey('integration', FakeIntegration::titleRef()->toArray());
        $this->assertSame(FakeIntegration::TITLE, FakeIntegration::titleRef()->resolutionId());
        $this->assertArrayNotHasKey('integration', AcfCloneFixtures::cloneATitleRef()->toArray());
    }

    public function testRuleDocumentFactoryPersistsFakeCatalogFieldsBesideAcf(): void
    {
        $composite = new CompositeFieldCatalog(array(
            new AcfIntegration(AcfCloneFixtures::pageCatalog()),
            new FakeIntegration(),
        ));
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('page' => 'Page'),
            $composite
        );

        $keys = array_column($factory->fieldsForPostType('page'), 'key');
        $this->assertContains(AcfCloneFixtures::TITLE, $keys);
        $this->assertContains(FakeIntegration::TITLE, $keys);

        $acf = $factory->fromAdminInput(array(
            'name'        => 'Shared title required',
            'post_type'   => 'page',
            'validations' => array(
                array(
                    'field_key' => AcfCloneFixtures::cloneATitlePosted(),
                    'type'      => 'required',
                ),
            ),
        ));
        $this->assertSame(1, $acf->schemaVersion);
        $this->assertArrayNotHasKey('integration', $acf->validations[0]->field->toArray());
        $this->assertSame(AcfCloneFixtures::cloneATitlePosted(), $acf->validations[0]->field->resolutionId());

        $fake = $factory->fromAdminInput(array(
            'name'        => 'Test title required',
            'post_type'   => 'page',
            'validations' => array(
                array(
                    'field_key' => FakeIntegration::TITLE,
                    'type'      => 'required',
                ),
            ),
        ));
        $this->assertSame(FakeIntegration::TITLE, $fake->validations[0]->field->key);
        $this->assertSame(FakeIntegration::TITLE, $fake->validations[0]->field->resolutionId());
        $this->assertArrayNotHasKey('integration', $fake->validations[0]->field->toArray());
        $this->assertStringNotContainsString('acf:', $fake->validations[0]->field->resolutionId());
    }

    public function testAuditThroughTheCompositeStillRecordsAcfCloneFindings(): void
    {
        $acf  = new AcfIntegration(AcfCloneFixtures::pageCatalog());
        $fake = new FakeIntegration(true, array(
            9 => array(FakeIntegration::TITLE => 'Filled'),
        ));
        $catalog = new CompositeFieldCatalog(array($acf, $fake));
        $rules = array(
            RuleFactory::rule(array(
                'id'          => 1,
                'postType'    => 'page',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'   => AcfCloneFixtures::cloneATitleRef(),
                        'message' => 'Shared Content → Title is required.',
                    )),
                ),
            )),
            RuleFactory::rule(array(
                'id'          => 2,
                'postType'    => 'page',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'   => FakeIntegration::titleRef(),
                        'message' => 'Test Title is required.',
                    )),
                ),
            )),
        );
        $repository = new InMemoryRuleRepository($rules);
        $store = new InMemoryAuditStore();
        $service = new ContentAuditService(
            $store,
            new InMemoryAuditPostScanner(array(
                array('id' => 9, 'postType' => 'page', 'status' => 'publish'),
            )),
            new InMemoryAuditLock(),
            $repository,
            new ContentEvaluator($repository, RuleEngine::v1()),
            $catalog,
            static function (int $postId, string $postType, array $fieldTypes) use ($acf, $fake): CompositeValueProvider {
                unset($postType, $fieldTypes);

                return new CompositeValueProvider(array(
                    $acf->storedProvider(
                        $postId,
                        'page',
                        $acf->fieldTypesForPostType('page'),
                        static fn (): mixed => null
                    ),
                    $fake->storedProvider($postId),
                ));
            },
            static function (array $ids): void {
                unset($ids);
            },
            static fn (): int => 1_000_000,
            1
        );

        $run = $service->start(1);
        $service->processBatch($run->id);
        $findings = $service->getFindings($run->id);

        $this->assertCount(1, $findings);
        $this->assertSame(AcfCloneFixtures::cloneATitlePosted(), $findings[0]->fieldKey);
        $this->assertSame('Shared Content → Title is required.', $findings[0]->message);
        $this->assertNotSame(FakeIntegration::TITLE, $findings[0]->fieldKey);
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $fields): array
    {
        $byId = array();
        foreach ($fields as $field) {
            $id = (string) ($field['resolution_id'] ?? $field['key'] ?? '');
            $byId[$id] = $field;
        }

        return $byId;
    }
}
