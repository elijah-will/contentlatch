<?php
/**
 * Determines the post status a save is trying to produce.
 *
 * Uses WordPress editor request signals, not the status already stored on
 * the post. ACF's validate_save_post AJAX is also a publish signal: ACF
 * only sends that request when validating Publish/Update, never Save Draft,
 * and the serialized form does not include the clicked Publish button.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

final class IntendedPostStatusResolver
{
    /**
     * @param array<string, mixed> $request
     */
    public function resolve(array $request): string
    {
        $status     = $this->string($request, 'post_status');
        $visibility = $this->string($request, 'visibility');

        if ($visibility === 'private' || $this->isPresent($request, 'private')) {
            return 'private';
        }

        if ($this->isPresent($request, 'publish')) {
            return $status === 'private' ? 'private' : 'publish';
        }

        if ($status === 'private' || $status === 'publish') {
            return $status;
        }

        if ($status === 'pending') {
            return 'pending';
        }

        if ($status === 'future') {
            return 'future';
        }

        if (in_array($status, array('draft', 'auto-draft'), true)) {
            return $this->acfValidationImpliesPublish($request) ? 'publish' : 'draft';
        }

        if ($status !== '') {
            return $status;
        }

        return $this->acfValidationImpliesPublish($request) ? 'publish' : 'draft';
    }

    /**
     * ACF AJAX validation uses action=acf/validate_save_post.
     *
     * Classic editor: jQuery/ACF serialize omits the clicked Publish submit
     * button, so hidden post_status stays draft/auto-draft.
     * Block editor: hidden metabox fields include original_post_status and
     * post_type, but not post_status or a publish button.
     * ACF JS skips that AJAX entirely for Save Draft / preview / autosave.
     *
     * @param array<string, mixed> $request
     */
    private function acfValidationImpliesPublish(array $request): bool
    {
        return $this->string($request, 'action') === 'acf/validate_save_post';
    }

    public function isBlockingStatus(string $status): bool
    {
        return $status === 'publish' || $status === 'private';
    }

    /**
     * @param array<string, mixed> $request
     */
    private function isPresent(array $request, string $key): bool
    {
        return isset($request[$key]) && $request[$key] !== '' && $request[$key] !== false;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function string(array $request, string $key): string
    {
        return isset($request[$key]) && is_scalar($request[$key])
            ? (string) $request[$key]
            : '';
    }
}
