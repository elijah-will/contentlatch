<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\AcfSaveValidator;
use ContentGuard\Infrastructure\ACF\IntendedPostStatusResolver;
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
use ContentGuard\Tests\Support\CoreCatalogFixtures;
use ContentGuard\Tests\Support\IncomingSaveFixtures;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class AcfSaveValidatorCoreTest extends TestCase
{
    /**
     * @var array<int, array{input: string, message: string}>
     */
    private array $errors = array();

    public function testClassicEmptyTitleOnPublishBlocks(): void
    {
        $this->validate(
            array($this->titleRequired()),
            array(),
            array('post_title' => '')
        );

        $this->assertSame(
            array(
                array(
                    'input'   => '',
                    'message' => 'Title — This field is required.',
                ),
            ),
            $this->errors
        );
    }

    public function testClassicValidTitleOnPublishDoesNotError(): void
    {
        $this->validate(
            array($this->titleRequired()),
            array(),
            array('post_title' => 'Hello')
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testClassicDraftEmptyTitleIsAllowed(): void
    {
        $this->validate(
            array($this->titleRequired()),
            array(),
            array(
                'post_title'  => '',
                'post_status' => 'draft',
                'save'        => 'Save Draft',
                'publish'     => '',
            )
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testClassicCoreWarningDoesNotBlock(): void
    {
        $this->validate(
            array(
                RuleFactory::rule(array(
                    'postType'    => 'post',
                    'severity'    => RuleSeverity::Warning,
                    'validations' => array(
                        RuleFactory::validation(array(
                            'field'  => CoreCatalogFixtures::titleRef(),
                            'type'   => 'min_length',
                            'params' => array('min' => 8),
                        )),
                    ),
                )),
            ),
            array(),
            array('post_title' => 'Hi')
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testGutenbergAcfAjaxWithoutCoreFieldsDoesNotFalseBlockTitle(): void
    {
        $this->validate(
            array($this->titleRequired()),
            array('field_type' => 'Seasoning'),
            array(
                'action'               => 'acf/validate_save_post',
                'original_post_status' => 'auto-draft',
                '_acf_screen'          => 'post',
                '_acf_post_id'         => '42',
            )
        );

        $this->assertSame(array(), $this->errors);
    }

    public function testClassicMixedAcfConditionCoreValidationBlocks(): void
    {
        $this->validate(
            array(
                RuleFactory::rule(array(
                    'postType'   => 'post',
                    'conditions' => array(
                        RuleFactory::condition(array(
                            'field'    => RuleFactory::field('field_type', 'product_type', 'Product Type'),
                            'operator' => 'equals',
                            'operand'  => 'Seasoning',
                        )),
                    ),
                    'validations' => array(
                        RuleFactory::validation(array(
                            'field'   => CoreCatalogFixtures::titleRef(),
                            'message' => 'Title is required.',
                        )),
                    ),
                )),
            ),
            array('field_type' => 'Seasoning'),
            array('post_title' => '')
        );

        $this->assertSame(
            array(
                array(
                    'input'   => '',
                    'message' => 'Title — This field is required.',
                ),
            ),
            $this->errors
        );
    }

    public function testClassicMixedCoreConditionAcfValidationBlocks(): void
    {
        $this->validate(
            array(
                RuleFactory::rule(array(
                    'postType'   => 'post',
                    'conditions' => array(
                        RuleFactory::condition(array(
                            'field'    => CoreCatalogFixtures::titleRef(),
                            'operator' => 'equals',
                            'operand'  => 'Product',
                        )),
                    ),
                    'validations' => array(
                        RuleFactory::validation(array(
                            'field'   => RuleFactory::field('field_ingredients', 'ingredients', 'Ingredients'),
                            'message' => 'Ingredients are required.',
                        )),
                    ),
                )),
            ),
            array('field_ingredients' => ''),
            array('post_title' => 'Product')
        );

        $this->assertSame('', $this->errors[0]['input']);
        $this->assertStringStartsWith("ContentGuard · Blocking\n", $this->errors[0]['message']);
        $this->assertStringContainsString('>Ingredients</button> — Ingredients are required.', $this->errors[0]['message']);
        $this->assertSame(
            array(
                'input'   => 'acf[field_ingredients]',
                'message' => 'Ingredients are required.',
            ),
            $this->errors[1]
        );
    }

    public function testClassicMultipleCoreBlockersAreOneMultilineNotice(): void
    {
        $this->validate(
            array(
                $this->required(CoreCatalogFixtures::titleRef()),
                $this->required(CoreCatalogFixtures::contentRef()),
                $this->required(CoreCatalogFixtures::featuredImageRef()),
            ),
            array(),
            array(
                'post_title'    => '',
                'content'       => '',
                '_thumbnail_id' => '-1',
            )
        );

        $this->assertSame(
            array(
                array(
                    'input'   => '',
                    'message' => "ContentGuard · Blocking\n3 blocking issues\n"
                        . "Title — This field is required.\n"
                        . "Content — This field is required.\n"
                        . "Featured Image — This field is required.",
                ),
            ),
            $this->errors
        );
        $this->assertStringNotContainsString('required..', $this->errors[0]['message']);
    }

    public function testClassicMixedCoreAndAcfBlockersKeepFieldErrorsSeparate(): void
    {
        $this->validate(
            array(
                $this->required(CoreCatalogFixtures::titleRef()),
                $this->required(CoreCatalogFixtures::contentRef()),
                $this->required(RuleFactory::field('field_ingredients', 'ingredients', 'Ingredients')),
            ),
            array('field_ingredients' => ''),
            array(
                'post_title' => '',
                'content'    => '',
            )
        );

        $this->assertSame('', $this->errors[0]['input']);
        $this->assertStringContainsString("ContentGuard · Blocking\n3 blocking issues\n", $this->errors[0]['message']);
        $this->assertStringContainsString('data-contentguard-core="title"', $this->errors[0]['message']);
        $this->assertStringContainsString('data-contentguard-core="content"', $this->errors[0]['message']);
        $this->assertStringContainsString('data-contentguard-field="field_ingredients"', $this->errors[0]['message']);
        $this->assertStringContainsString('>Title</button> — This field is required.', $this->errors[0]['message']);
        $this->assertStringContainsString('>Content</button> — This field is required.', $this->errors[0]['message']);
        $this->assertStringContainsString('>Ingredients</button> — This field is required.', $this->errors[0]['message']);
        $this->assertSame(
            array(
                'input'   => 'acf[field_ingredients]',
                'message' => 'Ingredients — This field is required.',
            ),
            $this->errors[1]
        );
        $this->assertStringNotContainsString('required..', $this->errors[0]['message']);
    }

    /**
     * @param array<int, mixed>    $rules
     * @param array<string, mixed> $acfPayload
     * @param array<string, mixed> $requestOverrides
     */
    private function validate(
        array $rules,
        mixed $acfPayload,
        array $requestOverrides,
    ): void {
        $this->errors = array();
        $repository   = new InMemoryRuleRepository($rules);
        $catalog      = $this->postAcfCatalog();
        $incoming     = IncomingSaveFixtures::evaluator(
            $repository,
            $catalog,
            CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('post'))
        );

        $request = array_merge(
            array(
                'post_ID'     => 42,
                'post_type'   => 'post',
                'post_status' => 'auto-draft',
                'publish'     => 'Publish',
            ),
            $requestOverrides
        );

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
            $incoming
        );

        $validator->validate($request, $acfPayload);
    }

    private function titleRequired(): \ContentGuard\Domain\Rule
    {
        return $this->required(CoreCatalogFixtures::titleRef());
    }

    private function required(\ContentGuard\Domain\FieldRef $field): \ContentGuard\Domain\Rule
    {
        return RuleFactory::rule(array(
            'postType'    => 'post',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => $field,
                )),
            ),
        ));
    }

    private function postAcfCatalog(): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (string $postType): array {
                if ($postType !== 'post') {
                    return array();
                }

                return array(
                    array(
                        'key'     => 'field_type',
                        'name'    => 'product_type',
                        'label'   => 'Product Type',
                        'type'    => 'select',
                        'choices' => array(
                            'sauce'     => 'Sauce',
                            'Seasoning' => 'Seasoning',
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
}
