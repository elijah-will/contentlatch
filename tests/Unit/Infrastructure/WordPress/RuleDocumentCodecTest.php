<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\WordPress;

use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Infrastructure\WordPress\PostTypeRuleRepository;
use ContentGuard\Infrastructure\WordPress\RuleDocumentCodec;
use ContentGuard\Infrastructure\WordPress\RulePostRecord;
use ContentGuard\Tests\Support\FakeRulePostStore;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleDocumentCodecTest extends TestCase
{
    public function testShowNewTagYesDocumentSurvivesWordPressContentFilters(): void
    {
        $json = $this->showNewTagJson('1');
        $this->assertTrue(RuleDocumentCodec::isDocument($json));
        $this->assertSame('1', json_decode($json, true)['conditions'][0]['operand']);
        $this->assertSame($json, RuleDocumentCodec::forPostContent($json));

        $slashed = addslashes($json);
        $this->assertSame($json, RuleDocumentCodec::recover(stripslashes($slashed)));
        $this->assertSame($json, RuleDocumentCodec::recover('<p>' . $json . '</p>'));
        $this->assertSame($json, RuleDocumentCodec::recover(addslashes('<p>' . $json . '</p>')));
    }

    public function testShowNewTagNoDocumentSurvivesWordPressContentFilters(): void
    {
        $json = $this->showNewTagJson('0');
        $this->assertSame('0', json_decode($json, true)['conditions'][0]['operand']);
        $this->assertSame($json, RuleDocumentCodec::recover('<p>' . addslashes($json) . '</p>'));
    }

    public function testGarbageContentIsNotADocument(): void
    {
        $this->assertNull(RuleDocumentCodec::recover(''));
        $this->assertNull(RuleDocumentCodec::recover('<p>not json</p>'));
        $this->assertFalse(RuleDocumentCodec::isDocument('{not-json'));
    }

    public function testQuotedLabelJsonIsReadWithoutUnslashing(): void
    {
        $json = $this->quotedShowNewTagJson();
        $this->assertStringContainsString('Show \\"New\\" Tag', $json);
        $this->assertTrue(RuleDocumentCodec::isDocument($json));
        $this->assertSame($json, RuleDocumentCodec::fromStoredMeta($json));
        $this->assertSame($json, RuleDocumentCodec::fromRawStorage($json));
        $this->assertSame($json, RuleDocumentCodec::recover($json));
        $this->assertFalse(RuleDocumentCodec::isDocument(stripslashes($json)));
    }

    public function testBackslashLabelJsonIsReadWithoutUnslashing(): void
    {
        $json = $this->encodeRule(
            RuleFactory::rule(
                array(
                    'conditions' => array(
                        RuleFactory::condition(array(
                            'field' => RuleFactory::field('field_path', 'path', 'C:\\Temp\\New'),
                        )),
                    ),
                )
            )
        );

        $this->assertTrue(RuleDocumentCodec::isDocument($json));
        $this->assertSame($json, RuleDocumentCodec::fromStoredMeta($json));
        $this->assertSame($json, RuleDocumentCodec::fromRawStorage($json));
        $this->assertSame('C:\\Temp\\New', json_decode($json, true)['conditions'][0]['field']['label']);
        $this->assertFalse(RuleDocumentCodec::isDocument(stripslashes($json)));
    }

    public function testValidPostContentJsonIsReadWithoutUnslashing(): void
    {
        $json = $this->quotedShowNewTagJson();

        $this->assertSame($json, RuleDocumentCodec::fromRawStorage($json));
        $this->assertSame($json, RuleDocumentCodec::fromRawStorage('<p>' . $json . '</p>'));
    }

    public function testSlashThenUnslashPreservesQuotedLabelJson(): void
    {
        $json = $this->quotedShowNewTagJson();
        $stored = stripslashes(RuleDocumentCodec::forStoredMeta($json));

        $this->assertTrue(RuleDocumentCodec::isDocument($stored));
        $this->assertSame($json, $stored);
        $this->assertSame('Show "New" Tag', json_decode($stored, true)['conditions'][0]['field']['label']);
    }

    public function testUnslashedQuotedLabelMetaIsNotADocument(): void
    {
        $broken = stripslashes($this->quotedShowNewTagJson());

        $this->assertNull(RuleDocumentCodec::fromStoredMeta($broken));
        $this->assertNull(RuleDocumentCodec::fromRawStorage($broken));
    }

    public function testRepositoryReloadsShowNewTagRuleAfterPostContentIsMangled(): void
    {
        $store = new FakeRulePostStore();
        $json = $this->showNewTagJson('1');
        $store->seed(
            new RulePostRecord(
                7,
                'Page ID is required when Show New Tag is Yes',
                'publish',
                '<p>' . addslashes($json) . '</p>',
                'product'
            )
        );

        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());
        $loaded = $repository->find(7);

        $this->assertNotNull($loaded);
        $this->assertSame('1', $loaded->conditions[0]->operand);
        $this->assertSame('required', $loaded->validations[0]->type);
        $this->assertSame('field_page_id', $loaded->validations[0]->field->key);
        $this->assertSame('Page ID is required when Show New Tag is Yes', $loaded->name);
    }

    public function testRepositoryStillRejectsIrrecoverableContent(): void
    {
        $store = new FakeRulePostStore();
        $store->seed(new RulePostRecord(8, 'Broken', 'publish', '<p>not a rule</p>', 'product'));

        $repository = new PostTypeRuleRepository($store, RuleDocumentValidator::v1());

        $this->assertNull($repository->find(8));
    }

    private function quotedShowNewTagJson(): string
    {
        return $this->encodeRule(
            RuleFactory::rule(
                array(
                    'name'       => 'New Products need Page ID',
                    'conditions' => array(
                        RuleFactory::condition(array(
                            'field'    => RuleFactory::field('field_683097e0dc6d2', 'show_new_tag', 'Show "New" Tag'),
                            'operator' => 'equals',
                            'operand'  => '1',
                        )),
                    ),
                    'validations' => array(
                        RuleFactory::validation(array(
                            'field' => RuleFactory::field('field_6a05f9e8420ea', 'pr_page_id', 'PowerReviews Page ID'),
                            'type'  => 'required',
                        )),
                    ),
                )
            )
        );
    }

    private function encodeRule(\ContentGuard\Domain\Rule $rule): string
    {
        $json = json_encode($rule->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);

        return $json;
    }

    private function showNewTagJson(string $operand): string
    {
        return $this->encodeRule($this->showNewTagRule($operand));
    }

    private function showNewTagRule(string $operand): \ContentGuard\Domain\Rule
    {
        return RuleFactory::rule(
            array(
                'id'         => '',
                'name'       => 'Page ID is required when Show New Tag is Yes',
                'conditions' => array(
                    RuleFactory::condition(array(
                        'field'    => RuleFactory::field('field_show_new_tag', 'show_new_tag', 'Show New Tag'),
                        'operator' => 'equals',
                        'operand'  => $operand,
                    )),
                ),
                'validations' => array(
                    RuleFactory::validation(array(
                        'field' => RuleFactory::field('field_page_id', 'page_id', 'Page ID'),
                        'type'  => 'required',
                    )),
                ),
            )
        );
    }
}
