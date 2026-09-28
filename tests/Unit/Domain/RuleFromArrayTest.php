<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Domain;

use ContentLatch\Domain\Exception\InvalidRuleException;
use ContentLatch\Domain\Rule;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleFromArrayTest extends TestCase
{
    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        Rule::fromArray(RuleFactory::document(array('name' => '')));
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        Rule::fromArray(RuleFactory::document(array('status' => 'archived')));
    }

    public function testInvalidSeverityIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        Rule::fromArray(RuleFactory::document(array('severity' => 'info')));
    }

    public function testNonAndConditionLogicIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        Rule::fromArray(RuleFactory::document(array('condition_logic' => 'or')));
    }

    public function testNonArrayConditionsAreRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        Rule::fromArray(RuleFactory::document(array('conditions' => 'equals')));
    }

    public function testEmptyFieldKeyIsRejected(): void
    {
        $this->expectException(InvalidRuleException::class);
        $document = RuleFactory::document();
        $document['conditions'][0]['field']['key'] = '';
        Rule::fromArray($document);
    }
}
