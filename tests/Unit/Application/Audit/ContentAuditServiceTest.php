<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application\Audit;

use ContentGuard\Application\Audit\AuditFindingQuery;
use ContentGuard\Application\Audit\AuditRepeaterCoordinates;
use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\Exception\AuditException;
use ContentGuard\Application\AuditPresentation;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\FieldInstance;
use ContentGuard\Domain\FieldRef;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\AcfIntegration;
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
use ContentGuard\Tests\Support\InMemoryAuditLock;
use ContentGuard\Tests\Support\InMemoryAuditPostScanner;
use ContentGuard\Tests\Support\InMemoryAuditStore;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class ContentAuditServiceTest extends TestCase
{
    private InMemoryAuditStore $store;

    private InMemoryAuditLock $lock;

    protected function setUp(): void
    {
        $this->store  = new InMemoryAuditStore();
        $this->lock   = new InMemoryAuditLock();
        $this->now    = 1_000_000;
        $this->values = array();
    }

    private int $now = 1_000_000;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $values = array();

    public function testStartWithActiveRulesAndZeroEligiblePostsCompletesImmediately(): void
    {
        $service = $this->service(array($this->signatureRule()), array(), new InMemoryAuditPostScanner(array()));
        $run     = $service->start(3);

        $this->assertSame(AuditRunStatus::Complete, $run->status);
        $this->assertSame(array('recipe'), $run->postTypes);
        $this->assertSame(0, $run->postsTotal);
        $this->assertSame(0, $run->postsScanned);
        $this->assertSame($run->id, $service->getLatestCompleteRun()?->id);
        $this->assertFalse($this->lock->held);
    }

    public function testStartCreatesPendingRunAndPreventsDuplicate(): void
    {
        $service = $this->service();
        $first   = $service->start(7);
        $second  = $service->start(8);

        $this->assertSame(AuditRunStatus::Pending, $first->status);
        $this->assertSame(0, $first->cursor);
        $this->assertSame(array('recipe'), $first->postTypes);
        $this->assertSame(2, $first->postsTotal);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(7, $second->actorUserId);
        $this->assertTrue($this->lock->held);
    }

    public function testCompleteRunAndLatestCompleteIsDistinctFromFailed(): void
    {
        $service = $this->service();
        $run     = $service->start(1);
        $run     = $service->processBatch($run->id);
        $run     = $service->processBatch($run->id);
        $run     = $service->processBatch($run->id);

        $this->assertSame(AuditRunStatus::Complete, $run->status);
        $this->assertFalse($this->lock->held);
        $this->assertSame($run->id, $service->getLatestCompleteRun()?->id);

        $this->values[20] = array(
            'field_signature'   => true,
            'field_description' => '',
        );
        $failed = $service->start(1);
        $this->store->failNextFindingWrite = true;
        $failed = $service->processBatch($failed->id);

        $this->assertSame(AuditRunStatus::Failed, $failed->status);
        $this->assertSame($run->id, $service->getLatestCompleteRun()?->id);
        $this->assertSame($failed->id, $service->getLatestRun()?->id);
    }

    public function testCancelIsNotAFailure(): void
    {
        $service = $this->service();
        $run     = $service->cancel($service->start(1)->id);

        $this->assertSame(AuditRunStatus::Cancelled, $run->status);
        $this->assertFalse($this->lock->held);
        $this->assertNull($service->getLatestCompleteRun());
    }

    public function testStaleActiveRunIsRecoveredBeforeNewStart(): void
    {
        $service = $this->service();
        $stale   = $service->start(1);
        $this->now += ContentAuditService::STALE_AFTER_SECONDS + 1;

        $next = $service->start(2);

        $this->assertSame(AuditRunStatus::Failed, $this->store->findRun($stale->id)?->status);
        $this->assertSame('Audit run timed out.', $this->store->findRun($stale->id)?->errorMessage);
        $this->assertNotSame($stale->id, $next->id);
        $this->assertSame(AuditRunStatus::Pending, $next->status);
    }

    public function testCursorAdvancesOnlyAfterSuccessfulPersistence(): void
    {
        $service = $this->service();
        $run     = $service->processBatch($service->start(1)->id);

        $this->assertSame(AuditRunStatus::Running, $run->status);
        $this->assertSame(10, $run->cursor);
        $this->assertSame(1, $run->postsFailed);
        $this->assertSame(0, $run->postsPassed);
        $this->assertCount(1, $this->store->findFindings($run->id));

        $this->store->failNextFindingWrite = true;
        $failed = $service->processBatch($run->id);

        $this->assertSame(AuditRunStatus::Failed, $failed->status);
        $this->assertSame(10, $failed->cursor);
        $this->assertSame(1, $failed->postsScanned);
    }

    public function testReplayingABatchIsIdempotent(): void
    {
        $service = $this->service();
        $run     = $service->processBatch($service->start(1)->id);
        $this->store->replaceFindingsForPosts(
            $run->id,
            array(10),
            $this->store->findFindings($run->id)
        );

        $this->assertCount(1, $this->store->findFindings($run->id));
    }

    public function testPassedResultsAreNotPersistedAndWarningsAre(): void
    {
        $this->values = array(
            10 => array(
                'field_signature'   => true,
                'field_description' => 'Filled',
            ),
            20 => array(
                'field_signature'   => true,
                'field_description' => '',
            ),
        );

        $warning = RuleFactory::rule(
            array(
                'id'         => 2,
                'postType'   => 'recipe',
                'severity'   => RuleSeverity::Warning,
                'conditions' => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'id'    => 'v-warn',
                            'field' => RuleFactory::field('field_title', 'title', 'Title'),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );

        $service = $this->service(array($this->signatureRule(), $warning), array(
            array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'),
            array('id' => 20, 'postType' => 'recipe', 'status' => 'publish'),
        ));

        $run = $service->processBatch($service->start(1)->id);
        $run = $service->processBatch($run->id);
        $run = $service->processBatch($run->id);

        $this->assertSame(AuditRunStatus::Complete, $run->status);
        $this->assertSame(0, $run->postsPassed);
        $this->assertSame(1, $run->postsWarned);
        $this->assertSame(1, $run->postsFailed);
        $severities = array_map(
            static fn ($finding): string => $finding->severity->value,
            $this->store->findFindings($run->id)
        );
        $this->assertContains('fail', $severities);
        $this->assertContains('warning', $severities);
        $this->assertGreaterThanOrEqual(2, count($this->store->findFindings($run->id)));
        foreach ($this->store->findFindings($run->id) as $finding) {
            $this->assertNotSame('passed', $finding->severity->value);
        }
    }

    public function testConditionOnlyMatchIsRecordedAsAFinding(): void
    {
        $this->values = array(
            10 => array(
                'field_description' => 'A healthy salad',
            ),
            20 => array(
                'field_description' => 'A tasty salad',
            ),
        );

        $rule = RuleFactory::rule(array(
            'id'          => 9,
            'postType'    => 'recipe',
            'conditions'  => array(
                RuleFactory::condition(array(
                    'field'    => RuleFactory::field(
                        'field_description',
                        'recipe_description',
                        'Recipe Description'
                    ),
                    'operator' => 'contains',
                    'operand'  => 'healthy',
                )),
            ),
            'validations' => array(),
            'message'     => 'Please avoid the term "healthy" in recipe content.',
        ));

        $service = $this->service(array($rule), array(
            array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'),
            array('id' => 20, 'postType' => 'recipe', 'status' => 'publish'),
        ));

        $run = $service->processBatch($service->start(1)->id);
        $run = $service->processBatch($run->id);
        $run = $service->processBatch($run->id);

        $this->assertSame(AuditRunStatus::Complete, $run->status);
        $this->assertSame(1, $run->postsFailed);
        $this->assertSame(1, $run->postsNotEvaluated);
        $findings = $this->store->findFindings($run->id);
        $this->assertCount(1, $findings);
        $this->assertSame(9, $findings[0]->ruleId);
        $this->assertSame('field_description', $findings[0]->fieldKey);
        $this->assertSame('fail', $findings[0]->severity->value);
        $this->assertSame('condition_matched', $findings[0]->code);
        $this->assertSame('Please avoid the term "healthy" in recipe content.', $findings[0]->message);
        $this->assertSame('', $findings[0]->validationId);
    }

    public function testDraftsAndOtherTypesAreNotScanned(): void
    {
        $scanner = new InMemoryAuditPostScanner(
            array(
                array('id' => 1, 'postType' => 'recipe', 'status' => 'draft'),
                array('id' => 2, 'postType' => 'recipe', 'status' => 'publish'),
                array('id' => 3, 'postType' => 'page', 'status' => 'publish'),
                array('id' => 4, 'postType' => 'recipe', 'status' => 'private'),
            )
        );
        $service = $this->service(array($this->signatureRule()), array(), $scanner);
        $run     = $service->start(1);

        $this->assertSame(2, $run->postsTotal);

        $first = $service->processBatch($run->id);
        $this->assertSame(2, $first->cursor);
        $second = $service->processBatch($first->id);
        $this->assertSame(4, $second->cursor);
        $done = $service->processBatch($second->id);
        $this->assertSame(AuditRunStatus::Complete, $done->status);
        $this->assertSame(2, $done->postsScanned);
    }

    public function testInactiveRulesAreIgnoredAndUnsupportedFieldsAreNotRead(): void
    {
        $inactive = RuleFactory::rule(
            array(
                'id'       => 9,
                'postType' => 'recipe',
                'status'   => RuleStatus::Inactive,
            )
        );
        $service = $this->service(array($this->signatureRule(), $inactive));
        $this->values[10]['field_secret'] = 'nope';
        $run = $service->processBatch($service->start(1)->id);

        $this->assertSame(1, $run->postsFailed);
        $this->assertSame('field_description', $this->store->findFindings($run->id)[0]->fieldKey);
    }

    public function testDistinctRulesWithTheSameNameAndValidationIdPersistSeparately(): void
    {
        $rules = array();
        foreach (array(652, 656, 659) as $id) {
            $rules[] = RuleFactory::rule(
                array(
                    'id'         => $id,
                    'name'       => 'Sauces require ingredients',
                    'postType'   => 'recipe',
                    'conditions' => array(
                        RuleFactory::condition(
                            array(
                                'field'    => RuleFactory::field('field_signature', 'is_signature', 'Is Signature'),
                                'operator' => 'equals',
                                'operand'  => '1',
                            )
                        ),
                    ),
                    'validations' => array(
                        RuleFactory::validation(
                            array(
                                'id'    => 'v1',
                                'field' => RuleFactory::field(
                                    'field_description',
                                    'recipe_description',
                                    'Recipe Description'
                                ),
                            )
                        ),
                    ),
                )
            );
        }

        $this->values = array(
            47 => array(
                'field_signature'   => true,
                'field_description' => '',
            ),
        );
        $service = $this->service(
            $rules,
            array(array('id' => 47, 'postType' => 'recipe', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $findings = $this->store->findFindings($run->id);
        $this->assertCount(3, $findings);
        $this->assertSame(array('652', '656', '659'), array_map(static fn ($finding): string => (string) $finding->ruleId, $findings));
        $this->assertSame(array('v1', 'v1', 'v1'), array_map(static fn ($finding): string => $finding->validationId, $findings));
        $this->assertSame(1, $run->postsFailed);

        $query = new AuditFindingQuery($run->id);
        $this->assertSame(3, $service->countFindings($query));
        $this->assertSame(1, $service->countDistinctPosts($query));
        $this->assertNotSame($run->postsFailed + $run->postsWarned, $service->countFindings($query));
        $this->assertCount(3, $service->getFindings($run->id));
        $this->assertCount(1, $service->queryFindings(new AuditFindingQuery($run->id, null, 656)));
    }

    public function testTwoValidationsOnTheSameFieldPersistSeparately(): void
    {
        $rule = RuleFactory::rule(
            array(
                'id'          => 5,
                'postType'    => 'recipe',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'id'     => 'v-required',
                            'field'  => RuleFactory::field('field_description', 'recipe_description', 'Recipe Description'),
                            'type'   => 'required',
                        )
                    ),
                    RuleFactory::validation(
                        array(
                            'id'     => 'v-min',
                            'field'  => RuleFactory::field('field_description', 'recipe_description', 'Recipe Description'),
                            'type'   => 'min_length',
                            'params' => array('min' => 20),
                        )
                    ),
                ),
            )
        );
        $this->values = array(
            10 => array('field_description' => ''),
        );
        $service = $this->service(
            array($rule),
            array(array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $findings = $this->store->findFindings($run->id);
        $this->assertCount(2, $findings);
        $ids = array_map(static fn ($finding): string => $finding->validationId, $findings);
        $this->assertContains('v-required', $ids);
        $this->assertContains('v-min', $ids);
    }

    public function testGroupChildFindingsKeepLeafFieldKeyAndDistinctValidations(): void
    {
        $nested = new FieldRef(
            'field_ingredients',
            'ingredients',
            'Product Details → Ingredients',
            array('field_product_details', 'field_ingredients'),
            'group'
        );
        $rule = RuleFactory::rule(
            array(
                'id'         => 31,
                'postType'   => 'recipe',
                'conditions' => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'id'    => 'v-required',
                            'field' => $nested,
                            'type'  => 'required',
                        )
                    ),
                    RuleFactory::validation(
                        array(
                            'id'     => 'v-min',
                            'field'  => $nested,
                            'type'   => 'min_length',
                            'params' => array('min' => 8),
                        )
                    ),
                ),
            )
        );
        $this->values = array(
            10 => array('field_ingredients' => ''),
        );
        $service = $this->service(
            array($rule),
            array(array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $findings = $this->store->findFindings($run->id);
        $this->assertCount(2, $findings);
        foreach ($findings as $finding) {
            $this->assertSame($run->id, $finding->runId);
            $this->assertSame(10, $finding->postId);
            $this->assertSame(31, $finding->ruleId);
            $this->assertSame('field_ingredients', $finding->fieldKey);
        }
        $ids = array_map(static fn ($finding): string => $finding->validationId, $findings);
        $this->assertContains('v-required', $ids);
        $this->assertContains('v-min', $ids);
        $this->assertSame(
            'Product Details → Ingredients',
            AuditPresentation::fieldLabel($rule, 'field_ingredients')
        );
    }

    public function testStoredGroupChildAuditTracksPopulatedAndEmptyValues(): void
    {
        $nested = new FieldRef(
            'field_ingredients',
            'item_ingredients',
            'Product Information → Ingredients Accordion',
            array('field_product_details', 'field_ingredients'),
            'group'
        );
        $rule = RuleFactory::rule(
            array(
                'id'         => 32,
                'postType'   => 'recipe',
                'conditions' => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'id'    => 'v-required',
                            'field' => $nested,
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );

        $store = array(
            10 => array(
                'field_ingredients' => '',
                'field_product_details' => array(
                    'field_ingredients' => '',
                ),
            ),
            20 => array(
                'field_ingredients' => '',
                'field_product_details' => array(
                    'field_ingredients' => 'Tomatoes, salt',
                ),
            ),
        );
        $catalog = new AcfFieldCatalog(
            static function (string $postType): array {
                if ($postType !== 'recipe') {
                    return array();
                }

                return array(
                    array(
                        'key'        => 'field_product_details',
                        'name'       => 'product_information',
                        'label'      => 'Product Information',
                        'type'       => 'group',
                        'sub_fields' => array(
                            array(
                                'key'   => 'field_ingredients',
                                'name'  => 'item_ingredients',
                                'label' => 'Ingredients Accordion',
                                'type'  => 'textarea',
                            ),
                        ),
                    ),
                );
            }
        );
        $repository = new InMemoryRuleRepository(array($rule));
        $service = new ContentAuditService(
            $this->store,
            new InMemoryAuditPostScanner(array(
                array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'),
                array('id' => 20, 'postType' => 'recipe', 'status' => 'publish'),
            )),
            $this->lock,
            $repository,
            new ContentEvaluator($repository, RuleEngine::v1()),
            new AcfIntegration($catalog),
            static function (int $postId, string $postType, array $fieldTypes) use ($catalog, &$store): \ContentGuard\Infrastructure\ACF\AcfStoredValueProvider {
                $maps = $catalog->nestedResolutionMaps($postType, $fieldTypes);

                return new \ContentGuard\Infrastructure\ACF\AcfStoredValueProvider(
                    $postId,
                    new \ContentGuard\Infrastructure\ACF\AcfValueNormalizer(),
                    $fieldTypes,
                    static fn (string $key): mixed => $store[$postId][$key] ?? null,
                    $maps['paths'],
                    $maps['names']
                );
            },
            static function (array $ids): void {
                unset($ids);
            },
            function (): int {
                return $this->now;
            },
            1
        );

        $run = $service->processBatch($service->start(1)->id);
        $run = $service->processBatch($run->id);
        $run = $service->processBatch($run->id);

        $findings = $this->store->findFindings($run->id);
        $this->assertCount(1, $findings);
        $this->assertSame(10, $findings[0]->postId);
        $this->assertSame('field_ingredients', $findings[0]->fieldKey);
        $this->assertSame(32, $findings[0]->ruleId);
        $this->assertSame('v-required', $findings[0]->validationId);
        $this->assertSame(
            'Product Information → Ingredients Accordion',
            AuditPresentation::fieldLabel($rule, 'field_ingredients')
        );
    }

    public function testInvalidRunIdAndInactiveStateAreRejected(): void
    {
        $service = $this->service();
        $run     = $service->cancel($service->start(1)->id);

        try {
            $service->processBatch(0);
            $this->fail('Expected AuditException');
        } catch (AuditException $exception) {
            $this->assertSame('Invalid audit run.', $exception->getMessage());
        }

        try {
            $service->processBatch(999);
            $this->fail('Expected AuditException');
        } catch (AuditException $exception) {
            $this->assertSame('Audit run not found.', $exception->getMessage());
        }

        try {
            $service->processBatch($run->id);
            $this->fail('Expected AuditException');
        } catch (AuditException $exception) {
            $this->assertSame('This audit run is no longer active.', $exception->getMessage());
        }
    }

    public function testRollupFailedBeatsWarningAndNotEvaluatedIsCounted(): void
    {
        $pageRule = RuleFactory::rule(
            array(
                'id'         => 3,
                'postType'   => 'page',
                'conditions' => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => RuleFactory::field('field_title', 'title', 'Title'),
                        )
                    ),
                ),
            )
        );
        $this->values = array(
            10 => array(
                'field_signature'   => true,
                'field_description' => '',
                'field_title'       => '',
            ),
            20 => array(
                'field_signature'   => false,
                'field_description' => '',
            ),
        );

        $service = $this->service(
            array($this->signatureRule(), $pageRule),
            array(
                array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'),
                array('id' => 20, 'postType' => 'recipe', 'status' => 'publish'),
            )
        );
        $run = $service->processBatch($service->start(1)->id);
        $run = $service->processBatch($run->id);
        $run = $service->processBatch($run->id);

        $this->assertSame(AuditRunStatus::Complete, $run->status);
        $this->assertSame(1, $run->postsFailed);
        $this->assertSame(0, $run->postsWarned);
        $this->assertSame(0, $run->postsPassed);
        $this->assertSame(1, $run->postsNotEvaluated);
        $this->assertSame(2, $run->postsScanned);
    }

    public function testBlockingFindingsForPostIgnoreOtherPostsAndIncompleteRuns(): void
    {
        $service = $this->service();
        $run     = $this->store->insertRun(1, array('recipe'), 2, '2026-01-01 00:00:00');
        $this->store->saveRun($run->withStatus(AuditRunStatus::Complete, '2026-01-01 00:01:00'));

        $this->store->replaceFindingsForPosts(1, array(42, 99), array(
            new \ContentGuard\Application\Audit\AuditFinding(
                0,
                1,
                42,
                'recipe',
                15,
                'field_description',
                'v1',
                'required',
                RuleSeverity::Fail,
                'Description is required',
                '2026-01-01 00:00:00'
            ),
            new \ContentGuard\Application\Audit\AuditFinding(
                0,
                1,
                42,
                'recipe',
                15,
                'field_yield',
                'v2',
                'required',
                RuleSeverity::Fail,
                'Yield is required',
                '2026-01-01 00:00:00'
            ),
            new \ContentGuard\Application\Audit\AuditFinding(
                0,
                1,
                99,
                'recipe',
                15,
                'field_description',
                'v1',
                'required',
                RuleSeverity::Fail,
                'Secret from another post',
                '2026-01-01 00:00:00'
            ),
            new \ContentGuard\Application\Audit\AuditFinding(
                0,
                1,
                42,
                'recipe',
                15,
                'field_description',
                'v3',
                'min_length',
                RuleSeverity::Warning,
                'This looks thin.',
                '2026-01-01 00:00:00'
            ),
        ));

        $blockers = $service->blockingFindingsForPost(1, 42);

        $this->assertCount(2, $blockers);
        $this->assertSame(array(42, 42), array_map(static fn ($finding) => $finding->postId, $blockers));
        $this->assertSame(
            array('Description is required', 'Yield is required'),
            array_map(static fn ($finding) => $finding->message, $blockers)
        );
        $this->assertSame(array(), $service->blockingFindingsForPost(1, 77));
        $this->assertSame(array(), $service->blockingFindingsForPost(0, 42));
        $this->assertSame(array(), $service->blockingFindingsForPost(999, 42));

        $pending = $this->store->insertRun(1, array('recipe'), 1, '2026-01-01 00:00:00');
        $this->assertSame(array(), $service->blockingFindingsForPost($pending->id, 42));
    }

    public function testRepeaterFailuresCollapseToOneFindingWithRowSnapshot(): void
    {
        $rule = RuleFactory::rule(array(
            'id'         => 60,
            'postType'   => 'recipe',
            'conditions' => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-required',
                    'field'      => \ContentGuard\Tests\Support\AcfRepeaterFixtures::ingredientRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
        $this->values = array(
            10 => array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT => array(
                    new FieldInstance('', array('display_row' => 1)),
                    new FieldInstance('Salt', array('display_row' => 2)),
                    new FieldInstance('', array('display_row' => 3)),
                    new FieldInstance('', array('display_row' => 5)),
                ),
            ),
        );
        $service = $this->service(
            array($rule),
            array(array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $findings = $this->store->findFindings($run->id);
        $this->assertCount(1, $findings);
        $this->assertSame($run->id, $findings[0]->runId);
        $this->assertSame(10, $findings[0]->postId);
        $this->assertSame(60, $findings[0]->ruleId);
        $this->assertSame(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT, $findings[0]->fieldKey);
        $this->assertSame('v-required', $findings[0]->validationId);
        $this->assertSame('required', $findings[0]->code);
        $this->assertSame('Ingredient is required in 3 rows (rows 1, 3, 5).', $findings[0]->message);
        $this->assertSame(array(), AuditRepeaterCoordinates::pairsFromFinding($findings[0]));
        $this->assertObjectNotHasProperty('instanceKey', $findings[0]);
        $this->assertObjectNotHasProperty('rowIndex', $findings[0]);
    }

    public function testFlexibleFailuresCollapseToOneFindingWithLayoutSnapshot(): void
    {
        $rule = RuleFactory::rule(array(
            'id'         => 80,
            'postType'   => 'page',
            'conditions' => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-required',
                    'field'      => \ContentGuard\Tests\Support\AcfFlexibleFixtures::heroTitleRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
        $this->values = array(
            10 => array(
                \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => array(
                    new FieldInstance('', array(
                        'display_row' => 2,
                        'layout'      => 'hero',
                    )),
                    new FieldInstance('', array(
                        'display_row' => 5,
                        'layout'      => 'hero',
                    )),
                ),
            ),
        );
        $service = $this->service(
            array($rule),
            array(array('id' => 10, 'postType' => 'page', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $findings = $this->store->findFindings($run->id);
        $this->assertCount(1, $findings);
        $this->assertSame($run->id, $findings[0]->runId);
        $this->assertSame(10, $findings[0]->postId);
        $this->assertSame(80, $findings[0]->ruleId);
        $this->assertSame(\ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE, $findings[0]->fieldKey);
        $this->assertSame('v-required', $findings[0]->validationId);
        $this->assertSame('required', $findings[0]->code);
        $this->assertSame('Title is required in 2 Hero rows (rows 2, 5).', $findings[0]->message);
        $this->assertSame(array(), AuditRepeaterCoordinates::pairsFromFinding($findings[0]));
        $this->assertObjectNotHasProperty('instanceKey', $findings[0]);
        $this->assertObjectNotHasProperty('rowIndex', $findings[0]);
        $this->assertObjectNotHasProperty('layout', $findings[0]);
    }

    public function testFlexibleSingleRowSnapshotNamesTheLayoutRowWithoutPersistingIt(): void
    {
        $rule = RuleFactory::rule(array(
            'id'         => 82,
            'postType'   => 'page',
            'conditions' => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-required',
                    'field'      => \ContentGuard\Tests\Support\AcfFlexibleFixtures::heroTitleRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
        $this->values = array(
            10 => array(
                \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => array(
                    new FieldInstance('', array(
                        'display_row' => 3,
                        'layout'      => 'hero',
                    )),
                ),
            ),
        );
        $service = $this->service(
            array($rule),
            array(array('id' => 10, 'postType' => 'page', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $findings = $this->store->findFindings($run->id);
        $this->assertCount(1, $findings);
        $this->assertSame('Title is required in Hero row 3.', $findings[0]->message);
        $this->assertObjectNotHasProperty('display_row', $findings[0]);
        $this->assertObjectNotHasProperty('rowIndex', $findings[0]);
        $this->assertObjectNotHasProperty('layout', $findings[0]);
    }

    public function testFlexibleZeroLayoutsCreateNoFinding(): void
    {
        $rule = RuleFactory::rule(array(
            'id'         => 81,
            'postType'   => 'page',
            'conditions' => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-required',
                    'field'      => \ContentGuard\Tests\Support\AcfFlexibleFixtures::heroTitleRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
        $this->values = array(
            10 => array(
                \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => array(),
            ),
        );
        $service = $this->service(
            array($rule),
            array(array('id' => 10, 'postType' => 'page', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $this->assertSame(array(), $this->store->findFindings($run->id));
    }

    public function testRepeaterOneRowAndZeroRowAuditMessages(): void
    {
        $rule = RuleFactory::rule(array(
            'id'         => 61,
            'postType'   => 'product',
            'conditions' => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-required',
                    'field'      => \ContentGuard\Tests\Support\AcfRepeaterFixtures::productSizeRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));

        $this->values = array(
            10 => array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE => array(
                    new FieldInstance('', array('display_row' => 2)),
                ),
            ),
            20 => array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE => array(),
            ),
        );
        $service = $this->service(
            array($rule),
            array(
                array('id' => 10, 'postType' => 'product', 'status' => 'publish'),
                array('id' => 20, 'postType' => 'product', 'status' => 'publish'),
            )
        );
        $run = $service->processBatch($service->start(1)->id);
        $run = $service->processBatch($run->id);

        $findings = $this->store->findFindings($run->id);
        $this->assertCount(2, $findings);
        $byPost = array();
        foreach ($findings as $finding) {
            $byPost[$finding->postId] = $finding;
            $this->assertSame(\ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE, $finding->fieldKey);
            $this->assertSame('v-required', $finding->validationId);
        }
        $this->assertSame('Product Size is required in row 2.', $byPost[10]->message);
        $this->assertSame(array(), AuditRepeaterCoordinates::pairsFromFinding($byPost[10]));
        $this->assertSame('Add at least one Item Size row.', $byPost[20]->message);
        $this->assertSame(array(), AuditRepeaterCoordinates::pairsFromFinding($byPost[20]));
        $this->assertSame('no_rows', $byPost[20]->code);
        $this->assertSame(
            'Product Information → Item Size → Product Size',
            AuditPresentation::fieldLabel($rule, \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE)
        );
    }

    public function testNestedRepeaterFailuresRetainEveryOuterInnerCoordinate(): void
    {
        $rule = RuleFactory::rule(array(
            'id'          => 90,
            'postType'    => 'recipe',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-required',
                    'field'      => \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::stepNameRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
        $this->values = array(
            10 => array(
                \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME => array(
                    $this->nestedCell('', 1, 1),
                    $this->nestedCell('', 1, 2),
                    $this->nestedCell('Grill', 2, 2),
                    $this->nestedCell('', 2, 1),
                ),
            ),
        );
        $service = $this->service(
            array($rule),
            array(array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $findings = $this->store->findFindings($run->id);
        $this->assertCount(1, $findings);
        $this->assertSame(\ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME, $findings[0]->fieldKey);
        $this->assertSame('required', $findings[0]->code);
        $this->assertSame('Name is required in 3 rows (rows 1/1, 1/2, 2/1).', $findings[0]->message);
        $this->assertStringContainsString('1/1', $findings[0]->message);
        $this->assertStringContainsString('1/2', $findings[0]->message);
        $this->assertStringContainsString('2/1', $findings[0]->message);
        $this->assertSame(
            array(array(1, 1), array(1, 2), array(2, 1)),
            AuditRepeaterCoordinates::pairsFromFinding($findings[0])
        );
        $this->assertSame(1, $findings[0]->context['repeater_rows'][0][0]['display_row']);
        $this->assertSame(1, $findings[0]->context['repeater_rows'][0][1]['display_row']);
        $this->assertSame(2, $findings[0]->context['repeater_rows'][2][0]['display_row']);
        $this->assertSame(1, $findings[0]->context['repeater_rows'][2][1]['display_row']);
        $this->assertArrayNotHasKey('display_row', $findings[0]->context);
        $this->assertObjectNotHasProperty('display_row', $findings[0]);
    }

    public function testSingleNestedRepeaterFailureNamesTheOuterInnerRow(): void
    {
        $rule = RuleFactory::rule(array(
            'id'          => 97,
            'postType'    => 'recipe',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-required',
                    'field'      => \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::stepNameRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
        $this->values = array(
            10 => array(
                \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME => array(
                    $this->nestedCell('', 1, 1),
                ),
            ),
        );
        $service = $this->service(
            array($rule),
            array(array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $finding = $this->store->findFindings($run->id)[0];
        $this->assertSame('Name is required in row 1/1.', $finding->message);
        $this->assertSame(array(array(1, 1)), AuditRepeaterCoordinates::pairsFromFinding($finding));
    }

    public function testHistoricalNestedSnapshotStillPresentsPairsAfterStoreRoundTrip(): void
    {
        $rule = RuleFactory::rule(array(
            'id'          => 98,
            'postType'    => 'recipe',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-required',
                    'field'      => \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::stepNameRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
        $this->values = array(
            10 => array(
                \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME => array(
                    $this->nestedCell('', 1, 1),
                    $this->nestedCell('', 1, 2),
                    $this->nestedCell('', 2, 1),
                ),
            ),
        );
        $service = $this->service(
            array($rule),
            array(array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $stored = $this->store->findFindings($run->id)[0];
        $hydrated = new \ContentGuard\Application\Audit\AuditFinding(
            $stored->id,
            $stored->runId,
            $stored->postId,
            $stored->postType,
            $stored->ruleId,
            $stored->fieldKey,
            $stored->validationId,
            $stored->code,
            $stored->severity,
            $stored->message,
            $stored->createdAt,
            AuditRepeaterCoordinates::contextFromCells(
                AuditRepeaterCoordinates::cellsFromSnapshot($stored->message)
            )
        );

        $this->assertSame('Name is required in 3 rows (rows 1/1, 1/2, 2/1).', $hydrated->message);
        $this->assertSame(
            array(array(1, 1), array(1, 2), array(2, 1)),
            AuditRepeaterCoordinates::pairsFromFinding($hydrated)
        );
        $this->assertSame(array('1/1', '1/2', '2/1'), AuditRepeaterCoordinates::tokensFromMessages(array($hydrated->message)));
    }

    public function testStoredNestedProviderAuditSnapshotShowsPairedCoordinates(): void
    {
        $rule = RuleFactory::rule(array(
            'id'          => 99,
            'postType'    => 'recipe',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-required',
                    'field'      => \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::stepNameRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
        $catalog = \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::recipeCatalog();
        $types   = $catalog->fieldTypesForPostType('recipe');
        $maps    = $catalog->nestedResolutionMaps('recipe', $types);
        $payload = array(
            array(
                \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEPS => array(
                    array(\ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME => ''),
                    array(\ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME => ''),
                ),
            ),
            array(
                \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEPS => array(
                    array(\ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME => ''),
                ),
            ),
        );
        $repository = new InMemoryRuleRepository(array($rule));
        $service = new ContentAuditService(
            $this->store,
            new InMemoryAuditPostScanner(array(
                array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'),
            )),
            $this->lock,
            $repository,
            new ContentEvaluator($repository, RuleEngine::v1()),
            new AcfIntegration($catalog),
            static function () use ($types, $maps, $payload): \ContentGuard\Infrastructure\ACF\AcfStoredValueProvider {
                return new \ContentGuard\Infrastructure\ACF\AcfStoredValueProvider(
                    10,
                    new \ContentGuard\Infrastructure\ACF\AcfValueNormalizer(),
                    $types,
                    static function (string $key) use ($payload): mixed {
                        return $key === \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::DIRECTIONS
                            ? $payload
                            : null;
                    },
                    $maps['paths'],
                    $maps['names'],
                    $maps['repeater_keys'],
                    $maps['flex_keys'] ?? array(),
                    $maps['layouts'] ?? array(),
                    $maps['clone_keys'] ?? array(),
                    $maps['repeater_chains'] ?? array()
                );
            },
            static function (array $ids): void {
                unset($ids);
            },
            function (): int {
                return $this->now;
            },
            1
        );
        $run = $service->processBatch($service->start(1)->id);

        $finding = $this->store->findFindings($run->id)[0];
        $this->assertSame('Name is required in 3 rows (rows 1/1, 1/2, 2/1).', $finding->message);
        $this->assertSame(
            array(array(1, 1), array(1, 2), array(2, 1)),
            AuditRepeaterCoordinates::pairsFromFinding($finding)
        );
    }

    public function testNestedRepeaterCollapseKeepsSameOuterAndCrossOuterCellsDistinct(): void
    {
        $rule = RuleFactory::rule(array(
            'id'          => 91,
            'postType'    => 'recipe',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-required',
                    'field'      => \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::stepNameRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
        $this->values = array(
            10 => array(
                \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME => array(
                    $this->nestedCell('', 1, 2),
                    $this->nestedCell('', 1, 3),
                    $this->nestedCell('', 2, 2),
                ),
            ),
        );
        $service = $this->service(
            array($rule),
            array(array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $pairs = AuditRepeaterCoordinates::pairsFromFinding($this->store->findFindings($run->id)[0]);
        $this->assertSame(array(array(1, 2), array(1, 3), array(2, 2)), $pairs);
        $this->assertNotContains(array(2, 1), $pairs);
    }

    public function testMixedNestedAndScalarFailuresStaySeparateFindings(): void
    {
        $nested = RuleFactory::rule(array(
            'id'          => 92,
            'postType'    => 'recipe',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-nested',
                    'field'      => \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::stepNameRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
        $scalar = RuleFactory::rule(array(
            'id'          => 93,
            'postType'    => 'recipe',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'    => 'v-desc',
                    'field' => RuleFactory::field('field_description', 'recipe_description', 'Recipe Description'),
                )),
            ),
        ));
        $this->values = array(
            10 => array(
                'field_description' => '',
                \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME => array(
                    $this->nestedCell('', 3, 3),
                    $this->nestedCell('', 3, 4),
                ),
            ),
        );
        $service = $this->service(
            array($nested, $scalar),
            array(array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $findings = $this->store->findFindings($run->id);
        $this->assertCount(2, $findings);
        $byField = array();
        foreach ($findings as $finding) {
            $byField[$finding->fieldKey] = $finding;
        }
        $this->assertSame(
            array(array(3, 3), array(3, 4)),
            AuditRepeaterCoordinates::pairsFromFinding($byField[\ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME])
        );
        $this->assertSame(
            'Name is required in 2 rows (rows 3/3, 3/4).',
            $byField[\ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME]->message
        );
        $this->assertSame(array(), AuditRepeaterCoordinates::pairsFromFinding($byField['field_description']));
        $this->assertSame('This field is required.', $byField['field_description']->message);
    }

    public function testNestedEmptyOuterAndEmptyInnerUseFieldLevelNoRows(): void
    {
        $rule = RuleFactory::rule(array(
            'id'          => 94,
            'postType'    => 'recipe',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-required',
                    'field'      => \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::stepNameRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
        $this->values = array(
            10 => array(
                \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME => array(),
            ),
            20 => array(
                \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME => array(),
            ),
        );
        $service = $this->service(
            array($rule),
            array(
                array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'),
                array('id' => 20, 'postType' => 'recipe', 'status' => 'publish'),
            )
        );
        $run = $service->processBatch($service->start(1)->id);
        $run = $service->processBatch($run->id);

        $findings = $this->store->findFindings($run->id);
        $this->assertCount(2, $findings);
        foreach ($findings as $finding) {
            $this->assertSame('no_rows', $finding->code);
            $this->assertSame('Add at least one Steps row.', $finding->message);
            $this->assertSame(array(), AuditRepeaterCoordinates::pairsFromFinding($finding));
        }
    }

    public function testCloneAndCoreAuditFindingsDoNotGainRepeaterCoordinates(): void
    {
        $clone = RuleFactory::rule(array(
            'id'          => 95,
            'postType'    => 'recipe',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'    => 'v-clone',
                    'field' => RuleFactory::field(
                        \ContentGuard\Tests\Support\AcfCloneFixtures::cloneATitlePosted(),
                        'title',
                        'Shared Content → Title'
                    ),
                )),
            ),
        ));
        $core = RuleFactory::rule(array(
            'id'          => 96,
            'postType'    => 'recipe',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'    => 'v-core',
                    'field' => RuleFactory::field('title', 'post_title', 'Title'),
                )),
            ),
        ));
        $this->values = array(
            10 => array(
                \ContentGuard\Tests\Support\AcfCloneFixtures::cloneATitlePosted() => '',
                'title' => '',
            ),
        );
        $service = $this->service(
            array($clone, $core),
            array(array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'))
        );
        $run = $service->processBatch($service->start(1)->id);

        $findings = $this->store->findFindings($run->id);
        $this->assertCount(2, $findings);
        foreach ($findings as $finding) {
            $this->assertSame(array(), AuditRepeaterCoordinates::pairsFromFinding($finding));
            $this->assertSame(array(), $finding->context);
            $this->assertSame('This field is required.', $finding->message);
        }
    }

    public function testEvaluateStoredPostUsesCurrentValuesNotPersistedFindings(): void
    {
        $this->values = array(
            10 => array(
                'field_signature'   => true,
                'field_description' => 'Filled after the audit',
            ),
        );
        $service = $this->service(
            array($this->signatureRule()),
            array(array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'))
        );

        $evaluation = $service->evaluateStoredPost(10, 'recipe');
        $this->assertNotNull($evaluation);
        $this->assertTrue($evaluation->isPassed());
        $this->assertNull($service->evaluateStoredPost(0, 'recipe'));
        $this->assertNull($service->evaluateStoredPost(10, ''));
    }

    /**
     * @param array<int, \ContentGuard\Domain\Rule> $rules
     * @param array<int, array{id: int, postType: string, status: string}> $posts
     */
    private function service(
        array $rules = array(),
        array $posts = array(),
        ?InMemoryAuditPostScanner $scanner = null,
    ): ContentAuditService {
        if ($rules === array()) {
            $rules = array($this->signatureRule());
        }

        if ($posts === array() && $scanner === null) {
            $posts = array(
                array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'),
                array('id' => 20, 'postType' => 'recipe', 'status' => 'private'),
            );
        }

        if ($this->values === array()) {
            $this->values = array(
                10 => array(
                    'field_signature'   => true,
                    'field_description' => '',
                ),
                20 => array(
                    'field_signature'   => true,
                    'field_description' => 'Filled',
                ),
            );
        }

        $repository = new InMemoryRuleRepository($rules);
        $catalog    = new AcfFieldCatalog(
            static function (string $postType): array {
                if ($postType !== 'recipe' && $postType !== 'page') {
                    return array();
                }

                return array(
                    array(
                        'key'   => 'field_signature',
                        'name'  => 'is_signature',
                        'label' => 'Is Signature',
                        'type'  => 'true_false',
                    ),
                    array(
                        'key'   => 'field_description',
                        'name'  => 'recipe_description',
                        'label' => 'Recipe Description',
                        'type'  => 'textarea',
                    ),
                    array(
                        'key'   => 'field_title',
                        'name'  => 'title',
                        'label' => 'Title',
                        'type'  => 'text',
                    ),
                );
            }
        );

        $values = &$this->values;

        return new ContentAuditService(
            $this->store,
            $scanner ?? new InMemoryAuditPostScanner($posts),
            $this->lock,
            $repository,
            new ContentEvaluator($repository, RuleEngine::v1()),
            new AcfIntegration($catalog),
            static function (int $postId, string $postType, array $fieldTypes) use (&$values): ArrayValueProvider {
                unset($postType, $fieldTypes);

                return new ArrayValueProvider($values[$postId] ?? array());
            },
            static function (array $ids): void {
                unset($ids);
            },
            function (): int {
                return $this->now;
            },
            1
        );
    }

    private function nestedCell(mixed $value, int $outer, int $inner): FieldInstance
    {
        return new FieldInstance($value, array(
            'repeater_rows' => array(
                array(
                    'repeater'    => \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::DIRECTIONS,
                    'key'         => 'row-' . ($outer - 1),
                    'index'       => $outer - 1,
                    'display_row' => $outer,
                ),
                array(
                    'repeater'    => \ContentGuard\Tests\Support\AcfNestedRepeaterFixtures::STEPS,
                    'key'         => 'row-' . ($inner - 1),
                    'index'       => $inner - 1,
                    'display_row' => $inner,
                ),
            ),
        ));
    }

    private function signatureRule(): \ContentGuard\Domain\Rule
    {
        return RuleFactory::rule(
            array(
                'id'         => 1,
                'postType'   => 'recipe',
                'conditions' => array(
                    RuleFactory::condition(
                        array(
                            'field'    => RuleFactory::field('field_signature', 'is_signature', 'Is Signature'),
                            'operator' => 'equals',
                            'operand'  => '1',
                        )
                    ),
                ),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => RuleFactory::field(
                                'field_description',
                                'recipe_description',
                                'Recipe Description'
                            ),
                        )
                    ),
                ),
            )
        );
    }
}
