<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Infrastructure\ACF\AcfIncomingValueProvider;
use ContentGuard\Infrastructure\ACF\AcfValueNormalizer;
use PHPUnit\Framework\TestCase;

final class AcfIncomingValueProviderTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private array $fieldTypes = array(
        'field_type'        => 'select',
        'field_ingredients' => 'textarea',
        'field_featured'    => 'true_false',
        'field_count'       => 'number',
    );

    public function testHasAndGetWhenFieldKeyExists(): void
    {
        $provider = $this->provider(
            array(
                'field_type' => 'sauce',
            )
        );

        $this->assertTrue($provider->has('field_type'));
        $this->assertSame('sauce', $provider->get('field_type'));
    }

    public function testMissingAllowedFieldKey(): void
    {
        $provider = $this->provider(array('field_type' => 'sauce'));

        $this->assertFalse($provider->has('field_ingredients'));
        $this->assertNull($provider->get('field_ingredients'));
    }

    public function testUnrelatedFieldKeyInPayloadIsIgnored(): void
    {
        $provider = $this->provider(
            array(
                'field_type'    => 'sauce',
                'field_secret'  => 'admin_email',
                'options_email' => 'root@example.com',
            )
        );

        $this->assertFalse($provider->has('field_secret'));
        $this->assertNull($provider->get('field_secret'));
        $this->assertFalse($provider->has('options_email'));
        $this->assertNull($provider->get('options_email'));
        $this->assertSame('sauce', $provider->get('field_type'));
    }

    public function testMalformedPayloadIsTreatedAsEmpty(): void
    {
        foreach (array(null, 'acf', 123, new \stdClass()) as $payload) {
            $provider = $this->provider($payload);
            $this->assertFalse($provider->has('field_type'));
            $this->assertNull($provider->get('field_type'));
        }
    }

    public function testEmptyValue(): void
    {
        $provider = $this->provider(array('field_ingredients' => ''));

        $this->assertTrue($provider->has('field_ingredients'));
        $this->assertNull($provider->get('field_ingredients'));
    }

    public function testBooleanValue(): void
    {
        $checked = $this->provider(array('field_featured' => '1'));
        $this->assertTrue($checked->get('field_featured'));

        $unchecked = $this->provider(array('field_featured' => '0'));
        $this->assertFalse($unchecked->get('field_featured'));
    }

    public function testScalarValue(): void
    {
        $provider = $this->provider(array('field_count' => '4'));
        $this->assertSame('4', $provider->get('field_count'));
    }

    public function testFieldNameIsNotCanonical(): void
    {
        $provider = $this->provider(array('product_type' => 'sauce'));
        $this->assertFalse($provider->has('product_type'));
        $this->assertNull($provider->get('product_type'));
    }

    private function provider(mixed $payload): AcfIncomingValueProvider
    {
        return new AcfIncomingValueProvider(
            $payload,
            new AcfValueNormalizer(),
            $this->fieldTypes
        );
    }
}
