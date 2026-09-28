<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\WordPress;

use ContentLatch\Infrastructure\WordPress\HttpRequest;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/Support/wordpress-admin-functions.php';

final class HttpRequestTest extends TestCase
{
    public function testUnslashRestoresQuotesAndApostrophesWithoutTouchingSlashesInPaths(): void
    {
        $clean = array(
            'message' => 'Can\'t contain the word "chicken" in row 1/5.',
            'path'    => 'recipes/chicken',
            'html'    => 'Avoid <script>alert(1)</script> & more',
        );

        $this->assertSame($clean, HttpRequest::unslash(wp_slash($clean)));
        $this->assertSame($clean, HttpRequest::unslash($clean));
    }
}
