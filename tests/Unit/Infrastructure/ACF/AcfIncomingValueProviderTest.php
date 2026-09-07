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

    public function testGroupChildUsesNestedPayload(): void
    {
        $this->fieldTypes['field_ingredients'] = 'textarea';
        $provider = $this->provider(
            array(
                'field_product_details' => array(
                    'field_ingredients' => 'Salt, tomatoes',
                ),
            ),
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            )
        );

        $this->assertTrue($provider->has('field_ingredients'));
        $this->assertSame('Salt, tomatoes', $provider->get('field_ingredients'));
    }

    public function testNestedGroupChildUsesDeepPayload(): void
    {
        $this->fieldTypes['field_calories'] = 'number';
        $provider = $this->provider(
            array(
                'field_product_details' => array(
                    'field_nutrition' => array(
                        'field_calories' => '120',
                    ),
                ),
            ),
            array(
                'field_calories' => array('field_product_details', 'field_nutrition', 'field_calories'),
            )
        );

        $this->assertTrue($provider->has('field_calories'));
        $this->assertSame('120', $provider->get('field_calories'));
    }

    public function testMissingParentIsAbsent(): void
    {
        $provider = $this->provider(
            array(),
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            )
        );

        $this->assertFalse($provider->has('field_ingredients'));
        $this->assertNull($provider->get('field_ingredients'));
    }

    public function testMissingChildIsAbsent(): void
    {
        $provider = $this->provider(
            array(
                'field_product_details' => array(),
            ),
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            )
        );

        $this->assertFalse($provider->has('field_ingredients'));
        $this->assertNull($provider->get('field_ingredients'));
    }

    public function testEmptyNestedScalarNormalizesAsNull(): void
    {
        $provider = $this->provider(
            array(
                'field_product_details' => array(
                    'field_ingredients' => '',
                ),
            ),
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            )
        );

        $this->assertTrue($provider->has('field_ingredients'));
        $this->assertNull($provider->get('field_ingredients'));
    }

    public function testGroupChildCanResolveNameKeyedPayload(): void
    {
        $provider = $this->provider(
            array(
                'field_product_details' => array(
                    'item_ingredients' => 'Salt, tomatoes',
                ),
            ),
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            ),
            array(
                'field_ingredients' => array('product_information', 'item_ingredients'),
            )
        );

        $this->assertTrue($provider->has('field_ingredients'));
        $this->assertSame('Salt, tomatoes', $provider->get('field_ingredients'));
    }

    public function testNonArrayParentIsAbsent(): void
    {
        $provider = $this->provider(
            array(
                'field_product_details' => 'not-a-group',
            ),
            array(
                'field_ingredients' => array('field_product_details', 'field_ingredients'),
            )
        );

        $this->assertFalse($provider->has('field_ingredients'));
        $this->assertNull($provider->get('field_ingredients'));
    }

    /**
     * @param array<string, list<string>> $fieldPaths
     * @param array<string, list<string>> $fieldPathNames
     */
    private function provider(
        mixed $payload,
        array $fieldPaths = array(),
        array $fieldPathNames = array(),
    ): AcfIncomingValueProvider {
        return new AcfIncomingValueProvider(
            $payload,
            new AcfValueNormalizer(),
            $this->fieldTypes,
            $fieldPaths,
            $fieldPathNames
        );
    }
}
