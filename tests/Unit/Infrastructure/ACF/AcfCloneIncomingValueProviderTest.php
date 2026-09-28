<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Infrastructure\ACF;

use ContentLatch\Domain\FieldRef;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;
use ContentLatch\Infrastructure\ACF\AcfIncomingValueProvider;
use ContentLatch\Infrastructure\ACF\AcfValueNormalizer;
use ContentLatch\Tests\Support\AcfCloneFixtures;
use PHPUnit\Framework\TestCase;

final class AcfCloneIncomingValueProviderTest extends TestCase
{
    public function testSeamlessCloneReadsCompositePostedKey(): void
    {
        $provider = $this->provider(array(
            AcfCloneFixtures::CLONE_A => array(
                AcfCloneFixtures::cloneATitlePosted() => 'Posted clone',
            ),
            AcfCloneFixtures::TITLE => 'Posted source',
        ));

        $this->assertSame('Posted clone', $provider->get(AcfCloneFixtures::cloneATitlePosted()));
        $this->assertSame('Posted source', $provider->get(AcfCloneFixtures::TITLE));
    }

    public function testGroupDisplayCloneReadsOriginalChildKey(): void
    {
        $provider = $this->provider(array(
            AcfCloneFixtures::CLONE_B => array(
                AcfCloneFixtures::TITLE => 'Grouped post',
            ),
        ));

        $this->assertSame('Grouped post', $provider->get(AcfCloneFixtures::cloneBTitleId()));
    }

    public function testEmptyAndMissingCloneValues(): void
    {
        $empty = $this->provider(array(
            AcfCloneFixtures::CLONE_A => array(
                AcfCloneFixtures::cloneATitlePosted() => '',
            ),
        ));
        $this->assertTrue($empty->has(AcfCloneFixtures::cloneATitlePosted()));
        $this->assertNull($empty->get(AcfCloneFixtures::cloneATitlePosted()));

        $missingChild = $this->provider(array(
            AcfCloneFixtures::CLONE_A => array(),
        ));
        $this->assertFalse($missingChild->has(AcfCloneFixtures::cloneATitlePosted()));

        $missingParent = $this->provider(array());
        $this->assertFalse($missingParent->has(AcfCloneFixtures::cloneATitlePosted()));

        $malformed = $this->provider(array(
            AcfCloneFixtures::CLONE_A => 'not-an-array',
        ));
        $this->assertFalse($malformed->has(AcfCloneFixtures::cloneATitlePosted()));
    }

    public function testCloneOfGroupReadsNestedPostedPath(): void
    {
        $provider = $this->provider(array(
            AcfCloneFixtures::CLONE_GROUP => array(
                AcfCloneFixtures::cloneGroupDetailsPosted() => array(
                    AcfCloneFixtures::INGREDIENTS => 'Vinegar',
                ),
            ),
        ));

        $id = FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_GROUP, AcfCloneFixtures::INGREDIENTS);
        $this->assertSame('Vinegar', $provider->get($id));
    }

    public function testRepeaterCloneIgnoresAcfcloneindexAndWalksRows(): void
    {
        $provider = $this->provider(array(
            AcfCloneFixtures::REPEATER => array(
                'row-0' => array(
                    AcfCloneFixtures::CLONE_REP => array(
                        AcfCloneFixtures::cloneRepTitlePosted() => 'First',
                    ),
                ),
                'acfcloneindex' => array(
                    AcfCloneFixtures::CLONE_REP => array(
                        AcfCloneFixtures::cloneRepTitlePosted() => 'Clone row',
                    ),
                ),
            ),
        ));

        $id        = FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_REP, AcfCloneFixtures::TITLE);
        $instances = $provider->instances($id);
        $this->assertCount(1, $instances);
        $this->assertSame('First', $instances[0]->value);
        $this->assertSame(
            'acf[' . AcfCloneFixtures::REPEATER . '][row-0][' . AcfCloneFixtures::CLONE_REP . '][' . AcfCloneFixtures::cloneRepTitlePosted() . ']',
            $instances[0]->context['input_name']
        );
    }

    public function testFlexibleCloneReadsTheMatchingLayoutRow(): void
    {
        $provider = $this->provider(array(
            AcfCloneFixtures::FLEX => array(
                'row-0' => array(
                    'acf_fc_layout' => 'hero',
                    AcfCloneFixtures::CLONE_FLEX => array(
                        AcfCloneFixtures::cloneFlexTitlePosted() => 'Hero posted',
                        AcfCloneFixtures::cloneFlexGroupPosted() => array(
                            AcfCloneFixtures::INGREDIENTS => 'Oil',
                        ),
                    ),
                ),
                'row-1' => array(
                    'acf_fc_layout' => 'cta',
                    AcfCloneFixtures::CLONE_FLEX => array(
                        AcfCloneFixtures::cloneFlexTitlePosted() => 'CTA',
                    ),
                ),
            ),
        ));

        $titleId = FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_FLEX, AcfCloneFixtures::TITLE);
        $ingId   = FieldRef::resolutionIdFor(AcfCloneFixtures::CLONE_FLEX, AcfCloneFixtures::INGREDIENTS);
        $this->assertSame('Hero posted', $provider->instances($titleId)[0]->value);
        $this->assertSame('Oil', $provider->instances($ingId)[0]->value);
    }

    public function testPrefixedNamesDoNotChangePostedKeys(): void
    {
        $provider = $this->provider(array(
            AcfCloneFixtures::CLONE_A => array(
                AcfCloneFixtures::cloneATitlePosted() => 'Still composite',
                'shared_a_title' => 'Name keyed',
            ),
        ));

        $this->assertSame('Still composite', $provider->get(AcfCloneFixtures::cloneATitlePosted()));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function provider(array $payload, ?AcfFieldCatalog $catalog = null): AcfIncomingValueProvider
    {
        $catalog ??= AcfCloneFixtures::pageCatalog();
        $types     = $catalog->fieldTypesForPostType('page');
        $maps      = $catalog->nestedResolutionMaps('page', $types);

        return new AcfIncomingValueProvider(
            $payload,
            new AcfValueNormalizer(),
            $types,
            $maps['paths'],
            $maps['names'],
            $maps['repeater_keys'],
            $maps['flex_keys'],
            $maps['layouts'],
            $maps['clone_keys']
        );
    }
}
