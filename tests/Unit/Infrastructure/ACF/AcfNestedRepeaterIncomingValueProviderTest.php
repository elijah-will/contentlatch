<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Infrastructure\ACF\AcfIncomingValueProvider;
use ContentGuard\Infrastructure\ACF\AcfValueNormalizer;
use ContentGuard\Tests\Support\AcfNestedRepeaterFixtures;
use PHPUnit\Framework\TestCase;

final class AcfNestedRepeaterIncomingValueProviderTest extends TestCase
{
    public function testTwoByTwoPostedRowsProduceFourInstances(): void
    {
        $provider = $this->provider(array(
            AcfNestedRepeaterFixtures::DIRECTIONS => array(
                'row-0' => array(
                    AcfNestedRepeaterFixtures::STEPS => array(
                        'row-0' => array(AcfNestedRepeaterFixtures::STEP_NAME => 'Cut chicken'),
                        'row-1' => array(AcfNestedRepeaterFixtures::STEP_NAME => 'Add seasoning'),
                    ),
                ),
                'row-1' => array(
                    AcfNestedRepeaterFixtures::STEPS => array(
                        'row-0' => array(AcfNestedRepeaterFixtures::STEP_NAME => 'Grill'),
                        'row-1' => array(AcfNestedRepeaterFixtures::STEP_NAME => 'Rest'),
                    ),
                ),
            ),
        ));

        $instances = $provider->instances(AcfNestedRepeaterFixtures::STEP_NAME);
        $this->assertCount(4, $instances);
        $this->assertSame(array('Cut chicken', 'Add seasoning', 'Grill', 'Rest'), array_map(
            static fn ($instance): mixed => $instance->value,
            $instances
        ));
        $this->assertSame(
            array(
                array(1, 1),
                array(1, 2),
                array(2, 1),
                array(2, 2),
            ),
            array_map(
                static fn ($instance): array => array(
                    $instance->context['repeater_rows'][0]['display_row'],
                    $instance->context['repeater_rows'][1]['display_row'],
                ),
                $instances
            )
        );
        $this->assertArrayNotHasKey('display_row', $instances[0]->context);
        $this->assertSame(
            'acf[' . AcfNestedRepeaterFixtures::DIRECTIONS . '][row-0]['
            . AcfNestedRepeaterFixtures::STEPS . '][row-1]['
            . AcfNestedRepeaterFixtures::STEP_NAME . ']',
            $instances[1]->context['input_name']
        );
    }

    public function testEmptyOuterRepeaterYieldsNoInstances(): void
    {
        $this->assertSame(array(), $this->provider(array())->instances(AcfNestedRepeaterFixtures::STEP_NAME));
        $this->assertSame(
            array(),
            $this->provider(array(AcfNestedRepeaterFixtures::DIRECTIONS => array()))
                ->instances(AcfNestedRepeaterFixtures::STEP_NAME)
        );
    }

    public function testEmptyInnerRepeaterDoesNotBecomeAScalarOrFakeRow(): void
    {
        $provider = $this->provider(array(
            AcfNestedRepeaterFixtures::DIRECTIONS => array(
                'row-0' => array(
                    AcfNestedRepeaterFixtures::STEPS => array(),
                ),
                'row-1' => array(
                    AcfNestedRepeaterFixtures::STEPS => array(
                        'row-0' => array(AcfNestedRepeaterFixtures::STEP_NAME => 'Grill'),
                    ),
                ),
            ),
        ));

        $instances = $provider->instances(AcfNestedRepeaterFixtures::STEP_NAME);
        $this->assertCount(1, $instances);
        $this->assertSame('Grill', $instances[0]->value);
        $this->assertSame(2, $instances[0]->context['repeater_rows'][0]['display_row']);
        $this->assertSame(1, $instances[0]->context['repeater_rows'][1]['display_row']);
    }

    public function testMissingInnerRepeaterIsSkippedSafely(): void
    {
        $provider = $this->provider(array(
            AcfNestedRepeaterFixtures::DIRECTIONS => array(
                'row-0' => array(),
                'row-1' => array(
                    AcfNestedRepeaterFixtures::STEPS => '0',
                ),
            ),
        ));

        $this->assertSame(array(), $provider->instances(AcfNestedRepeaterFixtures::STEP_NAME));
    }

    public function testAllEmptyInnersYieldNoInstances(): void
    {
        $provider = $this->provider(array(
            AcfNestedRepeaterFixtures::DIRECTIONS => array(
                'row-0' => array(AcfNestedRepeaterFixtures::STEPS => array()),
                'row-1' => array(AcfNestedRepeaterFixtures::STEPS => array()),
            ),
        ));

        $this->assertSame(array(), $provider->instances(AcfNestedRepeaterFixtures::STEP_NAME));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function provider(array $payload): AcfIncomingValueProvider
    {
        return new AcfIncomingValueProvider(
            $payload,
            new AcfValueNormalizer(),
            array(AcfNestedRepeaterFixtures::STEP_NAME => 'text'),
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
