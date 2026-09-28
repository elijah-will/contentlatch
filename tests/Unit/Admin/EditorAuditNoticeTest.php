<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Admin;

use ContentLatch\Admin\EditorAuditNotice;
use ContentLatch\Application\EditorFieldNavigation;
use PHPUnit\Framework\TestCase;

final class EditorAuditNoticeTest extends TestCase
{
    public function testNoticeOnlyEnqueuesOnEditorScreens(): void
    {
        $this->assertTrue(EditorAuditNotice::shouldEnqueue('post.php'));
        $this->assertTrue(EditorAuditNotice::shouldEnqueue('post-new.php'));
        $this->assertFalse(EditorAuditNotice::shouldEnqueue('edit.php'));
        $this->assertFalse(EditorAuditNotice::shouldEnqueue('contentlatch_page_contentlatch-audit'));
    }

    public function testNoticeDoesNotUseSaveValidationHooks(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/EditorAuditNotice.php');

        $this->assertStringContainsString('EditorAuditIssues', $php);
        $this->assertStringContainsString('blockingFindingsForPost', $php);
        $this->assertStringContainsString('evaluateStoredPost', $php);
        $this->assertStringContainsString('editor-blockers', $php);
        $this->assertStringContainsString('preserveAuditRunOnRedirect', $php);
        $this->assertStringNotContainsString('acf_add_validation_error', $php);
        $this->assertStringNotContainsString('acf/validate_save_post', $php);
        $this->assertStringNotContainsString('AcfSaveValidator', $php);
        $this->assertStringNotContainsString('SaveWarningNotifier', $php);
        $this->assertStringNotContainsString('editor-warnings', $php);
        $this->assertStringContainsString('navigationExtras', $php);
        $this->assertStringNotContainsString('contentlatch_row', $php);
    }

    public function testAuditArrivalNoticeDependsOnTheRunNotTheFieldQuery(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/EditorAuditNotice.php');
        $this->assertStringContainsString('requestedRunId', $php);
        $this->assertStringContainsString('AUDIT_RUN_ARG', $php);

        $issues = $this->methodSource($php, 'issuesForRequest');
        $this->assertStringContainsString('requestedRunId', $issues);
        $this->assertStringNotContainsString('requestedFieldKey', $issues);
        $this->assertStringContainsString('requestedFieldKey', $this->methodSource($php, 'issueForNavigation'));
    }

    public function testAuditArrivalPublishesRepeaterPathThroughEditorFieldFocus(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/EditorAuditNotice.php');
        $warnings = (string) file_get_contents(
            dirname(__DIR__, 3) . '/includes/Infrastructure/ACF/SaveWarningNotifier.php'
        );

        $this->assertStringContainsString(
            'EditorFieldFocus::enqueueAssets($request, self::navigationExtras($issues, $request));',
            $php
        );
        $this->assertStringContainsString("\$extra['repeaterPath'] = \$path;", $php);
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$warnings\s*!==\s*array\(\)\s*\)\s*\{\s*EditorFieldFocus::enqueueAssets\(/s',
            $warnings
        );
    }

    public function testSuccessfulSaveRedirectKeepsTheAuditRun(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/EditorAuditNotice.php');
        $this->assertStringContainsString('redirect_post_location', $php);
        $this->assertStringContainsString('AUDIT_RUN_ARG', $php);
        $this->assertStringContainsString('appendToEditUrl', $php);
        $this->assertStringContainsString('preserveAuditRunOnRedirect', $php);
    }

    public function testRequestedFieldSelectsItsRepeaterPathAmongOtherIssues(): void
    {
        $ingredient = array(
            array(
                'repeater'    => 'field_66a7ff4394039',
                'display_row' => 3,
            ),
        );

        $extras = $this->navigationExtras(
            array(
                $this->issue('field_66a800089403a', $ingredient),
                $this->issue('field_66a800af9403d', array(
                    array(
                        'repeater'    => 'field_66a800999403c',
                        'display_row' => 2,
                    ),
                )),
                $this->issue('field_66a800af9403d', array(
                    array(
                        'repeater'    => 'field_66a800999403c',
                        'display_row' => 3,
                    ),
                )),
            ),
            array(EditorFieldNavigation::QUERY_ARG => 'field_66a800089403a')
        );

        $this->assertSame($ingredient, $extras['repeaterPath']);
    }

    public function testSingleIssueStillPublishesItsRepeaterPath(): void
    {
        $path = array(
            array(
                'repeater'    => 'field_66a7ff4394039',
                'display_row' => 3,
            ),
        );

        $this->assertSame(
            array('repeaterPath' => $path),
            $this->navigationExtras(array($this->issue('field_66a800089403a', $path)))
        );
        $this->assertSame(
            array(
                'layout'       => 'recipe_step',
                'displayRow'   => 2,
                'repeaterPath' => $path,
            ),
            $this->navigationExtras(array(
                $this->issue('field_66a800089403a', $path, 'recipe_step', array(2)),
            ))
        );
    }

    public function testScalarIssueDoesNotInheritAnotherFieldsRepeaterPath(): void
    {
        $extras = $this->navigationExtras(
            array(
                $this->issue('field_66a800089403a', array(
                    array(
                        'repeater'    => 'field_66a7ff4394039',
                        'display_row' => 3,
                    ),
                )),
                $this->issue('field_66a8001111111'),
            ),
            array(EditorFieldNavigation::QUERY_ARG => 'field_66a8001111111')
        );

        $this->assertSame(array(), $extras);
        $this->assertSame(
            array(),
            $this->navigationExtras(array($this->issue('field_66a8001111111')))
        );
    }

    public function testNoMatchingFieldFallsBackWithoutAnotherIssuesPath(): void
    {
        $extras = $this->navigationExtras(
            array(
                $this->issue('field_66a800089403a', array(
                    array(
                        'repeater'    => 'field_66a7ff4394039',
                        'display_row' => 3,
                    ),
                )),
                $this->issue('field_66a800af9403d', array(
                    array(
                        'repeater'    => 'field_66a800999403c',
                        'display_row' => 2,
                    ),
                )),
            ),
            array(EditorFieldNavigation::QUERY_ARG => 'field_66a8002222222')
        );

        $this->assertSame(array(), $extras);
        $this->assertSame(array(), $this->navigationExtras(array(
            $this->issue('field_66a800089403a', array(
                array(
                    'repeater'    => 'field_66a7ff4394039',
                    'display_row' => 3,
                ),
            )),
            $this->issue('field_66a800af9403d'),
        )));
    }

    public function testAmbiguousSameFieldPathsAreNotSelected(): void
    {
        $extras = $this->navigationExtras(
            array(
                $this->issue('field_66a800af9403d', array(
                    array(
                        'repeater'    => 'field_66a800999403c',
                        'display_row' => 2,
                    ),
                )),
                $this->issue('field_66a800af9403d', array(
                    array(
                        'repeater'    => 'field_66a800999403c',
                        'display_row' => 3,
                    ),
                )),
            ),
            array(EditorFieldNavigation::QUERY_ARG => 'field_66a800af9403d')
        );

        $this->assertSame(array(), $extras);
    }

    public function testMatchingIssuesThatNameTheSamePathCanBeSelected(): void
    {
        $path = array(
            array(
                'repeater'    => 'field_66a7ff4394039',
                'display_row' => 3,
            ),
        );

        $this->assertSame(
            array('repeaterPath' => $path),
            $this->navigationExtras(
                array(
                    $this->issue('field_66a800089403a', $path),
                    $this->issue('field_66a800089403a', $path),
                    $this->issue('field_66a800af9403d', array(
                        array(
                            'repeater'    => 'field_66a800999403c',
                            'display_row' => 2,
                        ),
                    )),
                ),
                array(EditorFieldNavigation::QUERY_ARG => 'field_66a800089403a')
            )
        );
    }

    /**
     * @param list<array{repeater: string, display_row: int}> $path
     * @param list<int> $rows
     * @return array{message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>, repeaterPath?: list<array{repeater: string, display_row: int}>}
     */
    private function issue(string $fieldKey, array $path = array(), string $layout = '', array $rows = array()): array
    {
        $issue = array(
            'message'  => 'This field is required.',
            'label'    => $fieldKey,
            'fieldKey' => $fieldKey,
        );
        if ($path !== array()) {
            $issue['repeaterPath'] = $path;
        }
        if ($layout !== '') {
            $issue['layout'] = $layout;
        }
        if ($rows !== array()) {
            $issue['affectedRows'] = $rows;
        }

        return $issue;
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function navigationExtras(array $issues, array $request = array()): array
    {
        $method = new \ReflectionMethod(EditorAuditNotice::class, 'navigationExtras');
        $method->setAccessible(true);
        $extras = $method->invoke(null, $issues, $request);
        $this->assertIsArray($extras);

        return $extras;
    }

    private function methodSource(string $php, string $name): string
    {
        $matched = preg_match(
            '/function ' . preg_quote($name, '/') . '\(.*?\n    \}/s',
            $php,
            $method
        );
        $this->assertSame(1, $matched);

        return $method[0];
    }
}
