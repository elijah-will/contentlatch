<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application\Audit;

use ContentGuard\Tests\Support\InMemoryAuditPostScanner;
use PHPUnit\Framework\TestCase;

final class InMemoryAuditPostScannerTest extends TestCase
{
    public function testOrdersByIdRespectsCursorAndBatchSize(): void
    {
        $scanner = new InMemoryAuditPostScanner(
            array(
                array('id' => 30, 'postType' => 'recipe', 'status' => 'publish'),
                array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'),
                array('id' => 20, 'postType' => 'recipe', 'status' => 'draft'),
                array('id' => 15, 'postType' => 'page', 'status' => 'publish'),
            )
        );

        $first = $scanner->scan(array('recipe'), array('publish', 'private'), 0, 1);
        $this->assertCount(1, $first);
        $this->assertSame(10, $first[0]->id);

        $second = $scanner->scan(array('recipe'), array('publish', 'private'), 10, 10);
        $this->assertCount(1, $second);
        $this->assertSame(30, $second[0]->id);

        $this->assertSame(2, $scanner->count(array('recipe'), array('publish', 'private')));
    }
}