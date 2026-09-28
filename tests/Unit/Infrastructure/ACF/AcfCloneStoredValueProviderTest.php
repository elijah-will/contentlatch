<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\ACF;

use ContentLatch\Domain\FieldRef;
use ContentLatch\Domain\RuleEngine;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;
use ContentLatch\Infrastructure\ACF\AcfStoredValueProvider;
use ContentLatch\Infrastructure\ACF\AcfValueNormalizer;
use ContentLatch\Tests\Support\AcfCloneFixtures;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class AcfCloneStoredValueProviderTest extends TestCase
{
    public function testSeamlessCloneReadsFromCloneKeyNotTheOriginalLeaf(): void
    {
        $calls = array();
        $provider = $this->provider(
            static function (string $key) use (&$calls): mixed {
                $calls[] = $key;
                return match ($key) {
                    AcfCloneFixtures::CLONE_A => array(
                        AcfCloneFixtures::cloneATitlePosted() => 'Cloned title',
                    ),
                    AcfCloneFixtures::TITLE => 'Source title',
                    default => null,
                };
            }
        );

        $id = AcfCloneFixtures::cloneATitlePosted();
        $this->assertTrue($provider->has($id));
        $this->assertSame('Cloned title', $provider->get($id));
        $this->assertSame(array(AcfCloneFixtures::CLONE_A), $calls);
        $this->assertSame('Source title', $provider->get(AcfCloneFixtures::TITLE));
    }

    public function testGroupDisplayCloneWalksTheCloneArrayByOriginalChildKey(): void
    {
        $provider = $this->provider(
            static function (string $key): mixed {
                return match ($key) {
                    AcfCloneFixtures::CLONE_B => array(
                        AcfCloneFixtures::TITLE => 'Grouped title',
                    ),
                    AcfCloneFixtures::TITLE => 'Source title',
                    default => null,
                };
            }
        );

        $this->assertSame('Grouped title', $provider->get(AcfCloneFixtures::cloneBTitleId()));
    }

    public function testPrefixedCloneFallsBackToTheEffectiveName(): void
    {
        $calls = array();
        $provider = $this->provider(
            static function (string $key) use (&$calls): mixed {
                $calls[] = $key;
                return match ($key) {
                    AcfCloneFixtures::CLONE_A => false,
                    'shared_a_title' => 'Prefixed meta',
                    AcfCloneFixtures::TITLE => 'Source title',
                    default => null,
                };
            }
        );

        $this->assertSame('Prefixed meta', $provider->get(AcfCloneFixtures::cloneATitlePosted()));
        $this->assertNotContains(AcfCloneFixtures::TITLE, array_slice($calls, 0, 2));
    }

    public function testCloneOfGroupWalksCloneThenGroupThenLeaf(): void
    {
        $provider = $this->provider(
            static function (string $key): mixed {
                return $key === AcfCloneFixtures::CLONE_GROUP
                    ? array(
                        AcfCloneFixtures::cloneGroupDetailsPosted() => array(
                            AcfCloneFixtures::INGREDIENTS => 'Salt, pepper',
                        ),
                    )
                    : null;
            }
        );

        $id = FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_GROUP, AcfCloneFixtures::INGREDIENTS);
        $this->assertSame('Salt, pepper', $provider->get($id));
    }

    public function testCloneInsideRepeaterWalksTheRemainingPath(): void
    {
        $provider = $this->provider(
            static function (string $key): mixed {
                return $key === AcfCloneFixtures::REPEATER
                    ? array(
                        array(
                            AcfCloneFixtures::CLONE_REP => array(
                                AcfCloneFixtures::cloneRepTitlePosted() => 'Row one',
                            ),
                        ),
                        array(
                            AcfCloneFixtures::CLONE_REP => array(
                                AcfCloneFixtures::cloneRepTitlePosted() => '',
                            ),
                        ),
                    )
                    : null;
            }
        );

        $id        = FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_REP, AcfCloneFixtures::TITLE);
        $instances = $provider->instances($id);
        $this->assertCount(2, $instances);
        $this->assertSame('Row one', $instances[0]->value);
        $this->assertNull($instances[1]->value);
        $this->assertSame(
            'acf[' . AcfCloneFixtures::REPEATER . '][row-0][' . AcfCloneFixtures::CLONE_REP . '][' . AcfCloneFixtures::cloneRepTitlePosted() . ']',
            $instances[0]->context['input_name']
        );
    }

    public function testCloneInsideFlexibleWalksLayoutRows(): void
    {
        $provider = $this->provider(
            static function (string $key): mixed {
                return $key === AcfCloneFixtures::FLEX
                    ? array(
                        array(
                            'acf_fc_layout' => 'hero',
                            AcfCloneFixtures::CLONE_FLEX => array(
                                AcfCloneFixtures::cloneFlexTitlePosted() => 'Hero title',
                            ),
                        ),
                        array(
                            'acf_fc_layout' => 'cta',
                            AcfCloneFixtures::CLONE_FLEX => array(
                                AcfCloneFixtures::cloneFlexTitlePosted() => 'Ignored',
                            ),
                        ),
                    )
                    : null;
            }
        );

        $id        = FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_FLEX, AcfCloneFixtures::TITLE);
        $instances = $provider->instances($id);
        $this->assertCount(1, $instances);
        $this->assertSame('Hero title', $instances[0]->value);
        $this->assertSame('hero', $instances[0]->context['layout']);
        $this->assertSame(1, $instances[0]->context['display_row']);
    }

    public function testTwoClonesOfTheSameSourceRemainIndependentlyEvaluable(): void
    {
        $provider = $this->provider(
            static function (string $key): mixed {
                return match ($key) {
                    AcfCloneFixtures::CLONE_A => array(
                        AcfCloneFixtures::cloneATitlePosted() => '',
                    ),
                    AcfCloneFixtures::CLONE_B => array(
                        AcfCloneFixtures::TITLE => 'Hero title',
                    ),
                    AcfCloneFixtures::TITLE => 'Source title',
                    default => null,
                };
            }
        );

        $engine = RuleEngine::v1();
        $emptyA = $engine->evaluate(
            array(
                RuleFactory::rule(array(
                    'conditions'  => array(),
                    'validations' => array(
                        RuleFactory::validation(array(
                            'field' => AcfCloneFixtures::cloneATitleRef(),
                            'type'  => 'required',
                        )),
                    ),
                )),
            ),
            $provider
        );
        $filledB = $engine->evaluate(
            array(
                RuleFactory::rule(array(
                    'id'          => 2,
                    'conditions'  => array(),
                    'validations' => array(
                        RuleFactory::validation(array(
                            'field' => AcfCloneFixtures::cloneBTitleRef(),
                            'type'  => 'required',
                        )),
                    ),
                )),
            ),
            $provider
        );

        $this->assertTrue($emptyA->results[0]->isFailed());
        $this->assertSame(AcfCloneFixtures::cloneATitlePosted(), $emptyA->results[0]->fieldId);
        $this->assertTrue($filledB->results[0]->isPassed());
        $this->assertSame(AcfCloneFixtures::cloneBTitleId(), $filledB->results[0]->fieldId);
    }

    /**
     * @param callable(string $fieldKey, int $postId): mixed $reader
     */
    private function provider(callable $reader, ?AcfFieldCatalog $catalog = null): AcfStoredValueProvider
    {
        $catalog ??= AcfCloneFixtures::pageCatalog();
        $types     = $catalog->fieldTypesForPostType('page');
        $maps      = $catalog->nestedResolutionMaps('page', $types);

        return new AcfStoredValueProvider(
            42,
            new AcfValueNormalizer(),
            $types,
            $reader,
            $maps['paths'],
            $maps['names'],
            $maps['repeater_keys'],
            $maps['flex_keys'],
            $maps['layouts'],
            $maps['clone_keys']
        );
    }
}
