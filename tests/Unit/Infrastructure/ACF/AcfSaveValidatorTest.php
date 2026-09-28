<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\ACF;

use ContentLatch\Application\RuleDocumentValidator;
use ContentLatch\Application\RuleRepositoryInterface;
use ContentLatch\Domain\FieldRef;
use ContentLatch\Domain\Rule;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;
use ContentLatch\Infrastructure\ACF\AcfSaveValidator;
use ContentLatch\Infrastructure\ACF\IntendedPostStatusResolver;
use ContentLatch\Tests\Support\InMemoryRuleRepository;
use ContentLatch\Infrastructure\WordPress\PostTypeRuleRepository;
use ContentLatch\Infrastructure\WordPress\RulePostRecord;
use ContentLatch\Tests\Support\FakeRulePostStore;
use ContentLatch\Tests\Support\IncomingSaveFixtures;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AcfSaveValidatorTest extends TestCase
{
    /**
     * @var array<int, array{input: string, message: string}>
     */
    private array $errors = array();

    public function testMatchingConditionEmptyRequiredFieldOnPublishBlocks(): void
    {
        $this->validate($this->publishRequest(), $this->signaturePayload(''));

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[field_description]',
                'message' => 'Recipe Description — This field is required.',
            ),
        ));
        $this->assertStringContainsString('>Recipe Description</button> — This field is required.', $this->errors[0]['message']);
        $this->assertStringContainsString('data-contentlatch-field="field_description"', $this->errors[0]['message']);
        $this->assertStringNotContainsString('contentlatch_field', json_encode($this->errors) ?: '');
    }

    public function testMatchingConditionFilledFieldOnPublishDoesNotError(): void
    {
        $this->validate($this->publishRequest(), $this->signaturePayload('A signature recipe.'));

        $this->assertSame(array(), $this->errors);
    }

    public function testNonMatchingConditionEmptyFieldOnPublishDoesNotError(): void
    {
        $this->validate(
            $this->publishRequest(),
            array(
                'field_signature'   => '0',
                'field_description' => '',
            )
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testClassicEditorAcfAjaxPublishOfDraftIsBlocked(): void
    {
        $this->validate(
            $this->request(
                array(
                    'action'               => 'acf/validate_save_post',
                    'post_status'          => 'draft',
                    'original_post_status' => 'draft',
                    '_acf_screen'          => 'post',
                    '_acf_post_id'         => '42',
                )
            ),
            $this->signaturePayload('')
        );

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[field_description]',
                'message' => 'Recipe Description — This field is required.',
            ),
        ));
    }

    public function testGutenbergAcfAjaxPublishWithoutPostStatusIsBlocked(): void
    {
        $this->validate(
            $this->gutenbergAcfAjaxRequest(),
            $this->signaturePayload('')
        );

        $this->assertCount(1, $this->errors);
        $this->assertSame('acf[field_description]', $this->errors[0]['input']);
        $this->assertSame('Recipe Description — This field is required.', $this->errors[0]['message']);
        $this->assertGutenbergContentLatchMetadata($this->errors[0], array(
            'field'    => 'field_description',
            'fieldKey' => 'field_description',
            'label'    => 'Recipe Description',
            'message'  => 'This field is required.',
        ));
    }

    public function testRealRecipeFieldKeysOnAcfAjaxPublishAreBlocked(): void
    {
        $rule = RuleFactory::rule(
            array(
                'id'          => 1,
                'name'        => 'Signature requires description',
                'postType'    => 'recipe',
                'conditions'  => array(
                    RuleFactory::condition(
                        array(
                            'field'    => RuleFactory::field(
                                'field_65021edb3fb73',
                                'is_signature',
                                'Is Signature'
                            ),
                            'operator' => 'equals',
                            'operand'  => '1',
                        )
                    ),
                ),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => RuleFactory::field(
                                'field_64f8a42a61f56',
                                'recipe_description',
                                'Recipe Description'
                            ),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );

        $catalog = new AcfFieldCatalog(
            static function (string $postType): array {
                if ($postType !== 'recipe') {
                    return array();
                }

                return array(
                    array(
                        'key'   => 'field_65021edb3fb73',
                        'name'  => 'is_signature',
                        'label' => 'Is Signature',
                        'type'  => 'true_false',
                    ),
                    array(
                        'key'   => 'field_64f8a42a61f56',
                        'name'  => 'recipe_description',
                        'label' => 'Recipe Description',
                        'type'  => 'textarea',
                    ),
                );
            }
        );

        $this->validateWith(
            new InMemoryRuleRepository(array($rule)),
            $catalog,
            $this->gutenbergAcfAjaxRequest(array(
                'post_ID'              => 42,
                'post_type'            => 'recipe',
                'original_post_status' => 'draft',
                '_acf_screen'          => 'post',
                '_acf_post_id'         => '42',
            )),
            array(
                'field_65021edb3fb73' => '1',
                'field_64f8a42a61f56' => '',
            )
        );

        $this->assertCount(1, $this->errors);
        $this->assertSame('acf[field_64f8a42a61f56]', $this->errors[0]['input']);
        $this->assertSame('Recipe Description — This field is required.', $this->errors[0]['message']);
        $this->assertGutenbergContentLatchMetadata($this->errors[0], array(
            'field'    => 'field_64f8a42a61f56',
            'fieldKey' => 'field_64f8a42a61f56',
            'label'    => 'Recipe Description',
            'message'  => 'This field is required.',
        ));

        $this->validateWith(
            new InMemoryRuleRepository(array($rule)),
            $catalog,
            array(
                'action'               => 'acf/validate_save_post',
                'post_ID'              => 42,
                'post_type'            => 'recipe',
                'original_post_status' => 'draft',
                '_acf_screen'          => 'post',
                '_acf_post_id'         => '42',
            ),
            array(
                'field_65021edb3fb73' => '1',
                'field_64f8a42a61f56' => 'A signature recipe.',
            )
        );
        $this->assertSame(array(), $this->errors);

        $this->validateWith(
            new InMemoryRuleRepository(array($rule)),
            $catalog,
            array(
                'action'               => 'acf/validate_save_post',
                'post_ID'              => 42,
                'post_type'            => 'recipe',
                'original_post_status' => 'draft',
                '_acf_screen'          => 'post',
                '_acf_post_id'         => '42',
            ),
            array(
                'field_65021edb3fb73' => '0',
                'field_64f8a42a61f56' => '',
            )
        );
        $this->assertSame(array(), $this->errors);
    }

    public function testMatchingFailureOnDraftIsAllowed(): void
    {
        $this->validate(
            $this->request(
                array(
                    'post_status' => 'draft',
                    'save'        => 'Save Draft',
                )
            ),
            $this->signaturePayload('')
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testSwitchingAPublishedPostToDraftIsAllowed(): void
    {
        $this->validate(
            $this->request(
                array(
                    'post_status'          => 'draft',
                    'original_post_status' => 'publish',
                    'save'                 => 'Save Draft',
                )
            ),
            $this->signaturePayload('')
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testMatchingFailureOnPrivateIsBlocked(): void
    {
        $this->validate(
            $this->request(
                array(
                    'post_status' => 'draft',
                    'private'     => 'Private',
                )
            ),
            $this->signaturePayload('')
        );

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[field_description]',
                'message' => 'Recipe Description — This field is required.',
            ),
        ));
    }

    public function testWarningSeverityNeverBlocks(): void
    {
        $this->validate(
            $this->publishRequest(),
            $this->signaturePayload(''),
            array($this->signatureRule(array('severity' => RuleSeverity::Warning)))
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testMultipleFailingRulesReportEveryError(): void
    {
        $titleRule = RuleFactory::rule(
            array(
                'id'          => 2,
                'name'        => 'Title required',
                'postType'    => 'recipe',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => RuleFactory::field('field_title', 'recipe_title', 'Recipe Title'),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );

        $this->validate(
            $this->publishRequest(),
            array(
                'field_signature'   => '1',
                'field_description' => '',
                'field_title'       => '',
            ),
            array($this->signatureRule(), $titleRule)
        );

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[field_description]',
                'message' => 'Recipe Description — This field is required.',
            ),
            array(
                'input'   => 'acf[field_title]',
                'message' => 'Recipe Title — This field is required.',
            ),
        ));
    }

    public function testCustomRuleMessageIsUsed(): void
    {
        $rule = $this->signatureRule(
            array(
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field'   => RuleFactory::field(
                                'field_description',
                                'recipe_description',
                                'Recipe Description'
                            ),
                            'type'    => 'required',
                            'message' => 'Please add a signature recipe description.',
                        )
                    ),
                ),
            )
        );

        $this->validate($this->publishRequest(), $this->signaturePayload(''), array($rule));

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[field_description]',
                'message' => 'Recipe Description — Please add a signature recipe description.',
            ),
        ));
    }

    public function testUnknownNonCatalogFieldKeyIsRejected(): void
    {
        $unknownConditionRule = RuleFactory::rule(
            array(
                'id'         => 9,
                'postType'   => 'recipe',
                'conditions' => array(
                    RuleFactory::condition(
                        array(
                            'field'    => RuleFactory::field('field_not_in_catalog', 'secret', 'Secret'),
                            'operator' => 'equals',
                            'operand'  => 'yes',
                        )
                    ),
                ),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => RuleFactory::field('field_description', 'recipe_description', 'Recipe Description'),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );

        $this->validate(
            $this->publishRequest(),
            array(
                'field_not_in_catalog' => 'yes',
                'field_description'    => '',
                'recipe_description'   => 'not a key',
            ),
            array($unknownConditionRule)
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testMalformedPersistedRuleIsSkippedWithoutFatal(): void
    {
        $store = new FakeRulePostStore();
        $store->seed(new RulePostRecord(10, 'Broken', 'publish', '{not-json', 'recipe'));
        $store->seed(
            new RulePostRecord(
                11,
                'Valid',
                'publish',
                (string) json_encode($this->signatureRule(array('id' => 11))->toArray()),
                'recipe'
            )
        );

        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $this->validateWith(
            $repository,
            $this->recipeCatalog(),
            $this->publishRequest(),
            $this->signaturePayload('')
        );

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[field_description]',
                'message' => 'Recipe Description — This field is required.',
            ),
        ));
        $this->assertSame(1, $store->findCalls);
    }

    public function testIncomingTrueFalseUsesTheSameDomainEvaluationAsStored(): void
    {
        $this->validate($this->publishRequest(), $this->signaturePayload(''));
        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[field_description]',
                'message' => 'Recipe Description — This field is required.',
            ),
        ));

        $this->errors = array();
        $this->validate(
            $this->publishRequest(),
            array(
                'field_signature'   => 1,
                'field_description' => '',
            )
        );
        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[field_description]',
                'message' => 'Recipe Description — This field is required.',
            ),
        ));

        $this->errors = array();
        $this->validate(
            $this->publishRequest(),
            array(
                'field_signature'   => '0',
                'field_description' => '',
            )
        );
        $this->assertSame(array(), $this->errors);
    }

    public function testNonAcfSaveDoesNotError(): void
    {
        $this->validate($this->publishRequest(), null);
        $this->assertSame(array(), $this->errors);

        $this->validate(
            array(
                'post_ID'     => 42,
                'post_type'   => 'recipe',
                'post_status' => 'publish',
                'save'        => 'Update',
            ),
            'not-an-array'
        );
        $this->assertSame(array(), $this->errors);
    }

    public function testHeartbeatAndAutosaveAreIgnored(): void
    {
        $this->validate(
            $this->publishRequest(array('action' => 'heartbeat')),
            $this->signaturePayload('')
        );
        $this->assertSame(array(), $this->errors);

        $this->validate(
            $this->publishRequest(array('action' => 'autosave')),
            $this->signaturePayload('')
        );
        $this->assertSame(array(), $this->errors);
    }

    public function testUnexpectedExceptionFailsSafely(): void
    {
        $catalog = new AcfFieldCatalog(
            static function (): array {
                throw new RuntimeException('Catalog exploded.');
            }
        );

        $this->validateWith(
            new InMemoryRuleRepository(array($this->signatureRule())),
            $catalog,
            $this->publishRequest(),
            $this->signaturePayload('')
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testGroupRequiredFieldBlocksPublishWithNestedInputName(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->groupIngredientsRule())),
            $this->productGroupCatalog(),
            $this->publishRequest(array('post_type' => 'product')),
            array(
                'field_type' => 'sauce',
                'field_product_details' => array(
                    'field_ingredients' => '',
                ),
            )
        );

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[field_product_details][field_ingredients]',
                'message' => 'Product Details → Ingredients — This field is required.',
            ),
        ));
    }

    public function testGroupRequiredFieldPopulatedOnPublishDoesNotError(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->groupIngredientsRule())),
            $this->productGroupCatalog(),
            $this->publishRequest(array('post_type' => 'product')),
            array(
                'field_type' => 'sauce',
                'field_product_details' => array(
                    'field_ingredients' => 'Tomatoes, salt',
                ),
            )
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testGroupRequiredFieldAllowsDraft(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->groupIngredientsRule())),
            $this->productGroupCatalog(),
            $this->request(
                array(
                    'post_type'   => 'product',
                    'post_status' => 'draft',
                    'save'        => 'Save Draft',
                )
            ),
            array(
                'field_type' => 'sauce',
                'field_product_details' => array(
                    'field_ingredients' => '',
                ),
            )
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testGroupWarningRemainsNonBlocking(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->groupIngredientsRule(array(
                'severity' => RuleSeverity::Warning,
            )))),
            $this->productGroupCatalog(),
            $this->publishRequest(array('post_type' => 'product')),
            array(
                'field_type' => 'sauce',
                'field_product_details' => array(
                    'field_ingredients' => '',
                ),
            )
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testClassicAcfBlockersEmitContentLatchSummaryWithoutAFieldQuery(): void
    {
        $titleRule = RuleFactory::rule(array(
            'id'          => 2,
            'name'        => 'Title required',
            'postType'    => 'recipe',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => RuleFactory::field('field_title', 'recipe_title', 'Recipe Title'),
                    'type'  => 'required',
                )),
            ),
        ));

        $this->validate(
            $this->publishRequest(),
            array(
                'field_signature'   => '1',
                'field_description' => '',
                'field_title'       => '',
            ),
            array($this->signatureRule(), $titleRule)
        );

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[field_description]',
                'message' => 'Recipe Description — This field is required.',
            ),
            array(
                'input'   => 'acf[field_title]',
                'message' => 'Recipe Title — This field is required.',
            ),
        ));
        $this->assertStringContainsString("ContentLatch · Blocking\n2 blocking issues\n", $this->errors[0]['message']);
        $this->assertStringContainsString('>Recipe Description</button> — This field is required.', $this->errors[0]['message']);
        $this->assertStringContainsString('>Recipe Title</button> — This field is required.', $this->errors[0]['message']);
        $this->assertStringContainsString('data-contentlatch-field="field_description"', $this->errors[0]['message']);
        $this->assertStringContainsString('data-contentlatch-field="field_title"', $this->errors[0]['message']);
        $this->assertArrayNotHasKey('contentlatch_field', $this->publishRequest());

        $withoutField = $this->errors;
        $this->validate(
            $this->publishRequest(array('contentlatch_field' => 'field_description')),
            array(
                'field_signature'   => '1',
                'field_description' => '',
                'field_title'       => '',
            ),
            array($this->signatureRule(), $titleRule)
        );
        $this->assertSame($withoutField, $this->errors);
    }

    public function testSaveValidatorDoesNotUseContentguardFieldForPresentation(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Infrastructure/ACF/AcfSaveValidator.php');

        $this->assertStringContainsString('isClassicEditorRequest', $php);
        $this->assertStringContainsString('EditorAuditIssues::classicValidationNotice', $php);
        $this->assertStringContainsString('EditorAuditIssues::fromResult', $php);
        $this->assertStringContainsString('decorateAcfValidationError', $php);
        $this->assertStringContainsString("['contentlatch']", $php);
        $this->assertStringNotContainsString('requestedFieldKey', $php);
        $this->assertStringNotContainsString('QUERY_ARG', $php);
        $this->assertStringNotContainsString('requestedRunId', $php);
        $this->assertStringNotContainsString('show_in_rest', $php);
    }

    public function testGutenbergAcfAjaxKeepsFieldErrorsWithoutAClassicSummary(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->signatureRule())),
            $this->recipeCatalog(),
            array(
                'action'               => 'acf/validate_save_post',
                'post_type'            => 'recipe',
                '_acf_screen'          => 'post',
                '_acf_post_id'         => '42',
                'original_post_status' => 'auto-draft',
            ),
            $this->signaturePayload('')
        );

        $this->assertCount(1, $this->errors);
        $this->assertSame('acf[field_description]', $this->errors[0]['input']);
        $this->assertSame('Recipe Description — This field is required.', $this->errors[0]['message']);
        $this->assertStringNotContainsString('ContentLatch · Blocking', $this->errors[0]['message']);
        $this->assertStringNotContainsString('<button', $this->errors[0]['message']);
        $this->assertGutenbergContentLatchMetadata($this->errors[0], array(
            'field'    => 'field_description',
            'fieldKey' => 'field_description',
            'label'    => 'Recipe Description',
            'message'  => 'This field is required.',
        ));
    }

    public function testGutenbergRepeaterErrorIncludesOneLevelPath(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
            \ContentLatch\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            $this->gutenbergAcfAjaxRequest(),
            array(
                \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => 'Salt'),
                    'row-1' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => 'Pepper'),
                    'row-2' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                ),
            )
        );

        $input = 'acf[' . \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST
            . '][row-2][' . \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT . ']';
        $this->assertCount(1, $this->errors);
        $this->assertSame($input, $this->errors[0]['input']);
        $this->assertSame(
            'Ingredient List → Ingredient — This field is required in row 3.',
            $this->errors[0]['message']
        );
        $this->assertStringNotContainsString('<button', $this->errors[0]['message']);
        $this->assertGutenbergContentLatchMetadata($this->errors[0], array(
            'field'        => \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT,
            'fieldKey'     => \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT,
            'label'        => 'Ingredient List → Ingredient',
            'message'      => 'Ingredient is required in row 3.',
            'repeaterPath' => array(
                array(
                    'repeater'    => \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST,
                    'display_row' => 3,
                ),
            ),
        ));
    }

    public function testGutenbergNestedRepeaterErrorIncludesOuterThenInnerPath(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->nestedStepNameRule())),
            \ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::recipeCatalog(),
            $this->gutenbergAcfAjaxRequest(),
            array(
                \ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::DIRECTIONS => array(
                    'row-0' => array(
                        \ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::STEPS => array(
                            'row-0' => array(\ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME => 'Cut'),
                            'row-1' => array(\ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME => ''),
                        ),
                    ),
                ),
            )
        );

        $input = 'acf[' . \ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::DIRECTIONS
            . '][row-0][' . \ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::STEPS
            . '][row-1][' . \ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME . ']';
        $this->assertCount(1, $this->errors);
        $this->assertSame($input, $this->errors[0]['input']);
        $this->assertStringNotContainsString('<button', $this->errors[0]['message']);
        $this->assertGutenbergContentLatchMetadata($this->errors[0], array(
            'field'        => \ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME,
            'fieldKey'     => \ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::STEP_NAME,
            'label'        => 'Directions → Steps → Name',
            'message'      => 'Name is required in row 1/2.',
            'repeaterPath' => array(
                array(
                    'repeater'    => \ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::DIRECTIONS,
                    'display_row' => 1,
                ),
                array(
                    'repeater'    => \ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::STEPS,
                    'display_row' => 2,
                ),
            ),
        ));
    }

    public function testGutenbergFlexibleErrorIncludesLayoutAndAffectedRow(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->heroTitleRule())),
            \ContentLatch\Tests\Support\AcfFlexibleFixtures::pageCatalog(),
            $this->gutenbergAcfAjaxRequest(array('post_type' => 'page')),
            array(
                \ContentLatch\Tests\Support\AcfFlexibleFixtures::MODULES => array(
                    'row-0' => array(
                        'acf_fc_layout' => 'hero',
                        \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => '',
                    ),
                ),
            )
        );

        $input = 'acf[' . \ContentLatch\Tests\Support\AcfFlexibleFixtures::MODULES
            . '][row-0][' . \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE . ']';
        $this->assertCount(1, $this->errors);
        $this->assertSame($input, $this->errors[0]['input']);
        $this->assertSame(
            'Modules → Hero → Title — This field is required.',
            $this->errors[0]['message']
        );
        $this->assertStringNotContainsString('<button', $this->errors[0]['message']);
        $this->assertGutenbergContentLatchMetadata($this->errors[0], array(
            'field'        => \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
            'fieldKey'     => \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
            'label'        => 'Modules → Hero → Title',
            'message'      => 'This field is required.',
            'layout'       => 'hero',
            'affectedRows' => array(1),
        ));
    }

    public function testGutenbergCloneErrorUsesResolutionIdentity(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->cloneTitleRule())),
            \ContentLatch\Tests\Support\AcfCloneFixtures::pageCatalog(),
            $this->gutenbergAcfAjaxRequest(array('post_type' => 'page')),
            array(
                \ContentLatch\Tests\Support\AcfCloneFixtures::CLONE_A => array(
                    \ContentLatch\Tests\Support\AcfCloneFixtures::cloneATitlePosted() => '',
                ),
            )
        );

        $input = 'acf[' . \ContentLatch\Tests\Support\AcfCloneFixtures::CLONE_A
            . '][' . \ContentLatch\Tests\Support\AcfCloneFixtures::cloneATitlePosted() . ']';
        $this->assertCount(1, $this->errors);
        $this->assertSame($input, $this->errors[0]['input']);
        $this->assertSame(
            'Shared Content → Title — This field is required.',
            $this->errors[0]['message']
        );
        $this->assertStringNotContainsString('<button', $this->errors[0]['message']);
        $this->assertGutenbergContentLatchMetadata($this->errors[0], array(
            'field'    => \ContentLatch\Tests\Support\AcfCloneFixtures::cloneATitlePosted(),
            'fieldKey' => \ContentLatch\Tests\Support\AcfCloneFixtures::cloneATitlePosted(),
            'label'    => 'Shared Content → Title',
            'message'  => 'This field is required.',
        ));
    }

    public function testGutenbergDoesNotDecorateNativeAcfErrors(): void
    {
        $this->errors = array(
            array(
                'input'   => 'acf[field_title]',
                'message' => 'ACF own error',
            ),
        );
        $repository = new InMemoryRuleRepository(array($this->signatureRule()));
        $catalog    = $this->recipeCatalog();
        $validator  = new AcfSaveValidator(
            $repository,
            $catalog,
            new IntendedPostStatusResolver(),
            function (string $input, string $message): void {
                $this->errors[] = array(
                    'input'   => $input,
                    'message' => $message,
                );
            },
            IncomingSaveFixtures::evaluator($repository, $catalog),
            array($this, 'decorateCapturedError')
        );
        $validator->validate($this->gutenbergAcfAjaxRequest(), $this->signaturePayload(''));

        $this->assertSame('acf[field_title]', $this->errors[0]['input']);
        $this->assertSame('ACF own error', $this->errors[0]['message']);
        $this->assertArrayNotHasKey('contentlatch', $this->errors[0]);
        $this->assertSame('acf[field_description]', $this->errors[1]['input']);
        $this->assertArrayHasKey('contentlatch', $this->errors[1]);
        $this->assertSame('field_description', $this->errors[1]['contentlatch']['field']);
    }

    public function testClassicFieldErrorsRemainUndecorated(): void
    {
        $this->validate($this->publishRequest(), $this->signaturePayload(''));

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[field_description]',
                'message' => 'Recipe Description — This field is required.',
            ),
        ));
        $this->assertArrayNotHasKey('contentlatch', $this->errors[0]);
        $this->assertArrayNotHasKey('contentlatch', $this->errors[1]);
    }

    public function testRepeaterChildTargetsExactPostedRowInput(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
            \ContentLatch\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            $this->publishRequest(),
            array(
                \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                    'row-1' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => 'Salt'),
                    '67a1b2c3d4e5f' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                    'acfcloneindex' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => 'Clone'),
                ),
            )
        );

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[' . \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST . '][row-0][' . \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT . ']',
                'message' => 'Ingredient List → Ingredient — This field is required in row 1.',
            ),
            array(
                'input'   => 'acf[' . \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST . '][67a1b2c3d4e5f][' . \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT . ']',
                'message' => 'Ingredient List → Ingredient — This field is required in row 3.',
            ),
        ));
        $this->assertStringContainsString('Ingredient is required in row 1.', $this->errors[0]['message']);
        $this->assertStringContainsString('Ingredient is required in row 3.', $this->errors[0]['message']);
    }

    public function testGroupRepeaterChildTargetsNestedPostedRowInput(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->productSizeRepeaterRule())),
            \ContentLatch\Tests\Support\AcfRepeaterFixtures::productCatalog(),
            $this->publishRequest(array('post_type' => 'product')),
            array(
                \ContentLatch\Tests\Support\AcfRepeaterFixtures::PRODUCT_TYPE => 'sauce',
                \ContentLatch\Tests\Support\AcfRepeaterFixtures::PRODUCT_INFORMATION => array(
                    \ContentLatch\Tests\Support\AcfRepeaterFixtures::ITEM_SIZE => array(
                        'row-0' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE => ''),
                        'row-1' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE => '2.5oz'),
                    ),
                ),
            )
        );

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[' . \ContentLatch\Tests\Support\AcfRepeaterFixtures::PRODUCT_INFORMATION . '][' . \ContentLatch\Tests\Support\AcfRepeaterFixtures::ITEM_SIZE . '][row-0][' . \ContentLatch\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE . ']',
                'message' => 'Product Information → Item Size → Product Size — This field is required in row 1.',
            ),
        ));
    }

    public function testRepeaterZeroRowsTargetsRepeaterContainer(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->productSizeRepeaterRule())),
            \ContentLatch\Tests\Support\AcfRepeaterFixtures::productCatalog(),
            $this->publishRequest(array('post_type' => 'product')),
            array(
                \ContentLatch\Tests\Support\AcfRepeaterFixtures::PRODUCT_TYPE => 'sauce',
                \ContentLatch\Tests\Support\AcfRepeaterFixtures::PRODUCT_INFORMATION => array(
                    \ContentLatch\Tests\Support\AcfRepeaterFixtures::ITEM_SIZE => array(
                        'acfcloneindex' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE => '8oz'),
                    ),
                ),
            )
        );

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[' . \ContentLatch\Tests\Support\AcfRepeaterFixtures::PRODUCT_INFORMATION . '][' . \ContentLatch\Tests\Support\AcfRepeaterFixtures::ITEM_SIZE . ']',
                'message' => 'Add at least one Item Size row.',
            ),
        ));
    }

    public function testRepeaterDraftAllowedPublishAndPrivateBlocked(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
            \ContentLatch\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            $this->request(array(
                'post_status' => 'draft',
                'save'        => 'Save Draft',
            )),
            array(
                \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                ),
            )
        );
        $this->assertSame(array(), $this->errors);

        $this->validateWith(
            new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
            \ContentLatch\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            $this->publishRequest(),
            array(
                \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                ),
            )
        );
        $this->assertNotSame(array(), $this->errors);

        $this->validateWith(
            new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
            \ContentLatch\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            $this->request(array(
                'post_status' => 'draft',
                'private'     => 'Private',
            )),
            array(
                \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                ),
            )
        );
        $this->assertNotSame(array(), $this->errors);
    }

    public function testRepeaterWarningRemainsNonBlocking(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->ingredientRepeaterRule(array(
                'severity' => RuleSeverity::Warning,
            )))),
            \ContentLatch\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            $this->publishRequest(),
            array(
                \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                    'row-1' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                ),
            )
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testRepeaterValidationDoesNotClearExistingAcfErrors(): void
    {
        $this->errors = array(
            array(
                'input'   => 'acf[field_title]',
                'message' => 'ACF own error',
            ),
        );
        $validator = new AcfSaveValidator(
            new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
            \ContentLatch\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            new IntendedPostStatusResolver(),
            function (string $input, string $message): void {
                $this->errors[] = array(
                    'input'   => $input,
                    'message' => $message,
                );
            },
            IncomingSaveFixtures::evaluator(
                new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
                \ContentLatch\Tests\Support\AcfRepeaterFixtures::recipeCatalog()
            )
        );
        $validator->validate(
            $this->publishRequest(),
            array(
                \ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentLatch\Tests\Support\AcfRepeaterFixtures::INGREDIENT => 'Salt'),
                ),
            )
        );

        $this->assertSame(
            array(
                array(
                    'input'   => 'acf[field_title]',
                    'message' => 'ACF own error',
                ),
            ),
            $this->errors
        );
    }

    public function testNestedGroupRequiredFieldBlocksPrivate(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->nestedCaloriesRule())),
            $this->productGroupCatalog(),
            $this->request(
                array(
                    'post_type'   => 'product',
                    'post_status' => 'draft',
                    'private'     => 'Private',
                )
            ),
            array(
                'field_product_details' => array(
                    'field_nutrition' => array(
                        'field_calories' => '',
                    ),
                ),
            )
        );

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[field_product_details][field_nutrition][field_calories]',
                'message' => 'Product Details → Nutrition → Calories — This field is required.',
            ),
        ));
    }

    /**
     * @param array<string, mixed> $request
     * @param Rule[]               $rules
     */
    private function validate(array $request, mixed $payload, array $rules = array()): void
    {
        $this->validateWith(
            new InMemoryRuleRepository($rules === array() ? array($this->signatureRule()) : $rules),
            $this->recipeCatalog(),
            $request,
            $payload
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    public function testFlexibleChildTargetsExactPostedRowInput(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->heroTitleRule())),
            \ContentLatch\Tests\Support\AcfFlexibleFixtures::pageCatalog(),
            $this->publishRequest(array('post_type' => 'page')),
            array(
                \ContentLatch\Tests\Support\AcfFlexibleFixtures::MODULES => array(
                    'row-0' => array(
                        'acf_fc_layout' => 'hero',
                        \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => '',
                    ),
                    'row-1' => array(
                        'acf_fc_layout' => 'cta',
                        \ContentLatch\Tests\Support\AcfFlexibleFixtures::CTA_TITLE => '',
                    ),
                    '67a1b2c3d4e5f' => array(
                        'acf_fc_layout' => 'hero',
                        \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => '',
                    ),
                    'acfcloneindex' => array(
                        'acf_fc_layout' => 'hero',
                        \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => 'Clone',
                    ),
                    'row-9' => array(
                        'acf_fc_layout' => 'hero',
                        'acf_fc_layout_disabled' => '1',
                        \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => '',
                    ),
                ),
            )
        );

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[' . \ContentLatch\Tests\Support\AcfFlexibleFixtures::MODULES . '][row-0][' . \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE . ']',
                'message' => 'Modules → Hero → Title — This field is required.',
            ),
            array(
                'input'   => 'acf[' . \ContentLatch\Tests\Support\AcfFlexibleFixtures::MODULES . '][67a1b2c3d4e5f][' . \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE . ']',
                'message' => 'Modules → Hero → Title — This field is required.',
            ),
        ));
    }

    public function testFlexibleGroupChildTargetsNestedPostedInput(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->contentBlockHeadlineRule())),
            \ContentLatch\Tests\Support\AcfFlexibleFixtures::pageCatalog(),
            $this->publishRequest(array('post_type' => 'page')),
            array(
                \ContentLatch\Tests\Support\AcfFlexibleFixtures::MODULES => array(
                    'row-5' => array(
                        'acf_fc_layout' => 'content_block',
                        \ContentLatch\Tests\Support\AcfFlexibleFixtures::CONTENT_BLOCK_1 => array(
                            \ContentLatch\Tests\Support\AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE => '',
                        ),
                    ),
                ),
            )
        );

        $this->assertClassicBlockingSummary(array(
            array(
                'input'   => 'acf[' . \ContentLatch\Tests\Support\AcfFlexibleFixtures::MODULES . '][row-5]['
                    . \ContentLatch\Tests\Support\AcfFlexibleFixtures::CONTENT_BLOCK_1 . ']['
                    . \ContentLatch\Tests\Support\AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE . ']',
                'message' => 'Modules → Content Block → Content Block 1 → Headline — This field is required.',
            ),
        ));
    }

    public function testFlexibleZeroLayoutsDoNotError(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->heroTitleRule())),
            \ContentLatch\Tests\Support\AcfFlexibleFixtures::pageCatalog(),
            $this->publishRequest(array('post_type' => 'page')),
            array(
                \ContentLatch\Tests\Support\AcfFlexibleFixtures::MODULES => array(
                    'acfcloneindex' => array(
                        'acf_fc_layout' => 'hero',
                        \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => '',
                    ),
                ),
            )
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testFlexibleDraftAllowedPublishAndPrivateBlocked(): void
    {
        $payload = array(
            \ContentLatch\Tests\Support\AcfFlexibleFixtures::MODULES => array(
                'row-0' => array(
                    'acf_fc_layout' => 'hero',
                    \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => '',
                ),
            ),
        );

        $this->validateWith(
            new InMemoryRuleRepository(array($this->heroTitleRule())),
            \ContentLatch\Tests\Support\AcfFlexibleFixtures::pageCatalog(),
            $this->request(array(
                'post_type'   => 'page',
                'post_status' => 'draft',
                'save'        => 'Save Draft',
            )),
            $payload
        );
        $this->assertSame(array(), $this->errors);

        $this->validateWith(
            new InMemoryRuleRepository(array($this->heroTitleRule())),
            \ContentLatch\Tests\Support\AcfFlexibleFixtures::pageCatalog(),
            $this->publishRequest(array('post_type' => 'page')),
            $payload
        );
        $this->assertNotSame(array(), $this->errors);

        $this->validateWith(
            new InMemoryRuleRepository(array($this->heroTitleRule())),
            \ContentLatch\Tests\Support\AcfFlexibleFixtures::pageCatalog(),
            $this->request(array(
                'post_type'   => 'page',
                'post_status' => 'draft',
                'private'     => 'Private',
            )),
            $payload
        );
        $this->assertNotSame(array(), $this->errors);
    }

    public function testFlexibleWarningRemainsNonBlocking(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->heroTitleRule(array(
                'severity' => RuleSeverity::Warning,
            )))),
            \ContentLatch\Tests\Support\AcfFlexibleFixtures::pageCatalog(),
            $this->publishRequest(array('post_type' => 'page')),
            array(
                \ContentLatch\Tests\Support\AcfFlexibleFixtures::MODULES => array(
                    'row-0' => array(
                        'acf_fc_layout' => 'hero',
                        \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => '',
                    ),
                ),
            )
        );

        $this->assertSame(array(), $this->errors);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function heroTitleRule(array $overrides = array()): Rule
    {
        return RuleFactory::rule(
            array_merge(
                array(
                    'id'          => 80,
                    'name'        => 'Hero title required',
                    'postType'    => 'page',
                    'conditions'  => array(),
                    'validations' => array(
                        RuleFactory::validation(
                            array(
                                'field'      => \ContentLatch\Tests\Support\AcfFlexibleFixtures::heroTitleRef(),
                                'type'       => 'required',
                                'quantifier' => 'every',
                            )
                        ),
                    ),
                ),
                $overrides
            )
        );
    }

    private function contentBlockHeadlineRule(): Rule
    {
        return RuleFactory::rule(array(
            'id'          => 81,
            'name'        => 'Content block headline required',
            'postType'    => 'page',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'      => \ContentLatch\Tests\Support\AcfFlexibleFixtures::contentBlockHeadlineRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
    }

    /**
     * Classic ACF saves emit a ContentLatch summary, then the field errors.
     *
     * @param list<array{input: string, message: string}> $fieldErrors
     */
    private function assertClassicBlockingSummary(array $fieldErrors): void
    {
        $this->assertNotSame(array(), $this->errors);
        $this->assertSame('', $this->errors[0]['input']);
        $this->assertStringStartsWith("ContentLatch · Blocking\n", $this->errors[0]['message']);
        $this->assertSame($fieldErrors, array_slice($this->errors, 1));
    }

    /**
     * @param array<string, mixed> $error
     * @param array<string, mixed> $expected
     */
    private function assertGutenbergContentLatchMetadata(array $error, array $expected): void
    {
        $this->assertArrayHasKey('contentlatch', $error);
        $this->assertIsArray($error['contentlatch']);
        $this->assertSame($expected['field'], $error['contentlatch']['field']);
        $this->assertSame($expected['fieldKey'], $error['contentlatch']['fieldKey']);
        $this->assertSame($expected['label'], $error['contentlatch']['label']);
        $this->assertSame($expected['message'], $error['contentlatch']['message']);
        if (isset($expected['repeaterPath'])) {
            $this->assertSame($expected['repeaterPath'], $error['contentlatch']['repeaterPath']);
        } else {
            $this->assertArrayNotHasKey('repeaterPath', $error['contentlatch']);
        }
        if (isset($expected['layout'])) {
            $this->assertSame($expected['layout'], $error['contentlatch']['layout']);
        } else {
            $this->assertArrayNotHasKey('layout', $error['contentlatch']);
        }
        if (isset($expected['affectedRows'])) {
            $this->assertSame($expected['affectedRows'], $error['contentlatch']['affectedRows']);
        } else {
            $this->assertArrayNotHasKey('affectedRows', $error['contentlatch']);
        }
        $this->assertStringNotContainsString('<button', (string) $error['message']);
        $this->assertStringNotContainsString('contentlatch', (string) $error['message']);
    }

    /**
     * @param array<string, mixed> $contentlatch
     */
    public function decorateCapturedError(string $input, array $contentlatch): void
    {
        for ($i = count($this->errors) - 1; $i >= 0; $i--) {
            if (($this->errors[$i]['input'] ?? '') !== $input) {
                continue;
            }
            if (isset($this->errors[$i]['contentlatch'])) {
                continue;
            }

            $this->errors[$i]['contentlatch'] = $contentlatch;

            return;
        }
    }

    private function validateWith(
        RuleRepositoryInterface $repository,
        AcfFieldCatalog $catalog,
        array $request,
        mixed $payload,
    ): void {
        $this->errors = array();

        $validator = new AcfSaveValidator(
            $repository,
            $catalog,
            new IntendedPostStatusResolver(),
            function (string $input, string $message): void {
                $this->errors[] = array(
                    'input'   => $input,
                    'message' => $message,
                );
            },
            IncomingSaveFixtures::evaluator($repository, $catalog),
            array($this, 'decorateCapturedError')
        );

        $validator->validate($request, $payload);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function gutenbergAcfAjaxRequest(array $overrides = array()): array
    {
        $request = $this->request(
            array_merge(
                array(
                    'action'               => 'acf/validate_save_post',
                    'original_post_status' => 'auto-draft',
                    '_acf_screen'          => 'post',
                    '_acf_post_id'         => '42',
                ),
                $overrides
            )
        );
        unset($request['post_status']);

        return $request;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function signatureRule(array $overrides = array()): Rule
    {
        return RuleFactory::rule(
            array_merge(
                array(
                    'id'          => 1,
                    'name'        => 'Signature requires description',
                    'postType'    => 'recipe',
                    'conditions'  => array(
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
                                'type'  => 'required',
                            )
                        ),
                    ),
                ),
                $overrides
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function signaturePayload(string $description): array
    {
        return array(
            'field_signature'   => '1',
            'field_description' => $description,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function publishRequest(array $overrides = array()): array
    {
        return $this->request(
            array_merge(
                array(
                    'post_status' => 'auto-draft',
                    'publish'     => 'Publish',
                ),
                $overrides
            )
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function request(array $overrides = array()): array
    {
        return array_merge(
            array(
                'post_ID'     => 42,
                'post_type'   => 'recipe',
                'post_status' => 'draft',
            ),
            $overrides
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function groupIngredientsRule(array $overrides = array()): Rule
    {
        return RuleFactory::rule(
            array_merge(
                array(
                    'id'         => 21,
                    'name'       => 'Sauce products need ingredients',
                    'postType'   => 'product',
                    'conditions' => array(
                        RuleFactory::condition(
                            array(
                                'field'    => RuleFactory::field('field_type', 'product_type', 'Product Type'),
                                'operator' => 'equals',
                                'operand'  => 'sauce',
                            )
                        ),
                    ),
                    'validations' => array(
                        RuleFactory::validation(
                            array(
                                'field' => new FieldRef(
                                    'field_ingredients',
                                    'ingredients',
                                    'Product Details → Ingredients',
                                    array('field_product_details', 'field_ingredients'),
                                    'group'
                                ),
                                'type'  => 'required',
                            )
                        ),
                    ),
                ),
                $overrides
            )
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function ingredientRepeaterRule(array $overrides = array()): Rule
    {
        return RuleFactory::rule(
            array_merge(
                array(
                    'id'          => 50,
                    'name'        => 'Ingredients required',
                    'postType'    => 'recipe',
                    'conditions'  => array(),
                    'validations' => array(
                        RuleFactory::validation(
                            array(
                                'field'      => \ContentLatch\Tests\Support\AcfRepeaterFixtures::ingredientRef(),
                                'type'       => 'required',
                                'quantifier' => 'every',
                            )
                        ),
                    ),
                ),
                $overrides
            )
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function productSizeRepeaterRule(array $overrides = array()): Rule
    {
        return RuleFactory::rule(
            array_merge(
                array(
                    'id'         => 51,
                    'name'       => 'Sauce sizes required',
                    'postType'   => 'product',
                    'conditions' => array(
                        RuleFactory::condition(
                            array(
                                'field'    => RuleFactory::field(
                                    \ContentLatch\Tests\Support\AcfRepeaterFixtures::PRODUCT_TYPE,
                                    'product_type',
                                    'Product Type'
                                ),
                                'operator' => 'equals',
                                'operand'  => 'sauce',
                            )
                        ),
                    ),
                    'validations' => array(
                        RuleFactory::validation(
                            array(
                                'field'      => \ContentLatch\Tests\Support\AcfRepeaterFixtures::productSizeRef(),
                                'type'       => 'required',
                                'quantifier' => 'every',
                            )
                        ),
                    ),
                ),
                $overrides
            )
        );
    }

    private function nestedStepNameRule(): Rule
    {
        return RuleFactory::rule(array(
            'id'          => 60,
            'name'        => 'Step name required',
            'postType'    => 'recipe',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'      => \ContentLatch\Tests\Support\AcfNestedRepeaterFixtures::stepNameRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                )),
            ),
        ));
    }

    private function cloneTitleRule(): Rule
    {
        return RuleFactory::rule(array(
            'id'          => 70,
            'name'        => 'Clone title required',
            'postType'    => 'page',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => \ContentLatch\Tests\Support\AcfCloneFixtures::cloneATitleRef(),
                    'type'  => 'required',
                )),
            ),
        ));
    }

    private function nestedCaloriesRule(): Rule
    {
        return RuleFactory::rule(
            array(
                'id'         => 22,
                'name'       => 'Calories required',
                'postType'   => 'product',
                'conditions' => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => new FieldRef(
                                'field_calories',
                                'calories',
                                'Product Details → Nutrition → Calories',
                                array('field_product_details', 'field_nutrition', 'field_calories'),
                                'group'
                            ),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );
    }

    private function productGroupCatalog(): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (string $postType): array {
                if ($postType !== 'product') {
                    return array();
                }

                return array(
                    array(
                        'key'   => 'field_type',
                        'name'  => 'product_type',
                        'label' => 'Product Type',
                        'type'  => 'select',
                    ),
                    array(
                        'key'        => 'field_product_details',
                        'name'       => 'product_details',
                        'label'      => 'Product Details',
                        'type'       => 'group',
                        'sub_fields' => array(
                            array(
                                'key'   => 'field_ingredients',
                                'name'  => 'ingredients',
                                'label' => 'Ingredients',
                                'type'  => 'textarea',
                            ),
                            array(
                                'key'        => 'field_nutrition',
                                'name'       => 'nutrition',
                                'label'      => 'Nutrition',
                                'type'       => 'group',
                                'sub_fields' => array(
                                    array(
                                        'key'   => 'field_calories',
                                        'name'  => 'calories',
                                        'label' => 'Calories',
                                        'type'  => 'number',
                                    ),
                                ),
                            ),
                        ),
                    ),
                );
            }
        );
    }

    private function recipeCatalog(): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (string $postType): array {
                if ($postType !== 'recipe') {
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
                        'name'  => 'recipe_title',
                        'label' => 'Recipe Title',
                        'type'  => 'text',
                    ),
                );
            }
        );
    }
}
