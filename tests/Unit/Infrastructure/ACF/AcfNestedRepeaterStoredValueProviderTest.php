<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\ACF;

use ContentLatch\Infrastructure\ACF\AcfStoredValueProvider;
use ContentLatch\Infrastructure\ACF\AcfValueNormalizer;
use ContentLatch\Tests\Support\AcfNestedRepeaterFixtures;
use PHPUnit\Framework\TestCase;

final class AcfNestedRepeaterStoredValueProviderTest extends TestCase
{
    public function testStoredNestedRowsExpandToTheSameCartesianSet(): void
    {
        $provider = $this->provider(
            static function (string $key): mixed {
                return $key === AcfNestedRepeaterFixtures::DIRECTIONS
                    ? array(
                        array(
                            AcfNestedRepeaterFixtures::STEPS => array(
                                array(AcfNestedRepeaterFixtures::STEP_NAME => 'Cut chicken'),
                                array(AcfNestedRepeaterFixtures::STEP_NAME => 'Add seasoning'),
                            ),
                        ),
                        array(
                            AcfNestedRepeaterFixtures::STEPS => array(
                                array(AcfNestedRepeaterFixtures::STEP_NAME => 'Grill'),
                                array(AcfNestedRepeaterFixtures::STEP_NAME => 'Rest'),
                            ),
                        ),
                    )
                    : null;
            }
        );

        $instances = $provider->instances(AcfNestedRepeaterFixtures::STEP_NAME);
        $this->assertCount(4, $instances);
        $this->assertSame(array('Cut chicken', 'Add seasoning', 'Grill', 'Rest'), array_map(
            static fn ($instance): mixed => $instance->value,
            $instances
        ));
        $this->assertSame('row-0', $instances[0]->context['repeater_rows'][0]['key']);
        $this->assertSame('row-1', $instances[1]->context['repeater_rows'][1]['key']);
        $this->assertSame('row-1', $instances[2]->context['repeater_rows'][0]['key']);
        $this->assertSame('row-1', $instances[3]->context['repeater_rows'][1]['key']);
        $this->assertArrayNotHasKey('display_row', $instances[0]->context);
        $this->assertSame(
            'acf[' . AcfNestedRepeaterFixtures::DIRECTIONS . '][row-0]['
            . AcfNestedRepeaterFixtures::STEPS . '][row-0]['
            . AcfNestedRepeaterFixtures::STEP_NAME . ']',
            $instances[0]->context['input_name']
        );
    }

    public function testNameKeyedStoredNestedRows(): void
    {
        $provider = $this->provider(
            static function (string $key): mixed {
                return $key === AcfNestedRepeaterFixtures::DIRECTIONS
                    ? array(
                        array(
                            'steps' => array(
                                array('name' => 'Rest'),
                            ),
                        ),
                    )
                    : null;
            }
        );

        $instances = $provider->instances(AcfNestedRepeaterFixtures::STEP_NAME);
        $this->assertCount(1, $instances);
        $this->assertSame('Rest', $instances[0]->value);
    }

    public function testEmptyOuterStoredRepeaterYieldsNoInstances(): void
    {
        foreach (array(array(), '', null) as $empty) {
            $provider = $this->provider(
                static function (string $key) use ($empty): mixed {
                    return $key === AcfNestedRepeaterFixtures::DIRECTIONS ? $empty : null;
                }
            );
            $this->assertSame(array(), $provider->instances(AcfNestedRepeaterFixtures::STEP_NAME));
        }
    }

    public function testMixedEmptyInnerStoredRowsOnlyEmitExistingCells(): void
    {
        $provider = $this->provider(
            static function (string $key): mixed {
                return $key === AcfNestedRepeaterFixtures::DIRECTIONS
                    ? array(
                        array(AcfNestedRepeaterFixtures::STEPS => array()),
                        array(
                            AcfNestedRepeaterFixtures::STEPS => array(
                                array(AcfNestedRepeaterFixtures::STEP_NAME => 'Grill'),
                            ),
                        ),
                    )
                    : null;
            }
        );

        $instances = $provider->instances(AcfNestedRepeaterFixtures::STEP_NAME);
        $this->assertCount(1, $instances);
        $this->assertSame('Grill', $instances[0]->value);
        $this->assertSame(2, $instances[0]->context['repeater_rows'][0]['display_row']);
    }

    /**
     * @param callable(string $fieldKey, int $postId): mixed $reader
     */
    private function provider(callable $reader): AcfStoredValueProvider
    {
        return new AcfStoredValueProvider(
            12325,
            new AcfValueNormalizer(),
            array(AcfNestedRepeaterFixtures::STEP_NAME => 'text'),
            $reader,
            array(AcfNestedRepeaterFixtures::STEP_NAME => AcfNestedRepeaterFixtures::path()),
            array(AcfNestedRepeaterFixtures::STEP_NAME => AcfNestedRepeaterFixtures::pathNames()),
            array(AcfNestedRepeaterFixtures::STEP_NAME => AcfNestedRepeaterFixtures::DIRECTIONS),
            array(),
            array(),
            array(),
            array(AcfNestedRepeaterFixtures::STEP_NAME => AcfNestedRepeaterFixtures::chain())
        );
    }
}
