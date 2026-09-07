<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

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
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
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
        $this->assertSame('Warning: Recipe Description — Description is missing', $html);
        $this->assertSame('Warning: Recipe Description — Description is missing', SaveWarningNotifier::displayText($items[0]));
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

        $this->assertStringContainsString('Warning:', $html);
        $this->assertStringContainsString('<button type="button" class="contentguard-warning-field"', $html);
        $this->assertStringContainsString('aria-label="Go to field: Recipe Description"', $html);
        $this->assertStringContainsString('>Recipe Description</button>', $html);
        $this->assertStringContainsString('— Description is missing', $html);
        $this->assertStringNotContainsString('class="button"', $html);
        $this->assertStringNotContainsString('[object Object]', $html);
        $this->assertSame('Warning: Recipe Description — Description is missing', SaveWarningNotifier::displayText(array(
            'text'     => 'Recipe Description: Description is missing',
            'message'  => 'Description is missing',
            'label'    => 'Recipe Description',
            'fieldKey' => 'field_description',
        )));
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
            'Warning: Recipe Description — Description is missing',
            SaveWarningNotifier::displayText($warnings[0])
        );
        $this->assertSame(
            'Warning: Featured On — Featured On is missing',
            SaveWarningNotifier::displayText($warnings[1])
        );
        $this->assertSame(
            'Warning: Yield — Yield is missing',
            SaveWarningNotifier::displayText($warnings[2])
        );
        $this->assertStringContainsString('data-contentguard-field="field_description"', SaveWarningNotifier::classicNoticeHtml($warnings[0]));
        $this->assertStringContainsString('data-contentguard-field="field_featuredon"', SaveWarningNotifier::classicNoticeHtml($warnings[1]));
        $this->assertStringContainsString('data-contentguard-field="field_yield"', SaveWarningNotifier::classicNoticeHtml($warnings[2]));
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
        $this->assertSame('Warning: Recipe Description — Description is missing', SaveWarningNotifier::displayText($items[0]));
        $this->assertSame('Warning: Recipe Description — Description looks thin.', SaveWarningNotifier::displayText($items[1]));
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

        $this->assertSame(
            array(
                'messages' => array('Ingredients: This looks thin.'),
                'warnings' => array(
                    array(
                        'text'     => 'Ingredients: This looks thin.',
                        'message'  => 'This looks thin.',
                        'label'    => 'Ingredients',
                        'fieldKey' => 'field_ingredients',
                    ),
                ),
            ),
            $notifier->payloadForPost(42)
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

    /**
     * @param array<int, string> $stored
     */
    private function notifier(string $status, array &$stored, string $ingredients): SaveWarningNotifier
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
            static fn (string $fieldKey, int $postId): mixed => $ingredients
        );
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
