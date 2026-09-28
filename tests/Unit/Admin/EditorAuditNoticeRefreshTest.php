<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Admin;

use ContentLatch\Admin\EditorAuditNotice;
use ContentLatch\Application\Audit\AuditFinding;
use ContentLatch\Application\Audit\AuditRunStatus;
use ContentLatch\Application\Audit\ContentAuditService;
use ContentLatch\Application\ContentEvaluator;
use ContentLatch\Application\EditorAuditIssues;
use ContentLatch\Application\EditorFieldNavigation;
use ContentLatch\Domain\FieldRef;
use ContentLatch\Domain\RuleEngine;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;
use ContentLatch\Infrastructure\ACF\AcfIntegration;
use ContentLatch\Tests\Support\InMemoryRuleRepository;
use ContentLatch\Tests\Support\InMemoryAuditLock;
use ContentLatch\Tests\Support\InMemoryAuditPostScanner;
use ContentLatch\Tests\Support\InMemoryAuditStore;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class EditorAuditNoticeRefreshTest extends TestCase
{
    public function testRefreshDropsResolvedIssuesAndKeepsUnresolvedOnes(): void
    {
        $values = array(
            42 => array(
                'field_description' => '',
                'field_title'       => '',
                'field_ingredients' => '',
                'field_product_details' => array(
                    'field_ingredients' => '',
                ),
            ),
        );

        $notice = $this->notice($values);
        $request = array(EditorFieldNavigation::AUDIT_RUN_ARG => 1);
        $this->assertSame(array(), $notice->issuesForRequest(42, array(
            EditorFieldNavigation::QUERY_ARG => 'field_description',
        )));
        $this->assertNotSame(array(), $notice->issuesForRequest(42, $request));

        $initial = $notice->issuesForRequest(42, $request);
        $this->assertSame(
            array('field_description', 'field_title', 'field_ingredients'),
            array_column($initial, 'fieldKey')
        );
        $this->assertSame('Recipe Description', $initial[0]['label']);
        $this->assertSame('Title', $initial[1]['label']);
        $this->assertSame('Product Information → Ingredients Accordion', $initial[2]['label']);

        $values[42]['field_description'] = 'A signature recipe.';
        $values[42]['field_product_details']['field_ingredients'] = 'Tomatoes, salt';
        $values[42]['field_ingredients'] = '';

        $afterOneSave = $notice->issuesForRequest(42, $request);
        $this->assertSame(array('field_title'), array_column($afterOneSave, 'fieldKey'));
        $this->assertNotSame('', $notice->payloadForRequest(42, $request)['html']);

        $values[42]['field_title'] = 'Hot Sauce';
        $resolved = $notice->payloadForRequest(42, $request);
        $this->assertSame(array(), $resolved['issues']);
        $this->assertSame('', $resolved['html']);
        $this->assertSame('', $resolved['text']);
        $this->assertSame('', \ContentLatch\Application\EditorAuditIssues::classicNoticeHtml($resolved['issues']));
    }

    public function testUnresolvedIssueRemainsWhenTheStoredValueStillFails(): void
    {
        $values = array(
            42 => array(
                'field_description'     => '',
                'field_title'           => '',
                'field_ingredients'     => '',
                'field_product_details' => array(
                    'field_ingredients' => '',
                ),
            ),
        );
        $notice  = $this->notice($values);
        $request = array(EditorFieldNavigation::AUDIT_RUN_ARG => 1);

        $before = array_column($notice->issuesForRequest(42, $request), 'fieldKey');
        $this->assertSame(array('field_description', 'field_title', 'field_ingredients'), $before);

        $values[42]['field_description'] = '';
        $values[42]['field_title']       = '';
        $values[42]['field_product_details']['field_ingredients'] = '';

        $this->assertSame($before, array_column($notice->issuesForRequest(42, $request), 'fieldKey'));
    }

    public function testNoticeDoesNotAppearWithoutAnAuditRun(): void
    {
        $values = array(
            42 => array(
                'field_description'     => '',
                'field_title'           => '',
                'field_ingredients'     => '',
                'field_product_details' => array(
                    'field_ingredients' => '',
                ),
            ),
        );

        $this->assertSame(array(), $this->notice($values)->issuesForRequest(42, array()));
    }

    public function testFlexibleRowTargetsRefreshAfterTheFirstRowIsFixed(): void
    {
        $values = array(
            42 => array(
                \ContentLatch\Tests\Support\AcfFlexibleFixtures::MODULES => array(
                    array(
                        'acf_fc_layout' => 'hero',
                        \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => '',
                    ),
                    array(
                        'acf_fc_layout' => 'cta',
                        \ContentLatch\Tests\Support\AcfFlexibleFixtures::CTA_TITLE => 'Shop',
                    ),
                    array(
                        'acf_fc_layout' => 'hero',
                        \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => '',
                    ),
                ),
            ),
        );

        $notice  = $this->flexibleNotice($values);
        $request = array(EditorFieldNavigation::AUDIT_RUN_ARG => 1);

        $before = $notice->issuesForRequest(42, $request);
        $this->assertCount(1, $before);
        $this->assertSame(\ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE, $before[0]['fieldKey']);
        $this->assertSame('hero', $before[0]['layout']);
        $this->assertSame(array(1, 3), $before[0]['affectedRows']);
        $this->assertStringContainsString('data-contentlatch-display-row="1"', EditorAuditIssues::issueHtml($before[0]));
        $this->assertStringContainsString('data-contentlatch-display-row="3"', EditorAuditIssues::issueHtml($before[0]));

        $values[42][\ContentLatch\Tests\Support\AcfFlexibleFixtures::MODULES][0][\ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE] = 'Welcome';

        $after = $notice->issuesForRequest(42, $request);
        $this->assertCount(1, $after);
        $this->assertSame(array(3), $after[0]['affectedRows']);
        $this->assertSame('hero', $after[0]['layout']);
        $html = EditorAuditIssues::issueHtml($after[0]);
        $this->assertStringContainsString('data-contentlatch-display-row="3"', $html);
        $this->assertStringNotContainsString('data-contentlatch-display-row="1"', $html);
        $this->assertStringNotContainsString('>Row 1</button>', $html);
        $this->assertStringNotContainsString('contentlatch_row', $html);
    }

    public function testSuccessfulSaveRedirectKeepsTheAuditRun(): void
    {
        $values = array();
        $notice = $this->notice($values);
        $previous = $_GET;
        $_GET = array('contentlatch_run' => '7');

        try {
            $location = $notice->preserveAuditRunOnRedirect(
                'http://example.test/wp-admin/post.php?post=42&action=edit&message=1'
            );
        } finally {
            $_GET = $previous;
        }

        $this->assertStringContainsString('contentlatch_run=7', $location);
        $this->assertStringContainsString('post.php?post=42', $location);
    }

    public function testRefererRunIdIsReadOnlyAfterUrlSanitization(): void
    {
        $values = array();
        $notice = $this->notice($values);
        $location = 'http://example.test/wp-admin/post.php?post=42&action=edit&message=1';
        $previousGet = $_GET;
        $previousPost = $_POST;
        $previousServer = $_SERVER;
        $_GET = array();
        $_POST = array();

        try {
            $_SERVER['HTTP_REFERER'] = 'http://evil.test/wp-admin/post.php?post=9&contentlatch_run=9';
            $kept = $notice->preserveAuditRunOnRedirect($location);
            $this->assertStringContainsString('contentlatch_run=9', $kept);
            $this->assertStringStartsWith('http://example.test/', $kept);
            $this->assertStringNotContainsString('evil.test', $kept);

            $_SERVER['HTTP_REFERER'] = 'javascript:alert(document.domain)//?contentlatch_run=4';
            $this->assertSame($location, $notice->preserveAuditRunOnRedirect($location));

            $_SERVER['HTTP_REFERER'] = 'http://example.test/wp-admin/post.php?post=42&contentlatch_run=9abc';
            $this->assertSame($location, $notice->preserveAuditRunOnRedirect($location));
        } finally {
            $_GET = $previousGet;
            $_POST = $previousPost;
            $_SERVER = $previousServer;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $values
     */
    private function notice(array &$values): EditorAuditNotice
    {
        $rules = array(
            RuleFactory::rule(array(
                'id'         => 15,
                'postType'   => 'recipe',
                'conditions' => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'id'    => 'v-desc',
                        'field' => RuleFactory::field('field_description', 'recipe_description', 'Recipe Description'),
                    )),
                    RuleFactory::validation(array(
                        'id'    => 'v-title',
                        'field' => RuleFactory::field('field_title', 'title', 'Title'),
                    )),
                    RuleFactory::validation(array(
                        'id'    => 'v-ingredients',
                        'field' => new FieldRef(
                            'field_ingredients',
                            'item_ingredients',
                            'Product Information → Ingredients Accordion',
                            array('field_product_details', 'field_ingredients'),
                            'group'
                        ),
                    )),
                ),
            )),
        );

        $store = new InMemoryAuditStore();
        $repository = new InMemoryRuleRepository($rules);
        $catalog = new AcfFieldCatalog(
            static function (string $postType): array {
                if ($postType !== 'recipe') {
                    return array();
                }

                return array(
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

        $service = new ContentAuditService(
            $store,
            new InMemoryAuditPostScanner(array()),
            new InMemoryAuditLock(),
            $repository,
            new ContentEvaluator($repository, RuleEngine::v1()),
            new AcfIntegration($catalog),
            static function (int $postId, string $postType, array $fieldTypes) use ($catalog, &$values): \ContentLatch\Infrastructure\ACF\AcfStoredValueProvider {
                unset($fieldTypes);
                $maps = $catalog->nestedResolutionMaps($postType, $catalog->fieldTypesForPostType($postType));

                return new \ContentLatch\Infrastructure\ACF\AcfStoredValueProvider(
                    $postId,
                    new \ContentLatch\Infrastructure\ACF\AcfValueNormalizer(),
                    $catalog->fieldTypesForPostType($postType),
                    static fn (string $key): mixed => $values[$postId][$key] ?? null,
                    $maps['paths'],
                    $maps['names']
                );
            },
            static function (array $ids): void {
                unset($ids);
            },
            static fn (): int => 1_000_000
        );

        $run = $store->insertRun(1, array('recipe'), 1, '2026-01-01 00:00:00');
        $store->saveRun($run->withStatus(AuditRunStatus::Complete, '2026-01-01 00:01:00'));
        $store->replaceFindingsForPosts(1, array(42), array(
            new AuditFinding(0, 1, 42, 'recipe', 15, 'field_description', 'v-desc', 'required', RuleSeverity::Fail, 'Description is required', '2026-01-01 00:00:00'),
            new AuditFinding(0, 1, 42, 'recipe', 15, 'field_title', 'v-title', 'required', RuleSeverity::Fail, 'Title is required', '2026-01-01 00:00:00'),
            new AuditFinding(0, 1, 42, 'recipe', 15, 'field_ingredients', 'v-ingredients', 'required', RuleSeverity::Fail, 'Ingredients Accordion is required', '2026-01-01 00:00:00'),
        ));

        return new EditorAuditNotice(
            $service,
            $repository,
            static fn (int $postId): string => $postId === 42 ? 'recipe' : '',
            static fn (): bool => true,
            static fn (int $postId): bool => $postId === 42
        );
    }

    /**
     * @param array<int, array<string, mixed>> $values
     */
    private function flexibleNotice(array &$values): EditorAuditNotice
    {
        $rules = array(
            RuleFactory::rule(array(
                'id'          => 80,
                'postType'    => 'page',
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'id'         => 'v-hero-title',
                        'field'      => \ContentLatch\Tests\Support\AcfFlexibleFixtures::heroTitleRef(),
                        'type'       => 'required',
                        'quantifier' => 'every',
                    )),
                ),
            )),
        );

        $store      = new InMemoryAuditStore();
        $repository = new InMemoryRuleRepository($rules);
        $catalog    = \ContentLatch\Tests\Support\AcfFlexibleFixtures::pageCatalog();
        $service    = new ContentAuditService(
            $store,
            new InMemoryAuditPostScanner(array()),
            new InMemoryAuditLock(),
            $repository,
            new ContentEvaluator($repository, RuleEngine::v1()),
            new AcfIntegration($catalog),
            static function (int $postId, string $postType, array $fieldTypes) use ($catalog, &$values): \ContentLatch\Infrastructure\ACF\AcfStoredValueProvider {
                unset($fieldTypes);
                $maps = $catalog->nestedResolutionMaps($postType, $catalog->fieldTypesForPostType($postType));

                return new \ContentLatch\Infrastructure\ACF\AcfStoredValueProvider(
                    $postId,
                    new \ContentLatch\Infrastructure\ACF\AcfValueNormalizer(),
                    $catalog->fieldTypesForPostType($postType),
                    static fn (string $key): mixed => $values[$postId][$key] ?? null,
                    $maps['paths'],
                    $maps['names'],
                    $maps['repeater_keys'] ?? array(),
                    $maps['flex_keys'] ?? array(),
                    $maps['layouts'] ?? array()
                );
            },
            static function (array $ids): void {
                unset($ids);
            },
            static fn (): int => 1_000_000
        );

        $run = $store->insertRun(1, array('page'), 1, '2026-01-01 00:00:00');
        $store->saveRun($run->withStatus(AuditRunStatus::Complete, '2026-01-01 00:01:00'));
        $store->replaceFindingsForPosts(1, array(42), array(
            new AuditFinding(
                0,
                1,
                42,
                'page',
                80,
                \ContentLatch\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
                'v-hero-title',
                'required',
                RuleSeverity::Fail,
                'Title is required in 2 Hero rows (rows 1, 3).',
                '2026-01-01 00:00:00'
            ),
        ));

        return new EditorAuditNotice(
            $service,
            $repository,
            static fn (int $postId): string => $postId === 42 ? 'page' : '',
            static fn (): bool => true,
            static fn (int $postId): bool => $postId === 42
        );
    }
}
