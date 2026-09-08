<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Admin\EditorAuditNotice;
use ContentGuard\Application\Audit\AuditFinding;
use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\EditorAuditIssues;
use ContentGuard\Application\EditorFieldNavigation;
use ContentGuard\Domain\FieldRef;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\AcfIntegration;
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
use ContentGuard\Tests\Support\InMemoryAuditLock;
use ContentGuard\Tests\Support\InMemoryAuditPostScanner;
use ContentGuard\Tests\Support\InMemoryAuditStore;
use ContentGuard\Tests\Support\RuleFactory;
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
        $this->assertSame('', \ContentGuard\Application\EditorAuditIssues::classicNoticeHtml($resolved['issues']));
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
                \ContentGuard\Tests\Support\AcfFlexibleFixtures::MODULES => array(
                    array(
                        'acf_fc_layout' => 'hero',
                        \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => '',
                    ),
                    array(
                        'acf_fc_layout' => 'cta',
                        \ContentGuard\Tests\Support\AcfFlexibleFixtures::CTA_TITLE => 'Shop',
                    ),
                    array(
                        'acf_fc_layout' => 'hero',
                        \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE => '',
                    ),
                ),
            ),
        );

        $notice  = $this->flexibleNotice($values);
        $request = array(EditorFieldNavigation::AUDIT_RUN_ARG => 1);

        $before = $notice->issuesForRequest(42, $request);
        $this->assertCount(1, $before);
        $this->assertSame(\ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE, $before[0]['fieldKey']);
        $this->assertSame('hero', $before[0]['layout']);
        $this->assertSame(array(1, 3), $before[0]['affectedRows']);
        $this->assertStringContainsString('data-contentguard-display-row="1"', EditorAuditIssues::issueHtml($before[0]));
        $this->assertStringContainsString('data-contentguard-display-row="3"', EditorAuditIssues::issueHtml($before[0]));

        $values[42][\ContentGuard\Tests\Support\AcfFlexibleFixtures::MODULES][0][\ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE] = 'Welcome';

        $after = $notice->issuesForRequest(42, $request);
        $this->assertCount(1, $after);
        $this->assertSame(array(3), $after[0]['affectedRows']);
        $this->assertSame('hero', $after[0]['layout']);
        $html = EditorAuditIssues::issueHtml($after[0]);
        $this->assertStringContainsString('data-contentguard-display-row="3"', $html);
        $this->assertStringNotContainsString('data-contentguard-display-row="1"', $html);
        $this->assertStringNotContainsString('>Row 1</button>', $html);
        $this->assertStringNotContainsString('contentguard_row', $html);
    }

    public function testSuccessfulSaveRedirectKeepsTheAuditRun(): void
    {
        $values = array();
        $notice = $this->notice($values);
        $previous = $_REQUEST;
        $_REQUEST = array('contentguard_run' => '7');

        try {
            $location = $notice->preserveAuditRunOnRedirect(
                'http://example.test/wp-admin/post.php?post=42&action=edit&message=1'
            );
        } finally {
            $_REQUEST = $previous;
        }

        $this->assertStringContainsString('contentguard_run=7', $location);
        $this->assertStringContainsString('post.php?post=42', $location);
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
            static function (int $postId, string $postType, array $fieldTypes) use ($catalog, &$values): \ContentGuard\Infrastructure\ACF\AcfStoredValueProvider {
                unset($fieldTypes);
                $maps = $catalog->nestedResolutionMaps($postType, $catalog->fieldTypesForPostType($postType));

                return new \ContentGuard\Infrastructure\ACF\AcfStoredValueProvider(
                    $postId,
                    new \ContentGuard\Infrastructure\ACF\AcfValueNormalizer(),
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
                        'field'      => \ContentGuard\Tests\Support\AcfFlexibleFixtures::heroTitleRef(),
                        'type'       => 'required',
                        'quantifier' => 'every',
                    )),
                ),
            )),
        );

        $store      = new InMemoryAuditStore();
        $repository = new InMemoryRuleRepository($rules);
        $catalog    = \ContentGuard\Tests\Support\AcfFlexibleFixtures::pageCatalog();
        $service    = new ContentAuditService(
            $store,
            new InMemoryAuditPostScanner(array()),
            new InMemoryAuditLock(),
            $repository,
            new ContentEvaluator($repository, RuleEngine::v1()),
            new AcfIntegration($catalog),
            static function (int $postId, string $postType, array $fieldTypes) use ($catalog, &$values): \ContentGuard\Infrastructure\ACF\AcfStoredValueProvider {
                unset($fieldTypes);
                $maps = $catalog->nestedResolutionMaps($postType, $catalog->fieldTypesForPostType($postType));

                return new \ContentGuard\Infrastructure\ACF\AcfStoredValueProvider(
                    $postId,
                    new \ContentGuard\Infrastructure\ACF\AcfValueNormalizer(),
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
                \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
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
