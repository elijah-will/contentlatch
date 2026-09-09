<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\WordPress;

use ContentGuard\Infrastructure\WordPress\CoreIncomingPayload;
use PHPUnit\Framework\TestCase;

final class CoreIncomingPayloadTest extends TestCase
{
    public function testGutenbergPartialUpdateDoesNotInventOmittedCoreFields(): void
    {
        $markup   = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->';
        $prepared = (object) array(
            'ID'           => 13214,
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_content' => $markup,
        );
        $request = new class ($markup) implements \ArrayAccess {
            public function __construct(private string $markup)
            {
            }

            public function get_json_params(): array
            {
                return array(
                    'id'      => 13214,
                    'status'  => 'publish',
                    'content' => array(
                        'raw'           => $this->markup,
                        'rendered'      => '<p>Hello world</p>',
                        'protected'     => false,
                        'block_version' => 1,
                    ),
                );
            }

            public function offsetExists(mixed $offset): bool
            {
                return in_array($offset, array('id', 'status', 'content'), true);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->get_json_params()[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
            }

            public function offsetUnset(mixed $offset): void
            {
            }
        };

        $payload = CoreIncomingPayload::fromPreparedPost($prepared, $request);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('post_content', $payload);
        $this->assertArrayHasKey('content', $payload);
        $this->assertSame($markup, $payload['post_content']);
        $this->assertSame('<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->', CoreIncomingPayload::unwrapRestValue($payload['content']));
        $this->assertArrayNotHasKey('title', $payload);
        $this->assertArrayNotHasKey('post_title', $payload);
        $this->assertArrayNotHasKey('featured_media', $payload);
        $this->assertArrayNotHasKey('featured_image', $payload);
        $this->assertArrayNotHasKey('author', $payload);
        $this->assertArrayNotHasKey('slug', $payload);
        $this->assertNull($request['featured_media'] ?? null);
        $this->assertArrayNotHasKey('featured_media', $request->get_json_params());
        $this->assertArrayNotHasKey('title', $request->get_json_params());
    }

    public function testExplicitFeaturedMediaZeroIsSubmitted(): void
    {
        $payload = CoreIncomingPayload::fromPreparedPost(
            (object) array(
                'ID'        => 13214,
                'post_type' => 'post',
            ),
            array('featured_media' => 0)
        );

        $this->assertSame(array('featured_media' => 0), $payload);
    }

    public function testJsonParamsFeaturedMediaIsPreferredOverMissingArrayAccess(): void
    {
        $request = new class implements \ArrayAccess {
            public function get_json_params(): array
            {
                return array('featured_media' => 123);
            }

            public function offsetExists(mixed $offset): bool
            {
                return false;
            }

            public function offsetGet(mixed $offset): mixed
            {
                return null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
            }

            public function offsetUnset(mixed $offset): void
            {
            }
        };

        $payload = CoreIncomingPayload::fromPreparedPost((object) array('ID' => 1), $request);

        $this->assertSame(123, $payload['featured_media']);
    }
}
