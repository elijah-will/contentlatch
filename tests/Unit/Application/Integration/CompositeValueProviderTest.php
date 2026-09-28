<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application\Integration;

use ContentLatch\Application\Integration\CompositeValueProvider;
use ContentLatch\Domain\ArrayValueProvider;
use ContentLatch\Domain\FieldInstance;
use PHPUnit\Framework\TestCase;

final class CompositeValueProviderTest extends TestCase
{
    public function testSingleProviderMatchesTheUnderlyingValues(): void
    {
        $inner = new ArrayValueProvider(array(
            'field_title' => 'Hello',
            'field_empty' => '',
        ));
        $composite = new CompositeValueProvider(array($inner));

        $this->assertTrue($composite->has('field_title'));
        $this->assertSame('Hello', $composite->get('field_title'));
        $this->assertSame('', $composite->get('field_empty'));
        $this->assertFalse($composite->has('field_missing'));
        $this->assertNull($composite->get('field_missing'));
        $this->assertEquals(array(new FieldInstance('Hello')), $composite->instances('field_title'));
        $this->assertSame(array(), $composite->instances('field_missing'));
    }

    public function testInstancesAreRoutedWhenHasIsFalse(): void
    {
        $nested = new class implements \ContentLatch\Domain\Contracts\FieldValueProviderInterface {
            public function has(string $fieldId): bool
            {
                return false;
            }

            public function get(string $fieldId): mixed
            {
                return null;
            }

            public function instances(string $fieldId): array
            {
                if ($fieldId !== 'field_row') {
                    return array();
                }

                return array(new FieldInstance(''));
            }
        };

        $composite = new CompositeValueProvider(array($nested));

        $this->assertTrue($composite->has('field_row'));
        $this->assertEquals(array(new FieldInstance('')), $composite->instances('field_row'));
        $this->assertFalse($composite->has('field_other'));
    }
}
