<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\FieldRef;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\AcfSaveValidator;
use ContentGuard\Infrastructure\ACF\IntendedPostStatusResolver;
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
use ContentGuard\Infrastructure\WordPress\PostTypeRuleRepository;
use ContentGuard\Infrastructure\WordPress\RulePostRecord;
use ContentGuard\Tests\Support\FakeRulePostStore;
use ContentGuard\Tests\Support\RuleFactory;
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

        $this->assertSame(
            array(
                array(
                    'input'   => 'acf[field_description]',
                    'message' => 'Recipe Description is required.',
                ),
            ),
            $this->errors
        );
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

        $this->assertSame(
            array(
                array(
                    'input'   => 'acf[field_description]',
                    'message' => 'Recipe Description is required.',
                ),
            ),
            $this->errors
        );
    }

    public function testGutenbergAcfAjaxPublishWithoutPostStatusIsBlocked(): void
    {
        $request = $this->request(
            array(
                'action'               => 'acf/validate_save_post',
                'original_post_status' => 'auto-draft',
                '_acf_screen'          => 'post',
                '_acf_post_id'         => '42',
            )
        );
        unset($request['post_status']);

        $this->validate($request, $this->signaturePayload(''));

        $this->assertCount(1, $this->errors);
        $this->assertSame('acf[field_description]', $this->errors[0]['input']);
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
                'field_64f8a42a61f56' => '',
            )
        );

        $this->assertSame(
            array(
                array(
                    'input'   => 'acf[field_64f8a42a61f56]',
                    'message' => 'Recipe Description is required.',
                ),
            ),
            $this->errors
        );

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

        $this->assertCount(1, $this->errors);
        $this->assertSame('acf[field_description]', $this->errors[0]['input']);
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

        $this->assertSame(
            array(
                array(
                    'input'   => 'acf[field_description]',
                    'message' => 'Recipe Description is required.',
                ),
                array(
                    'input'   => 'acf[field_title]',
                    'message' => 'Recipe Title is required.',
                ),
            ),
            $this->errors
        );
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

        $this->assertSame(
            array(
                array(
                    'input'   => 'acf[field_description]',
                    'message' => 'Please add a signature recipe description.',
                ),
            ),
            $this->errors
        );
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

        $this->assertCount(1, $this->errors);
        $this->assertSame('acf[field_description]', $this->errors[0]['input']);
        $this->assertSame(1, $store->findCalls);
    }

    public function testIncomingTrueFalseUsesTheSameDomainEvaluationAsStored(): void
    {
        $this->validate($this->publishRequest(), $this->signaturePayload(''));
        $this->assertCount(1, $this->errors);

        $this->errors = array();
        $this->validate(
            $this->publishRequest(),
            array(
                'field_signature'   => 1,
                'field_description' => '',
            )
        );
        $this->assertCount(1, $this->errors);

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

        $this->assertSame(
            array(
                array(
                    'input'   => 'acf[field_product_details][field_ingredients]',
                    'message' => 'Product Details → Ingredients is required.',
                ),
            ),
            $this->errors
        );
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

    public function testRepeaterChildTargetsExactPostedRowInput(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
            \ContentGuard\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            $this->publishRequest(),
            array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                    'row-1' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT => 'Salt'),
                    '67a1b2c3d4e5f' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                    'acfcloneindex' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT => 'Clone'),
                ),
            )
        );

        $this->assertSame(
            array(
                array(
                    'input'   => 'acf[' . \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST . '][row-0][' . \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT . ']',
                    'message' => 'Ingredient List → Ingredient is required.',
                ),
                array(
                    'input'   => 'acf[' . \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST . '][67a1b2c3d4e5f][' . \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT . ']',
                    'message' => 'Ingredient List → Ingredient is required.',
                ),
            ),
            $this->errors
        );
    }

    public function testGroupRepeaterChildTargetsNestedPostedRowInput(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->productSizeRepeaterRule())),
            \ContentGuard\Tests\Support\AcfRepeaterFixtures::productCatalog(),
            $this->publishRequest(array('post_type' => 'product')),
            array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_TYPE => 'sauce',
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_INFORMATION => array(
                    \ContentGuard\Tests\Support\AcfRepeaterFixtures::ITEM_SIZE => array(
                        'row-0' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE => ''),
                        'row-1' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE => '2.5oz'),
                    ),
                ),
            )
        );

        $this->assertSame(
            array(
                array(
                    'input'   => 'acf[' . \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_INFORMATION . '][' . \ContentGuard\Tests\Support\AcfRepeaterFixtures::ITEM_SIZE . '][row-0][' . \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE . ']',
                    'message' => 'Product Information → Item Size → Product Size is required.',
                ),
            ),
            $this->errors
        );
    }

    public function testRepeaterZeroRowsTargetsRepeaterContainer(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->productSizeRepeaterRule())),
            \ContentGuard\Tests\Support\AcfRepeaterFixtures::productCatalog(),
            $this->publishRequest(array('post_type' => 'product')),
            array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_TYPE => 'sauce',
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_INFORMATION => array(
                    \ContentGuard\Tests\Support\AcfRepeaterFixtures::ITEM_SIZE => array(
                        'acfcloneindex' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_SIZE => '8oz'),
                    ),
                ),
            )
        );

        $this->assertSame(
            array(
                array(
                    'input'   => 'acf[' . \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_INFORMATION . '][' . \ContentGuard\Tests\Support\AcfRepeaterFixtures::ITEM_SIZE . ']',
                    'message' => 'Add at least one Item Size row.',
                ),
            ),
            $this->errors
        );
    }

    public function testRepeaterDraftAllowedPublishAndPrivateBlocked(): void
    {
        $this->validateWith(
            new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
            \ContentGuard\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            $this->request(array(
                'post_status' => 'draft',
                'save'        => 'Save Draft',
            )),
            array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                ),
            )
        );
        $this->assertSame(array(), $this->errors);

        $this->validateWith(
            new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
            \ContentGuard\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            $this->publishRequest(),
            array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                ),
            )
        );
        $this->assertNotSame(array(), $this->errors);

        $this->validateWith(
            new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
            \ContentGuard\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            $this->request(array(
                'post_status' => 'draft',
                'private'     => 'Private',
            )),
            array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
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
            \ContentGuard\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            $this->publishRequest(),
            array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                    'row-1' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
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
            new ContentEvaluator(
                new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
                RuleEngine::v1()
            ),
            new InMemoryRuleRepository(array($this->ingredientRepeaterRule())),
            \ContentGuard\Tests\Support\AcfRepeaterFixtures::recipeCatalog(),
            new IntendedPostStatusResolver(),
            function (string $input, string $message): void {
                $this->errors[] = array(
                    'input'   => $input,
                    'message' => $message,
                );
            }
        );
        $validator->validate(
            $this->publishRequest(),
            array(
                \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST => array(
                    'row-0' => array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT => 'Salt'),
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

        $this->assertSame(
            array(
                array(
                    'input'   => 'acf[field_product_details][field_nutrition][field_calories]',
                    'message' => 'Product Details → Nutrition → Calories is required.',
                ),
            ),
            $this->errors
        );
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
    private function validateWith(
        RuleRepositoryInterface $repository,
        AcfFieldCatalog $catalog,
        array $request,
        mixed $payload,
    ): void {
        $this->errors = array();

        $validator = new AcfSaveValidator(
            new ContentEvaluator($repository, RuleEngine::v1()),
            $repository,
            $catalog,
            new IntendedPostStatusResolver(),
            function (string $input, string $message): void {
                $this->errors[] = array(
                    'input'   => $input,
                    'message' => $message,
                );
            }
        );

        $validator->validate($request, $payload);
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
                                'field'      => \ContentGuard\Tests\Support\AcfRepeaterFixtures::ingredientRef(),
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
                                    \ContentGuard\Tests\Support\AcfRepeaterFixtures::PRODUCT_TYPE,
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
                                'field'      => \ContentGuard\Tests\Support\AcfRepeaterFixtures::productSizeRef(),
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
