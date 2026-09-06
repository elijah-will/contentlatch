<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\ACF\SaveWarningNotifier;
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class SaveWarningNotifierTest extends TestCase
{
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
            array('messages' => array('Ingredients: This looks thin.')),
            $notifier->payloadForPost(42)
        );
        $this->assertSame(array(), $stored);
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
}
