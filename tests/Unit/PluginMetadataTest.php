<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit;

use ContentGuard\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginMetadataTest extends TestCase
{
    public function testReleaseMetadataStaysConsistentAndDoesNotPinAcfFree(): void
    {
        $root   = dirname(__DIR__, 2);
        $header = (string) file_get_contents($root . '/contentguard.php');
        $readme = (string) file_get_contents($root . '/readme.txt');
        $ignore = (string) file_get_contents($root . '/.distignore');
        $license = (string) file_get_contents($root . '/license.txt');

        $this->assertSame('1.0.0', Plugin::VERSION);
        $this->assertMatchesRegularExpression('/^\s*\*\s*Version:\s*1\.0\.0\s*$/m', $header);
        $this->assertStringContainsString('Stable tag: 1.0.0', $readme);
        $this->assertMatchesRegularExpression('/^\s*\*\s*Requires at least:\s*6\.6\s*$/m', $header);
        $this->assertMatchesRegularExpression('/^\s*\*\s*Requires PHP:\s*8\.1\s*$/m', $header);
        $this->assertMatchesRegularExpression('/^\s*\*\s*Text Domain:\s*contentguard\s*$/m', $header);
        $this->assertMatchesRegularExpression('/^\s*\*\s*License:\s*GPL-2\.0-or-later\s*$/m', $header);

        $this->assertStringNotContainsString('Requires Plugins:', $header);
        $this->assertStringNotContainsString('Requires Plugins:', $readme);
        $this->assertStringNotContainsString('github.com/contentguard/contentguard', $header);
        $this->assertStringNotContainsString('Plugin URI:', $header);

        $this->assertStringContainsString("\nvendor\n", "\n" . $ignore . "\n");
        $this->assertDoesNotMatchRegularExpression('/^vendor\/bin$/m', $ignore);

        $this->assertStringContainsString('GNU GENERAL PUBLIC LICENSE', $license);
        $this->assertStringContainsString('either version 2 of the License, or', $license);
        $this->assertStringContainsString('(at your option) any later version.', $license);
    }
}
