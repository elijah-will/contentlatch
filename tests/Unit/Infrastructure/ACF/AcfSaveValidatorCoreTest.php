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
                    'message' => 'Title is required.',
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
                    'message' => 'Title is required.',
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

        $this->assertSame(
            array(
                array(
                    'input'   => 'acf[field_ingredients]',
                    'message' => 'Ingredients are required.',
                ),
            ),
            $this->errors
        );
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
        return RuleFactory::rule(array(
            'postType'    => 'post',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => CoreCatalogFixtures::titleRef(),
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
