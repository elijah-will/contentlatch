<?php
/**
 * Production gettext must stay statically discoverable.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\AuditPresentation;
use ContentGuard\Application\DomainMessages;
use ContentGuard\Application\EditorNoticePresentation;
use ContentGuard\Infrastructure\WordPress\CoreFieldCatalog;
use PHPUnit\Framework\TestCase;

final class GettextLiteralsTest extends TestCase
{
    public function testProductionGettextCallsUseLiteralStringsAndDomain(): void
    {
        $failures = $this->dynamicGettextCalls($this->pluginRoot() . '/includes');
        $failures = array_merge($failures, $this->dynamicGettextCalls($this->pluginRoot() . '/admin'));
        $main     = $this->pluginRoot() . '/contentguard.php';
        if (is_file($main)) {
            $failures = array_merge($failures, $this->dynamicCallsInFile($main));
        }

        $this->assertSame(array(), $failures);
    }

    public function testDomainCodeDoesNotCallWordPressGettext(): void
    {
        $this->assertSame(array(), $this->dynamicGettextCalls($this->pluginRoot() . '/includes/Domain', true));
    }

    public function testDomainMessagesKeepEnglishWording(): void
    {
        $this->assertSame('This field is required.', DomainMessages::present('This field is required.'));
        $this->assertSame(
            'This field must be at least 3 characters.',
            DomainMessages::present('This field must be at least 3 characters.')
        );
        $this->assertSame(
            'This field must be at most 12 characters.',
            DomainMessages::present('This field must be at most 12 characters.')
        );
        $this->assertSame(
            'Add at least one Gallery row.',
            DomainMessages::present('Add at least one Gallery row.')
        );
        $this->assertSame('Keep this custom message.', DomainMessages::present('Keep this custom message.'));
    }

    public function testPluralLabelsUseSingularForOne(): void
    {
        $this->assertSame('1 issue', AuditPresentation::issuesCountLabel(1));
        $this->assertSame('3 issues', AuditPresentation::issuesCountLabel(3));
        $this->assertSame('1 Blocking', AuditPresentation::blockingCountLabel(1));
        $this->assertSame('4 Blocking', AuditPresentation::blockingCountLabel(4));
        $this->assertSame('1 Warning', AuditPresentation::warningCountLabel(1));
        $this->assertSame('2 Warning', AuditPresentation::warningCountLabel(2));
    }

    public function testEditorNoticeCopyIsUnchanged(): void
    {
        $this->assertSame(
            'ContentGuard · Warning',
            EditorNoticePresentation::title(EditorNoticePresentation::SEVERITY_WARNING)
        );
        $this->assertSame(
            'ContentGuard · Blocking',
            EditorNoticePresentation::title(EditorNoticePresentation::SEVERITY_BLOCKING)
        );
        $this->assertSame('', EditorNoticePresentation::countLabel(EditorNoticePresentation::SEVERITY_WARNING, 1));
        $this->assertSame('2 warnings', EditorNoticePresentation::countLabel(EditorNoticePresentation::SEVERITY_WARNING, 2));
        $this->assertSame(
            '3 blocking issues',
            EditorNoticePresentation::countLabel(EditorNoticePresentation::SEVERITY_BLOCKING, 3)
        );
        $this->assertSame(
            'Title — This field is required.',
            EditorNoticePresentation::issueLine('Title', 'This field is required.', 'required')
        );
    }

    public function testCoreFieldLabelsStayDiscoverableEnglish(): void
    {
        $catalog = new CoreFieldCatalog(
            static fn (): bool => true,
            static fn (): bool => true,
            static fn (): bool => true,
        );
        $labels = array();
        foreach ($catalog->fieldsForPostType('post') as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
            $this->assertSame('WordPress', $field['group_label']);
        }

        $this->assertSame(
            array(
                'title'          => 'Title',
                'content'        => 'Content',
                'excerpt'        => 'Excerpt',
                'slug'           => 'Slug',
                'featured_image' => 'Featured Image',
                'author'         => 'Author',
            ),
            $labels
        );
    }

    public function testPotExtractionDoesNotScrapeTranslationWrappers(): void
    {
        $script = (string) file_get_contents($this->pluginRoot() . '/bin/make-pot.php');
        $this->assertStringNotContainsString('I18n::translate', $script);
        $this->assertStringNotContainsString('Text::translate', $script);
        $this->assertStringContainsString('make-pot', $script);

        $pot = (string) file_get_contents($this->pluginRoot() . '/languages/contentguard.pot');
        foreach (
            array(
                'This field is required.',
                'Add a WHEN condition or THEN requirement.',
                'Deleted rule',
                '%d warnings',
                '%d blocking issues',
                'Title',
                'Featured Image',
                'Add at least one %s row.',
            ) as $msgid
        ) {
            $this->assertStringContainsString('msgid "' . $msgid . '"', $pot, $msgid);
        }
    }

    private function pluginRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return list<string>
     */
    private function dynamicGettextCalls(string $directory, bool $anyCall = false): array
    {
        if (!is_dir($directory)) {
            return array();
        }

        $failures = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $failures = array_merge($failures, $this->dynamicCallsInFile($file->getPathname(), $anyCall));
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private function dynamicCallsInFile(string $path, bool $anyCall = false): array
    {
        $functions = array(
            '__'           => 1,
            '_e'           => 1,
            '_x'           => 2,
            '_ex'          => 2,
            '_n'           => 2,
            '_nx'          => 2,
            'esc_html__'   => 1,
            'esc_attr__'   => 1,
            'esc_html_e'   => 1,
            'esc_attr_e'   => 1,
            'esc_html_x'   => 2,
            'esc_attr_x'   => 2,
        );
        $code   = (string) file_get_contents($path);
        $tokens = token_get_all($code);
        $failures = array();
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || !isset($functions[$tokens[$i][1]])) {
                continue;
            }

            $name = $tokens[$i][1];
            $prev = $this->previousSignificant($tokens, $i);
            if ($prev !== null && is_array($prev) && in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), true)) {
                continue;
            }

            $open = $this->nextSignificantIndex($tokens, $i + 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            $line = $tokens[$i][2];
            if ($anyCall) {
                $failures[] = $path . ':' . $line . ' ' . $name . '()';
                continue;
            }

            $args = $this->arguments($tokens, $open);
            $literalCount = $functions[$name];
            for ($arg = 0; $arg < $literalCount; $arg++) {
                if (!isset($args[$arg]) || !$this->isSingleLiteral($args[$arg])) {
                    $failures[] = $path . ':' . $line . ' ' . $name . '() argument ' . ($arg + 1) . ' is not a literal';
                }
            }

            $domain = $args[count($args) - 1] ?? array();
            if (!$this->isDomainLiteral($domain)) {
                $failures[] = $path . ':' . $line . ' ' . $name . '() text domain is not the literal contentguard';
            }
        }

        return $failures;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function previousSignificant(array $tokens, int $index): mixed
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function nextSignificantIndex(array $tokens, int $index): ?int
    {
        $count = count($tokens);
        for ($i = $index; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<list<mixed>>
     */
    private function arguments(array $tokens, int $open): array
    {
        $args  = array();
        $current = array();
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(') {
                $depth++;
                if ($depth > 1) {
                    $current[] = $token;
                }
                continue;
            }
            if ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    if ($current !== array()) {
                        $args[] = $current;
                    }
                    break;
                }
                $current[] = $token;
                continue;
            }
            if ($token === ',' && $depth === 1) {
                $args[] = $current;
                $current = array();
                continue;
            }
            if ($depth >= 1) {
                $current[] = $token;
            }
        }

        return $args;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function isSingleLiteral(array $tokens): bool
    {
        $strings = 0;
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $strings++;
                continue;
            }

            return false;
        }

        return $strings === 1;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function isDomainLiteral(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            return in_array($token[1], array("'contentguard'", '"contentguard"'), true);
        }

        return false;
    }
}
