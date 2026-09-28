<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\WordPress;

use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;
use ContentLatch\Infrastructure\ACF\IntendedPostStatusResolver;
use ContentLatch\Tests\Support\InMemoryRuleRepository;
use ContentLatch\Infrastructure\WordPress\CoreFieldCatalog;
use ContentLatch\Infrastructure\WordPress\RestSaveValidator;
use ContentLatch\Tests\Support\AcfNestedRepeaterFixtures;
use ContentLatch\Tests\Support\AcfRepeaterFixtures;
use ContentLatch\Tests\Support\CoreCatalogFixtures;
use ContentLatch\Tests\Support\IncomingSaveFixtures;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once dirname(__DIR__, 3) . '/Support/wordpress-rest-functions.php';

final class RestSaveValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['contentlatch_test_post_fields'] = array();
        $GLOBALS['contentlatch_test_thumbnails'] = array();
    }

    public function testEmptyTitleOnPublishReturnsWpErrorWithHttp400(): void
    {
        $result = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($this->prepared(array('post_title' => '')), array('status' => 'publish'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(RestSaveValidator::ERROR_CODE, $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status']);
        $this->assertSame('Title is required.', $result->get_error_message());
        $this->assertNotSame(403, $result->get_error_data()['status']);
    }

    public function testGutenbergPreparedPostWithoutPostTypeStillBlocksEmptyTitle(): void
    {
        $prepared = (object) array(
            'ID'           => 42,
            'post_status'  => 'publish',
            'post_content' => "<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph -->",
        );
        $result = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate(
                $prepared,
                array(
                    'status' => 'publish',
                    'title'  => array('raw' => '', 'rendered' => ''),
                ),
                'post'
            );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(400, $result->get_error_data()['status']);
        $this->assertSame('Title is required.', $result->get_error_message());
    }

    public function testGutenbergEmptyTitleRawBlocksWhenPreparedPostOmitsTitle(): void
    {
        $prepared = (object) array(
            'ID'          => 42,
            'post_type'   => 'post',
            'post_status' => 'publish',
        );
        $result = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($prepared, array(
                'status' => 'publish',
                'title'  => array('raw' => ''),
            ));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(400, $result->get_error_data()['status']);
    }

    public function testGutenbergFilledTitleRawIsAcceptedWhenPreparedPostOmitsTitle(): void
    {
        $prepared = (object) array(
            'ID'          => 42,
            'post_type'   => 'post',
            'post_status' => 'publish',
        );
        $result = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($prepared, array(
                'status' => 'publish',
                'title'  => array('raw' => 'Hello World'),
            ));

        $this->assertSame($prepared, $result);
    }

    public function testRequestTypeIsUsedWhenPreparedPostOmitsPostType(): void
    {
        $prepared = (object) array(
            'ID'          => 42,
            'post_status' => 'publish',
            'post_title'  => '',
        );
        $result = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($prepared, array(
                'status' => 'publish',
                'type'   => 'post',
            ));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(400, $result->get_error_data()['status']);
    }

    public function testValidTitleOnPublishIsAccepted(): void
    {
        $prepared = $this->prepared(array('post_title' => 'Hello'));
        $result   = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($prepared, array('status' => 'publish'));

        $this->assertSame($prepared, $result);
    }

    public function testEmptyContentOnPublishIsRejected(): void
    {
        $result = $this->validator(array($this->required(CoreCatalogFixtures::contentRef())))
            ->validate(
                $this->prepared(array('post_content' => "<!-- wp:paragraph -->\n<!-- /wp:paragraph -->")),
                array('status' => 'publish')
            );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(400, $result->get_error_data()['status']);
    }

    public function testValidContentOnPublishIsAccepted(): void
    {
        $prepared = $this->prepared(array(
            'post_content' => "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->",
        ));
        $result = $this->validator(array($this->required(CoreCatalogFixtures::contentRef())))
            ->validate($prepared, array('status' => 'publish'));

        $this->assertSame($prepared, $result);
    }

    public function testGutenbergContentRawObjectOnPublishIsAccepted(): void
    {
        $markup   = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->';
        $prepared = (object) array(
            'ID'          => 42,
            'post_type'   => 'post',
            'post_status' => 'publish',
        );
        $result = $this->validator(array($this->required(CoreCatalogFixtures::contentRef())))
            ->validate($prepared, array(
                'status'  => 'publish',
                'content' => array(
                    'raw'           => $markup,
                    'rendered'      => '<p>Hello world</p>',
                    'protected'     => false,
                    'block_version' => 1,
                ),
            ));

        $this->assertSame($prepared, $result);
    }

    public function testGutenbergContentStringOnPublishIsAccepted(): void
    {
        $markup   = "<!-- wp:paragraph -->\n<p>Hello world</p>\n<!-- /wp:paragraph -->";
        $prepared = (object) array(
            'ID'          => 42,
            'post_type'   => 'post',
            'post_status' => 'publish',
        );
        $result = $this->validator(array($this->required(CoreCatalogFixtures::contentRef())))
            ->validate($prepared, array(
                'status'  => 'publish',
                'content' => $markup,
            ));

        $this->assertSame($prepared, $result);
    }

    public function testGutenbergEmptyContentRawIsRejected(): void
    {
        $prepared = (object) array(
            'ID'          => 42,
            'post_type'   => 'post',
            'post_status' => 'publish',
        );
        $result = $this->validator(array($this->required(CoreCatalogFixtures::contentRef())))
            ->validate($prepared, array(
                'status'  => 'publish',
                'content' => array('raw' => ''),
            ));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(400, $result->get_error_data()['status']);
    }

    public function testJsonBodyIsPreferredWhenSanitizedContentIsAnEmptyObject(): void
    {
        $markup  = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->';
        $request = new class ($markup) implements \ArrayAccess {
            public function __construct(private string $markup)
            {
            }

            public function get_json_params(): array
            {
                return array(
                    'status'  => 'publish',
                    'content' => array(
                        'raw'           => $this->markup,
                        'rendered'      => '<p>Hello world</p>',
                        'protected'     => false,
                        'block_version' => 1,
                    ),
                );
            }

            public function offsetExists(mixed $offset): bool
            {
                return $offset === 'status' || $offset === 'content';
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $offset === 'content' ? array() : 'publish';
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
            }

            public function offsetUnset(mixed $offset): void
            {
            }
        };
        $prepared = (object) array(
            'ID'          => 42,
            'post_type'   => 'post',
            'post_status' => 'publish',
        );
        $result = $this->validator(array($this->required(CoreCatalogFixtures::contentRef())))
            ->validate($prepared, $request);

        $this->assertSame($prepared, $result);
    }

    public function testGutenbergTitleRawMeetsMinimumLength(): void
    {
        $prepared = (object) array(
            'ID'          => 42,
            'post_type'   => 'post',
            'post_status' => 'publish',
        );
        $rule = RuleFactory::rule(array(
            'postType'    => 'post',
            'severity'    => RuleSeverity::Warning,
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'  => CoreCatalogFixtures::titleRef(),
                    'type'   => 'min_length',
                    'params' => array('min' => 10),
                )),
            ),
        ));
        $passing = $this->validator(array($rule))->validate($prepared, array(
            'status' => 'publish',
            'title'  => array('raw' => 'This is a valid title'),
        ));
        $failing = $this->validator(array($rule))->validate($prepared, array(
            'status' => 'publish',
            'title'  => array('raw' => 'Short'),
        ));

        $this->assertSame($prepared, $passing);
        $this->assertSame($prepared, $failing);
    }

    public function testGutenbergTitleRawObjectBlocksWhenTooShort(): void
    {
        $prepared = (object) array(
            'ID'          => 42,
            'post_type'   => 'post',
            'post_status' => 'publish',
        );
        $rule = RuleFactory::rule(array(
            'postType'    => 'post',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'  => CoreCatalogFixtures::titleRef(),
                    'type'   => 'min_length',
                    'params' => array('min' => 10),
                )),
            ),
        ));
        $passing = $this->validator(array($rule))->validate($prepared, array(
            'status' => 'publish',
            'title'  => array('raw' => 'This is a valid title'),
        ));
        $failing = $this->validator(array($rule))->validate($prepared, array(
            'status' => 'publish',
            'title'  => array('raw' => 'Short'),
        ));

        $this->assertSame($prepared, $passing);
        $this->assertInstanceOf(WP_Error::class, $failing);
        $this->assertSame(400, $failing->get_error_data()['status']);
    }

    public function testEmptyExcerptOnPublishIsRejected(): void
    {
        $result = $this->validator(array($this->required(CoreCatalogFixtures::excerptRef())))
            ->validate($this->prepared(array('post_excerpt' => '')), array('status' => 'publish'));

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testValidExcerptOnPublishIsAccepted(): void
    {
        $prepared = $this->prepared(array('post_excerpt' => 'A blurb'));
        $result   = $this->validator(array($this->required(CoreCatalogFixtures::excerptRef())))
            ->validate($prepared, array('status' => 'publish'));

        $this->assertSame($prepared, $result);
    }

    public function testEmptyFeaturedImageOnPublishIsRejected(): void
    {
        $result = $this->validator(array($this->required(CoreCatalogFixtures::featuredImageRef())))
            ->validate($this->prepared(), array('status' => 'publish', 'featured_media' => 0));

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testValidFeaturedImageOnPublishIsAccepted(): void
    {
        $prepared = $this->prepared();
        $result   = $this->validator(array($this->required(CoreCatalogFixtures::featuredImageRef())))
            ->validate($prepared, array('status' => 'publish', 'featured_media' => 22));

        $this->assertSame($prepared, $result);
    }

    public function testEmptyAuthorOnPublishIsRejected(): void
    {
        $result = $this->validator(array($this->required(CoreCatalogFixtures::authorRef())))
            ->validate($this->prepared(array('post_author' => 0)), array('status' => 'publish'));

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testValidAuthorOnPublishIsAccepted(): void
    {
        $prepared = $this->prepared(array('post_author' => 4));
        $result   = $this->validator(array($this->required(CoreCatalogFixtures::authorRef())))
            ->validate($prepared, array('status' => 'publish'));

        $this->assertSame($prepared, $result);
    }

    public function testWarningDoesNotRejectTheRequest(): void
    {
        $prepared = $this->prepared(array('post_title' => 'Hi'));
        $result   = $this->validator(array(
            $this->required(CoreCatalogFixtures::titleRef(), array(
                'severity'    => RuleSeverity::Warning,
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'  => CoreCatalogFixtures::titleRef(),
                        'type'   => 'min_length',
                        'params' => array('min' => 8),
                    )),
                ),
            )),
        ))->validate($prepared, array('status' => 'publish'));

        $this->assertSame($prepared, $result);
    }

    public function testDraftSaveIsNotRejected(): void
    {
        $prepared = $this->prepared(array(
            'post_title'  => '',
            'post_status' => 'draft',
        ));
        $result = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($prepared, array('status' => 'draft'));

        $this->assertSame($prepared, $result);
    }

    public function testAutosaveRouteIsNotRejected(): void
    {
        $prepared = $this->prepared(array('post_title' => ''));
        $result   = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($prepared, array(
                'status' => 'publish',
                'route'  => '/wp/v2/posts/42/autosaves',
            ));

        $this->assertSame($prepared, $result);
    }

    public function testRevisionPostTypeIsNotRejected(): void
    {
        $prepared = $this->prepared(array(
            'post_type'  => 'revision',
            'post_title' => '',
        ));
        $result = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($prepared, array('status' => 'publish'));

        $this->assertSame($prepared, $result);
    }

    public function testMixedAcfConditionCoreValidationOnRest(): void
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

        $failing = $this->productValidator($rules)->validate(
            $this->prepared(array(
                'post_type'  => 'product',
                'post_title' => '',
            )),
            array('status' => 'publish', 'acf' => array('field_type' => 'Seasoning'))
        );
        $passing = $this->productValidator($rules)->validate(
            $this->prepared(array(
                'post_type'  => 'product',
                'post_title' => 'Salt',
            )),
            array('status' => 'publish', 'acf' => array('field_type' => 'Seasoning'))
        );

        $this->assertInstanceOf(WP_Error::class, $failing);
        $this->assertSame(400, $failing->get_error_data()['status']);
        $this->assertInstanceOf(\stdClass::class, $passing);
    }

    public function testMixedCoreConditionAcfValidationOnRest(): void
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

        $failing = $this->productValidator($rules)->validate(
            $this->prepared(array(
                'post_type'  => 'product',
                'post_title' => 'Product',
            )),
            array('status' => 'publish', 'acf' => array('field_ingredients' => ''))
        );
        $passing = $this->productValidator($rules)->validate(
            $this->prepared(array(
                'post_type'  => 'product',
                'post_title' => 'Product',
            )),
            array('status' => 'publish', 'acf' => array('field_ingredients' => 'Salt'))
        );

        $this->assertInstanceOf(WP_Error::class, $failing);
        $this->assertSame(400, $failing->get_error_data()['status']);
        $this->assertInstanceOf(\stdClass::class, $passing);
    }

    public function testRepeaterFailureIncludesStructuredRepeaterPath(): void
    {
        $result = $this->recipeValidator(array(
            RuleFactory::rule(array(
                'postType'    => 'recipe',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'      => AcfRepeaterFixtures::ingredientRef(),
                        'type'       => 'required',
                        'quantifier' => 'every',
                    )),
                ),
            )),
        ))->validate(
            $this->prepared(array(
                'post_type'  => 'recipe',
                'post_title' => 'Recipe',
            )),
            array(
                'status' => 'publish',
                'acf'    => array(
                    AcfRepeaterFixtures::INGREDIENT_LIST => array(
                        'row-0' => array(AcfRepeaterFixtures::INGREDIENT => 'Salt'),
                        'row-1' => array(AcfRepeaterFixtures::INGREDIENT => 'Pepper'),
                        'row-2' => array(AcfRepeaterFixtures::INGREDIENT => ''),
                    ),
                ),
            )
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(400, $result->get_error_data()['status']);
        $this->assertSame(RestSaveValidator::ERROR_CODE, $result->get_error_code());
        $failure = $result->get_error_data()['failures'][0];
        $this->assertSame(AcfRepeaterFixtures::INGREDIENT, $failure['field']);
        $this->assertSame(
            array(
                array(
                    'repeater'    => AcfRepeaterFixtures::INGREDIENT_LIST,
                    'display_row' => 3,
                ),
            ),
            $failure['repeaterPath']
        );
    }

    public function testNestedRepeaterFailureIncludesOuterThenInnerPath(): void
    {
        $result = $this->nestedRecipeValidator(array(
            RuleFactory::rule(array(
                'postType'    => 'recipe',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field'      => AcfNestedRepeaterFixtures::stepNameRef(),
                        'type'       => 'required',
                        'quantifier' => 'every',
                    )),
                ),
            )),
        ))->validate(
            $this->prepared(array(
                'post_type'  => 'recipe',
                'post_title' => 'Recipe',
            )),
            array(
                'status' => 'publish',
                'acf'    => array(
                    AcfNestedRepeaterFixtures::DIRECTIONS => array(
                        'row-0' => array(
                            AcfNestedRepeaterFixtures::STEPS => array(
                                'row-0' => array(AcfNestedRepeaterFixtures::STEP_NAME => 'Cut'),
                                'row-1' => array(AcfNestedRepeaterFixtures::STEP_NAME => ''),
                            ),
                        ),
                    ),
                ),
            )
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(400, $result->get_error_data()['status']);
        $failure = $result->get_error_data()['failures'][0];
        $this->assertSame(AcfNestedRepeaterFixtures::STEP_NAME, $failure['field']);
        $this->assertSame(
            array(
                array(
                    'repeater'    => AcfNestedRepeaterFixtures::DIRECTIONS,
                    'display_row' => 1,
                ),
                array(
                    'repeater'    => AcfNestedRepeaterFixtures::STEPS,
                    'display_row' => 2,
                ),
            ),
            $failure['repeaterPath']
        );
    }

    public function testMultipleFailuresAreAggregatedWithoutUsing403(): void
    {
        $result = $this->validator(array(
            $this->required(CoreCatalogFixtures::titleRef(), array('id' => 1)),
            $this->required(CoreCatalogFixtures::excerptRef(), array('id' => 2)),
        ))->validate(
            $this->prepared(array('post_title' => '', 'post_excerpt' => '')),
            array('status' => 'publish')
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(400, $result->get_error_data()['status']);
        $this->assertSame('Title is required. Excerpt is required.', $result->get_error_message());
        $this->assertCount(2, $result->get_error_data()['failures']);
        $this->assertSame('title', $result->get_error_data()['failures'][0]['field']);
        $this->assertSame('Title', $result->get_error_data()['failures'][0]['label']);
        $this->assertSame('excerpt', $result->get_error_data()['failures'][1]['field']);
        $this->assertSame('Excerpt', $result->get_error_data()['failures'][1]['label']);
        $this->assertArrayNotHasKey('status_forbidden', $result->get_error_data());
    }

    public function testExistingPostOmittingFeaturedMediaKeepsStoredThumbnail(): void
    {
        $GLOBALS['contentlatch_test_thumbnails'][42] = 123;
        $prepared = $this->gutenbergPrepared();
        $result   = $this->validator(array($this->required(CoreCatalogFixtures::featuredImageRef())))
            ->validate($prepared, array('status' => 'publish'));

        $this->assertSame($prepared, $result);
    }

    public function testExistingPostFeaturedMediaIdIsAccepted(): void
    {
        $prepared = $this->gutenbergPrepared();
        $result   = $this->validator(array($this->required(CoreCatalogFixtures::featuredImageRef())))
            ->validate($prepared, array('status' => 'publish', 'featured_media' => 123));

        $this->assertSame($prepared, $result);
    }

    public function testExistingPostFeaturedMediaZeroClearsStoredThumbnail(): void
    {
        $GLOBALS['contentlatch_test_thumbnails'][42] = 123;
        $result = $this->validator(array($this->required(CoreCatalogFixtures::featuredImageRef())))
            ->validate($this->gutenbergPrepared(), array('status' => 'publish', 'featured_media' => 0));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('Featured Image is required.', $result->get_error_message());
        $this->assertSame('featured_image', $result->get_error_data()['failures'][0]['field']);
    }

    public function testNewPostOmittingFeaturedMediaFailsRequired(): void
    {
        $prepared = (object) array(
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
        );
        $result = $this->validator(array($this->required(CoreCatalogFixtures::featuredImageRef())))
            ->validate($prepared, array('status' => 'publish'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('featured_image', $result->get_error_data()['failures'][0]['field']);
    }

    public function testExistingPostOmittingTitleUsesStoredTitle(): void
    {
        $GLOBALS['contentlatch_test_post_fields'][42] = array('post_title' => 'Existing title');
        $prepared = $this->gutenbergPrepared();
        $result   = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($prepared, array('status' => 'publish'));

        $this->assertSame($prepared, $result);
    }

    public function testExistingPostSubmittedEmptyTitleFailsDespiteStoredTitle(): void
    {
        $GLOBALS['contentlatch_test_post_fields'][42] = array('post_title' => 'Existing title');
        $prepared = (object) array(
            'ID'          => 42,
            'post_type'   => 'post',
            'post_status' => 'publish',
        );
        $result = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($prepared, array(
                'status' => 'publish',
                'title'  => array('raw' => ''),
            ));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('Title is required.', $result->get_error_message());
    }

    public function testExistingPostOmittingContentUsesStoredContent(): void
    {
        $GLOBALS['contentlatch_test_post_fields'][42] = array(
            'post_content' => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
        );
        $prepared = (object) array(
            'ID'         => 42,
            'post_type'  => 'post',
            'post_status'=> 'publish',
            'post_title' => 'Existing title',
        );
        $result = $this->validator(array($this->required(CoreCatalogFixtures::contentRef())))
            ->validate($prepared, array('status' => 'publish'));

        $this->assertSame($prepared, $result);
    }

    public function testExistingPostSubmittedEmptyContentFailsDespiteStoredContent(): void
    {
        $GLOBALS['contentlatch_test_post_fields'][42] = array(
            'post_content' => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
        );
        $prepared = (object) array(
            'ID'          => 42,
            'post_type'   => 'post',
            'post_status' => 'publish',
        );
        $result = $this->validator(array($this->required(CoreCatalogFixtures::contentRef())))
            ->validate($prepared, array(
                'status'  => 'publish',
                'content' => array('raw' => ''),
            ));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('Content is required.', $result->get_error_message());
    }

    public function testGutenbergPartialUpdatePassesWhenStoredTitleAndFeaturedImageRemain(): void
    {
        $GLOBALS['contentlatch_test_post_fields'][42] = array('post_title' => 'Existing title');
        $GLOBALS['contentlatch_test_thumbnails'][42] = 123;
        $prepared = $this->gutenbergPrepared();
        $result   = $this->validator(array(
            RuleFactory::rule(array(
                'id'          => 10,
                'postType'    => 'post',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array('id' => 'v-title', 'field' => CoreCatalogFixtures::titleRef())),
                    RuleFactory::validation(array('id' => 'v-content', 'field' => CoreCatalogFixtures::contentRef())),
                    RuleFactory::validation(array('id' => 'v-featured', 'field' => CoreCatalogFixtures::featuredImageRef())),
                ),
            )),
        ))->validate($prepared, array(
            'status'  => 'publish',
            'content' => array(
                'raw'           => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
                'rendered'      => '<p>Hello world</p>',
                'protected'     => false,
                'block_version' => 1,
            ),
        ));

        $this->assertSame($prepared, $result);
    }

    public function testOmittedFeaturedMediaWithoutStoredThumbnailNamesTheFailingField(): void
    {
        $result = $this->validator(array(
            $this->required(CoreCatalogFixtures::titleRef(), array('id' => 1)),
            $this->required(CoreCatalogFixtures::contentRef(), array('id' => 2)),
            $this->required(CoreCatalogFixtures::featuredImageRef(), array('id' => 3)),
        ))->validate(
            $this->gutenbergPrepared(array('post_title' => 'Existing title')),
            array('status' => 'publish')
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('Featured Image is required.', $result->get_error_message());
        $this->assertCount(1, $result->get_error_data()['failures']);
        $this->assertSame('featured_image', $result->get_error_data()['failures'][0]['field']);
        $this->assertSame(3, $result->get_error_data()['failures'][0]['rule_id']);
    }

    /**
     * @param array<int, mixed> $rules
     */
    private function validator(array $rules): RestSaveValidator
    {
        $repository = new InMemoryRuleRepository($rules);

        return new RestSaveValidator(
            IncomingSaveFixtures::evaluator(
                $repository,
                new AcfFieldCatalog(static fn (): array => array()),
                CoreCatalogFixtures::integration()
            ),
            new IntendedPostStatusResolver()
        );
    }

    /**
     * @param array<int, mixed> $rules
     */
    private function productValidator(array $rules): RestSaveValidator
    {
        $repository = new InMemoryRuleRepository($rules);

        return new RestSaveValidator(
            IncomingSaveFixtures::evaluator(
                $repository,
                $this->productAcfCatalog(),
                CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('product'))
            ),
            new IntendedPostStatusResolver()
        );
    }

    /**
     * @param array<int, mixed> $rules
     */
    private function recipeValidator(array $rules): RestSaveValidator
    {
        $repository = new InMemoryRuleRepository($rules);

        return new RestSaveValidator(
            IncomingSaveFixtures::evaluator(
                $repository,
                AcfRepeaterFixtures::recipeCatalog(),
                CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('recipe'))
            ),
            new IntendedPostStatusResolver()
        );
    }

    /**
     * @param array<int, mixed> $rules
     */
    private function nestedRecipeValidator(array $rules): RestSaveValidator
    {
        $repository = new InMemoryRuleRepository($rules);

        return new RestSaveValidator(
            IncomingSaveFixtures::evaluator(
                $repository,
                AcfNestedRepeaterFixtures::recipeCatalog(),
                CoreCatalogFixtures::integration(CoreCatalogFixtures::fullPost('recipe'))
            ),
            new IntendedPostStatusResolver()
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function prepared(array $overrides = array()): object
    {
        return (object) array_merge(
            array(
                'ID'           => 42,
                'post_type'    => 'post',
                'post_status'  => 'publish',
                'post_title'   => 'Existing',
                'post_content' => 'Body',
                'post_excerpt' => 'Blurb',
                'post_name'    => 'existing',
                'post_author'  => 1,
            ),
            $overrides
        );
    }

    /**
     * Gutenberg rest_pre_insert shape: content is copied onto the prepared
     * post, but unchanged title/featured_media are omitted from both the
     * prepared post and the JSON body.
     *
     * @param array<string, mixed> $overrides
     */
    private function gutenbergPrepared(array $overrides = array()): object
    {
        return (object) array_merge(
            array(
                'ID'           => 42,
                'post_type'    => 'post',
                'post_status'  => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
            ),
            $overrides
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function required(\ContentLatch\Domain\FieldRef $field, array $overrides = array()): \ContentLatch\Domain\Rule
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
