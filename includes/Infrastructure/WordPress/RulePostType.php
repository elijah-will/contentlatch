<?php
/**
 * Hidden CPT for rule persistence.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

defined('ABSPATH') || exit;

final class RulePostType
{
    public const POST_TYPE = 'contentlatch_rule';
    public const TARGET_META_KEY = '_contentlatch_post_type';
    public const DOCUMENT_META_KEY = '_contentlatch_rule_document';

    public static function register(): void
    {
        if (!function_exists('register_post_type')) {
            return;
        }

        register_post_type(
            self::POST_TYPE,
            array(
                'labels'              => array(
                    'name'          => __('ContentLatch Rules', 'contentlatch'),
                    'singular_name' => __('ContentLatch Rule', 'contentlatch'),
                ),
                'public'              => false,
                'publicly_queryable'  => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'show_in_nav_menus'   => false,
                'show_in_admin_bar'   => false,
                'show_in_rest'        => false,
                'exclude_from_search' => true,
                'hierarchical'        => false,
                'rewrite'             => false,
                'query_var'           => false,
                'capability_type'     => 'post',
                'capabilities'        => array(
                    'edit_post'          => Capabilities::MANAGE,
                    'read_post'          => Capabilities::MANAGE,
                    'delete_post'        => Capabilities::MANAGE,
                    'edit_posts'         => Capabilities::MANAGE,
                    'edit_others_posts'  => Capabilities::MANAGE,
                    'delete_posts'       => Capabilities::MANAGE,
                    'publish_posts'      => Capabilities::MANAGE,
                    'read_private_posts' => Capabilities::MANAGE,
                    'create_posts'       => Capabilities::MANAGE,
                ),
                'map_meta_cap'        => false,
                'supports'            => array('title'),
            )
        );
    }
}
