<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Infrastructure\ACF\AcfSaveValidator;
use ContentGuard\Infrastructure\ACF\IntendedPostStatusResolver;
use ContentGuard\Tests\Support\InMemoryRuleRepository;
use ContentGuard\Tests\Support\AcfCloneFixtures;
use ContentGuard\Tests\Support\IncomingSaveFixtures;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class AcfCloneSaveValidatorTest extends TestCase
{
    /**
     * @var array<int, array{input: string, message: string}>
     */
    private array $errors = array();

    public function testTopLevelSeamlessCloneAttachesToCompositeInput(): void
    {
        $this->validate(
            array($this->requiredRule(AcfCloneFixtures::cloneATitleRef())),
            array(
                AcfCloneFixtures::CLONE_A => array(
                    AcfCloneFixtures::cloneATitlePosted() => '',
                ),
            )
        );

        $this->assertClassicFieldError(
            'acf[' . AcfCloneFixtures::CLONE_A . '][' . AcfCloneFixtures::cloneATitlePosted() . ']',
            'Shared Content → Title — This field is required.'
        );
    }

    public function testGroupDisplayCloneAttachesToOriginalChildInput(): void
    {
        $this->validate(
            array($this->requiredRule(AcfCloneFixtures::cloneBTitleRef())),
            array(
                AcfCloneFixtures::CLONE_B => array(
                    AcfCloneFixtures::TITLE => '',
                ),
            )
        );

        $this->assertClassicFieldError(
            'acf[' . AcfCloneFixtures::CLONE_B . '][' . AcfCloneFixtures::TITLE . ']',
            'Hero Clone → Title — This field is required.'
        );
    }

    public function testCloneOfGroupAttachesToNestedInput(): void
    {
        $this->validate(
            array($this->requiredRule(AcfCloneFixtures::cloneGroupIngredientsRef())),
            array(
                AcfCloneFixtures::CLONE_GROUP => array(
                    AcfCloneFixtures::cloneGroupDetailsPosted() => array(
                        AcfCloneFixtures::INGREDIENTS => '',
                    ),
                ),
            )
        );

        $this->assertSame('', $this->errors[0]['input']);
        $this->assertStringStartsWith("ContentGuard · Blocking\n", $this->errors[0]['message']);
        $this->assertSame(
            'acf[' . AcfCloneFixtures::CLONE_GROUP . '][' . AcfCloneFixtures::cloneGroupDetailsPosted() . '][' . AcfCloneFixtures::INGREDIENTS . ']',
            $this->errors[1]['input']
        );
    }

    public function testRepeaterCloneAttachesToTheLiveRowInput(): void
    {
        $this->validate(
            array($this->requiredRule(AcfCloneFixtures::repeaterCloneTitleRef(), 'every')),
            array(
                AcfCloneFixtures::REPEATER => array(
                    'row-0' => array(
                        AcfCloneFixtures::CLONE_REP => array(
                            AcfCloneFixtures::cloneRepTitlePosted() => '',
                        ),
                    ),
                    'acfcloneindex' => array(
                        AcfCloneFixtures::CLONE_REP => array(
                            AcfCloneFixtures::cloneRepTitlePosted() => 'ignored',
                        ),
                    ),
                ),
            )
        );

        $this->assertSame('', $this->errors[0]['input']);
        $this->assertStringStartsWith("ContentGuard · Blocking\n", $this->errors[0]['message']);
        $this->assertSame(
            'acf[' . AcfCloneFixtures::REPEATER . '][row-0][' . AcfCloneFixtures::CLONE_REP . '][' . AcfCloneFixtures::cloneRepTitlePosted() . ']',
            $this->errors[1]['input']
        );
    }

    public function testFlexibleCloneAttachesToTheMatchingLayoutRow(): void
    {
        $this->validate(
            array($this->requiredRule(AcfCloneFixtures::flexCloneTitleRef(), 'every')),
            array(
                AcfCloneFixtures::FLEX => array(
                    'row-2' => array(
                        'acf_fc_layout' => 'hero',
                        AcfCloneFixtures::CLONE_FLEX => array(
                            AcfCloneFixtures::cloneFlexTitlePosted() => '',
                        ),
                    ),
                ),
            )
        );

        $this->assertSame('', $this->errors[0]['input']);
        $this->assertStringStartsWith("ContentGuard · Blocking\n", $this->errors[0]['message']);
        $this->assertSame(
            'acf[' . AcfCloneFixtures::FLEX . '][row-2][' . AcfCloneFixtures::CLONE_FLEX . '][' . AcfCloneFixtures::cloneFlexTitlePosted() . ']',
            $this->errors[1]['input']
        );
    }

    public function testDraftIsAllowedAndWarningIsNonBlocking(): void
    {
        $this->validate(
            array($this->requiredRule(AcfCloneFixtures::cloneATitleRef())),
            array(
                AcfCloneFixtures::CLONE_A => array(
                    AcfCloneFixtures::cloneATitlePosted() => '',
                ),
            ),
            $this->request(array('post_status' => 'draft', 'original_post_status' => 'draft'))
        );
        $this->assertSame(array(), $this->errors);

        $this->validate(
            array($this->requiredRule(AcfCloneFixtures::cloneATitleRef(), '', RuleSeverity::Warning)),
            array(
                AcfCloneFixtures::CLONE_A => array(
                    AcfCloneFixtures::cloneATitlePosted() => '',
                ),
            )
        );
        $this->assertSame(array(), $this->errors);
    }

    public function testPublishAndPrivateAreBlocked(): void
    {
        foreach (array('publish', 'private') as $status) {
            $this->errors = array();
            $this->validate(
                array($this->requiredRule(AcfCloneFixtures::cloneATitleRef())),
                array(
                    AcfCloneFixtures::CLONE_A => array(
                        AcfCloneFixtures::cloneATitlePosted() => '',
                    ),
                ),
                $this->request(array('post_status' => $status))
            );
            $this->assertNotSame(array(), $this->errors, $status . ' should block');
        }
    }

    private function assertClassicFieldError(string $input, string $message): void
    {
        $this->assertNotSame(array(), $this->errors);
        $this->assertSame('', $this->errors[0]['input']);
        $this->assertStringStartsWith("ContentGuard · Blocking\n", $this->errors[0]['message']);
        $this->assertSame(
            array(
                array(
                    'input'   => $input,
                    'message' => $message,
                ),
            ),
            array_slice($this->errors, 1)
        );
    }

    /**
     * @param Rule[] $rules
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $request
     */
    private function validate(array $rules, array $payload, array $request = array()): void
    {
        $this->errors = array();
        $repository   = new InMemoryRuleRepository($rules);
        $validator    = new AcfSaveValidator(
            $repository,
            AcfCloneFixtures::pageCatalog(),
            new IntendedPostStatusResolver(),
            function (string $input, string $message): void {
                $this->errors[] = array(
                    'input'   => $input,
                    'message' => $message,
                );
            },
            IncomingSaveFixtures::evaluator($repository, AcfCloneFixtures::pageCatalog())
        );

        $validator->validate($request === array() ? $this->request() : $request, $payload);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function request(array $overrides = array()): array
    {
        return array_merge(
            array(
                'post_ID'              => 42,
                'post_type'            => 'page',
                'post_status'          => 'publish',
                'original_post_status' => 'draft',
                'action'               => 'editpost',
            ),
            $overrides
        );
    }

    private function requiredRule(
        \ContentGuard\Domain\FieldRef $field,
        string $quantifier = '',
        RuleSeverity $severity = RuleSeverity::Fail
    ): Rule {
        return RuleFactory::rule(array(
            'id'          => 10,
            'name'        => 'Clone required',
            'postType'    => 'page',
            'severity'    => $severity,
            'conditions'  => array(),
            'validations' => array(
                RuleFactory::validation(array(
                    'field'      => $field,
                    'type'       => 'required',
                    'quantifier' => $quantifier,
                )),
            ),
        ));
    }
}
