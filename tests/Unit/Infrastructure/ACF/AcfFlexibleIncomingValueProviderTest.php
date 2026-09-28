<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\ACF;

use ContentLatch\Infrastructure\ACF\AcfIncomingValueProvider;
use ContentLatch\Infrastructure\ACF\AcfValueNormalizer;
use ContentLatch\Tests\Support\AcfFlexibleFixtures;
use PHPUnit\Framework\TestCase;

final class AcfFlexibleIncomingValueProviderTest extends TestCase
{
    public function testExistingDynamicAndCloneRows(): void
    {
        $provider = $this->heroProvider(array(
            AcfFlexibleFixtures::MODULES => array(
                'row-0' => array(
                    'acf_fc_layout' => 'hero',
                    AcfFlexibleFixtures::HERO_TITLE => 'Welcome',
                ),
                '67a1b2c3d4e5f' => array(
                    'acf_fc_layout' => 'hero',
                    AcfFlexibleFixtures::HERO_TITLE => '',
                ),
                'acfcloneindex' => array(
                    'acf_fc_layout' => 'hero',
                    AcfFlexibleFixtures::HERO_TITLE => 'Clone',
                ),
            ),
        ));

        $instances = $provider->instances(AcfFlexibleFixtures::HERO_TITLE);
        $this->assertCount(2, $instances);
        $this->assertSame('Welcome', $instances[0]->value);
        $this->assertNull($instances[1]->value);
        $this->assertSame('row-0', $instances[0]->context['row_key']);
        $this->assertSame('67a1b2c3d4e5f', $instances[1]->context['row_key']);
        $this->assertSame(
            'acf[' . AcfFlexibleFixtures::MODULES . '][67a1b2c3d4e5f][' . AcfFlexibleFixtures::HERO_TITLE . ']',
            $instances[1]->context['input_name']
        );
    }

    public function testDisabledAndNonMatchingLayoutsAreSkipped(): void
    {
        $provider = $this->heroProvider(array(
            AcfFlexibleFixtures::MODULES => array(
                'row-0' => array(
                    'acf_fc_layout' => 'hero',
                    'acf_fc_layout_disabled' => '1',
                    AcfFlexibleFixtures::HERO_TITLE => '',
                ),
                'row-1' => array(
                    'acf_fc_layout' => 'cta',
                    AcfFlexibleFixtures::CTA_TITLE => '',
                ),
                'row-2' => array(
                    'acf_fc_layout' => 'hero',
                    AcfFlexibleFixtures::HERO_TITLE => 'Keep',
                ),
            ),
        ));

        $instances = $provider->instances(AcfFlexibleFixtures::HERO_TITLE);
        $this->assertCount(1, $instances);
        $this->assertSame('Keep', $instances[0]->value);
        $this->assertSame(3, $instances[0]->context['display_row']);
        $this->assertSame(
            'acf[' . AcfFlexibleFixtures::MODULES . '][row-2][' . AcfFlexibleFixtures::HERO_TITLE . ']',
            $instances[0]->context['input_name']
        );
    }

    public function testFlexibleGroupIncomingInputName(): void
    {
        $provider = new AcfIncomingValueProvider(
            array(
                AcfFlexibleFixtures::MODULES => array(
                    'row-5' => array(
                        'acf_fc_layout' => 'content_block',
                        AcfFlexibleFixtures::CONTENT_BLOCK_1 => array(
                            AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE => '',
                        ),
                    ),
                ),
            ),
            new AcfValueNormalizer(),
            array(AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE => 'text'),
            array(
                AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE => array(
                    AcfFlexibleFixtures::MODULES,
                    AcfFlexibleFixtures::CONTENT_BLOCK_1,
                    AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE,
                ),
            ),
            array(),
            array(),
            array(AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE => AcfFlexibleFixtures::MODULES),
            array(AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE => 'content_block')
        );

        $instances = $provider->instances(AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE);
        $this->assertCount(1, $instances);
        $this->assertSame(
            'acf[' . AcfFlexibleFixtures::MODULES . '][row-5]['
            . AcfFlexibleFixtures::CONTENT_BLOCK_1 . ']['
            . AcfFlexibleFixtures::CONTENT_BLOCK_HEADLINE . ']',
            $instances[0]->context['input_name']
        );
    }

    public function testPostedLayoutDoesNotOverrideTrustedCatalogLayout(): void
    {
        $provider = $this->heroProvider(array(
            AcfFlexibleFixtures::MODULES => array(
                'row-0' => array(
                    'acf_fc_layout' => 'cta',
                    AcfFlexibleFixtures::HERO_TITLE => '',
                ),
            ),
        ));

        $this->assertSame(array(), $provider->instances(AcfFlexibleFixtures::HERO_TITLE));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function heroProvider(array $payload): AcfIncomingValueProvider
    {
        return new AcfIncomingValueProvider(
            $payload,
            new AcfValueNormalizer(),
            array(AcfFlexibleFixtures::HERO_TITLE => 'text'),
            AcfFlexibleFixtures::heroTitlePaths(),
            AcfFlexibleFixtures::heroTitleNames(),
            array(),
            AcfFlexibleFixtures::heroTitleFlexKeys(),
            AcfFlexibleFixtures::heroTitleLayouts()
        );
    }
}
