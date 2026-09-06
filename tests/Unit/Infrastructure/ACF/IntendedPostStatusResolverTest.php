<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Infrastructure\ACF;

use ContentGuard\Infrastructure\ACF\IntendedPostStatusResolver;
use PHPUnit\Framework\TestCase;

final class IntendedPostStatusResolverTest extends TestCase
{
    private IntendedPostStatusResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new IntendedPostStatusResolver();
    }

    public function testPublishButtonProducesPublishEvenWhenHiddenStatusIsAutoDraft(): void
    {
        $this->assertSame(
            'publish',
            $this->resolver->resolve(
                array(
                    'post_status' => 'auto-draft',
                    'publish'     => 'Publish',
                )
            )
        );
    }

    public function testUpdateOfPublishedPostUsesHiddenPublishStatus(): void
    {
        $this->assertSame(
            'publish',
            $this->resolver->resolve(
                array(
                    'post_status'          => 'publish',
                    'original_post_status' => 'publish',
                    'save'                 => 'Update',
                )
            )
        );
    }

    public function testSwitchToDraftIsDraftEvenIfTheStoredPostIsPublished(): void
    {
        $this->assertSame(
            'draft',
            $this->resolver->resolve(
                array(
                    'post_status'          => 'draft',
                    'original_post_status' => 'publish',
                    'save'                 => 'Save Draft',
                )
            )
        );
    }

    public function testPrivateButtonProducesPrivate(): void
    {
        $this->assertSame(
            'private',
            $this->resolver->resolve(
                array(
                    'post_status' => 'draft',
                    'private'     => 'Private',
                )
            )
        );
    }

    public function testVisibilityPrivateProducesPrivate(): void
    {
        $this->assertSame(
            'private',
            $this->resolver->resolve(
                array(
                    'post_status' => 'publish',
                    'visibility'  => 'private',
                    'save'        => 'Update',
                )
            )
        );
    }

    public function testPublishButtonOnPrivatePostStaysPrivate(): void
    {
        $this->assertSame(
            'private',
            $this->resolver->resolve(
                array(
                    'post_status' => 'private',
                    'publish'     => 'Publish',
                )
            )
        );
    }

    public function testPendingAndFutureAreNotBlocking(): void
    {
        $this->assertSame('pending', $this->resolver->resolve(array('post_status' => 'pending')));
        $this->assertSame('future', $this->resolver->resolve(array('post_status' => 'future')));
        $this->assertFalse($this->resolver->isBlockingStatus('pending'));
        $this->assertFalse($this->resolver->isBlockingStatus('future'));
        $this->assertFalse($this->resolver->isBlockingStatus('draft'));
        $this->assertTrue($this->resolver->isBlockingStatus('publish'));
        $this->assertTrue($this->resolver->isBlockingStatus('private'));
    }

    public function testMissingStatusDefaultsToDraft(): void
    {
        $this->assertSame('draft', $this->resolver->resolve(array()));
    }

    public function testClassicEditorAcfAjaxPublishOfDraftIsPublish(): void
    {
        $this->assertSame(
            'publish',
            $this->resolver->resolve(
                array(
                    'action'               => 'acf/validate_save_post',
                    'post_ID'              => 42,
                    'post_type'            => 'recipe',
                    'post_status'          => 'draft',
                    'original_post_status' => 'draft',
                    '_acf_screen'          => 'post',
                    '_acf_post_id'         => '42',
                )
            )
        );
    }

    public function testClassicEditorAcfAjaxPublishOfAutoDraftIsPublish(): void
    {
        $this->assertSame(
            'publish',
            $this->resolver->resolve(
                array(
                    'action'      => 'acf/validate_save_post',
                    'post_status' => 'auto-draft',
                    'post_type'   => 'recipe',
                )
            )
        );
    }

    public function testGutenbergAcfAjaxPublishWithoutPostStatusIsPublish(): void
    {
        $this->assertSame(
            'publish',
            $this->resolver->resolve(
                array(
                    'action'               => 'acf/validate_save_post',
                    'post_ID'              => 42,
                    'post_type'            => 'recipe',
                    'original_post_status' => 'auto-draft',
                    '_acf_screen'          => 'post',
                    '_acf_post_id'         => '42',
                )
            )
        );
    }

    public function testSaveDraftWithoutAcfAjaxActionRemainsDraft(): void
    {
        $this->assertSame(
            'draft',
            $this->resolver->resolve(
                array(
                    'post_status'          => 'draft',
                    'original_post_status' => 'draft',
                    'save'                 => 'Save Draft',
                    'post_type'            => 'recipe',
                )
            )
        );
    }

    public function testAcfAjaxDoesNotOverrideExplicitPrivate(): void
    {
        $this->assertSame(
            'private',
            $this->resolver->resolve(
                array(
                    'action'     => 'acf/validate_save_post',
                    'visibility' => 'private',
                )
            )
        );
    }
}
