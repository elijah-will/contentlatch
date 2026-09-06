<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\Exception\ForbiddenRuleMutationException;
use ContentGuard\Application\Exception\RulePersistenceException;
use ContentGuard\Application\RuleMutationPresentation;
use ContentGuard\Domain\Exception\InvalidRuleException;
use PHPUnit\Framework\TestCase;

final class RuleMutationPresentationTest extends TestCase
{
    public function testValidationMessagesStaySpecific(): void
    {
        $this->assertSame(
            'We could not add this rule. A condition value is required.',
            RuleMutationPresentation::saveFailureMessage(
                new InvalidRuleException('A condition value is required.'),
                true
            )
        );
        $this->assertSame(
            'We could not save this rule. You are not allowed to manage ContentGuard rules.',
            RuleMutationPresentation::saveFailureMessage(
                new ForbiddenRuleMutationException('You are not allowed to manage ContentGuard rules.')
            )
        );
        $this->assertSame('Rule added.', RuleMutationPresentation::addedMessage());
        $this->assertSame('Rule saved.', RuleMutationPresentation::savedMessage());
    }

    public function testMissingRulePersistenceBecomesARecoverableSaveMessage(): void
    {
        $message = RuleMutationPresentation::saveFailureMessage(
            new RulePersistenceException('Rule not found.'),
            true
        );

        $this->assertStringContainsString('could not add this rule', $message);
        $this->assertStringContainsString('preserved', $message);
        $this->assertStringNotContainsString('Rule not found.', $message);
        $this->assertSame(
            'We could not open this rule. It may have been deleted or could not be read.',
            RuleMutationPresentation::missingRuleMessage()
        );
        $this->assertSame(
            'We could not add this rule. The rule could not be read after saving. Your entered values have been preserved so you can try again.',
            RuleMutationPresentation::unreadAfterSaveMessage(true)
        );
    }

    public function testDraftForNewRuleClearsSubmittedIds(): void
    {
        $draft = RuleMutationPresentation::draftForNewRule(
            array(
                'id'      => 5,
                'rule_id' => 5,
                'name'    => 'Repair UPC is required',
            )
        );

        $this->assertSame('', $draft['id']);
        $this->assertSame('', $draft['rule_id']);
        $this->assertSame('Repair UPC is required', $draft['name']);
        $this->assertNull(RuleMutationPresentation::draftForNewRule(null));
    }

    public function testDebugLogOmitsDocumentBodies(): void
    {
        $this->assertTrue(method_exists(RuleMutationPresentation::class, 'logDebug'));
        RuleMutationPresentation::logDebug('probe', array(
            'id'       => 9,
            'json'     => '{"secret":true}',
            'document' => '{"secret":true}',
        ));
        $this->assertSame(
            'We could not add this rule. The rule could not be read after saving. Your entered values have been preserved so you can try again.',
            RuleMutationPresentation::unreadAfterSaveMessage(true)
        );
    }
}
