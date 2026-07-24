<?php
/**
 * Render the Doc Child Pages block.
 *
 * @package GatherPress\Docs
 * @since 0.2.0
 *
 * @var array     $attributes Block attributes.
 * @var string    $content    Block default content.
 * @var \WP_Block $block      Block instance.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$gatherpress_docs_post_id = isset( $block->context['postId'] )
	? (int) $block->context['postId']
	: (int) get_the_ID();

$gatherpress_docs_root = (int) GatherPress\Docs\Settings::get()['root_page'];
$gatherpress_docs_list = '';

if ( GatherPress\Docs\Post_Type::POST_TYPE === get_post_type( $gatherpress_docs_post_id ) ) {
	$gatherpress_docs_list = GatherPress\Docs\Post_Type::child_list( $gatherpress_docs_post_id );
} elseif ( $gatherpress_docs_root && $gatherpress_docs_root === $gatherpress_docs_post_id ) {
	$gatherpress_docs_list = GatherPress\Docs\Post_Type::child_list( 0 );
}

echo $gatherpress_docs_list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped during construction.
