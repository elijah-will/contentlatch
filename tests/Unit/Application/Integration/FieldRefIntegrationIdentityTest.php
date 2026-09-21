<?php
/**
 * Phase 11C: FieldRef does not persist integration. Resolution ids must be unique.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application\Integration;

use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\Integration\CompositeFieldCatalog;
use ContentGuard\Application\Integration\CompositeValueProvider;
use ContentGuard\Application\Integration\FieldCatalog;
use ContentGuard\Application\Integration\Integration;
use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\FieldRef;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\ACF\AcfIntegration;
use ContentGuard\Tests\Support\InMemoryRuleRepository;
use ContentGuard\Tests\Support\AcfCloneFixtures;
use ContentGuard\Tests\Support\AcfFlexibleFixtures;
use ContentGuard\Tests\Support\AcfRepeaterFixtures;
use ContentGuard\Tests\Support\FakeIntegration;
use ContentGuard\Tests\Support\InMemoryAuditLock;
use ContentGuard\Tests\Support\InMemoryAuditPostScanner;
use ContentGuard\Tests\Support\InMemoryAuditStore;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class FieldRefIntegrationIdentityTest extends TestCase
{
    public function testDistinctIntegrationFieldsDoNotNeedAnIntegrationProperty(): void
    {
        $acf  = AcfCloneFixtures::cloneATitleRef();
        $fake = FakeIntegration::titleRef();

        $this->assertNotSame($acf->resolutionId(), $fake->resolutionId());
        $this->assertArrayNotHasKey('integration', $acf->toArray());
        $this->assertArrayNotHasKey('integration', $fake->toArray());
        $this->assertSame('Shared Content → Title', $acf->label);
        $this->assertSame('Test Title', $fake->label);
    }

    public function testCatalogCanKeepTwoEntriesWhenLabelsMatchButResolutionIdsDiffer(): void
    {
        $acf = new AcfIntegration(AcfCloneFixtures::pageCatalog());
        $fake = new FakeIntegration();
        $composite = new CompositeFieldCatalog(array($acf, $fake));
        $byId = $this->byId($composite->fieldsForPostType('page'));

        $this->assertSame(Integration::ACF, $byId[AcfCloneFixtures::TITLE]['integration']);
        $this->assertSame('Title', $byId[AcfCloneFixtures::TITLE]['label']);
        $this->assertSame(FakeIntegration::ID, $byId[FakeIntegration::TITLE]['integration']);
        $this->assertSame('Test Title', $byId[FakeIntegration::TITLE]['label']);
        $this->assertNotSame($byId[AcfCloneFixtures::TITLE]['key'], $byId[FakeIntegration::TITLE]['key']);
    }

    public function testDuplicateResolutionIdsCollideInLookupMapsWithoutFieldRefChanges(): void
    {
        $acf = new AcfIntegration(AcfCloneFixtures::catalogFor(array(AcfCloneFixtures::directTitle())));
        $fake = $this->catalog(
            array(
                array(
                    'key'         => AcfCloneFixtures::TITLE,
                    'name'        => 'test_title',
                    'label'       => 'Test Title',
                    'type'        => 'text',
                    'integration' => FakeIntegration::ID,
                ),
            )
        );
        $composite = new CompositeFieldCatalog(array($acf, $fake));
        $fields = $composite->fieldsForPostType('page');

        $this->assertCount(2, $fields);
        $this->assertSame(Integration::ACF, $fields[0]['integration']);
        $this->assertSame(FakeIntegration::ID, $fields[1]['integration']);
        $this->assertSame(AcfCloneFixtures::TITLE, $fields[0]['key']);
        $this->assertSame(AcfCloneFixtures::TITLE, $fields[1]['key']);

        $types = $composite->fieldTypesForPostType('page');
        $this->assertCount(1, $types);
        $this->assertSame('text', $types[AcfCloneFixtures::TITLE]);

        $acfRef  = new FieldRef(AcfCloneFixtures::TITLE, 'title', 'Title');
        $fakeRef = new FieldRef(AcfCloneFixtures::TITLE, 'test_title', 'Test Title');
        $this->assertSame($acfRef->resolutionId(), $fakeRef->resolutionId());
        $this->assertArrayNotHasKey('integration', $acfRef->toArray());
        $this->assertArrayNotHasKey('integration', $fakeRef->toArray());

        $provider = new CompositeValueProvider(array(
            new ArrayValueProvider(array(AcfCloneFixtures::TITLE => 'From ACF')),
            new ArrayValueProvider(array(AcfCloneFixtures::TITLE => 'From fake')),
        ));
        $this->assertSame('From ACF', $provider->get(AcfCloneFixtures::TITLE));
    }

    public function testRuleEngineUsesResolutionIdsNotIntegrationMetadata(): void
    {
        $acfRule = RuleFactory::rule(array(
            'id'          => 1,
            'postType'    => 'page',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => AcfCloneFixtures::cloneATitleRef(),
                )),
            ),
        ));
        $fakeRule = RuleFactory::rule(array(
            'id'          => 2,
            'postType'    => 'page',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => FakeIntegration::titleRef(),
                )),
            ),
        ));

        $results = RuleEngine::v1()->evaluate(
            array($acfRule, $fakeRule),
            new CompositeValueProvider(array(
                new ArrayValueProvider(array(
                    AcfCloneFixtures::cloneATitlePosted() => 'ACF',
                )),
                new ArrayValueProvider(array(
                    FakeIntegration::TITLE => 'Fake',
                )),
            )),
            9
        )->results;

        $this->assertTrue($results[0]->isPassed());
        $this->assertTrue($results[1]->isPassed());
        $this->assertSame(AcfCloneFixtures::cloneATitlePosted(), $results[0]->fieldId);
        $this->assertSame(FakeIntegration::TITLE, $results[1]->fieldId);
    }

    public function testExistingAcfRuleDocumentsRoundTripWithoutIntegration(): void
    {
        $document = array(
            'schema_version'  => Rule::SCHEMA_VERSION,
            'id'              => 15,
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

        $this->assertArrayNotHasKey('integration', $document['validations'][0]['field']);
        $loaded = RuleDocumentValidator::v1()->validateArray($document);
        $this->assertSame(1, $loaded->schemaVersion);
        $encoded = $loaded->toArray();
        $this->assertArrayNotHasKey('integration', $encoded['validations'][0]['field']);
        $this->assertSame(AcfCloneFixtures::TITLE, $encoded['validations'][0]['field']['key']);
        $this->assertSame(AcfCloneFixtures::CLONE_A, $encoded['validations'][0]['field']['clone']);
        $this->assertSame(
            AcfCloneFixtures::cloneATitlePosted(),
            $loaded->validations[0]->field->resolutionId()
        );
        $this->assertStringNotContainsString('acf:', $loaded->validations[0]->field->resolutionId());
    }

    public function testFactoryStillPersistsAcfIdentityWhenTheCompositeAlsoHasFakeFields(): void
    {
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('page' => 'Page'),
            static fn (string $postType): array => (new CompositeFieldCatalog(array(
                new AcfIntegration(AcfCloneFixtures::pageCatalog()),
                new FakeIntegration(),
            )))->fieldsForPostType($postType)
        );

        $rule = $factory->fromAdminInput(array(
            'name'        => 'Shared title required',
            'post_type'   => 'page',
            'validations' => array(
                array(
                    'field_key' => AcfCloneFixtures::cloneATitlePosted(),
                    'type'      => 'required',
                ),
            ),
        ));

        $this->assertSame(1, $rule->schemaVersion);
        $this->assertArrayNotHasKey('integration', $rule->validations[0]->field->toArray());
        $this->assertSame(AcfCloneFixtures::cloneATitlePosted(), $rule->validations[0]->field->resolutionId());
    }

    public function testNestedAcfIdentitiesStayUnchangedBesideTheFakeCatalog(): void
    {
        $composite = new CompositeFieldCatalog(array(
            new AcfIntegration(AcfCloneFixtures::pageCatalog()),
            new AcfIntegration(AcfRepeaterFixtures::productCatalog()),
            new AcfIntegration(AcfFlexibleFixtures::pageCatalog()),
            new FakeIntegration(true, array(), array('page', 'product')),
        ));

        $page = $this->byId($composite->fieldsForPostType('page'));
        $product = $this->byId($composite->fieldsForPostType('product'));

        $this->assertSame(
            array(AcfCloneFixtures::CLONE_A, AcfCloneFixtures::cloneATitlePosted()),
            $page[AcfCloneFixtures::cloneATitlePosted()]['path']
        );
        $this->assertSame('clone', $page[AcfCloneFixtures::cloneATitlePosted()]['container']);
        $this->assertSame('hero', $page[AcfFlexibleFixtures::HERO_TITLE]['layout']);
        $this->assertSame('repeater', $product[AcfRepeaterFixtures::PRODUCT_SIZE]['container']);
        $this->assertSame(FakeIntegration::ID, $page[FakeIntegration::TITLE]['integration']);
        $this->assertSame(
            AcfCloneFixtures::cloneATitleRef()->resolutionId(),
            $page[AcfCloneFixtures::cloneATitlePosted()]['resolution_id']
        );
    }

    public function testAuditFindingIdentityStaysTheResolutionId(): void
    {
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
        $catalog = new CompositeFieldCatalog(array(
            new AcfIntegration(AcfCloneFixtures::pageCatalog()),
            new FakeIntegration(),
        ));
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
                new ArrayValueProvider(array(
                    AcfCloneFixtures::cloneATitlePosted() => '',
                    FakeIntegration::TITLE => '',
                )),
            )),
            static function (array $ids): void {
                unset($ids);
            },
            static fn (): int => 1_000_000,
            1
        );

        $run = $service->start(1);
        $service->processBatch($run->id);
        $findings = $service->getFindings($run->id);
        $keys = array_map(static fn ($finding): string => $finding->fieldKey, $findings);

        $this->assertContains(AcfCloneFixtures::cloneATitlePosted(), $keys);
        $this->assertContains(FakeIntegration::TITLE, $keys);
        $this->assertNotContains('acf:' . AcfCloneFixtures::cloneATitlePosted(), $keys);
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
