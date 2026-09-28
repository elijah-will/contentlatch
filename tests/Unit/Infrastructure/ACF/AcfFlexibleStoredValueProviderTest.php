<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\ACF;

use ContentLatch\Infrastructure\ACF\AcfStoredValueProvider;
use ContentLatch\Infrastructure\ACF\AcfValueNormalizer;
use ContentLatch\Tests\Support\AcfFlexibleFixtures;
use PHPUnit\Framework\TestCase;

final class AcfFlexibleStoredValueProviderTest extends TestCase
{
    public function testOnlyMatchingLayoutRowsAreInstantiated(): void
    {
        $provider = $this->heroProvider(
            static function (string $key): mixed {
                return $key === AcfFlexibleFixtures::MODULES
                    ? array(
                        array(
                            'acf_fc_layout' => 'hero',
                            AcfFlexibleFixtures::HERO_TITLE => 'Welcome',
                        ),
                        array(
                            'acf_fc_layout' => 'cta',
                            AcfFlexibleFixtures::CTA_TITLE => '',
                        ),
                        array(
                            'acf_fc_layout' => 'hero',
                            AcfFlexibleFixtures::HERO_TITLE => '',
                        ),
                    )
                    : 'should-not-read-leaf';
            }
        );

        $instances = $provider->instances(AcfFlexibleFixtures::HERO_TITLE);
        $this->assertCount(2, $instances);
        $this->assertSame('Welcome', $instances[0]->value);
        $this->assertNull($instances[1]->value);
        $this->assertSame('hero', $instances[0]->context['layout']);
        $this->assertSame('row-0', $instances[0]->context['row_key']);
        $this->assertSame(0, $instances[0]->context['row_index']);
        $this->assertSame(1, $instances[0]->context['display_row']);
        $this->assertSame('row-2', $instances[1]->context['row_key']);
        $this->assertSame(2, $instances[1]->context['row_index']);
        $this->assertSame(3, $instances[1]->context['display_row']);
        $this->assertSame(
            'acf[' . AcfFlexibleFixtures::MODULES . '][row-2][' . AcfFlexibleFixtures::HERO_TITLE . ']',
            $instances[1]->context['input_name']
        );
    }

    public function testMissingAndEmptyChildrenAndAcfeKeysAreIgnored(): void
    {
        $provider = $this->heroProvider(
            static function (string $key): mixed {
                return $key === AcfFlexibleFixtures::MODULES
                    ? array(
                        array(
                            'acf_fc_layout' => 'hero',
                            '_acfe_flexible_toggle' => 1,
                        ),
                        array(
                            'acf_fc_layout' => 'hero',
                            AcfFlexibleFixtures::HERO_TITLE => '',
                            '_acfe_flexible_toggle' => null,
                        ),
                    )
                    : null;
            }
        );

        $instances = $provider->instances(AcfFlexibleFixtures::HERO_TITLE);
        $this->assertCount(2, $instances);
        $this->assertNull($instances[0]->value);
        $this->assertNull($instances[1]->value);
    }

    public function testZeroMatchingLayoutsReturnNoInstances(): void
    {
        $provider = $this->heroProvider(
            static function (string $key): mixed {
                return $key === AcfFlexibleFixtures::MODULES
                    ? array(
                        array(
                            'acf_fc_layout' => 'cta',
                            AcfFlexibleFixtures::CTA_TITLE => '',
                        ),
                    )
                    : null;
            }
        );

        $this->assertSame(array(), $provider->instances(AcfFlexibleFixtures::HERO_TITLE));
    }

    public function testFlexibleGroupChildWalksTrustedPath(): void
    {
        $provider = $this->headlineProvider(
            static function (string $key): mixed {
                return $key === AcfFlexibleFixtures::MODULES
                    ? array(
                        array(
                            'acf_fc_layout' => 'content_block',
                            AcfFlexibleFixtures::CONTENT_BLOCK_1 => array(
                                AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE => 'About Dash',
                            ),
                        ),
                    )
                    : null;
            }
        );

        $instances = $provider->instances(AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE);
        $this->assertCount(1, $instances);
        $this->assertSame('About Dash', $instances[0]->value);
        $this->assertSame(
            'acf[' . AcfFlexibleFixtures::MODULES . '][row-0]['
            . AcfFlexibleFixtures::CONTENT_BLOCK_1 . ']['
            . AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE . ']',
            $instances[0]->context['input_name']
        );
    }

    /**
     * @param callable(string $fieldKey, int $postId): mixed $reader
     */
    private function heroProvider(callable $reader): AcfStoredValueProvider
    {
        return new AcfStoredValueProvider(
            5,
            new AcfValueNormalizer(),
            array(AcfFlexibleFixtures::HERO_TITLE => 'text'),
            $reader,
            AcfFlexibleFixtures::heroTitlePaths(),
            AcfFlexibleFixtures::heroTitleNames(),
            array(),
            AcfFlexibleFixtures::heroTitleFlexKeys(),
            AcfFlexibleFixtures::heroTitleLayouts()
        );
    }

    /**
     * @param callable(string $fieldKey, int $postId): mixed $reader
     */
    private function headlineProvider(callable $reader): AcfStoredValueProvider
    {
        return new AcfStoredValueProvider(
            5,
            new AcfValueNormalizer(),
            array(AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE => 'text'),
            $reader,
            array(
                AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE => array(
                    AcfFlexibleFixtures::MODULES,
                    AcfFlexibleFixtures::CONTENT_BLOCK_1,
                    AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE,
                ),
            ),
            array(
                AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE => array(
                    'modules',
                    'content_block_1',
                    'headline',
                ),
            ),
            array(),
            array(AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE => AcfFlexibleFixtures::MODULES),
            array(AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE => 'content_block')
        );
    }
}
