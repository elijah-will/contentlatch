<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\IncomingSaveEvaluator;
use ContentGuard\Domain\FieldRef;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Tests\Support\InMemoryRuleRepository;
use ContentGuard\Infrastructure\WordPress\CoreFieldCatalog;
use ContentGuard\Tests\Support\CoreCatalogFixtures;
use ContentGuard\Tests\Support\IncomingSaveFixtures;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/wordpress-rest-functions.php';

final class IncomingSaveEvaluatorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['contentguard_test_post_fields'] = array();
        $GLOBALS['contentguard_test_thumbnails'] = array();
    }

    public function testEmptyTitleWithBlockingRequiredIsRejected(): void
    {
        $evaluation = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::titleRef())))
            ->evaluate(1, 'post', array('title' => ''), null);

        $this->assertTrue($evaluation->isFailed());
        $this->assertSame(CoreFieldCatalog::TITLE, $evaluation->results[0]->fieldId);
    }

    public function testValidTitleIsAccepted(): void
    {
        $evaluation = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::titleRef())))
            ->evaluate(1, 'post', array('title' => 'Hello'), null);

        $this->assertTrue($evaluation->isPassed());
    }

    public function testEmptyContentWithBlockingRequiredIsRejected(): void
    {
        $failing = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::contentRef())))
            ->evaluate(1, 'post', array(
                'content' => "<!-- wp:paragraph -->\n<!-- /wp:paragraph -->",
            ), null);
        $passing = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::contentRef())))
            ->evaluate(1, 'post', array(
                'content' => "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->",
            ), null);

        $this->assertTrue($failing->isFailed());
        $this->assertTrue($passing->isPassed());
    }

    public function testGutenbergRestContentObjectPassesRequired(): void
    {
        $markup  = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->';
        $passing = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::contentRef())))
            ->evaluate(1, 'post', array(
                'content' => array(
                    'raw'           => $markup,
                    'rendered'      => '<p>Hello world</p>',
                    'protected'     => false,
                    'block_version' => 1,
                ),
            ), null);
        $failing = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::contentRef())))
            ->evaluate(1, 'post', array(
                'content' => array('raw' => ''),
            ), null);

        $this->assertTrue($passing->isPassed());
        $this->assertTrue($failing->isFailed());
    }

    public function testGutenbergRestTitleRawMinimumLength(): void
    {
        $rule = $this->requiredRule(CoreCatalogFixtures::titleRef(), array(
            'severity'    => RuleSeverity::Warning,
            'validations' => array(
                RuleFactory::validation(array(
                    'field'   => CoreCatalogFixtures::titleRef(),
                    'type'    => 'min_length',
                    'params'  => array('min' => 10),
                    'message' => 'Title is too short.',
                )),
            ),
        ));
        $passing = $this->coreOnly(array($rule))->evaluate(
            1,
            'post',
            array('title' => array('raw' => 'This is a valid title')),
            null
        );
        $failing = $this->coreOnly(array($rule))->evaluate(
            1,
            'post',
            array('title' => array('raw' => 'Short')),
            null
        );

        $this->assertTrue($passing->isPassed());
        $this->assertTrue($failing->isWarning());
        $this->assertFalse($failing->isFailed());
    }

    public function testEmptyExcerptWithBlockingRequiredIsRejected(): void
    {
        $failing = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::excerptRef())))
            ->evaluate(1, 'post', array('excerpt' => ''), null);
        $passing = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::excerptRef())))
            ->evaluate(1, 'post', array('excerpt' => 'A blurb'), null);

        $this->assertTrue($failing->isFailed());
        $this->assertTrue($passing->isPassed());
    }

    public function testEmptyFeaturedImageWithBlockingRequiredIsRejected(): void
    {
        $failing = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::featuredImageRef())))
            ->evaluate(1, 'post', array('featured_media' => 0), null);
        $passing = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::featuredImageRef())))
            ->evaluate(1, 'post', array('featured_media' => 15), null);

        $this->assertTrue($failing->isFailed());
        $this->assertTrue($passing->isPassed());
    }

    public function testEmptyAuthorWithBlockingRequiredIsRejected(): void
    {
        $failing = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::authorRef())))
            ->evaluate(1, 'post', array('author' => 0), null);
        $passing = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::authorRef())))
            ->evaluate(1, 'post', array('author' => 3), null);

        $this->assertTrue($failing->isFailed());
        $this->assertTrue($passing->isPassed());
    }

    public function testFailedCoreWarningDoesNotFailEvaluation(): void
    {
        $evaluation = $this->coreOnly(array(
            $this->requiredRule(CoreCatalogFixtures::titleRef(), array(
                'severity' => RuleSeverity::Warning,
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'   => CoreCatalogFixtures::titleRef(),
                        'type'    => 'min_length',
                        'params'  => array('min' => 8),
                        'message' => 'Title is too short.',
                    )),
                ),
            )),
        ))->evaluate(1, 'post', array('title' => 'Hi'), null);

        $this->assertTrue($evaluation->isWarning());
        $this->assertFalse($evaluation->isFailed());
        $this->assertTrue($evaluation->results[0]->isWarning());
        $this->assertSame('Title is too short.', $evaluation->results[0]->message);
    }

    public function testMixedAcfConditionCoreValidation(): void
    {
        $rules = array(
            RuleFactory::rule(array(
                'postType'   => 'product',
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
        );

        $failing = $this->mixed($rules)->evaluate(
            4,
            'product',
            array('title' => ''),
            array('field_type' => 'Seasoning')
        );
        $passing = $this->mixed($rules)->evaluate(
            4,
            'product',
            array('title' => 'Salt'),
            array('field_type' => 'Seasoning')
        );
        $skipped = $this->mixed($rules)->evaluate(
            4,
            'product',
            array('title' => ''),
            array('field_type' => 'sauce')
        );

        $this->assertTrue($failing->isFailed());
        $this->assertSame(CoreFieldCatalog::TITLE, $failing->results[0]->fieldId);
        $this->assertTrue($passing->isPassed());
        $this->assertTrue($skipped->results[0]->isSkipped());
    }

    public function testConditionOnlyBlockingContainsBlocksSave(): void
    {
        $rule = RuleFactory::rule(array(
            'postType'    => 'post',
            'conditions'  => array(
                RuleFactory::condition(array(
                    'field'    => CoreCatalogFixtures::contentRef(),
                    'operator' => 'contains',
                    'operand'  => 'healthy',
                )),
            ),
            'validations' => array(),
            'message'     => 'Please avoid the term "healthy" in recipe content.',
        ));

        $failing = $this->coreOnly(array($rule))->evaluate(
            1,
            'post',
            array('content' => 'A healthy dinner'),
            null
        );
        $passing = $this->coreOnly(array($rule))->evaluate(
            1,
            'post',
            array('content' => 'A tasty dinner'),
            null
        );

        $this->assertTrue($failing->isFailed());
        $this->assertSame(CoreFieldCatalog::CONTENT, $failing->results[0]->fieldId);
        $this->assertSame('Please avoid the term "healthy" in recipe content.', $failing->results[0]->message);
        $this->assertTrue($passing->results[0]->isPassed());
        $this->assertTrue($passing->isPassed());
        $this->assertFalse($passing->isFailed());
    }

    public function testConditionOnlyWarningContainsDoesNotBlockSave(): void
    {
        $rule = RuleFactory::rule(array(
            'postType'    => 'post',
            'severity'    => RuleSeverity::Warning,
            'conditions'  => array(
                RuleFactory::condition(array(
                    'field'    => CoreCatalogFixtures::contentRef(),
                    'operator' => 'contains',
                    'operand'  => 'healthy',
                )),
            ),
            'validations' => array(),
            'message'     => 'Please avoid the term "healthy" in recipe content.',
        ));

        $evaluation = $this->coreOnly(array($rule))->evaluate(
            1,
            'post',
            array('content' => 'A healthy dinner'),
            null
        );

        $this->assertTrue($evaluation->isWarning());
        $this->assertFalse($evaluation->isFailed());
        $this->assertSame('Please avoid the term "healthy" in recipe content.', $evaluation->results[0]->message);
    }

    public function testMixedCoreConditionAcfValidation(): void
    {
        $rules = array(
            RuleFactory::rule(array(
                'postType'   => 'product',
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
        );

        $failing = $this->mixed($rules)->evaluate(
            8,
            'product',
            array('title' => 'Product'),
            array('field_ingredients' => '')
        );
        $passing = $this->mixed($rules)->evaluate(
            8,
            'product',
            array('post_title' => 'Product'),
            array('field_ingredients' => 'Salt')
        );

        $this->assertTrue($failing->isFailed());
        $this->assertSame('field_ingredients', $failing->results[0]->fieldId);
        $this->assertTrue($passing->isPassed());
    }

    public function testCoreRulesAreSkippedWhenCorePayloadIsAbsent(): void
    {
        $evaluation = $this->mixed(array(
            $this->requiredRule(CoreCatalogFixtures::titleRef(), array('postType' => 'product')),
        ))->evaluate(1, 'product', null, array('field_type' => 'Seasoning'));

        $this->assertTrue($evaluation->isNotEvaluated());
        $this->assertSame(array(), $evaluation->results);
    }

    public function testAcfRulesStillEvaluateWhenCorePayloadIsAbsent(): void
    {
        $evaluation = $this->mixed(array(
            RuleFactory::rule(array(
                'postType'    => 'product',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'   => RuleFactory::field('field_ingredients', 'ingredients', 'Ingredients'),
                        'message' => 'Ingredients are required.',
                    )),
                ),
            )),
        ))->evaluate(1, 'product', null, array('field_ingredients' => ''));

        $this->assertTrue($evaluation->isFailed());
        $this->assertSame('field_ingredients', $evaluation->results[0]->fieldId);
    }

    public function testMultipleBlockingFailuresAreAllReturned(): void
    {
        $evaluation = $this->coreOnly(array(
            $this->requiredRule(CoreCatalogFixtures::titleRef(), array('id' => 1)),
            $this->requiredRule(CoreCatalogFixtures::excerptRef(), array('id' => 2)),
        ))->evaluate(1, 'post', array('title' => '', 'excerpt' => ''), null);

        $this->assertTrue($evaluation->isFailed());
        $this->assertCount(2, $evaluation->results);
        $this->assertTrue($evaluation->results[0]->isFailed());
        $this->assertTrue($evaluation->results[1]->isFailed());
    }

    public function testOmittedFeaturedMediaUsesStoredThumbnailOnExistingPost(): void
    {
        $GLOBALS['contentguard_test_thumbnails'][13214] = 123;
        $evaluation = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::featuredImageRef())))
            ->evaluate(13214, 'post', array('content' => '<p>Hello world</p>'), null);

        $this->assertTrue($evaluation->isPassed());
        $this->assertFalse($evaluation->results[0]->isFailed());
    }

    public function testSubmittedFeaturedMediaZeroFailsEvenWhenStoredThumbnailExists(): void
    {
        $GLOBALS['contentguard_test_thumbnails'][13214] = 123;
        $evaluation = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::featuredImageRef())))
            ->evaluate(13214, 'post', array('featured_media' => 0), null);

        $this->assertTrue($evaluation->isFailed());
        $this->assertSame(CoreFieldCatalog::FEATURED_IMAGE, $evaluation->results[0]->fieldId);
    }

    public function testSubmittedFeaturedMediaIdPasses(): void
    {
        $evaluation = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::featuredImageRef())))
            ->evaluate(13214, 'post', array('featured_media' => 123), null);

        $this->assertTrue($evaluation->isPassed());
    }

    public function testOmittedFeaturedMediaOnNewPostFailsRequired(): void
    {
        $evaluation = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::featuredImageRef())))
            ->evaluate(0, 'post', array('content' => '<p>Hello world</p>'), null);

        $this->assertTrue($evaluation->isFailed());
        $this->assertSame(CoreFieldCatalog::FEATURED_IMAGE, $evaluation->results[0]->fieldId);
    }

    public function testOmittedFeaturedMediaWithNoStoredThumbnailFailsRequired(): void
    {
        $GLOBALS['contentguard_test_thumbnails'][13214] = 0;
        $evaluation = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::featuredImageRef())))
            ->evaluate(13214, 'post', array('content' => '<p>Hello world</p>'), null);

        $this->assertTrue($evaluation->isFailed());
        $this->assertSame(CoreFieldCatalog::FEATURED_IMAGE, $evaluation->results[0]->fieldId);
    }

    public function testOmittedTitleUsesStoredTitleOnExistingPost(): void
    {
        $GLOBALS['contentguard_test_post_fields'][13214] = array('post_title' => 'Existing title');
        $evaluation = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::titleRef())))
            ->evaluate(13214, 'post', array('content' => '<p>Hello world</p>'), null);

        $this->assertTrue($evaluation->isPassed());
    }

    public function testSubmittedEmptyTitleFailsEvenWhenStoredTitleExists(): void
    {
        $GLOBALS['contentguard_test_post_fields'][13214] = array('post_title' => 'Existing title');
        $evaluation = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::titleRef())))
            ->evaluate(13214, 'post', array('title' => ''), null);

        $this->assertTrue($evaluation->isFailed());
        $this->assertSame(CoreFieldCatalog::TITLE, $evaluation->results[0]->fieldId);
    }

    public function testOmittedContentUsesStoredContentOnExistingPost(): void
    {
        $GLOBALS['contentguard_test_post_fields'][13214] = array(
            'post_content' => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
        );
        $evaluation = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::contentRef())))
            ->evaluate(13214, 'post', array('title' => 'Existing title'), null);

        $this->assertTrue($evaluation->isPassed());
    }

    public function testSubmittedEmptyContentFailsEvenWhenStoredContentExists(): void
    {
        $GLOBALS['contentguard_test_post_fields'][13214] = array(
            'post_content' => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
        );
        $evaluation = $this->coreOnly(array($this->requiredRule(CoreCatalogFixtures::contentRef())))
            ->evaluate(13214, 'post', array('content' => ''), null);

        $this->assertTrue($evaluation->isFailed());
        $this->assertSame(CoreFieldCatalog::CONTENT, $evaluation->results[0]->fieldId);
    }

    public function testGutenbergPartialPayloadPassesWhenStoredCoreFieldsSatisfyTheRule(): void
    {
        $GLOBALS['contentguard_test_post_fields'][13214] = array('post_title' => 'Existing title');
        $GLOBALS['contentguard_test_thumbnails'][13214] = 123;
        $evaluation = $this->coreOnly(array(
            RuleFactory::rule(array(
                'id'          => 10,
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'id'    => 'v-title',
                        'field' => CoreCatalogFixtures::titleRef(),
                    )),
                    RuleFactory::validation(array(
                        'id'    => 'v-content',
                        'field' => CoreCatalogFixtures::contentRef(),
                    )),
                    RuleFactory::validation(array(
                        'id'    => 'v-featured',
                        'field' => CoreCatalogFixtures::featuredImageRef(),
                    )),
                ),
            )),
        ))->evaluate(13214, 'post', array(
            'content' => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
        ), null);

        $this->assertTrue($evaluation->isPassed());
        $this->assertCount(3, $evaluation->results);
        $this->assertSame(array('title', 'content', 'featured_image'), array_map(
            static fn ($result): string => (string) $result->fieldId,
            $evaluation->results
        ));
    }

    /**
     * @param array<int, mixed> $rules
     */
    private function coreOnly(array $rules): IncomingSaveEvaluator
    {
        return IncomingSaveFixtures::evaluator(
            new InMemoryRuleRepository($rules),
            new AcfFieldCatalog(static fn (): array => array()),
            CoreCatalogFixtures::integration()
        );
    }

    /**
     * @param array<int, mixed> $rules
     */
    private function mixed(array $rules): IncomingSaveEvaluator
    {
        return IncomingSaveFixtures::evaluator(
            new InMemoryRuleRepository($rules),
            $this->productAcfCatalog(),
            CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('product'))
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function requiredRule(FieldRef $field, array $overrides = array()): Rule
    {
        return RuleFactory::rule(array_merge(
            array(
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => $field,
                    )),
                ),
            ),
            $overrides
        ));
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
