<?php
/**
 * Incoming Core values evaluate through ContentEvaluator without RuleEngine changes.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\WordPress;

use ContentGuard\Application\ConditionOperators;
use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\Integration\CompositeFieldCatalog;
use ContentGuard\Application\Integration\CompositeValueProvider;
use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Domain\Exception\InvalidRuleException;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\AcfIncomingValueProvider;
use ContentGuard\Infrastructure\ACF\AcfIntegration;
use ContentGuard\Infrastructure\ACF\AcfValueNormalizer;
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
use ContentGuard\Infrastructure\WordPress\CoreFieldCatalog;
use ContentGuard\Tests\Support\CoreCatalogFixtures;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class CoreIncomingEvaluationTest extends TestCase
{
    public function testIncomingTitleRequiredAndCondition(): void
    {
        $required = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'   => CoreCatalogFixtures::titleRef(),
                        'message' => 'Title is required.',
                    )),
                ),
            )),
        ));

        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array('title' => '')))->isFailed());
        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array('title' => 'Hello')))->isPassed());

        $conditional = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(
                    RuleFactory::condition(array(
                        'field'    => CoreCatalogFixtures::titleRef(),
                        'operator' => 'equals',
                        'operand'  => 'Product',
                    )),
                ),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'   => CoreCatalogFixtures::excerptRef(),
                        'message' => 'Excerpt is required.',
                    )),
                ),
            )),
        ));

        $this->assertTrue($conditional->evaluate(1, 'post', $this->incoming(array(
            'title'   => 'Product',
            'excerpt' => '',
        )))->isFailed());
        $this->assertTrue($conditional->evaluate(1, 'post', $this->incoming(array(
            'title'   => 'Other',
            'excerpt' => '',
        )))->results[0]->isSkipped());
        $this->assertTrue($conditional->evaluate(1, 'post', $this->incoming(array(
            'title'   => 'Product',
            'excerpt' => 'A short blurb',
        )))->isPassed());
    }

    public function testIncomingTitleAndContentContainsOperators(): void
    {
        $avoidHealthy = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'   => 'post',
                'conditions' => array(
                    RuleFactory::condition(array(
                        'field'    => CoreCatalogFixtures::contentRef(),
                        'operator' => 'contains',
                        'operand'  => 'healthy',
                    )),
                ),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'   => CoreCatalogFixtures::titleRef(),
                        'message' => 'Please avoid the term "healthy".',
                    )),
                ),
            )),
        ));

        $this->assertTrue($avoidHealthy->evaluate(1, 'post', $this->incoming(array(
            'content' => '<!-- wp:paragraph --><p>This is a HEALTHY recipe.</p><!-- /wp:paragraph -->',
            'title'   => '',
        )))->isFailed());
        $this->assertTrue($avoidHealthy->evaluate(1, 'post', $this->incoming(array(
            'content' => '<p>This recipe is nutritious.</p>',
            'title'   => '',
        )))->results[0]->isSkipped());

        $needsBrand = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'   => 'post',
                'conditions' => array(
                    RuleFactory::condition(array(
                        'field'    => CoreCatalogFixtures::titleRef(),
                        'operator' => 'does_not_contain',
                        'operand'  => 'B&G',
                    )),
                ),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'   => CoreCatalogFixtures::excerptRef(),
                        'message' => 'Title should mention B&G.',
                    )),
                ),
            )),
        ));

        $this->assertTrue($needsBrand->evaluate(1, 'post', $this->incoming(array(
            'title'   => 'Seasonings',
            'excerpt' => '',
        )))->isFailed());
        $this->assertTrue($needsBrand->evaluate(1, 'post', $this->incoming(array(
            'title'   => 'B&G Seasonings',
            'excerpt' => '',
        )))->results[0]->isSkipped());
        $this->assertTrue($needsBrand->evaluate(1, 'post', $this->incoming(array(
            'title'   => 'Seasonings',
            'excerpt' => 'A short blurb',
        )))->isPassed());
    }

    public function testFactoryPersistsCoreContentContainsWhen(): void
    {
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('post' => 'Post'),
            CoreCatalogFixtures::integration()
        );

        $rule = $factory->fromAdminInput(array(
            'name'             => 'Avoid healthy',
            'target_post_type' => 'post',
            'conditions'       => array(
                array(
                    'field_key' => CoreFieldCatalog::CONTENT,
                    'operator'  => 'contains',
                    'operand'   => 'healthy',
                    'type'      => 'number',
                    'label'     => 'Forged',
                ),
            ),
            'validations'      => array(
                array(
                    'field_key' => CoreFieldCatalog::TITLE,
                    'type'      => 'required',
                    'message'   => 'Please avoid the term "healthy".',
                ),
            ),
        ));

        $this->assertSame('contains', $rule->conditions[0]->operator);
        $this->assertSame('healthy', $rule->conditions[0]->operand);
        $this->assertSame(CoreFieldCatalog::CONTENT, $rule->conditions[0]->field->key);
        $this->assertSame('Content', $rule->conditions[0]->field->label);
    }

    public function testIncomingContentRequiredAndCondition(): void
    {
        $required = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => CoreCatalogFixtures::contentRef(),
                    )),
                ),
            )),
        ));

        $emptyMarkup = $this->incoming(array(
            'content' => "<!-- wp:paragraph -->\n<!-- /wp:paragraph -->",
        ));
        $this->assertTrue($required->evaluate(1, 'post', $emptyMarkup)->isFailed());
        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array(
            'content' => "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->",
        )))->isPassed());

        $conditional = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(
                    RuleFactory::condition(array(
                        'field'    => CoreCatalogFixtures::contentRef(),
                        'operator' => 'is_not_empty',
                        'operand'  => null,
                    )),
                ),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => CoreCatalogFixtures::titleRef(),
                    )),
                ),
            )),
        ));
        $this->assertTrue($conditional->evaluate(1, 'post', $this->incoming(array(
            'content' => '<p></p>',
            'title'   => '',
        )))->results[0]->isSkipped());
        $this->assertTrue($conditional->evaluate(1, 'post', $this->incoming(array(
            'content' => '<p>Body</p>',
            'title'   => '',
        )))->isFailed());
    }

    public function testIncomingExcerptRequiredAndLength(): void
    {
        $required = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => CoreCatalogFixtures::excerptRef(),
                    )),
                ),
            )),
        ));
        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array('excerpt' => '')))->isFailed());
        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array('excerpt' => 'Hello')))->isPassed());

        $min = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'  => CoreCatalogFixtures::excerptRef(),
                        'type'   => 'min_length',
                        'params' => array('min' => 5),
                    )),
                ),
            )),
        ));
        $this->assertTrue($min->evaluate(1, 'post', $this->incoming(array('excerpt' => 'Hi')))->isFailed());
        $this->assertTrue($min->evaluate(1, 'post', $this->incoming(array('excerpt' => 'Hello')))->isPassed());

        $max = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'  => CoreCatalogFixtures::excerptRef(),
                        'type'   => 'max_length',
                        'params' => array('max' => 4),
                    )),
                ),
            )),
        ));
        $this->assertTrue($max->evaluate(1, 'post', $this->incoming(array('excerpt' => 'Hello')))->isFailed());
        $this->assertTrue($max->evaluate(1, 'post', $this->incoming(array('excerpt' => 'Hi')))->isPassed());
    }

    public function testIncomingSlugEvaluatesAsSubmittedTextWithoutGenerating(): void
    {
        $required = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => CoreCatalogFixtures::slugRef(),
                    )),
                ),
            )),
        ));

        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array('slug' => '')))->isFailed());
        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array('slug' => 'hello-world')))->isPassed());
        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array('title' => 'Hello')))->isFailed());

        $equals = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(
                    RuleFactory::condition(array(
                        'field'    => CoreCatalogFixtures::slugRef(),
                        'operator' => 'equals',
                        'operand'  => 'hello-world',
                    )),
                ),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => CoreCatalogFixtures::titleRef(),
                    )),
                ),
            )),
        ));
        $this->assertTrue($equals->evaluate(1, 'post', $this->incoming(array(
            'slug'  => 'hello-world',
            'title' => 'Hello',
        )))->isPassed());
        $this->assertTrue($equals->evaluate(1, 'post', $this->incoming(array(
            'slug'  => 'other',
            'title' => '',
        )))->results[0]->isSkipped());
    }

    public function testIncomingFeaturedImagePresence(): void
    {
        $required = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => CoreCatalogFixtures::featuredImageRef(),
                    )),
                ),
            )),
        ));
        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array('featured_image' => 0)))->isFailed());
        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array('_thumbnail_id' => '-1')))->isFailed());
        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array('featured_image' => 15)))->isPassed());

        $present = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(
                    RuleFactory::condition(array(
                        'field'    => CoreCatalogFixtures::featuredImageRef(),
                        'operator' => 'is_not_empty',
                        'operand'  => null,
                    )),
                ),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => CoreCatalogFixtures::titleRef(),
                    )),
                ),
            )),
        ));
        $this->assertTrue($present->evaluate(1, 'post', $this->incoming(array(
            'featured_image' => 0,
            'title'          => '',
        )))->results[0]->isSkipped());
        $this->assertTrue($present->evaluate(1, 'post', $this->incoming(array(
            'featured_media' => 9,
            'title'          => '',
        )))->isFailed());
        $this->assertTrue($present->evaluate(1, 'post', $this->incoming(array(
            'featured_media' => 9,
            'title'          => 'Has image',
        )))->isPassed());
    }

    public function testIncomingAuthorAsTextNotNumeric(): void
    {
        $required = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => CoreCatalogFixtures::authorRef(),
                    )),
                ),
            )),
        ));
        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array('author' => 0)))->isFailed());
        $this->assertTrue($required->evaluate(1, 'post', $this->incoming(array('post_author' => '4')))->isPassed());

        $equals = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'post',
                'conditions'  => array(
                    RuleFactory::condition(array(
                        'field'    => CoreCatalogFixtures::authorRef(),
                        'operator' => 'equals',
                        'operand'  => '4',
                    )),
                ),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => CoreCatalogFixtures::titleRef(),
                    )),
                ),
            )),
        ));
        $this->assertTrue($equals->evaluate(1, 'post', $this->incoming(array(
            'author' => 4,
            'title'  => 'Hello',
        )))->isPassed());
        $this->assertTrue($equals->evaluate(1, 'post', $this->incoming(array(
            'author' => 5,
            'title'  => '',
        )))->results[0]->isSkipped());

        $this->assertFalse(ConditionOperators::isNumericField('text'));
        $this->assertArrayNotHasKey('greater_than', ConditionOperators::labelsForFieldType('text'));

        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('post' => 'Post'),
            CoreCatalogFixtures::integration()
        );
        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('Numeric comparisons can only be used with number fields.');
        $factory->fromAdminInput(array(
            'name'             => 'Author numeric',
            'target_post_type' => 'post',
            'conditions'       => array(
                array(
                    'field_key' => CoreFieldCatalog::AUTHOR,
                    'operator'  => 'greater_than',
                    'operand'   => '1',
                ),
            ),
            'validations'      => array(
                array(
                    'field_key' => CoreFieldCatalog::TITLE,
                    'type'      => 'required',
                ),
            ),
        ));
    }

    public function testMixedIncomingAcfConditionCoreValidation(): void
    {
        $core = CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('product'));
        $acf  = new AcfIntegration($this->productAcfCatalog());
        $types = (new CompositeFieldCatalog(array($core, $acf)))->fieldTypesForPostType('product');
        $evaluator = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'product',
                'conditions'  => array(
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
        ));

        $failing = new CompositeValueProvider(array(
            $core->incomingProvider(array('title' => ''), 'product', $types),
            $this->acfIncoming(array('field_type' => 'Seasoning'), $types),
        ));
        $failed = $evaluator->evaluate(4, 'product', $failing);
        $this->assertTrue($failed->isFailed());
        $this->assertSame(CoreFieldCatalog::TITLE, $failed->results[0]->fieldId);

        $passing = new CompositeValueProvider(array(
            $core->incomingProvider(array('title' => 'Salt'), 'product', $types),
            $this->acfIncoming(array('field_type' => 'Seasoning'), $types),
        ));
        $this->assertTrue($evaluator->evaluate(4, 'product', $passing)->isPassed());

        $skipped = new CompositeValueProvider(array(
            $core->incomingProvider(array('title' => ''), 'product', $types),
            $this->acfIncoming(array('field_type' => 'sauce'), $types),
        ));
        $this->assertTrue($evaluator->evaluate(4, 'product', $skipped)->results[0]->isSkipped());
    }

    public function testMixedIncomingCoreConditionAcfValidation(): void
    {
        $core = CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('product'));
        $acf  = new AcfIntegration($this->productAcfCatalog());
        $types = (new CompositeFieldCatalog(array($core, $acf)))->fieldTypesForPostType('product');
        $evaluator = $this->evaluator(array(
            RuleFactory::rule(array(
                'postType'    => 'product',
                'conditions'  => array(
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
        ));

        $failing = new CompositeValueProvider(array(
            $core->incomingProvider(array('title' => 'Product'), 'product', $types),
            $this->acfIncoming(array('field_ingredients' => ''), $types),
        ));
        $failed = $evaluator->evaluate(8, 'product', $failing);
        $this->assertTrue($failed->isFailed());
        $this->assertSame('field_ingredients', $failed->results[0]->fieldId);

        $passing = new CompositeValueProvider(array(
            $core->incomingProvider(array('post_title' => 'Product'), 'product', $types),
            $this->acfIncoming(array('field_ingredients' => 'Salt'), $types),
        ));
        $this->assertTrue($evaluator->evaluate(8, 'product', $passing)->isPassed());
    }

    /**
     * @param array<int, mixed> $rules
     */
    private function evaluator(array $rules): ContentEvaluator
    {
        return new ContentEvaluator(
            new InMemoryRuleRepository($rules),
            RuleEngine::v1()
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function incoming(array $payload): \ContentGuard\Domain\Contracts\FieldValueProviderInterface
    {
        $core = CoreCatalogFixtures::integration();

        return $core->incomingProvider(
            $payload,
            'post',
            $core->fieldTypesForPostType('post')
        );
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $fieldTypes
     */
    private function acfIncoming(array $payload, array $fieldTypes): AcfIncomingValueProvider
    {
        return new AcfIncomingValueProvider(
            $payload,
            new AcfValueNormalizer(),
            $fieldTypes
        );
    }

    private function productAcfCatalog(): AcfFieldCatalog
    {
        return new AcfFieldCatalog(
            static function (string $postType): array {
                if ($postType !== 'product') {
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
