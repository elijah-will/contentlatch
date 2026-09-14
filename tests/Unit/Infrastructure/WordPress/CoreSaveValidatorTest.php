<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\WordPress;

use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\IntendedPostStatusResolver;
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
use ContentGuard\Infrastructure\WordPress\CoreSaveValidator;
use ContentGuard\Infrastructure\WordPress\HttpRequest;
use ContentGuard\Tests\Support\CoreCatalogFixtures;
use ContentGuard\Tests\Support\IncomingSaveFixtures;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/Support/wordpress-rest-functions.php';
require_once dirname(__DIR__, 3) . '/Support/wordpress-admin-functions.php';

final class CoreSaveValidatorTest extends TestCase
{
    /**
     * @var array<int, array{message: string, title: string, args: array<string, mixed>}>
     */
    private array $deaths = array();

    protected function setUp(): void
    {
        $this->deaths = array();
        $GLOBALS['contentguard_test_post_fields'] = array();
        $GLOBALS['contentguard_test_thumbnails'] = array();
        $_POST = array();
    }

    protected function tearDown(): void
    {
        $_POST = array();
    }

    public function testPublishBlockerBlocksClassicEditpost(): void
    {
        $messages = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($this->publishRequest(array('post_title' => '')));

        $this->assertSame(array('Title — This field is required.'), $messages);
    }

    public function testPrivateBlockerBlocksClassicEditpost(): void
    {
        $messages = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($this->publishRequest(array(
                'post_title'  => '',
                'publish'     => '',
                'private'     => 'Private',
                'visibility'  => 'private',
                'post_status' => 'draft',
            )));

        $this->assertSame(array('Title — This field is required.'), $messages);
    }

    public function testDraftSaveIsAllowed(): void
    {
        $messages = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($this->publishRequest(array(
                'post_title'  => '',
                'post_status' => 'draft',
                'save'        => 'Save Draft',
                'publish'     => '',
            )));

        $this->assertNull($messages);
    }

    public function testPublishedToDraftWithInvalidTitleIsAllowed(): void
    {
        $GLOBALS['contentguard_test_post_fields'][42] = array('post_title' => 'Existing Title');

        $messages = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate(array(
                'action'               => 'editpost',
                'post_ID'              => 42,
                'post_type'            => 'post',
                'post_status'          => 'draft',
                'original_post_status' => 'publish',
                'save'                 => 'Update',
                'post_title'           => '',
                'content'              => 'Body',
                'post_author'          => 1,
            ));

        $this->assertNull($messages);
    }

    public function testDraftToPublishWithInvalidTitleIsBlocked(): void
    {
        $GLOBALS['contentguard_test_post_fields'][42] = array('post_title' => 'Existing Title');

        $messages = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate(array(
                'action'               => 'editpost',
                'post_ID'              => 42,
                'post_type'            => 'post',
                'post_status'          => 'draft',
                'original_post_status' => 'draft',
                'publish'              => 'Publish',
                'post_title'           => '',
                'content'              => 'Body',
                'post_author'          => 1,
            ));

        $this->assertSame(array('Title — This field is required.'), $messages);
    }

    public function testPublishedToDraftWithRemovedFeaturedImageIsAllowed(): void
    {
        $GLOBALS['contentguard_test_thumbnails'][42] = 123;

        $messages = $this->validator(array($this->required(CoreCatalogFixtures::featuredImageRef())))
            ->validate(array(
                'action'               => 'editpost',
                'post_ID'              => 42,
                'post_type'            => 'post',
                'post_status'          => 'draft',
                'original_post_status' => 'publish',
                'save'                 => 'Update',
                'post_title'           => 'Existing Title',
                'content'              => 'Body',
                '_thumbnail_id'        => '-1',
                'post_author'          => 1,
            ));

        $this->assertNull($messages);
    }

    public function testPublishedToPrivateWithInvalidTitleIsBlocked(): void
    {
        $GLOBALS['contentguard_test_post_fields'][42] = array('post_title' => 'Existing Title');

        $messages = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate(array(
                'action'               => 'editpost',
                'post_ID'              => 42,
                'post_type'            => 'post',
                'post_status'          => 'draft',
                'original_post_status' => 'publish',
                'save'                 => 'Update',
                'visibility'           => 'private',
                'post_title'           => '',
                'content'              => 'Body',
                'post_author'          => 1,
            ));

        $this->assertSame(array('Title — This field is required.'), $messages);
    }

    public function testPublishedToDraftWithValidTitleIsAllowed(): void
    {
        $messages = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate(array(
                'action'               => 'editpost',
                'post_ID'              => 42,
                'post_type'            => 'post',
                'post_status'          => 'draft',
                'original_post_status' => 'publish',
                'save'                 => 'Update',
                'post_title'           => 'Still valid',
                'content'              => 'Body',
                'post_author'          => 1,
            ));

        $this->assertNull($messages);
    }

    public function testPendingAndFutureSavesAreAllowed(): void
    {
        $validator = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())));

        $this->assertNull($validator->validate($this->publishRequest(array(
            'post_title'  => '',
            'post_status' => 'pending',
            'publish'     => '',
        ))));
        $this->assertNull($validator->validate($this->publishRequest(array(
            'post_title'  => '',
            'post_status' => 'future',
            'publish'     => '',
        ))));
    }

    public function testCoreOnlyPostTypeIsBlockedWithoutAcf(): void
    {
        $messages = $this->validator(
            array($this->required(CoreCatalogFixtures::titleRef(), array('postType' => 'notice'))),
            CoreCatalogFixtures::fullPost('notice'),
            new AcfFieldCatalog(static fn (): array => array()),
            'notice'
        )->validate($this->publishRequest(array(
            'post_type'  => 'notice',
            'post_title' => '',
        )));

        $this->assertSame(array('Title — This field is required.'), $messages);
    }

    public function testMissingAndEmptyThumbnailIdBlockFeaturedImage(): void
    {
        $validator = $this->validator(array($this->required(CoreCatalogFixtures::featuredImageRef())));

        $this->assertSame(
            array('Featured Image — This field is required.'),
            $validator->validate($this->publishRequest(array(
                'post_ID' => 0,
                'content' => '<p>Hello</p>',
            )))
        );
        $this->assertSame(
            array('Featured Image — This field is required.'),
            $validator->validate($this->publishRequest(array('_thumbnail_id' => '')))
        );
        $this->assertSame(
            array('Featured Image — This field is required.'),
            $validator->validate($this->publishRequest(array('_thumbnail_id' => '0')))
        );
        $this->assertSame(
            array('Featured Image — This field is required.'),
            $validator->validate($this->publishRequest(array('_thumbnail_id' => '-1')))
        );
    }

    public function testValidThumbnailIdPassesFeaturedImage(): void
    {
        $messages = $this->validator(array($this->required(CoreCatalogFixtures::featuredImageRef())))
            ->validate($this->publishRequest(array('_thumbnail_id' => '22')));

        $this->assertNull($messages);
    }

    public function testEmptyAuthorBlocksAndValidAuthorPasses(): void
    {
        $validator = $this->validator(array($this->required(CoreCatalogFixtures::authorRef())));

        $this->assertSame(
            array('Author — This field is required.'),
            $validator->validate($this->publishRequest(array('post_author' => '0')))
        );
        $this->assertNull($validator->validate($this->publishRequest(array('post_author' => '4'))));
    }

    public function testExplicitEmptySubmittedTitleWinsOverStoredValue(): void
    {
        $GLOBALS['contentguard_test_post_fields'][42] = array('post_title' => 'Existing title');

        $messages = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($this->publishRequest(array('post_title' => '')));

        $this->assertSame(array('Title — This field is required.'), $messages);
    }

    public function testOmittedTitleOnExistingPostUsesStoredValue(): void
    {
        $GLOBALS['contentguard_test_post_fields'][42] = array('post_title' => 'Existing title');

        $messages = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($this->omitting($this->publishRequest(array(
                'content' => '<p>Hello world</p>',
            )), array('post_title')));

        $this->assertNull($messages);
    }

    public function testOmittedFeaturedImageOnExistingPostUsesStoredThumbnail(): void
    {
        $GLOBALS['contentguard_test_thumbnails'][42] = 123;

        $messages = $this->validator(array($this->required(CoreCatalogFixtures::featuredImageRef())))
            ->validate($this->publishRequest(array(
                'content' => '<p>Hello world</p>',
            )));

        $this->assertNull($messages);
    }

    public function testNewPostDoesNotInventStoredCoreValues(): void
    {
        $GLOBALS['contentguard_test_post_fields'][0] = array('post_title' => 'Should not be used');
        $GLOBALS['contentguard_test_thumbnails'][0] = 99;

        $messages = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($this->omitting($this->publishRequest(array(
                'post_ID' => 0,
                'content' => '<p>Hello world</p>',
            )), array('post_title')));

        $this->assertSame(array('Title — This field is required.'), $messages);
    }

    public function testRestRequestsAreSkipped(): void
    {
        $messages = $this->validator(
            array($this->required(CoreCatalogFixtures::titleRef())),
            null,
            null,
            'post',
            static fn (): bool => true
        )->validate($this->publishRequest(array('post_title' => '')));

        $this->assertNull($messages);
    }

    public function testAutosavesAreSkipped(): void
    {
        $messages = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($this->publishRequest(array(
                'action'     => 'autosave',
                'post_title' => '',
            )));

        $this->assertNull($messages);
    }

    public function testRevisionsAreSkipped(): void
    {
        $messages = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->validate($this->publishRequest(array(
                'post_type'  => 'revision',
                'post_title' => '',
            )));

        $this->assertNull($messages);
    }

    public function testNonEditpostAdminRequestsAreSkipped(): void
    {
        $validator = $this->validator(array($this->required(CoreCatalogFixtures::titleRef())));

        $this->assertNull($validator->validate($this->publishRequest(array(
            'action'     => 'inline-save',
            'post_title' => '',
        ))));
        $this->assertNull($validator->validate($this->publishRequest(array(
            'action'     => 'heartbeat',
            'post_title' => '',
        ))));
        $this->assertNull($validator->validate(array(
            'post_title'  => '',
            'post_status' => 'publish',
            'publish'     => 'Publish',
        )));
    }

    public function testMixedCoreAndAcfRulesEvaluateOnClassicEditpost(): void
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

        $failing = $this->productValidator($rules)->validate($this->publishRequest(array(
            'post_type'  => 'product',
            'post_title' => '',
            'acf'        => array('field_type' => 'Seasoning'),
        )));
        $skipped = $this->productValidator($rules)->validate($this->publishRequest(array(
            'post_type'  => 'product',
            'post_title' => '',
            'acf'        => array('field_type' => 'sauce'),
        )));

        $this->assertSame(array('Title — This field is required.'), $failing);
        $this->assertNull($skipped);
    }

    public function testBlockingAbortsBeforePersistence(): void
    {
        $_POST = $this->publishRequest(array('post_title' => ''));
        $this->validator(array($this->required(CoreCatalogFixtures::titleRef())))
            ->onLoadPost();

        $this->assertCount(1, $this->deaths);
        $this->assertStringContainsString('ContentGuard · Blocking', $this->deaths[0]['message']);
        $this->assertStringContainsString('Title — This field is required.', $this->deaths[0]['message']);
        $this->assertStringContainsString('contentguard-audit-blockers', $this->deaths[0]['message']);
        $this->assertSame('ContentGuard · Blocking', $this->deaths[0]['title']);
        $this->assertTrue($this->deaths[0]['args']['back_link']);
        $this->assertSame(400, $this->deaths[0]['args']['response']);
        $this->assertStringNotContainsString('blocking issues', $this->deaths[0]['message']);
        $this->assertStringNotContainsString('required..', $this->deaths[0]['message']);
    }

    public function testMultipleClassicBlockersRenderAsSeparateListItems(): void
    {
        $_POST = $this->publishRequest(array(
            'post_title'    => '',
            'content'       => '',
            '_thumbnail_id' => '-1',
        ));
        $this->validator(array(
            $this->required(CoreCatalogFixtures::titleRef()),
            $this->required(CoreCatalogFixtures::contentRef()),
            $this->required(CoreCatalogFixtures::featuredImageRef()),
        ))->onLoadPost();

        $html = $this->deaths[0]['message'];
        $this->assertSame('ContentGuard · Blocking', $this->deaths[0]['title']);
        $this->assertStringContainsString('ContentGuard · Blocking', $html);
        $this->assertStringContainsString('3 blocking issues', $html);
        $this->assertStringContainsString('<li>Title — This field is required.</li>', $html);
        $this->assertStringContainsString('<li>Content — This field is required.</li>', $html);
        $this->assertStringContainsString('<li>Featured Image — This field is required.</li>', $html);
        $this->assertStringNotContainsString('required..', $html);
        $this->assertStringNotContainsString(
            'Title — This field is required. Content — This field is required.',
            $html
        );
    }

    public function testWarningDoesNotBlockClassicEditpost(): void
    {
        $messages = $this->validator(array(
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
        ))->validate($this->publishRequest(array('post_title' => 'Hi')));

        $this->assertNull($messages);
    }

    public function testConditionOnlyBlockingRuleBlocksClassicPublishAndUpdate(): void
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

        $this->assertSame(
            array('Content — Please avoid the term "healthy" in recipe content.'),
            $this->validator(array($rule))->validate($this->publishRequest(array(
                'content' => 'A healthy dinner',
            )))
        );
        $this->assertSame(
            array('Content — Please avoid the term "healthy" in recipe content.'),
            $this->validator(array($rule))->validate(array(
                'action'               => 'editpost',
                'post_ID'              => 42,
                'post_type'            => 'post',
                'post_status'          => 'publish',
                'original_post_status' => 'publish',
                'save'                 => 'Update',
                'post_title'           => 'Existing',
                'content'              => 'A healthy dinner',
                'post_author'          => 1,
            ))
        );
        $this->assertNull(
            $this->validator(array($rule))->validate($this->publishRequest(array(
                'content' => 'A tasty dinner',
            )))
        );
    }

    public function testConditionOnlyWarningRuleDoesNotBlockClassicEditpost(): void
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

        $this->assertNull(
            $this->validator(array($rule))->validate($this->publishRequest(array(
                'content' => 'A healthy dinner',
            )))
        );
    }

    public function testSlashedClassicTitleDoesNotInflateMaxLength(): void
    {
        $rule = RuleFactory::rule(array(
            'postType'    => 'post',
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'  => CoreCatalogFixtures::titleRef(),
                    'type'   => 'max_length',
                    'params' => array('max' => 10),
                )),
            ),
        ));

        $request = $this->publishRequest(array('post_title' => "O'Brien's"));
        $this->assertNull($this->validator(array($rule))->validate($request));
        $this->assertNull(
            $this->validator(array($rule))->validate(HttpRequest::unslash(wp_slash($request)))
        );
    }

    public function testRegisterHooksClassicLoadActionsAndDoesNotPersist(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Infrastructure/WordPress/CoreSaveValidator.php');

        $this->assertStringContainsString("add_action('load-post.php'", $src);
        $this->assertStringContainsString("add_action('load-post-new.php'", $src);
        $this->assertStringContainsString("!== 'editpost'", $src);
        $this->assertStringContainsString('wp_die', $src);
        $this->assertStringContainsString("'back_link' => true", $src);
        $this->assertStringContainsString('CoreIncomingPayload::fromRequest', $src);
        $this->assertStringContainsString('IntendedPostStatusResolver', $src);
        $this->assertStringContainsString('REST_REQUEST', $src);
        $this->assertStringContainsString('wp_is_serving_rest_request', $src);
        $this->assertStringContainsString("request['acf']", $src);
        $this->assertStringContainsString('EditorNoticePresentation', $src);
        $this->assertStringNotContainsString('wp_insert_post_data', $src);
        $this->assertStringNotContainsString('wp_update_post', $src);
        $this->assertStringNotContainsString('wp_insert_post(', $src);
        $this->assertStringNotContainsString('edit_post(', $src);
        $this->assertStringNotContainsString('save_post', $src);
        $this->assertStringNotContainsString('has_post_thumbnail', $src);
        $this->assertStringNotContainsString('set_post_thumbnail', $src);
    }

    /**
     * @param array<int, mixed> $rules
     * @param callable(): bool|null $isRestRequest
     */
    private function validator(
        array $rules,
        mixed $coreCatalog = null,
        ?AcfFieldCatalog $acfCatalog = null,
        string $postType = 'post',
        mixed $isRestRequest = null,
    ): CoreSaveValidator {
        $repository = new InMemoryRuleRepository($rules);
        $acfCatalog ??= new AcfFieldCatalog(static fn (): array => array());
        $incoming = IncomingSaveFixtures::evaluator(
            $repository,
            $acfCatalog,
            CoreCatalogFixtures::integration($coreCatalog ?? CoreCatalogFixtures::fullPost($postType))
        );

        return new CoreSaveValidator(
            $incoming,
            new IntendedPostStatusResolver(),
            $isRestRequest,
            function (string $message, string $title, array $args): void {
                $this->deaths[] = array(
                    'message' => $message,
                    'title'   => $title,
                    'args'    => $args,
                );
            }
        );
    }

    /**
     * @param array<int, mixed> $rules
     */
    private function productValidator(array $rules): CoreSaveValidator
    {
        return $this->validator(
            $rules,
            CoreCatalogFixtures::fullPost('product'),
            $this->productAcfCatalog(),
            'product'
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function publishRequest(array $overrides = array()): array
    {
        $request = array_merge(
            array(
                'action'      => 'editpost',
                'post_ID'     => 42,
                'post_type'   => 'post',
                'post_status' => 'draft',
                'publish'     => 'Publish',
                'post_title'  => 'Existing',
                'content'     => 'Body',
                'post_author' => 1,
            ),
            $overrides
        );

        return $request;
    }

    /**
     * @param array<string, mixed> $request
     * @param list<string>         $keys
     * @return array<string, mixed>
     */
    private function omitting(array $request, array $keys): array
    {
        foreach ($keys as $key) {
            unset($request[$key]);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function required(\ContentGuard\Domain\FieldRef $field, array $overrides = array()): \ContentGuard\Domain\Rule
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
                );
            }
        );
    }
}
