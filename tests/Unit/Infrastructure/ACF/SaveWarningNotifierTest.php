<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Application\EditorCoreNavigation;
use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\ContentEvaluation;
use ContentGuard\Domain\EvaluationResult;
use ContentGuard\Domain\EvaluationStatus;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\SaveWarningNotifier;
use ContentGuard\Tests\Support\InMemoryRuleRepository;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class SaveWarningNotifierTest extends TestCase
{
    public function testClassicWarningsStayIndependentOfTheAuditNotice(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Infrastructure/ACF/SaveWarningNotifier.php');

        $this->assertStringNotContainsString('EditorAuditNotice', $php);
        $this->assertStringNotContainsString('editor-blockers', $php);
        $this->assertStringNotContainsString('contentguard-audit-blockers', $php);
        $this->assertStringContainsString('classicNoticeHtml', $php);
        $this->assertStringContainsString('admin_notices', $php);
        $this->assertStringContainsString('shouldRenderClassicNotices', $php);
        $this->assertStringContainsString("base ?? '') === 'post'", $php);
        $this->assertStringContainsString('shouldEnqueue', $php);
        $this->assertStringContainsString('navigationExtras', $php);
        $this->assertStringNotContainsString('contentguard_row', $php);
    }

    public function testGutenbergAssetsStayOnTheIndividualPostEditor(): void
    {
        $this->assertTrue(SaveWarningNotifier::shouldEnqueue('post.php'));
        $this->assertTrue(SaveWarningNotifier::shouldEnqueue('post-new.php'));
        $this->assertFalse(SaveWarningNotifier::shouldEnqueue('edit.php'));
        $this->assertFalse(SaveWarningNotifier::shouldEnqueue('index.php'));
        $this->assertFalse(SaveWarningNotifier::shouldEnqueue('toplevel_page_contentguard'));
        $this->assertFalse(SaveWarningNotifier::shouldEnqueue('contentguard_page_contentguard-audit'));
    }

    public function testEmptyWarningsDoNotRepublishEditorFieldNavigationState(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Infrastructure/ACF/SaveWarningNotifier.php');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$warnings\s*!==\s*array\(\)\s*\)\s*\{\s*EditorFieldFocus::enqueueAssets\(/s',
            $php
        );
        $this->assertStringContainsString(
            'EditorFieldFocus::sanitizedEditorQuery()',
            $php
        );
        $this->assertSame(
            1,
            substr_count($php, 'EditorFieldFocus::enqueueAssets')
        );
        $this->assertStringContainsString('navigationExtras($warnings)', $php);
        $this->assertStringContainsString('contentguard-editor-warnings', $php);
        $this->assertStringContainsString('contentguardEditorWarnings', $php);
        $this->assertStringNotContainsString(
            "wp_localize_script(\n            'contentguard-editor-field'",
            $php
        );
        $this->assertStringNotContainsString(
            "wp_localize_script(\n            \"contentguard-editor-field\"",
            $php
        );
    }

    public function testWarningNavigationExtrasStillCarryRepeaterPathForASingleWarning(): void
    {
        $method = new \ReflectionMethod(SaveWarningNotifier::class, 'navigationExtras');
        $method->setAccessible(true);

        $empty = $method->invoke(null, array());
        $this->assertSame(array(), $empty);

        $path = array(
            array(
                'repeater'    => 'field_66a7ff4394039',
                'display_row' => 3,
            ),
        );
        $extras = $method->invoke(null, array(
            array(
                'text'         => 'Ingredient List → Ingredient — This field is required in row 3.',
                'message'      => 'This field is required in row 3.',
                'label'        => 'Ingredient List → Ingredient',
                'fieldKey'     => 'field_66a800089403a',
                'repeaterPath' => $path,
            ),
        ));
        $this->assertSame($path, $extras['repeaterPath']);
        $this->assertArrayNotHasKey('displayRow', $extras);

        $multiple = $method->invoke(null, array(
            array(
                'text'     => 'One',
                'message'  => 'One',
                'label'    => 'A',
                'fieldKey' => 'field_a',
            ),
            array(
                'text'     => 'Two',
                'message'  => 'Two',
                'label'    => 'B',
                'fieldKey' => 'field_b',
            ),
        ));
        $this->assertSame(array(), $multiple);
    }

    public function testWarningMessagesIgnoreFailuresAndPasses(): void
    {
        $engine = RuleEngine::v1();
        $warning = RuleFactory::rule(array(
            'id'          => 1,
            'severity'    => RuleSeverity::Warning,
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'message' => 'This looks thin.',
                )),
            ),
        ));
        $fail = RuleFactory::rule(array(
            'id'          => 2,
            'name'        => 'Blocking',
            'severity'    => RuleSeverity::Fail,
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'      => 'v2',
                    'message' => 'Ingredients are required.',
                )),
            ),
        ));

        $evaluation = $engine->evaluate(
            array($warning, $fail),
            new ArrayValueProvider(array('field_ingredients' => '')),
            9
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertSame(
            array('Ingredients: This looks thin.'),
            SaveWarningNotifier::warningMessages($evaluation)
        );
        $this->assertSame(
            array(
                array(
                    'text'     => 'Ingredients: This looks thin.',
                    'message'  => 'This looks thin.',
                    'label'    => 'Ingredients',
                    'fieldKey' => 'field_ingredients',
                ),
            ),
            SaveWarningNotifier::warningItems($evaluation)
        );
    }

    public function testCoreTitleWarningClearsWhenStoredTitleMeetsTheRule(): void
    {
        $rule = RuleFactory::rule(array(
            'postType'    => 'post',
            'severity'    => RuleSeverity::Warning,
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'   => \ContentGuard\Tests\Support\CoreCatalogFixtures::titleRef(),
                    'type'    => 'min_length',
                    'params'  => array('min' => 8),
                    'message' => 'Title is too short.',
                )),
            ),
        ));
        $repository = new InMemoryRuleRepository(array($rule));
        $core       = \ContentGuard\Tests\Support\CoreCatalogFixtures::integration();
        $acfCatalog = new AcfFieldCatalog(static fn (): array => array());
        $acf        = new \ContentGuard\Infrastructure\ACF\AcfIntegration($acfCatalog);
        $composite  = new \ContentGuard\Application\Integration\CompositeFieldCatalog(array($core, $acf));

        $short = $this->coreWarningNotifier($repository, $acfCatalog, $composite, $core, $acf, 'Hi');
        $this->assertNotSame(array(), $short->warningsForPost(42));
        $this->assertSame('Title is too short.', $short->warningsForPost(42)[0]['message']);

        $long = $this->coreWarningNotifier($repository, $acfCatalog, $composite, $core, $acf, 'Long enough');
        $this->assertSame(array(), $long->warningsForPost(42));
    }

    public function testOneWarningTargetsItsOwnField(): void
    {
        $items = SaveWarningNotifier::warningItems($this->evaluationFromWarnings(array(
            $this->warningResult('field_description', 'Recipe Description', 'Description is missing'),
        )));

        $this->assertCount(1, $items);
        $this->assertSame('field_description', $items[0]['fieldKey']);
        $this->assertTrue(SaveWarningNotifier::isClickableWarning($items[0]));
        $this->assertStringContainsString('data-contentguard-field="field_description"', SaveWarningNotifier::classicNoticeHtml($items[0]));
        $this->assertStringContainsString('Recipe Description', SaveWarningNotifier::classicNoticeHtml($items[0]));
    }

    public function testMultipleWarningsEachTargetTheirOwnField(): void
    {
        $items = SaveWarningNotifier::warningItems($this->evaluationFromWarnings(array(
            $this->warningResult('field_description', 'Recipe Description', 'Description is missing'),
            $this->warningResult('field_yield', 'Yield', 'Yield is missing'),
            $this->warningResult('field_featuredon', 'Featured On', 'Featured On is missing'),
        )));

        $this->assertCount(3, $items);
        $this->assertSame(
            array('field_description', 'field_yield', 'field_featuredon'),
            array_column($items, 'fieldKey')
        );
        $this->assertSame(
            array('Recipe Description', 'Yield', 'Featured On'),
            array_column($items, 'label')
        );
        $this->assertNotSame($items[0]['fieldKey'], $items[1]['fieldKey']);
        $this->assertNotSame($items[1]['fieldKey'], $items[2]['fieldKey']);

        $html = implode("\n", array_map(
            static fn (array $item): string => SaveWarningNotifier::classicNoticeHtml($item),
            $items
        ));
        $this->assertStringContainsString('data-contentguard-field="field_description"', $html);
        $this->assertStringContainsString('data-contentguard-field="field_yield"', $html);
        $this->assertStringContainsString('data-contentguard-field="field_featuredon"', $html);
        $this->assertStringNotContainsString('data-contentguard-field="field_description" data-contentguard-field="field_yield"', $html);
    }

    public function testWarningWithoutFieldKeyRemainsPlainText(): void
    {
        $items = SaveWarningNotifier::warningItems($this->evaluationFromWarnings(array(
            $this->warningResult(null, 'Recipe Description', 'Description is missing'),
        )));

        $this->assertSame('', $items[0]['fieldKey']);
        $this->assertFalse(SaveWarningNotifier::isClickableWarning($items[0]));
        $html = SaveWarningNotifier::classicNoticeHtml($items[0]);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('data-contentguard-field', $html);
        $this->assertStringContainsString('notice notice-warning', $html);
        $this->assertStringContainsString('ContentGuard · Warning', $html);
        $this->assertStringContainsString('Recipe Description — Description is missing', $html);
        $this->assertStringNotContainsString('contentguard-editor-warnings__count', $html);
        $this->assertSame('Recipe Description — Description is missing', SaveWarningNotifier::displayText($items[0]));
        $this->assertStringNotContainsString('[object Object]', $html);
    }

    public function testUnsafeFieldKeyRemainsNonClickable(): void
    {
        $items = SaveWarningNotifier::warningItems($this->evaluationFromWarnings(array(
            $this->warningResult('field_nested/path', 'Recipe Description', 'Description is missing'),
            $this->warningResult('acf[field_yield]', 'Yield', 'Yield is missing'),
            $this->warningResult('field_abc"><script>', 'Featured On', 'Featured On is missing'),
        )));

        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertSame('', $item['fieldKey']);
            $this->assertFalse(SaveWarningNotifier::isClickableWarning($item));
            $html = SaveWarningNotifier::classicNoticeHtml($item);
            $this->assertStringNotContainsString('<button', $html);
            $this->assertStringNotContainsString('data-contentguard-field', $html);
            $this->assertStringNotContainsString('<script>', $html);
        }
    }

    public function testMissingFieldStillRendersAWarning(): void
    {
        $items = SaveWarningNotifier::warningItems($this->evaluationFromWarnings(array(
            $this->warningResult('field_deleted999', 'Yield', 'Yield is missing'),
        )));

        $this->assertSame('field_deleted999', $items[0]['fieldKey']);
        $this->assertSame('Yield is missing', $items[0]['text']);
        $html = SaveWarningNotifier::classicNoticeHtml($items[0]);
        $this->assertStringContainsString('Yield', $html);
        $this->assertStringContainsString('Yield is missing', $html);
        $this->assertStringContainsString('data-contentguard-field="field_deleted999"', $html);
    }

    public function testClassicNoticeUsesFieldLabelAsTheNavigationTarget(): void
    {
        $html = SaveWarningNotifier::classicNoticeHtml(array(
            'text'     => 'Recipe Description: Description is missing',
            'message'  => 'Description is missing',
            'label'    => 'Recipe Description',
            'fieldKey' => 'field_description',
        ));

        $this->assertStringContainsString('ContentGuard · Warning', $html);
        $this->assertStringContainsString('notice notice-warning', $html);
        $this->assertStringContainsString('<button type="button" class="contentguard-warning-field"', $html);
        $this->assertStringContainsString('aria-label="Go to field: Recipe Description"', $html);
        $this->assertStringContainsString('>Recipe Description</button>', $html);
        $this->assertStringContainsString('— Description is missing', $html);
        $this->assertStringNotContainsString('contentguard-editor-warnings__count', $html);
        $this->assertStringNotContainsString('class="button"', $html);
        $this->assertStringNotContainsString('[object Object]', $html);
        $this->assertSame('Recipe Description — Description is missing', SaveWarningNotifier::displayText(array(
            'text'     => 'Recipe Description: Description is missing',
            'message'  => 'Description is missing',
            'label'    => 'Recipe Description',
            'fieldKey' => 'field_description',
        )));
    }

    public function testClassicCoreWarningsAreClickableAndGutenbergSlugIsNot(): void
    {
        $items = SaveWarningNotifier::warningItems($this->evaluationFromWarnings(array(
            $this->warningResult('title', 'Title', 'This field is required.'),
            $this->warningResult('slug', 'Slug', 'This field is required.'),
        )));

        $this->assertSame('title', $items[0]['fieldKey']);
        $this->assertSame('slug', $items[1]['fieldKey']);
        $this->assertTrue(SaveWarningNotifier::isClickableWarning($items[0], EditorCoreNavigation::SURFACE_CLASSIC));
        $this->assertTrue(SaveWarningNotifier::isClickableWarning($items[1], EditorCoreNavigation::SURFACE_CLASSIC));
        $this->assertFalse(SaveWarningNotifier::isClickableWarning($items[1], EditorCoreNavigation::SURFACE_GUTENBERG));

        $classic = SaveWarningNotifier::noticeHtml($items, EditorCoreNavigation::SURFACE_CLASSIC);
        $this->assertStringContainsString('data-contentguard-core="title"', $classic);
        $this->assertStringContainsString('data-contentguard-core="slug"', $classic);

        $gutenberg = SaveWarningNotifier::noticeHtml($items, EditorCoreNavigation::SURFACE_GUTENBERG);
        $this->assertStringContainsString('data-contentguard-core="title"', $gutenberg);
        $this->assertStringNotContainsString('data-contentguard-core="slug"', $gutenberg);
        $this->assertStringContainsString('Slug — This field is required.', $gutenberg);
    }

    public function testMultipleThenValidationsOnOneRuleRenderIndependentMessages(): void
    {
        $rule = RuleFactory::rule(array(
            'id'          => 10,
            'name'        => 'Recipe completeness',
            'postType'    => 'recipe',
            'severity'    => RuleSeverity::Warning,
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'id'      => 'v1',
                    'field'   => RuleFactory::field('field_description', 'recipe_description', 'Recipe Description'),
                    'message' => 'Description is missing',
                )),
                RuleFactory::validation(array(
                    'id'      => 'v2',
                    'field'   => RuleFactory::field('field_featuredon', 'featured_on', 'Featured On'),
                    'message' => 'Featured On is missing',
                )),
                RuleFactory::validation(array(
                    'id'      => 'v3',
                    'field'   => RuleFactory::field('field_yield', 'yield', 'Yield'),
                    'message' => 'Yield is missing',
                )),
            ),
        ));
        $fields = array(
            array('key' => 'field_description', 'name' => 'recipe_description', 'label' => 'Recipe Description', 'type' => 'textarea'),
            array('key' => 'field_featuredon', 'name' => 'featured_on', 'label' => 'Featured On', 'type' => 'text'),
            array('key' => 'field_yield', 'name' => 'yield', 'label' => 'Yield', 'type' => 'text'),
        );
        $repository = new InMemoryRuleRepository(array($rule), RuleDocumentValidator::v1());
        $notifier = new SaveWarningNotifier(
            new ContentEvaluator($repository, RuleEngine::v1()),
            $repository,
            new AcfFieldCatalog(
                static function (string $postType) use ($fields): array {
                    return $postType === 'recipe' ? $fields : array();
                }
            ),
            static fn (int $postId): string => 'recipe',
            static fn (int $postId): string => 'publish',
            static function (array $messages): void {
            },
            static fn (): array => array(),
            static fn (string $fieldKey, int $postId): mixed => ''
        );

        $payload = $notifier->payloadForPost(42);
        $warnings = $payload['warnings'];

        $this->assertCount(3, $warnings);
        $this->assertSame(
            array('field_description', 'field_featuredon', 'field_yield'),
            array_column($warnings, 'fieldKey')
        );
        $this->assertSame(
            array('Description is missing', 'Featured On is missing', 'Yield is missing'),
            array_column($warnings, 'message')
        );
        $this->assertSame(
            array('Recipe Description', 'Featured On', 'Yield'),
            array_column($warnings, 'label')
        );

        foreach ($warnings as $warning) {
            $this->assertIsString($warning['text']);
            $this->assertIsString($warning['message']);
            $this->assertIsString($warning['label']);
            $this->assertIsString($warning['fieldKey']);
            $display = SaveWarningNotifier::displayText($warning);
            $html = SaveWarningNotifier::classicNoticeHtml($warning);
            $this->assertStringNotContainsString('[object Object]', $display);
            $this->assertStringNotContainsString('[object Object]', $html);
            $this->assertStringNotContainsString('Array', $display);
            $this->assertTrue(SaveWarningNotifier::isClickableWarning($warning));
        }

        $this->assertSame(
            'Recipe Description — Description is missing',
            SaveWarningNotifier::displayText($warnings[0])
        );
        $this->assertSame(
            'Featured On — Featured On is missing',
            SaveWarningNotifier::displayText($warnings[1])
        );
        $this->assertSame(
            'Yield — Yield is missing',
            SaveWarningNotifier::displayText($warnings[2])
        );
        $grouped = SaveWarningNotifier::classicNoticeHtml($warnings);
        $this->assertStringContainsString('ContentGuard · Warning', $grouped);
        $this->assertStringContainsString('3 warnings', $grouped);
        $this->assertStringContainsString('notice notice-warning', $grouped);
        $this->assertStringContainsString('data-contentguard-field="field_description"', $grouped);
        $this->assertStringContainsString('data-contentguard-field="field_featuredon"', $grouped);
        $this->assertStringContainsString('data-contentguard-field="field_yield"', $grouped);
    }

    public function testMultipleWarningsForTheSameFieldKeepTheirOwnMessages(): void
    {
        $items = SaveWarningNotifier::warningItems($this->evaluationFromWarnings(array(
            $this->warningResult('field_description', 'Recipe Description', 'Description is missing'),
            $this->warningResult('field_description', 'Recipe Description', 'Description looks thin.'),
        )));

        $this->assertCount(2, $items);
        $this->assertSame('field_description', $items[0]['fieldKey']);
        $this->assertSame('field_description', $items[1]['fieldKey']);
        $this->assertSame('Description is missing', $items[0]['message']);
        $this->assertSame('Description looks thin.', $items[1]['message']);
        $this->assertSame('Recipe Description — Description is missing', SaveWarningNotifier::displayText($items[0]));
        $this->assertSame('Recipe Description — Description looks thin.', SaveWarningNotifier::displayText($items[1]));
        $grouped = SaveWarningNotifier::classicNoticeHtml($items);
        $this->assertStringContainsString('ContentGuard · Warning', $grouped);
        $this->assertStringContainsString('2 warnings', $grouped);
        $this->assertStringNotContainsString('[object Object]', SaveWarningNotifier::classicNoticeHtml($items[0]));
        $this->assertStringNotContainsString('[object Object]', SaveWarningNotifier::classicNoticeHtml($items[1]));
    }

    public function testDraftStatusDoesNotStoreWarnings(): void
    {
        $stored = array();
        $notifier = $this->notifier('draft', $stored, '');

        $notifier->notify(42);

        $this->assertSame(array(), $stored);
    }

    public function testPublishedWarningIsStoredWithoutBlocking(): void
    {
        $stored = array();
        $notifier = $this->notifier('publish', $stored, '');

        $notifier->notify(42);

        $this->assertSame(array('Ingredients: This looks thin.'), $stored);
        $this->assertTrue(SaveWarningNotifier::isPublishedStatus('publish'));
        $this->assertTrue(SaveWarningNotifier::isPublishedStatus('private'));
        $this->assertFalse(SaveWarningNotifier::isPublishedStatus('draft'));
    }

    public function testPrivateWarningIsStored(): void
    {
        $stored = array();
        $notifier = $this->notifier('private', $stored, '');

        $notifier->notify(42);

        $this->assertNotSame(array(), $stored);
    }

    public function testPassingWarningRuleStoresNothing(): void
    {
        $stored = array();
        $notifier = $this->notifier('publish', $stored, 'salt, pepper');

        $notifier->notify(42);

        $this->assertSame(array(), $stored);
    }

    public function testPayloadForPostUsesMessagesKeyAndDoesNotBlock(): void
    {
        $stored = array();
        $notifier = $this->notifier('publish', $stored, '');

        $payload = $notifier->payloadForPost(42);
        $warnings = array(
            array(
                'text'     => 'Ingredients: This looks thin.',
                'message'  => 'This looks thin.',
                'label'    => 'Ingredients',
                'fieldKey' => 'field_ingredients',
            ),
        );
        $this->assertSame(array('Ingredients: This looks thin.'), $payload['messages']);
        $this->assertSame($warnings, $payload['warnings']);
        $this->assertStringContainsString('ContentGuard · Warning', $payload['html']);
        $this->assertStringContainsString('>Ingredients</button>', $payload['html']);
        $this->assertStringContainsString('— This looks thin.', $payload['html']);
        $this->assertStringNotContainsString('contentguard-editor-warnings__count', $payload['html']);
        $this->assertSame(
            "ContentGuard · Warning\nIngredients — This looks thin.",
            $payload['text']
        );
        $this->assertSame(array(), $stored);
    }

    public function testPublishedPostWithMultipleFieldsKeepsIndependentTargets(): void
    {
        $notifier = $this->multiFieldNotifier(array(
            'field_description' => '',
            'field_yield'       => '',
            'field_featuredon'  => '',
        ));

        $warnings = $notifier->warningsForPost(42);

        $this->assertCount(3, $warnings);
        $this->assertSame('field_description', $warnings[0]['fieldKey']);
        $this->assertSame('field_yield', $warnings[1]['fieldKey']);
        $this->assertSame('field_featuredon', $warnings[2]['fieldKey']);
        $this->assertSame(
            array('field_description', 'field_yield', 'field_featuredon'),
            array_column($notifier->payloadForPost(42)['warnings'], 'fieldKey')
        );
    }

    public function testSauceMinLengthWarningMatchesKnownManualCase(): void
    {
        $rule = RuleFactory::rule(array(
            'id'          => 3,
            'name'        => 'Sauce description length',
            'postType'    => 'product',
            'severity'    => RuleSeverity::Warning,
            'conditions'  => array(
                RuleFactory::condition(array(
                    'field'    => RuleFactory::field('field_type', 'product_type', 'Product Type'),
                    'operator' => 'equals',
                    'operand'  => 'sauce',
                )),
            ),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'  => RuleFactory::field('field_description', 'recipe_description', 'Recipe Description'),
                    'type'   => 'min_length',
                    'params' => array('min' => 50),
                )),
            ),
        ));

        $values = array(
            'field_type'        => 'sauce',
            'field_description' => str_repeat('a', 10),
        );
        $stored = array();
        $notifier = new SaveWarningNotifier(
            new ContentEvaluator(
                new InMemoryRuleRepository(array($rule), RuleDocumentValidator::v1()),
                RuleEngine::v1()
            ),
            new InMemoryRuleRepository(array($rule), RuleDocumentValidator::v1()),
            new AcfFieldCatalog(
                static fn (): array => array(
                    array(
                        'key'   => 'field_type',
                        'name'  => 'product_type',
                        'label' => 'Product Type',
                        'type'  => 'select',
                    ),
                    array(
                        'key'   => 'field_description',
                        'name'  => 'recipe_description',
                        'label' => 'Recipe Description',
                        'type'  => 'textarea',
                    ),
                )
            ),
            static fn (int $postId): string => 'product',
            static fn (int $postId): string => 'publish',
            static function (array $messages) use (&$stored): void {
                $stored = $messages;
            },
            static fn (): array => array(),
            static fn (string $fieldKey, int $postId): mixed => $values[$fieldKey] ?? null
        );

        $messages = $notifier->messagesForPost(88);
        $this->assertNotSame(array(), $messages);
        $this->assertStringContainsString('50', $messages[0]);
        $this->assertSame(array(), $stored);
    }

    public function testClassicNoticeRendersOnTheIndividualPostEditor(): void
    {
        $stored = array();
        $html = $this->renderClassicNotices(
            $this->notifier('publish', $stored, '', $this->screen('post', false)),
            array('post' => '42')
        );

        $this->assertStringContainsString('notice notice-warning', $html);
        $this->assertStringContainsString('ContentGuard · Warning', $html);
        $this->assertStringContainsString('Ingredients', $html);
        $this->assertStringContainsString('This looks thin.', $html);
        $this->assertStringContainsString('data-contentguard-field="field_ingredients"', $html);
        $this->assertSame(array(), $stored);
    }

    public function testClassicNoticeDoesNotRenderOnEditPhpEvenWhenAPostGlobalExists(): void
    {
        $stored = array();
        $previousPost = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = (object) array('ID' => 42);

        try {
            $html = $this->renderClassicNotices(
                $this->notifier('publish', $stored, '', $this->screen('edit', false)),
                array()
            );
        } finally {
            if ($previousPost === null) {
                unset($GLOBALS['post']);
            } else {
                $GLOBALS['post'] = $previousPost;
            }
        }

        $this->assertSame('', $html);
    }

    public function testClassicNoticeDoesNotRenderOnContentGuardRules(): void
    {
        $stored = array();
        $html = $this->renderClassicNotices(
            $this->notifier('publish', $stored, '', $this->screen('toplevel_page_contentguard', false)),
            array('post' => '42')
        );

        $this->assertSame('', $html);
    }

    public function testClassicNoticeDoesNotRenderOnContentGuardAudit(): void
    {
        $stored = array();
        $html = $this->renderClassicNotices(
            $this->notifier('publish', $stored, '', $this->screen('contentguard_page_contentguard-audit', false)),
            array('post' => '42')
        );

        $this->assertSame('', $html);
    }

    public function testClassicNoticeDoesNotRenderOnTheDashboard(): void
    {
        $stored = array();
        $html = $this->renderClassicNotices(
            $this->notifier('publish', $stored, '', $this->screen('dashboard', false)),
            array('post' => '42')
        );

        $this->assertSame('', $html);
    }

    public function testBlockEditorDoesNotPrintPhpAdminNotices(): void
    {
        $stored = array();
        $html = $this->renderClassicNotices(
            $this->notifier('publish', $stored, '', $this->screen('post', true)),
            array('post' => '42')
        );

        $this->assertSame('', $html);
        $this->assertTrue(SaveWarningNotifier::shouldEnqueue('post.php'));
    }

    public function testListScreenDoesNotUseAnotherPostsGlobalAsTheEditorTarget(): void
    {
        $stored = array();
        $previousPost = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = (object) array('ID' => 99);

        try {
            $notifier = $this->notifier('publish', $stored, '', $this->screen('edit', false));
            $this->assertFalse($notifier->shouldRenderClassicNotices());
            $this->assertSame('', $this->renderClassicNotices($notifier, array()));
            $this->assertNotSame(array(), $notifier->warningsForPost(42));
        } finally {
            if ($previousPost === null) {
                unset($GLOBALS['post']);
            } else {
                $GLOBALS['post'] = $previousPost;
            }
        }
    }

    public function testGroupChildWarningsStillEvaluateForTheEditorPayload(): void
    {
        $rule = RuleFactory::rule(array(
            'id'          => 21,
            'postType'    => 'product',
            'severity'    => RuleSeverity::Warning,
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => new \ContentGuard\Domain\FieldRef(
                        'field_ingredients',
                        'ingredients',
                        'Product Details → Ingredients',
                        array('field_product_details', 'field_ingredients'),
                        'group'
                    ),
                    'message' => 'Ingredients look thin.',
                )),
            ),
        ));
        $repository = new InMemoryRuleRepository(array($rule), RuleDocumentValidator::v1());
        $notifier = new SaveWarningNotifier(
            new ContentEvaluator($repository, RuleEngine::v1()),
            $repository,
            new AcfFieldCatalog(
                static fn (): array => array(
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
                        ),
                    ),
                )
            ),
            static fn (int $postId): string => 'product',
            static fn (int $postId): string => 'publish',
            static function (array $messages): void {
            },
            static fn (): array => array(),
            static fn (string $fieldKey, int $postId): mixed => $fieldKey === 'field_product_details'
                ? array('field_ingredients' => '')
                : null
        );

        $warnings = $notifier->warningsForPost(636);
        $this->assertCount(1, $warnings);
        $this->assertSame('field_ingredients', $warnings[0]['fieldKey']);
        $this->assertSame('Product Details → Ingredients', $warnings[0]['label']);
        $this->assertSame('Ingredients look thin.', $warnings[0]['message']);
    }

    public function testRepeaterChildWarningsStillEvaluateForTheEditorPayload(): void
    {
        $rule = RuleFactory::rule(array(
            'id'          => 22,
            'postType'    => 'recipe',
            'severity'    => RuleSeverity::Warning,
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'      => \ContentGuard\Tests\Support\AcfRepeaterFixtures::ingredientRef(),
                    'type'       => 'required',
                    'quantifier' => 'every',
                    'message'    => 'Ingredient is missing.',
                )),
            ),
        ));
        $repository = new InMemoryRuleRepository(array($rule), RuleDocumentValidator::v1());
        $catalog = \ContentGuard\Tests\Support\AcfRepeaterFixtures::recipeCatalog();
        $notifier = new SaveWarningNotifier(
            new ContentEvaluator($repository, RuleEngine::v1()),
            $repository,
            $catalog,
            static fn (int $postId): string => 'recipe',
            static fn (int $postId): string => 'publish',
            static function (array $messages): void {
            },
            static fn (): array => array(),
            static function (string $fieldKey, int $postId): mixed {
                unset($postId);
                return $fieldKey === \ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT_LIST
                    ? array(
                        array(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT => ''),
                    )
                    : null;
            }
        );

        $warnings = $notifier->warningsForPost(12325);
        $this->assertCount(1, $warnings);
        $this->assertSame(\ContentGuard\Tests\Support\AcfRepeaterFixtures::INGREDIENT, $warnings[0]['fieldKey']);
        $this->assertSame('Ingredient is missing.', $warnings[0]['message']);
        $this->assertFalse($notifier->shouldRenderClassicNotices());
        $this->assertArrayNotHasKey('affectedRows', $warnings[0]);
        $this->assertArrayNotHasKey('layout', $warnings[0]);
    }

    public function testFlexibleWarningsMergeAffectedRowsAndIgnoreOtherLayouts(): void
    {
        $items = SaveWarningNotifier::warningItems($this->evaluationFromWarnings(array(
            new EvaluationResult(
                EvaluationStatus::Warning,
                80,
                9,
                \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
                'Title looks thin.',
                RuleSeverity::Warning,
                'required',
                array(
                    'field_label' => 'Modules → Hero → Title',
                    'layout'      => 'hero',
                    'display_row' => 1,
                )
            ),
            new EvaluationResult(
                EvaluationStatus::Warning,
                80,
                9,
                \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
                'Title looks thin.',
                RuleSeverity::Warning,
                'required',
                array(
                    'field_label' => 'Modules → Hero → Title',
                    'layout'      => 'hero',
                    'display_row' => 3,
                )
            ),
        )));

        $this->assertCount(1, $items);
        $this->assertSame(\ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE, $items[0]['fieldKey']);
        $this->assertSame('hero', $items[0]['layout']);
        $this->assertSame(array(1, 3), $items[0]['affectedRows']);

        $html = SaveWarningNotifier::classicNoticeHtml($items[0]);
        $this->assertStringContainsString('data-contentguard-layout="hero"', $html);
        $this->assertStringContainsString('data-contentguard-display-row="1"', $html);
        $this->assertStringContainsString('data-contentguard-display-row="3"', $html);
        $this->assertStringContainsString('>Row 1</button>', $html);
        $this->assertStringContainsString('>Row 3</button>', $html);
        $this->assertStringNotContainsString('contentguard_row', $html);
    }

    public function testSingleFlexibleWarningNavigatesDirectlyToThatRow(): void
    {
        $items = SaveWarningNotifier::warningItems($this->evaluationFromWarnings(array(
            new EvaluationResult(
                EvaluationStatus::Warning,
                80,
                9,
                \ContentGuard\Tests\Support\AcfFlexibleFixtures::HERO_TITLE,
                'Title looks thin.',
                RuleSeverity::Warning,
                'required',
                array(
                    'field_label' => 'Modules → Hero → Title',
                    'layout'      => 'hero',
                    'display_row' => 3,
                )
            ),
        )));

        $this->assertSame(array(3), $items[0]['affectedRows']);
        $html = SaveWarningNotifier::classicNoticeHtml($items[0]);
        $this->assertStringContainsString('data-contentguard-display-row="3"', $html);
        $this->assertStringContainsString('Go to Modules → Hero → Title, row 3', $html);
        $this->assertStringNotContainsString('>Row 3</button>', $html);
    }

    /**
     * @param array<int, string> $stored
     */
    private function notifier(string $status, array &$stored, string $ingredients, mixed $screenOf = null): SaveWarningNotifier
    {
        $rule = RuleFactory::rule(array(
            'id'          => 1,
            'name'        => 'Warn missing ingredients',
            'postType'    => 'recipe',
            'severity'    => RuleSeverity::Warning,
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'message' => 'This looks thin.',
                )),
            ),
        ));
        $repository = new InMemoryRuleRepository(array($rule), RuleDocumentValidator::v1());
        $catalog = new AcfFieldCatalog(
            static function (string $postType): array {
                if ($postType !== 'recipe') {
                    return array();
                }

                return array(
                    array(
                        'key'   => 'field_ingredients',
                        'name'  => 'ingredients',
                        'label' => 'Ingredients',
                        'type'  => 'textarea',
                    ),
                );
            }
        );

        return new SaveWarningNotifier(
            new ContentEvaluator($repository, RuleEngine::v1()),
            $repository,
            $catalog,
            static fn (int $postId): string => 'recipe',
            static fn (int $postId): string => $status,
            static function (array $messages) use (&$stored): void {
                $stored = $messages;
            },
            static fn (): array => array(),
            static fn (string $fieldKey, int $postId): mixed => $ingredients,
            $screenOf
        );
    }

    /**
     * @return callable(): object
     */
    private function screen(string $base, bool $isBlockEditor): mixed
    {
        return static fn (): object => (object) array(
            'base'            => $base,
            'is_block_editor' => $isBlockEditor,
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    private function renderClassicNotices(SaveWarningNotifier $notifier, array $query): string
    {
        $previous = $_GET;
        $_GET     = $query;

        try {
            ob_start();
            $notifier->onAdminNotices();

            return (string) ob_get_clean();
        } finally {
            $_GET = $previous;
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function multiFieldNotifier(array $values): SaveWarningNotifier
    {
        $fields = array(
            array('key' => 'field_description', 'name' => 'recipe_description', 'label' => 'Recipe Description', 'type' => 'textarea'),
            array('key' => 'field_yield', 'name' => 'yield', 'label' => 'Yield', 'type' => 'text'),
            array('key' => 'field_featuredon', 'name' => 'featured_on', 'label' => 'Featured On', 'type' => 'text'),
        );
        $rules = array(
            RuleFactory::rule(array(
                'id'          => 1,
                'name'        => 'Warn description',
                'postType'    => 'recipe',
                'severity'    => RuleSeverity::Warning,
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'id'      => 'v1',
                        'field'   => RuleFactory::field('field_description', 'recipe_description', 'Recipe Description'),
                        'message' => 'Description is missing',
                    )),
                ),
            )),
            RuleFactory::rule(array(
                'id'          => 2,
                'name'        => 'Warn yield',
                'postType'    => 'recipe',
                'severity'    => RuleSeverity::Warning,
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'id'      => 'v2',
                        'field'   => RuleFactory::field('field_yield', 'yield', 'Yield'),
                        'message' => 'Yield is missing',
                    )),
                ),
            )),
            RuleFactory::rule(array(
                'id'          => 3,
                'name'        => 'Warn featured',
                'postType'    => 'recipe',
                'severity'    => RuleSeverity::Warning,
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array(
                        'id'      => 'v3',
                        'field'   => RuleFactory::field('field_featuredon', 'featured_on', 'Featured On'),
                        'message' => 'Featured On is missing',
                    )),
                ),
            )),
        );
        $repository = new InMemoryRuleRepository($rules, RuleDocumentValidator::v1());
        $catalog = new AcfFieldCatalog(
            static function (string $postType) use ($fields): array {
                return $postType === 'recipe' ? $fields : array();
            }
        );

        return new SaveWarningNotifier(
            new ContentEvaluator($repository, RuleEngine::v1()),
            $repository,
            $catalog,
            static fn (int $postId): string => 'recipe',
            static fn (int $postId): string => 'publish',
            static function (array $messages): void {
            },
            static fn (): array => array(),
            static fn (string $fieldKey, int $postId): mixed => $values[$fieldKey] ?? null
        );
    }

    private function coreWarningNotifier(
        InMemoryRuleRepository $repository,
        AcfFieldCatalog $acfCatalog,
        \ContentGuard\Application\Integration\CompositeFieldCatalog $composite,
        \ContentGuard\Infrastructure\WordPress\CoreIntegration $core,
        \ContentGuard\Infrastructure\ACF\AcfIntegration $acf,
        string $title,
    ): SaveWarningNotifier {
        return new SaveWarningNotifier(
            new ContentEvaluator($repository, RuleEngine::v1()),
            $repository,
            $acfCatalog,
            static fn (int $postId): string => 'post',
            static fn (int $postId): string => 'publish',
            static function (array $messages): void {
            },
            static fn (): array => array(),
            null,
            null,
            $composite,
            static function (int $postId, string $postType, array $fieldTypes) use ($core, $acf, $title): \ContentGuard\Application\Integration\CompositeValueProvider {
                return new \ContentGuard\Application\Integration\CompositeValueProvider(array(
                    $core->storedProvider(
                        $postId,
                        $postType,
                        $fieldTypes,
                        static fn (string $fieldId, int $id): mixed => $fieldId === 'title' ? $title : null
                    ),
                    $acf->storedProvider($postId, $postType, $fieldTypes),
                ));
            }
        );
    }

    /**
     * @param EvaluationResult[] $results
     */
    private function evaluationFromWarnings(array $results): ContentEvaluation
    {
        return ContentEvaluation::fromResults(9, $results);
    }

    private function warningResult(?string $fieldId, string $label, string $message): EvaluationResult
    {
        return new EvaluationResult(
            EvaluationStatus::Warning,
            1,
            9,
            $fieldId,
            $message,
            RuleSeverity::Warning,
            'required',
            array('field_label' => $label)
        );
    }
}
