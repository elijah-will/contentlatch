<?php
/**
 * Phase 12A: Core + ACF catalog/provider composition without RuleEngine changes.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application\Integration;

use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\AuditPresentation;
use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\Integration\CompositeFieldCatalog;
use ContentGuard\Application\Integration\CompositeValueProvider;
use ContentGuard\Application\Integration\Integration;
use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Domain\EvaluationStatus;
use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\FieldInstance;
use ContentGuard\Domain\FieldRef;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\AcfIntegration;
use ContentGuard\Tests\Support\InMemoryRuleRepository;
use ContentGuard\Infrastructure\WordPress\CoreFieldCatalog;
use ContentGuard\Infrastructure\WordPress\CoreIntegration;
use ContentGuard\Tests\Support\AcfCloneFixtures;
use ContentGuard\Tests\Support\AcfFlexibleFixtures;
use ContentGuard\Tests\Support\AcfRepeaterFixtures;
use ContentGuard\Tests\Support\CoreCatalogFixtures;
use ContentGuard\Tests\Support\InMemoryAuditLock;
use ContentGuard\Tests\Support\InMemoryAuditPostScanner;
use ContentGuard\Tests\Support\InMemoryAuditStore;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class CoreAcfCompositionTest extends TestCase
{
    public function testCompositeCatalogMergesCoreAndAcfWithoutIdentityCollision(): void
    {
        $core      = CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('page'));
        $acf       = new AcfIntegration(AcfCloneFixtures::pageCatalog());
        $composite = new CompositeFieldCatalog(array($core, $acf));
        $byId      = $this->byId($composite->fieldsForPostType('page'));

        $this->assertSame(Integration::CORE, $byId[CoreFieldCatalog::TITLE]['integration']);
        $this->assertSame('WordPress', $byId[CoreFieldCatalog::TITLE]['group_label']);
        $this->assertSame('Title', $byId[CoreFieldCatalog::TITLE]['label']);
        $this->assertSame(Integration::ACF, $byId[AcfCloneFixtures::TITLE]['integration']);
        $this->assertSame('Title', $byId[AcfCloneFixtures::TITLE]['label']);
        $this->assertNotSame(CoreFieldCatalog::TITLE, AcfCloneFixtures::TITLE);
        $this->assertSame('text', $composite->fieldTypesForPostType('page')[CoreFieldCatalog::TITLE]);
        $this->assertSame('text', $composite->fieldTypesForPostType('page')[AcfCloneFixtures::TITLE]);

        $cloneId = AcfCloneFixtures::cloneATitlePosted();
        $this->assertSame('Shared Content → Title', $byId[$cloneId]['breadcrumb']);
        $this->assertSame(Integration::ACF, $byId[$cloneId]['integration']);
        $this->assertStringStartsWith('field_', $cloneId);
        $this->assertSame('title', $byId[CoreFieldCatalog::TITLE]['key']);
    }

    public function testAcfNestedCatalogEntriesRemainUnchangedBesideCore(): void
    {
        $core = CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('product'));
        $repeater = new CompositeFieldCatalog(array(
            $core,
            new AcfIntegration(AcfRepeaterFixtures::productCatalog()),
        ));
        $size = $this->byId($repeater->fieldsForPostType('product'))[AcfRepeaterFixtures::PRODUCT_SIZE];
        $this->assertSame('Product Information → Item Size → Product Size', $size['breadcrumb']);
        $this->assertSame('repeater', $size['container']);
        $this->assertSame(Integration::ACF, $size['integration']);

        $flex = new CompositeFieldCatalog(array(
            CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('page')),
            new AcfIntegration(AcfFlexibleFixtures::pageCatalog()),
        ));
        $hero = $this->byId($flex->fieldsForPostType('page'))[AcfFlexibleFixtures::HERO_TITLE];
        $this->assertSame('Modules → Hero → Title', $hero['breadcrumb']);
        $this->assertSame('flexible_content', $hero['container']);
        $this->assertSame(Integration::ACF, $hero['integration']);
    }

    public function testCptWithoutAcfFieldsStillExposesCoreFields(): void
    {
        $core = CoreCatalogFixtures::integration(CoreCatalogFixtures::catalog(
            array('book' => array('title', 'editor')),
            array('book')
        ));
        $acf = new AcfIntegration(new AcfFieldCatalog(static fn (): array => array()));
        $composite = new CompositeFieldCatalog(array($core, $acf));
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('book' => 'Book', 'empty' => 'Empty'),
            $composite
        );

        $this->assertArrayHasKey('book', $factory->selectablePostTypes());
        $this->assertArrayNotHasKey('empty', $factory->selectablePostTypes());
        $ids = array_column($factory->fieldsForPostType('book'), 'key');
        $this->assertSame(array(CoreFieldCatalog::TITLE, CoreFieldCatalog::CONTENT, CoreFieldCatalog::SLUG), $ids);
        $this->assertNotContains('field_title', $ids);
    }

    public function testCompositeProviderRoutesCoreAndAcfWithoutCollisions(): void
    {
        $core = CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('page'));
        $acf  = new AcfIntegration(AcfCloneFixtures::pageCatalog());
        $types = (new CompositeFieldCatalog(array($core, $acf)))->fieldTypesForPostType('page');
        $composite = new CompositeValueProvider(array(
            $core->storedProvider(9, 'page', $types, static function (string $id): mixed {
                return match ($id) {
                    CoreFieldCatalog::TITLE   => 'Core title',
                    CoreFieldCatalog::CONTENT => '<p>Body</p>',
                    default => 'core-leak',
                };
            }),
            $acf->storedProvider(9, 'page', $types, static function (string $key): mixed {
                return match ($key) {
                    AcfCloneFixtures::CLONE_A => array(
                        AcfCloneFixtures::cloneATitlePosted() => 'ACF clone',
                    ),
                    AcfCloneFixtures::TITLE => 'ACF source',
                    default => 'acf-leak',
                };
            }),
        ));

        $this->assertTrue($composite->has(CoreFieldCatalog::TITLE));
        $this->assertTrue($composite->has(CoreFieldCatalog::CONTENT));
        $this->assertTrue($composite->has(AcfCloneFixtures::TITLE));
        $this->assertTrue($composite->has(AcfCloneFixtures::cloneATitlePosted()));
        $this->assertSame('Core title', $composite->get(CoreFieldCatalog::TITLE));
        $this->assertSame('Body', $composite->get(CoreFieldCatalog::CONTENT));
        $this->assertSame('ACF source', $composite->get(AcfCloneFixtures::TITLE));
        $this->assertSame('ACF clone', $composite->get(AcfCloneFixtures::cloneATitlePosted()));
        $this->assertEquals(array(new FieldInstance('Core title')), $composite->instances(CoreFieldCatalog::TITLE));
        $this->assertFalse($core->storedProvider(9, 'page', $types, static fn (): mixed => 'x')->has(AcfCloneFixtures::TITLE));
        $this->assertFalse($acf->storedProvider(9, 'page', $types, static fn (): mixed => 'x')->has(CoreFieldCatalog::TITLE));
    }

    public function testRuleDocumentFactoryPersistsCoreFieldsWithoutIntegrationMetadata(): void
    {
        $factory = $this->factory();
        $rule = $factory->fromAdminInput(array(
            'name'             => 'Title is required',
            'target_post_type' => 'post',
            'validations'      => array(
                array(
                    'field_key' => CoreFieldCatalog::TITLE,
                    'type'      => 'required',
                ),
            ),
        ));

        $this->assertSame(Rule::SCHEMA_VERSION, $rule->schemaVersion);
        $this->assertSame(1, $rule->schemaVersion);
        $this->assertSame(CoreFieldCatalog::TITLE, $rule->validations[0]->field->key);
        $this->assertSame('post_title', $rule->validations[0]->field->name);
        $this->assertSame('Title', $rule->validations[0]->field->label);
        $this->assertSame(array(), $rule->validations[0]->field->path);
        $this->assertArrayNotHasKey('integration', $rule->validations[0]->field->toArray());
        $this->assertStringNotContainsString('core:', $rule->validations[0]->field->resolutionId());
        $this->assertStringNotContainsString('acf:', json_encode($rule->toArray()) ?: '');

        $acf = $factory->fromAdminInput(array(
            'name'             => 'ACF ingredients required',
            'target_post_type' => 'post',
            'validations'      => array(
                array(
                    'field_key' => 'field_ingredients',
                    'type'      => 'required',
                ),
            ),
        ));
        $this->assertSame('field_ingredients', $acf->validations[0]->field->key);
        $this->assertArrayNotHasKey('integration', $acf->validations[0]->field->toArray());
    }

    public function testForgedCoreMetadataIsIgnoredAndUnknownIdsAreRejected(): void
    {
        $factory = $this->factory();
        $rule = $factory->fromAdminInput(array(
            'name'             => 'Forged core metadata',
            'target_post_type' => 'post',
            'validations'      => array(
                array(
                    'field_key'     => CoreFieldCatalog::TITLE,
                    'type'          => 'required',
                    'path'          => array('field_forged', 'title'),
                    'container'     => FieldRef::CONTAINER_GROUP,
                    'layout'        => 'hero',
                    'clone'         => 'field_clone_forged',
                    'integration'   => 'acf',
                    'key'           => 'field_stolen',
                ),
            ),
        ));

        $field = $rule->validations[0]->field;
        $this->assertSame(CoreFieldCatalog::TITLE, $field->key);
        $this->assertSame(array(), $field->path);
        $this->assertSame('', $field->container);
        $this->assertSame('', $field->clone);
        $this->assertArrayNotHasKey('integration', $field->toArray());

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Unsupported field.');
        $factory->fromAdminInput(array(
            'name'             => 'Unknown core id',
            'target_post_type' => 'post',
            'validations'      => array(
                array(
                    'field_key' => 'core:title',
                    'type'      => 'required',
                ),
            ),
        ));
    }

    public function testRuleEngineEvaluatesCoreTitleValidatorsThroughTheProvider(): void
    {
        $engine = RuleEngine::v1();
        $types  = CoreCatalogFixtures::fullPost()->fieldTypesForPostType('post');
        $core   = CoreCatalogFixtures::integration();

        $required = RuleFactory::rule(array(
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'   => CoreCatalogFixtures::titleRef(),
                    'type'    => 'required',
                    'message' => 'Title is required.',
                )),
            ),
        ));
        $empty = $core->storedProvider(1, 'post', $types, static fn (): mixed => '');
        $this->assertTrue($engine->evaluate(array($required), $empty, 1)->results[0]->isFailed());

        $filled = $core->storedProvider(1, 'post', $types, static fn (string $id): mixed => $id === 'title' ? 'Hello' : null);
        $this->assertTrue($engine->evaluate(array($required), $filled, 1)->results[0]->isPassed());

        $min = RuleFactory::rule(array(
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'  => CoreCatalogFixtures::titleRef(),
                    'type'   => 'min_length',
                    'params' => array('min' => 5),
                )),
            ),
        ));
        $tooShort = $core->storedProvider(1, 'post', $types, static fn (string $id): mixed => $id === 'title' ? 'Hi' : null);
        $this->assertTrue($engine->evaluate(array($min), $tooShort, 1)->results[0]->isFailed());
        $long = $core->storedProvider(1, 'post', $types, static fn (string $id): mixed => $id === 'title' ? 'Hello world' : null);
        $this->assertTrue($engine->evaluate(array($min), $long, 1)->results[0]->isPassed());

        $max = RuleFactory::rule(array(
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'  => CoreCatalogFixtures::titleRef(),
                    'type'   => 'max_length',
                    'params' => array('max' => 4),
                )),
            ),
        ));
        $this->assertTrue($engine->evaluate(array($max), $filled, 1)->results[0]->isFailed());
        $short = $core->storedProvider(1, 'post', $types, static fn (string $id): mixed => $id === 'title' ? 'Hi' : null);
        $this->assertTrue($engine->evaluate(array($max), $short, 1)->results[0]->isPassed());

        $allowed = RuleFactory::rule(array(
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'  => CoreCatalogFixtures::titleRef(),
                    'type'   => 'allowed_values',
                    'params' => array('values' => array('Draft', 'Ready')),
                )),
            ),
        ));
        $wrong = $core->storedProvider(1, 'post', $types, static fn (string $id): mixed => $id === 'title' ? 'Hello' : null);
        $this->assertTrue($engine->evaluate(array($allowed), $wrong, 1)->results[0]->isFailed());
        $ok = $core->storedProvider(1, 'post', $types, static fn (string $id): mixed => $id === 'title' ? 'Ready' : null);
        $this->assertTrue($engine->evaluate(array($allowed), $ok, 1)->results[0]->isPassed());
    }

    public function testMixedAcfConditionAndCoreValidationUsesUnchangedRuleEngine(): void
    {
        $engine  = RuleEngine::v1();
        $core    = CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('product'));
        $acf     = new AcfIntegration($this->productAcfCatalog());
        $types   = (new CompositeFieldCatalog(array($core, $acf)))->fieldTypesForPostType('product');
        $rule    = RuleFactory::rule(array(
            'postType'    => 'product',
            'conditions'  => array(
                RuleFactory::condition(array(
                    'field'    => RuleFactory::field('field_type', 'product_type', 'Product Type'),
                    'operator' => 'equals',
                    'operand'  => 'sauce',
                )),
            ),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'   => CoreCatalogFixtures::titleRef(),
                    'message' => 'Title is required.',
                )),
            ),
        ));

        $failing = new CompositeValueProvider(array(
            $core->storedProvider(4, 'product', $types, static fn (string $id): mixed => $id === 'title' ? '' : null),
            $acf->storedProvider(4, 'product', $types, static fn (string $key): mixed => $key === 'field_type' ? 'sauce' : null),
        ));
        $failed = $engine->evaluate(array($rule), $failing, 4)->results[0];
        $this->assertTrue($failed->isFailed());
        $this->assertSame(CoreFieldCatalog::TITLE, $failed->fieldId);
        $this->assertSame('required', $failed->code);

        $passing = new CompositeValueProvider(array(
            $core->storedProvider(4, 'product', $types, static fn (string $id): mixed => $id === 'title' ? 'Marinara' : null),
            $acf->storedProvider(4, 'product', $types, static fn (string $key): mixed => $key === 'field_type' ? 'sauce' : null),
        ));
        $this->assertTrue($engine->evaluate(array($rule), $passing, 4)->results[0]->isPassed());

        $skipped = new CompositeValueProvider(array(
            $core->storedProvider(4, 'product', $types, static fn (string $id): mixed => $id === 'title' ? '' : null),
            $acf->storedProvider(4, 'product', $types, static fn (string $key): mixed => $key === 'field_type' ? 'pasta' : null),
        ));
        $this->assertTrue($engine->evaluate(array($rule), $skipped, 4)->results[0]->isSkipped());
    }

    public function testMixedCoreConditionAndAcfValidation(): void
    {
        $engine = RuleEngine::v1();
        $core   = CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('product'));
        $acf    = new AcfIntegration($this->productAcfCatalog());
        $types  = (new CompositeFieldCatalog(array($core, $acf)))->fieldTypesForPostType('product');
        $rule   = RuleFactory::rule(array(
            'postType'    => 'product',
            'conditions'  => array(
                RuleFactory::condition(array(
                    'field'    => CoreCatalogFixtures::titleRef(),
                    'operator' => 'equals',
                    'operand'  => 'Something',
                )),
            ),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'   => RuleFactory::field('field_ingredients', 'ingredients', 'Ingredients'),
                    'message' => 'Ingredients are required.',
                )),
            ),
        ));

        $failing = new CompositeValueProvider(array(
            $core->storedProvider(8, 'product', $types, static fn (string $id): mixed => $id === 'title' ? 'Something' : null),
            $acf->storedProvider(8, 'product', $types, static fn (string $key): mixed => $key === 'field_ingredients' ? '' : null),
        ));
        $failed = $engine->evaluate(array($rule), $failing, 8)->results[0];
        $this->assertTrue($failed->isFailed());
        $this->assertSame('field_ingredients', $failed->fieldId);

        $passing = new CompositeValueProvider(array(
            $core->storedProvider(8, 'product', $types, static fn (string $id): mixed => $id === 'title' ? 'Something' : null),
            $acf->storedProvider(8, 'product', $types, static fn (string $key): mixed => $key === 'field_ingredients' ? 'tomatoes' : null),
        ));
        $this->assertTrue($engine->evaluate(array($rule), $passing, 8)->results[0]->isPassed());
    }

    public function testAuditUsesCompositeProviderForCoreTitleFindings(): void
    {
        $core    = CoreCatalogFixtures::integration();
        $acf     = new AcfIntegration($this->productAcfCatalog());
        $catalog = new CompositeFieldCatalog(array($core, $acf));
        $rules   = array(
            RuleFactory::rule(array(
                'id'          => 1,
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'   => CoreCatalogFixtures::titleRef(),
                        'message' => 'Title is required.',
                    )),
                ),
            )),
            RuleFactory::rule(array(
                'id'          => 2,
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'   => RuleFactory::field('field_ingredients', 'ingredients', 'Ingredients'),
                        'message' => 'Ingredients are required.',
                    )),
                ),
            )),
        );
        $repository = new InMemoryRuleRepository($rules);
        $service = new ContentAuditService(
            new InMemoryAuditStore(),
            new InMemoryAuditPostScanner(array(
                array('id' => 11, 'postType' => 'post', 'status' => 'publish'),
                array('id' => 12, 'postType' => 'post', 'status' => 'publish'),
            )),
            new InMemoryAuditLock(),
            $repository,
            new ContentEvaluator($repository, RuleEngine::v1()),
            $catalog,
            static function (int $postId, string $postType, array $fieldTypes) use ($core, $acf): CompositeValueProvider {
                return new CompositeValueProvider(array(
                    $core->storedProvider(
                        $postId,
                        $postType,
                        $fieldTypes,
                        static function (string $id) use ($postId): mixed {
                            if ($id !== CoreFieldCatalog::TITLE) {
                                return null;
                            }

                            return $postId === 12 ? 'Published title' : '';
                        }
                    ),
                    $acf->storedProvider(
                        $postId,
                        $postType,
                        $fieldTypes,
                        static fn (string $key): mixed => $key === 'field_ingredients' ? 'tomatoes' : null
                    ),
                ));
            },
            static function (array $ids): void {
                unset($ids);
            },
            static fn (): int => 1_000_000,
            10
        );

        $run = $service->start(1);
        $service->processBatch($run->id);
        $findings = $service->getFindings($run->id);

        $this->assertCount(1, $findings);
        $this->assertSame(11, $findings[0]->postId);
        $this->assertSame(CoreFieldCatalog::TITLE, $findings[0]->fieldKey);
        $this->assertSame('Title is required.', $findings[0]->message);
        $this->assertSame('Title', AuditPresentation::fieldLabel($rules[0], $findings[0]->fieldKey));
        $this->assertNotSame('title', AuditPresentation::fieldLabel($rules[0], $findings[0]->fieldKey));
        $this->assertNotSame('field_ingredients', $findings[0]->fieldKey);
    }

    public function testRuleEngineSourceStaysFreeOfCoreBranches(): void
    {
        $engine = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Domain/RuleEngine.php');
        $ref    = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Domain/FieldRef.php');

        $this->assertStringNotContainsString('CoreIntegration', $engine);
        $this->assertStringNotContainsString('CoreFieldCatalog', $engine);
        $this->assertStringNotContainsString('WordPress Core', $engine);
        $this->assertStringNotContainsString("'integration'", $ref);
        $this->assertStringNotContainsString('CoreFieldRef', $ref);
    }

    private function factory(): RuleDocumentFactory
    {
        $core = CoreCatalogFixtures::integration();
        $acf  = new AcfIntegration($this->productAcfCatalog());

        return RuleDocumentFactory::v1(
            static fn (): array => array('post' => 'Post', 'product' => 'Product'),
            new CompositeFieldCatalog(array($core, $acf))
        );
    }

    private function productAcfCatalog(): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (string $postType): array {
                if (!in_array($postType, array('post', 'product'), true)) {
                    return array();
                }

                return array(
                    array(
                        'key'     => 'field_type',
                        'name'    => 'product_type',
                        'label'   => 'Product Type',
                        'type'    => 'select',
                        'choices' => array(
                            'sauce' => 'Sauce',
                            'pasta' => 'Pasta',
                        ),
                    ),
                    array(
                        'key'   => 'field_ingredients',
                        'name'  => 'ingredients',
                        'label' => 'Ingredients',
                        'type'  => 'textarea',
                    ),
                );
            }
        );
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
