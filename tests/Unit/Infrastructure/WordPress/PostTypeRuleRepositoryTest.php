<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\WordPress;

use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\Exception\RulePersistenceException;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Domain\RuleStatus;
use ContentGuard\Infrastructure\WordPress\PostTypeRuleRepository;
use ContentGuard\Infrastructure\WordPress\RulePostRecord;
use ContentGuard\Tests\Support\FakeRulePostStore;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class PostTypeRuleRepositoryTest extends TestCase
{
    private FakeRulePostStore $store;

    private PostTypeRuleRepository $repository;

    protected function setUp(): void
    {
        $this->store      = new FakeRulePostStore();
        $this->repository = new PostTypeRuleRepository($this->store, RuleDocumentValidator::v1());
    }

    public function testTrueFalseYesAndRequiredTextPersistAndReload(): void
    {
        $created = $this->repository->save(
            RuleFactory::rule(
                array(
                    'id'         => '',
                    'name'       => 'Page ID is required when Show New Tag is Yes',
                    'conditions' => array(
                        RuleFactory::condition(array(
                            'field'    => RuleFactory::field('field_show_new_tag', 'show_new_tag', 'Show New Tag'),
                            'operator' => 'equals',
                            'operand'  => '1',
                        )),
                    ),
                    'validations' => array(
                        RuleFactory::validation(array(
                            'field' => RuleFactory::field('field_page_id', 'page_id', 'Page ID'),
                            'type'  => 'required',
                        )),
                    ),
                )
            )
        );

        $this->assertSame(1, $created->id);
        $this->assertNotNull($this->store->get(1));
        $loaded = $this->repository->find(1);
        $this->assertNotNull($loaded);
        $this->assertSame('1', $loaded->conditions[0]->operand);
        $this->assertSame('required', $loaded->validations[0]->type);
        $this->assertSame('Page ID is required when Show New Tag is Yes', $this->repository->findAll()[0]->name);

        $no = $this->repository->save(
            RuleFactory::rule(
                array(
                    'id'         => '',
                    'name'       => 'Page ID is required when Show New Tag is No',
                    'conditions' => array(
                        RuleFactory::condition(array(
                            'field'    => RuleFactory::field('field_show_new_tag', 'show_new_tag', 'Show New Tag'),
                            'operator' => 'equals',
                            'operand'  => '0',
                        )),
                    ),
                    'validations' => array(
                        RuleFactory::validation(array(
                            'field' => RuleFactory::field('field_page_id', 'page_id', 'Page ID'),
                            'type'  => 'required',
                        )),
                    ),
                )
            )
        );

        $reloadedNo = $this->repository->find($no->id);
        $this->assertNotNull($reloadedNo);
        $this->assertSame('0', $reloadedNo->conditions[0]->operand);
    }

    public function testCreateStampsCptIdAndPersistsJson(): void
    {
        $created = $this->repository->save(RuleFactory::rule(array('id' => '')));

        $this->assertSame(1, $created->id);
        $this->assertSame(1, $this->store->insertCalls);
        $this->assertSame(1, $this->store->updateCalls);

        $record = $this->store->get(1);
        $this->assertNotNull($record);
        $decoded = json_decode($record->json, true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['id']);
        $this->assertSame(1, $decoded['schema_version']);
        $this->assertSame('product', $record->targetPostType);
        $this->assertSame('publish', $record->wpStatus);

        $loaded = $this->repository->find(1);
        $this->assertNotNull($loaded);
        $this->assertSame(1, $loaded->id);
        $this->assertSame('Example rule', $loaded->name);
    }

    public function testUpdateChangesPersistedDocument(): void
    {
        $created = $this->repository->save(RuleFactory::rule(array('id' => '', 'name' => 'Original')));
        $updated = $this->repository->save(
            RuleFactory::rule(
                array(
                    'id'     => $created->id,
                    'name'   => 'Updated',
                    'status' => RuleStatus::Inactive,
                )
            )
        );

        $this->assertSame('Updated', $updated->name);
        $this->assertSame(RuleStatus::Inactive, $updated->status);
        $this->assertSame('draft', $this->store->get((int) $created->id)?->wpStatus);
        $this->assertSame('Updated', $this->repository->find($created->id)?->name);
    }

    public function testDeleteRemovesRule(): void
    {
        $created = $this->repository->save(RuleFactory::rule(array('id' => '')));
        $this->assertTrue($this->repository->delete($created->id));
        $this->assertNull($this->repository->find($created->id));
        $this->assertFalse($this->repository->delete(999));
    }

    public function testActiveVersusInactiveAndPostTypeFiltering(): void
    {
        $this->repository->save(RuleFactory::rule(array('id' => '', 'name' => 'Active product')));
        $this->repository->save(
            RuleFactory::rule(
                array(
                    'id'     => '',
                    'name'   => 'Inactive product',
                    'status' => RuleStatus::Inactive,
                )
            )
        );
        $this->repository->save(
            RuleFactory::rule(
                array(
                    'id'       => '',
                    'name'     => 'Active page',
                    'postType' => 'page',
                )
            )
        );

        $activeProducts = $this->repository->findActiveForPostType('product');
        $this->assertCount(1, $activeProducts);
        $this->assertSame('Active product', $activeProducts[0]->name);
        $this->assertCount(1, $this->repository->findActiveForPostType('page'));
        $this->assertCount(0, $this->repository->findActiveForPostType('post'));
        $this->assertEqualsCanonicalizing(array('product', 'page'), $this->repository->findActivePostTypes());
        $this->assertCount(3, $this->repository->findAll());
    }

    public function testDuplicateStoreRowsForTheSameRuleAreLoadedOnce(): void
    {
        $this->repository->save(RuleFactory::rule(array('id' => '', 'name' => 'Sauces require ingredients')));
        $this->store->repeatEachFind = 3;

        $active = $this->repository->findActiveForPostType('product');

        $this->assertCount(1, $active);
        $this->assertSame('Sauces require ingredients', $active[0]->name);
    }

    public function testMalformedJsonIsSkippedOnRead(): void
    {
        $this->store->seed(new RulePostRecord(10, 'Broken', 'publish', '{not-json', 'product'));

        $this->assertNull($this->repository->find(10));
        $this->assertSame(array(), $this->repository->findActiveForPostType('product'));
    }

    /**
     * @dataProvider invalidDocumentProvider
     * @param array<string, mixed> $overrides
     */
    public function testInvalidDocumentsAreSkippedOnRead(array $overrides): void
    {
        $document = RuleFactory::document($overrides);
        if (isset($overrides['conditions'])) {
            $document['conditions'] = $overrides['conditions'];
        }
        if (isset($overrides['validations'])) {
            $document['validations'] = $overrides['validations'];
        }

        $this->store->seed(
            new RulePostRecord(
                11,
                'Invalid',
                'publish',
                (string) json_encode($document),
                'product'
            )
        );

        $this->assertNull($this->repository->find(11));
        $this->assertSame(array(), $this->repository->findActiveForPostType('product'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidDocumentProvider(): array
    {
        $emptyKey = RuleFactory::document();
        $emptyKey['conditions'][0]['field']['key'] = '';

        $unknownOperator = RuleFactory::document();
        $unknownOperator['conditions'][0]['operator'] = 'contains';

        $unknownValidator = RuleFactory::document();
        $unknownValidator['validations'][0]['type'] = 'regex';

        return array(
            'schema version'     => array(array('schema_version' => 2)),
            'condition logic'    => array(array('condition_logic' => 'or')),
            'severity'           => array(array('severity' => 'info')),
            'status'             => array(array('status' => 'archived')),
            'empty field key'    => array($emptyKey),
            'unknown operator'   => array($unknownOperator),
            'unknown validator'  => array($unknownValidator),
        );
    }

    public function testSaveRejectsUnknownOperatorBeforePersist(): void
    {
        $document = RuleFactory::document(array('id' => ''));
        $document['conditions'][0]['operator'] = 'contains';
        $rule = \ContentGuard\Domain\Rule::fromArray($document);

        try {
            $this->repository->save($rule);
            $this->fail('Expected InvalidRuleException');
        } catch (InvalidRuleException) {
            $this->assertSame(0, $this->store->insertCalls);
        }
    }

    public function testSaveRejectsUnknownValidatorBeforePersist(): void
    {
        $document = RuleFactory::document(array('id' => ''));
        $document['validations'][0]['type'] = 'regex';
        $rule = \ContentGuard\Domain\Rule::fromArray($document);

        $this->expectException(InvalidRuleException::class);
        $this->repository->save($rule);
    }

    public function testCachingAvoidsRepeatedQueriesUntilWrite(): void
    {
        $this->repository->save(RuleFactory::rule(array('id' => '')));
        $this->store->findCalls = 0;

        $this->repository->findActiveForPostType('product');
        $this->repository->findActiveForPostType('product');
        $this->assertSame(1, $this->store->findCalls);

        $this->repository->save(
            RuleFactory::rule(array('id' => '', 'name' => 'Second'))
        );
        $this->repository->findActiveForPostType('product');
        $this->assertSame(2, $this->store->findCalls);
        $this->assertCount(2, $this->repository->findActiveForPostType('product'));
        $this->assertSame(2, $this->store->findCalls);
    }

    public function testFailedStampUpdateDoesNotLeaveAPartialCreate(): void
    {
        $this->store->failNextUpdate = true;

        try {
            $this->repository->save(RuleFactory::rule(array('id' => '')));
            $this->fail('Expected RulePersistenceException');
        } catch (RulePersistenceException) {
            $this->assertSame(array(), $this->store->records);
        }
    }

    public function testEvaluatorReadsOnlyActiveRulesFromTheCptRepository(): void
    {
        $this->repository->save(RuleFactory::rule(array('id' => '')));
        $this->store->seed(
            new RulePostRecord(
                50,
                'Corrupt',
                'publish',
                '{"schema_version":1,"name":"x","post_type":"product","status":"active","severity":"fail","condition_logic":"and","conditions":[{"id":"c1","field":{"key":"field_type","name":"t","label":"T"},"operator":"contains","operand":"x"}],"validations":[]}',
                'product'
            )
        );

        $evaluator = new ContentEvaluator($this->repository, RuleEngine::v1());
        $evaluation = $evaluator->evaluate(
            9,
            'product',
            new ArrayValueProvider(
                array(
                    'field_type'        => 'sauce',
                    'field_ingredients' => 'tomatoes',
                )
            )
        );

        $this->assertTrue($evaluation->isPassed());
        $this->assertCount(1, $evaluation->appliedResults());
    }
}
