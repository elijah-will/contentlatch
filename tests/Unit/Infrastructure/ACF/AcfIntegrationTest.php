<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Application\Integration\CompositeFieldCatalog;
use ContentGuard\Application\Integration\CompositeValueProvider;
use ContentGuard\Application\Integration\Integration;
use ContentGuard\Application\RuleDocumentFactory;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Dependencies;
use ContentGuard\Domain\FieldRef;
use ContentGuard\Infrastructure\ACF\AcfIntegration;
use ContentGuard\Infrastructure\ACF\AcfStoredValueProvider;
use ContentGuard\Infrastructure\ACF\AcfValueNormalizer;
use ContentGuard\Tests\Support\AcfCloneFixtures;
use ContentGuard\Tests\Support\AcfFlexibleFixtures;
use ContentGuard\Tests\Support\AcfRepeaterFixtures;
use PHPUnit\Framework\TestCase;

final class AcfIntegrationTest extends TestCase
{
    public function testDescriptorIdentifiesAcfAndRespectsAvailability(): void
    {
        $catalog = AcfCloneFixtures::pageCatalog();
        $available = new AcfIntegration($catalog, true);
        $missing   = new AcfIntegration($catalog, false);

        $this->assertSame(Integration::ACF, $available->descriptor()->id);
        $this->assertSame('ACF', $available->descriptor()->label);
        $this->assertTrue($available->isAvailable());
        $this->assertTrue($available->descriptor()->available);
        $this->assertFalse($missing->isAvailable());
        $this->assertFalse($missing->descriptor()->available);
    }

    public function testWordpressAvailabilityUsesTheExistingAcfMinimum(): void
    {
        $integration = AcfIntegration::wordpress(new Dependencies());
        $this->assertSame(defined('ACF_VERSION') && version_compare((string) ACF_VERSION, '6.0.0', '>='), $integration->isAvailable());
        $this->assertSame((new Dependencies())->acfMeetsMinimum(), $integration->isAvailable());
    }

    public function testCompositeCatalogWithOnlyAcfMatchesNativeCatalogArrays(): void
    {
        $native    = AcfCloneFixtures::pageCatalog();
        $acf       = new AcfIntegration($native);
        $composite = new CompositeFieldCatalog(array($acf));

        $expected = array();
        foreach ($native->fieldsForPostType('page') as $field) {
            $expected[] = array_merge($field->toCatalogArray(), array(
                'integration' => Integration::ACF,
            ));
        }

        $this->assertSame($expected, $acf->fieldsForPostType('page'));
        $this->assertSame($expected, $composite->fieldsForPostType('page'));
        $this->assertSame($native->fieldTypesForPostType('page'), $composite->fieldTypesForPostType('page'));
    }

    public function testCloneResolutionIdsStayUnprefixed(): void
    {
        $composite = new CompositeFieldCatalog(array(new AcfIntegration(AcfCloneFixtures::pageCatalog())));
        $byId      = array();
        foreach ($composite->fieldsForPostType('page') as $field) {
            $id = (string) ($field['resolution_id'] ?? $field['key'] ?? '');
            $byId[$id] = $field;
        }

        $cloneId = AcfCloneFixtures::cloneATitlePosted();
        $this->assertArrayHasKey($cloneId, $byId);
        $this->assertSame(AcfCloneFixtures::TITLE, $byId[$cloneId]['key']);
        $this->assertSame(AcfCloneFixtures::CLONE_A, $byId[$cloneId]['clone']);
        $this->assertSame($cloneId, $byId[$cloneId]['resolution_id']);
        $this->assertStringStartsWith('field_', $cloneId);
        $this->assertStringNotContainsString('acf:', $cloneId);
        $this->assertSame(Integration::ACF, $byId[$cloneId]['integration']);
        $this->assertSame('Shared Content → Title', $byId[$cloneId]['breadcrumb']);
    }

    public function testGroupRepeaterAndFlexibleCatalogEntriesStayUnchanged(): void
    {
        $repeater = new CompositeFieldCatalog(array(new AcfIntegration(AcfRepeaterFixtures::productCatalog())));
        $flex     = new CompositeFieldCatalog(array(new AcfIntegration(AcfFlexibleFixtures::pageCatalog())));

        $size = $this->fieldByResolutionId($repeater->fieldsForPostType('product'), AcfRepeaterFixtures::PRODUCT_SIZE);
        $this->assertSame('Product Information → Item Size → Product Size', $size['breadcrumb']);
        $this->assertSame('repeater', $size['container']);
        $this->assertSame(Integration::ACF, $size['integration']);

        $hero = $this->fieldByResolutionId($flex->fieldsForPostType('page'), AcfFlexibleFixtures::HERO_TITLE);
        $this->assertSame('Modules → Hero → Title', $hero['breadcrumb']);
        $this->assertSame('flexible_content', $hero['container']);
        $this->assertSame('hero', $hero['layout']);
        $this->assertSame(Integration::ACF, $hero['integration']);
    }

    public function testCompositeStoredProviderMatchesNativeAcfValues(): void
    {
        $native = AcfCloneFixtures::pageCatalog();
        $acf    = new AcfIntegration($native);
        $types  = $native->fieldTypesForPostType('page');
        $reader = static function (string $key): mixed {
            return match ($key) {
                AcfCloneFixtures::CLONE_A => array(
                    AcfCloneFixtures::cloneATitlePosted() => 'Cloned title',
                ),
                AcfCloneFixtures::TITLE => 'Source title',
                default => null,
            };
        };

        $maps = $native->nestedResolutionMaps('page', $types);
        $direct = new AcfStoredValueProvider(
            9,
            new AcfValueNormalizer(),
            $types,
            $reader,
            $maps['paths'],
            $maps['names'],
            $maps['repeater_keys'],
            $maps['flex_keys'] ?? array(),
            $maps['layouts'] ?? array(),
            $maps['clone_keys'] ?? array()
        );
        $composite = new CompositeValueProvider(array(
            $acf->storedProvider(9, 'page', $types, $reader),
        ));

        $cloneId = AcfCloneFixtures::cloneATitlePosted();
        $this->assertSame($direct->has($cloneId), $composite->has($cloneId));
        $this->assertSame($direct->get($cloneId), $composite->get($cloneId));
        $this->assertSame('Cloned title', $composite->get($cloneId));
        $this->assertSame($direct->get(AcfCloneFixtures::TITLE), $composite->get(AcfCloneFixtures::TITLE));
        $this->assertSame('Source title', $composite->get(AcfCloneFixtures::TITLE));
        $this->assertEquals($direct->instances($cloneId), $composite->instances($cloneId));
    }

    public function testIncomingProviderExposesSubmittedAcfValues(): void
    {
        $native = AcfCloneFixtures::pageCatalog();
        $acf    = new AcfIntegration($native);
        $types  = $native->fieldTypesForPostType('page');
        $cloneId = AcfCloneFixtures::cloneATitlePosted();

        $provider = $acf->incomingProvider(
            array(
                AcfCloneFixtures::CLONE_A => array(
                    $cloneId => 'Incoming clone title',
                ),
            ),
            'page',
            $types
        );

        $this->assertTrue($provider->has($cloneId));
        $this->assertSame('Incoming clone title', $provider->get($cloneId));
        $this->assertFalse($provider->has('title'));
    }

    public function testPersistedAcfFieldRefsDoNotGainAnIntegrationProperty(): void
    {
        $ref = AcfCloneFixtures::cloneATitleRef();
        $data = $ref->toArray();

        $this->assertArrayNotHasKey('integration', $data);
        $this->assertSame(AcfCloneFixtures::TITLE, $data['key']);
        $this->assertSame(AcfCloneFixtures::CLONE_A, $data['clone']);
        $this->assertSame(AcfCloneFixtures::cloneATitlePosted(), $ref->resolutionId());
        $this->assertSame($ref->resolutionId(), FieldRef::fromArray($data)->resolutionId());
    }

    public function testRuleDocumentsBuiltThroughTheCompositeCatalogStaySchemaVersionOne(): void
    {
        $composite = new CompositeFieldCatalog(array(new AcfIntegration(AcfCloneFixtures::pageCatalog())));
        $factory = RuleDocumentFactory::v1(
            static fn (): array => array('page' => 'Page'),
            static fn (string $postType): array => $composite->fieldsForPostType($postType)
        );

        $rule = $factory->fromAdminInput(array(
            'name'        => 'Shared title required',
            'post_type'   => 'page',
            'status'      => 'active',
            'severity'    => 'fail',
            'validations' => array(
                array(
                    'field_key' => AcfCloneFixtures::cloneATitlePosted(),
                    'type'      => 'required',
                ),
            ),
        ));

        $encoded = $rule->toArray();
        $this->assertSame(1, $encoded['schema_version']);
        $this->assertArrayNotHasKey('integration', $encoded['validations'][0]['field']);
        $this->assertSame(AcfCloneFixtures::TITLE, $encoded['validations'][0]['field']['key']);
        $this->assertSame(AcfCloneFixtures::CLONE_A, $encoded['validations'][0]['field']['clone']);

        $loaded = RuleDocumentValidator::v1()->validateArray($encoded);
        $this->assertSame($rule->validations[0]->field->resolutionId(), $loaded->validations[0]->field->resolutionId());
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function fieldByResolutionId(array $fields, string $id): array
    {
        foreach ($fields as $field) {
            $fieldId = (string) ($field['resolution_id'] ?? $field['key'] ?? '');
            if ($fieldId === $id) {
                return $field;
            }
        }

        $this->fail('Field ' . $id . ' was not in the catalog.');
    }
}
