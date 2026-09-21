<?php
/**
 * Production Holland House Recipes: field group → Repeater → scalar.
 *
 * Recipes is an ACF field group, not an ACF Group field. The empty
 * Section Title sits on the Directions Repeater itself.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\Audit\AuditRepeaterCoordinates;
use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\EditorAuditIssues;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\EvaluationStatus;
use ContentGuard\Domain\FieldInstance;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\ACF\AcfIntegration;
use ContentGuard\Infrastructure\ACF\AcfSaveValidator;
use ContentGuard\Infrastructure\ACF\IntendedPostStatusResolver;
use ContentGuard\Tests\Support\InMemoryRuleRepository;
use ContentGuard\Tests\Support\AcfHollandHouseRecipeFixtures as HH;
use ContentGuard\Tests\Support\AcfNestedRepeaterFixtures;
use ContentGuard\Tests\Support\AcfRepeaterFixtures;
use ContentGuard\Tests\Support\InMemoryAuditLock;
use ContentGuard\Tests\Support\InMemoryAuditPostScanner;
use ContentGuard\Tests\Support\InMemoryAuditStore;
use ContentGuard\Tests\Support\IncomingSaveFixtures;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class HollandHouseRepeaterRowCoordinateTest extends TestCase
{
    public function testCatalogDiscoversSectionTitleAsOneLevelRepeaterChild(): void
    {
        $catalog = HH::catalog();
        $byKey   = array();
        foreach ($catalog->fieldsForPostType('recipes') as $field) {
            $byKey[$field->key] = $field;
        }

        $this->assertArrayNotHasKey(HH::FIELD_GROUP, $byKey);
        $this->assertArrayNotHasKey(HH::DIRECTIONS, $byKey);
        $this->assertArrayHasKey(HH::SECTION_TITLE, $byKey);

        $title = $byKey[HH::SECTION_TITLE];
        $this->assertSame('section_title', $title->name);
        $this->assertSame('Section Title', $title->label);
        $this->assertSame('text', $title->type);
        $this->assertSame('repeater', $title->container);
        $this->assertSame(HH::DIRECTIONS, $title->repeaterKey);
        $this->assertSame(array(HH::DIRECTIONS), $title->repeaterChain());
        $this->assertSame(array(HH::DIRECTIONS, HH::SECTION_TITLE), $title->path);
        $this->assertSame('Directions → Section Title', $title->breadcrumb());
        $this->assertFalse($title->isNestedRepeaterChild());
        $this->assertTrue($title->isBuilderSelectable());

        $this->assertArrayHasKey(HH::DIRECTION, $byKey);
        $direction = $byKey[HH::DIRECTION];
        $this->assertSame(array(HH::DIRECTIONS, HH::SECTION_DIRECTIONS), $direction->repeaterChain());
        $this->assertTrue($direction->isNestedRepeaterChild());
        $this->assertTrue($direction->isBuilderSelectable());
    }

    public function testIncomingAndStoredProvidersAttachTheDirectionsRow(): void
    {
        $integration = new AcfIntegration(HH::catalog());
        $types       = $integration->fieldTypesForPostType('recipes');

        $incoming = $integration->incomingProvider(
            HH::incomingDirectionsWithEmptySecondTitle(),
            'recipes',
            $types
        );
        $incomingRows = $incoming->instances(HH::SECTION_TITLE);
        $this->assertOneLevelDirectionsRows($incomingRows);

        $stored = $integration->storedProvider(
            374,
            'recipes',
            $types,
            static function (string $key): mixed {
                return $key === HH::DIRECTIONS
                    ? HH::storedDirectionsWithEmptySecondTitle()
                    : null;
            }
        );
        $storedRows = $stored->instances(HH::SECTION_TITLE);
        $this->assertOneLevelDirectionsRows($storedRows);
    }

    public function testRuleEnginePreservesTheOneLevelRowCoordinate(): void
    {
        $integration = new AcfIntegration(HH::catalog());
        $provider    = $integration->incomingProvider(
            HH::incomingDirectionsWithEmptySecondTitle(),
            'recipes',
            $integration->fieldTypesForPostType('recipes')
        );

        $evaluation = RuleEngine::v1()->evaluate(
            array($this->sectionTitleRule()),
            $provider,
            374
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertCount(1, $evaluation->results);
        $result = $evaluation->results[0];
        $this->assertSame(EvaluationStatus::Failed, $result->status);
        $this->assertSame(HH::SECTION_TITLE, $result->fieldId);
        $this->assertSame(2, $result->context['display_row']);
        $this->assertSame('row-1', $result->context['row_key']);
        $this->assertSame(1, $result->context['row_index']);
        $this->assertSame(
            'acf[' . HH::DIRECTIONS . '][row-1][' . HH::SECTION_TITLE . ']',
            $result->context['input_name']
        );
        $this->assertArrayNotHasKey('repeater_rows', $result->context);
        $this->assertSame('This field is required.', $result->message);
        $this->assertSame(array(), AuditRepeaterCoordinates::instanceChain($result->context));
    }

    public function testAuditAndEditorNameTheEmptyDirectionsRow(): void
    {
        $store   = new InMemoryAuditStore();
        $values  = array(
            374 => array(
                HH::SECTION_TITLE => array(
                    new FieldInstance('Preheat oven', array(
                        'display_row' => 1,
                        'row_key'     => 'row-0',
                        'row_index'   => 0,
                    )),
                    new FieldInstance('', array(
                        'display_row' => 2,
                        'row_key'     => 'row-1',
                        'row_index'   => 1,
                    )),
                    new FieldInstance('Serve', array(
                        'display_row' => 3,
                        'row_key'     => 'row-2',
                        'row_index'   => 2,
                    )),
                ),
            ),
        );
        $service = new ContentAuditService(
            $store,
            new InMemoryAuditPostScanner(array(
                array('id' => 374, 'postType' => 'recipes', 'status' => 'publish'),
            )),
            new InMemoryAuditLock(),
            new InMemoryRuleRepository(array($this->sectionTitleRule())),
            new ContentEvaluator(
                new InMemoryRuleRepository(array($this->sectionTitleRule())),
                RuleEngine::v1()
            ),
            new AcfIntegration(HH::catalog()),
            static function (int $postId) use (&$values): ArrayValueProvider {
                return new ArrayValueProvider($values[$postId] ?? array());
            },
            static function (array $ids): void {
                unset($ids);
            },
            static fn (): int => 1_000_000,
            1
        );

        $run      = $service->processBatch($service->start(1)->id);
        $findings = $store->findFindings($run->id);
        $this->assertCount(1, $findings);
        $this->assertSame('Section Title is required in row 2.', $findings[0]->message);
        $this->assertSame(array(), AuditRepeaterCoordinates::pairsFromFinding($findings[0]));

        $issues = EditorAuditIssues::fromEvaluation(
            RuleEngine::v1()->evaluate(
                array($this->sectionTitleRule()),
                new ArrayValueProvider($values[374]),
                374
            ),
            374
        );
        $this->assertCount(1, $issues);
        $this->assertSame('Section Title is required in row 2.', $issues[0]['message']);
        $this->assertArrayNotHasKey('affectedRows', $issues[0]);
        $this->assertArrayNotHasKey('layout', $issues[0]);
        $this->assertStringNotContainsString(
            'data-contentguard-display-row',
            EditorAuditIssues::issueHtml($issues[0])
        );
    }

    public function testSaveValidationTargetsTheEmptyDirectionsRowInput(): void
    {
        $errors     = array();
        $repository = new InMemoryRuleRepository(array($this->sectionTitleRule()));
        $catalog    = HH::catalog();
        $validator  = new AcfSaveValidator(
            $repository,
            $catalog,
            new IntendedPostStatusResolver(),
            static function (string $input, string $message) use (&$errors): void {
                $errors[] = array(
                    'input'   => $input,
                    'message' => $message,
                );
            },
            IncomingSaveFixtures::evaluator($repository, $catalog)
        );

        $validator->validate(
            array(
                'post_ID'     => 374,
                'post_type'   => 'recipes',
                'post_status' => 'auto-draft',
                'publish'     => 'Publish',
            ),
            HH::incomingDirectionsWithEmptySecondTitle()
        );

        $this->assertSame('', $errors[0]['input']);
        $this->assertStringContainsString('ContentGuard · Blocking', $errors[0]['message']);
        $this->assertStringContainsString('Section Title is required in row 2.', $errors[0]['message']);
        $this->assertStringContainsString('data-contentguard-field="' . HH::SECTION_TITLE . '"', $errors[0]['message']);
        $this->assertStringContainsString('data-contentguard-repeater-path=', $errors[0]['message']);
        $this->assertStringContainsString(HH::DIRECTIONS, $errors[0]['message']);
        $this->assertStringNotContainsString('data-contentguard-display-row', $errors[0]['message']);
        $this->assertSame(
            array(
                'input'   => 'acf[' . HH::DIRECTIONS . '][row-1][' . HH::SECTION_TITLE . ']',
                'message' => 'Directions → Section Title — This field is required in row 2.',
            ),
            $errors[1]
        );
    }

    public function testClassicSummaryNamesOneLevelAndNestedRowsWithoutAFieldQuery(): void
    {
        $errors     = array();
        $rules      = array(
            $this->ingredientTitleRule(),
            $this->sectionTitleRule(),
            $this->ingredientRule(),
        );
        $repository = new InMemoryRuleRepository($rules);
        $catalog    = HH::catalog();
        $validator  = new AcfSaveValidator(
            $repository,
            $catalog,
            new IntendedPostStatusResolver(),
            static function (string $input, string $message) use (&$errors): void {
                $errors[] = array(
                    'input'   => $input,
                    'message' => $message,
                );
            },
            IncomingSaveFixtures::evaluator($repository, $catalog)
        );

        $validator->validate(
            array(
                'post_ID'     => 374,
                'post_type'   => 'recipes',
                'post_status' => 'publish',
            ),
            array(
                HH::INGREDIENTS => array(
                    'row-0' => array(
                        HH::INGREDIENT_TITLE => 'Produce',
                        HH::SECTION_INGREDIENTS => $this->innerIngredientRows(14, 14),
                    ),
                    'row-1' => array(
                        HH::INGREDIENT_TITLE => '',
                        HH::SECTION_INGREDIENTS => array(
                            'row-0' => array(HH::INGREDIENT => 'Salt'),
                        ),
                    ),
                ),
                HH::DIRECTIONS => array(
                    'row-0' => array(HH::SECTION_TITLE => ''),
                ),
            )
        );

        $this->assertArrayNotHasKey('contentguard_field', array(
            'post_ID'     => 374,
            'post_type'   => 'recipes',
            'post_status' => 'publish',
        ));
        $this->assertSame('', $errors[0]['input']);
        $this->assertStringContainsString("ContentGuard · Blocking\n3 blocking issues\n", $errors[0]['message']);
        $this->assertStringContainsString(
            '>Ingredients → Ingredient Title</button> — Ingredient Title is required in row 2.',
            $errors[0]['message']
        );
        $this->assertStringContainsString(
            '>Directions → Section Title</button> — Section Title is required in row 1.',
            $errors[0]['message']
        );
        $this->assertStringContainsString(
            '>Ingredients → Section Ingredients → Ingredient</button> — Ingredient is required in row 1/14.',
            $errors[0]['message']
        );
        $this->assertStringContainsString('data-contentguard-field="' . HH::INGREDIENT_TITLE . '"', $errors[0]['message']);
        $this->assertStringContainsString('data-contentguard-field="' . HH::SECTION_TITLE . '"', $errors[0]['message']);
        $this->assertStringContainsString('data-contentguard-field="' . HH::INGREDIENT . '"', $errors[0]['message']);
        $this->assertStringContainsString('data-contentguard-repeater-path=', $errors[0]['message']);
        $this->assertStringContainsString(HH::INGREDIENTS, $errors[0]['message']);
        $this->assertStringContainsString(HH::SECTION_INGREDIENTS, $errors[0]['message']);
        $this->assertStringContainsString('&quot;display_row&quot;:14', $errors[0]['message']);
        $this->assertStringNotContainsString('data-contentguard-display-row', $errors[0]['message']);
        $this->assertCount(4, $errors);
        $this->assertNotSame('', $errors[1]['input']);
        $this->assertNotSame('', $errors[2]['input']);
        $this->assertNotSame('', $errors[3]['input']);
        $this->assertStringContainsString('This field is required in row 2.', $errors[1]['message'] . $errors[2]['message'] . $errors[3]['message']);
    }

    public function testClassicNestedDirectionSummaryUsesOuterInnerPair(): void
    {
        $errors     = array();
        $repository = new InMemoryRuleRepository(array($this->directionRule()));
        $catalog    = HH::catalog();
        $validator  = new AcfSaveValidator(
            $repository,
            $catalog,
            new IntendedPostStatusResolver(),
            static function (string $input, string $message) use (&$errors): void {
                $errors[] = array(
                    'input'   => $input,
                    'message' => $message,
                );
            },
            IncomingSaveFixtures::evaluator($repository, $catalog)
        );

        $inner = array();
        for ($i = 0; $i < 14; $i++) {
            $inner['row-' . $i] = array(
                HH::DIRECTION => $i === 13 ? '' : 'Step ' . ($i + 1),
            );
        }

        $validator->validate(
            array(
                'post_ID'     => 374,
                'post_type'   => 'recipes',
                'post_status' => 'publish',
            ),
            array(
                HH::DIRECTIONS => array(
                    'row-0' => array(
                        HH::SECTION_TITLE => 'Preheat',
                        HH::SECTION_DIRECTIONS => $inner,
                    ),
                ),
            )
        );

        $this->assertSame('', $errors[0]['input']);
        $this->assertStringContainsString(
            '>Directions → Section Directions → Direction</button> — Direction is required in row 1/14.',
            $errors[0]['message']
        );
        $this->assertStringContainsString('data-contentguard-field="' . HH::DIRECTION . '"', $errors[0]['message']);
        $this->assertStringContainsString(HH::DIRECTIONS, $errors[0]['message']);
        $this->assertStringContainsString(HH::SECTION_DIRECTIONS, $errors[0]['message']);
        $this->assertStringContainsString('&quot;display_row&quot;:14', $errors[0]['message']);
        $this->assertNotSame('', $errors[1]['input']);
    }

    public function testExistingOneLevelAndNestedRepeaterCoordinatesStayDistinct(): void
    {
        $plain = (new AcfIntegration(AcfRepeaterFixtures::recipeCatalog()))->incomingProvider(
            array(
                AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(AcfRepeaterFixtures::INGREDIENT => 'Salt'),
                    'row-1' => array(AcfRepeaterFixtures::INGREDIENT => ''),
                ),
            ),
            'recipe',
            array(AcfRepeaterFixtures::INGREDIENT => 'text')
        )->instances(AcfRepeaterFixtures::INGREDIENT);

        $this->assertSame(2, $plain[1]->context['display_row']);
        $this->assertArrayNotHasKey('repeater_rows', $plain[1]->context);

        $nested = (new AcfIntegration(AcfNestedRepeaterFixtures::recipeCatalog()))->incomingProvider(
            array(
                AcfNestedRepeaterFixtures::DIRECTIONS => array(
                    'row-0' => array(
                        AcfNestedRepeaterFixtures::STEPS => array(
                            'row-0' => array(AcfNestedRepeaterFixtures::STEP_NAME => ''),
                        ),
                    ),
                ),
            ),
            'recipe',
            array(AcfNestedRepeaterFixtures::STEP_NAME => 'text')
        )->instances(AcfNestedRepeaterFixtures::STEP_NAME);

        $this->assertCount(1, $nested);
        $this->assertArrayHasKey('repeater_rows', $nested[0]->context);
        $this->assertCount(2, $nested[0]->context['repeater_rows']);
        $this->assertSame(1, $nested[0]->context['repeater_rows'][0]['display_row']);
        $this->assertSame(1, $nested[0]->context['repeater_rows'][1]['display_row']);
    }

    /**
     * @param list<FieldInstance> $instances
     */
    private function assertOneLevelDirectionsRows(array $instances): void
    {
        $this->assertCount(3, $instances);
        $this->assertSame('Preheat oven', $instances[0]->value);
        $this->assertNull($instances[1]->value);
        $this->assertSame('Serve', $instances[2]->value);
        $this->assertSame(array(1, 2, 3), array_map(
            static fn (FieldInstance $instance): int => (int) $instance->context['display_row'],
            $instances
        ));
        $this->assertSame('row-1', $instances[1]->context['row_key']);
        $this->assertSame(1, $instances[1]->context['row_index']);
        $this->assertSame(
            'acf[' . HH::DIRECTIONS . '][row-1][' . HH::SECTION_TITLE . ']',
            $instances[1]->context['input_name']
        );
        $this->assertArrayNotHasKey('repeater_rows', $instances[1]->context);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function innerIngredientRows(int $count, int $emptyDisplayRow): array
    {
        $rows = array();
        for ($i = 0; $i < $count; $i++) {
            $rows['row-' . $i] = array(
                HH::INGREDIENT => ($i + 1) === $emptyDisplayRow ? '' : 'Item ' . ($i + 1),
            );
        }

        return $rows;
    }

    private function sectionTitleRule(): \ContentGuard\Domain\Rule
    {
        return RuleFactory::rule(array(
            'id'          => 74,
            'postType'    => 'recipes',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-section-title',
                    'field'      => HH::sectionTitleRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
    }

    private function directionRule(): \ContentGuard\Domain\Rule
    {
        return RuleFactory::rule(array(
            'id'          => 75,
            'postType'    => 'recipes',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-direction',
                    'field'      => HH::directionRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
    }

    private function ingredientTitleRule(): \ContentGuard\Domain\Rule
    {
        return RuleFactory::rule(array(
            'id'          => 73,
            'postType'    => 'recipes',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-ingredient-title',
                    'field'      => HH::ingredientTitleRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
    }

    private function ingredientRule(): \ContentGuard\Domain\Rule
    {
        return RuleFactory::rule(array(
            'id'          => 76,
            'postType'    => 'recipes',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'         => 'v-ingredient',
                    'field'      => HH::ingredientRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
    }
}
