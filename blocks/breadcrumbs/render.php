<?php
/**
 * Render the Doc Breadcrumbs block.
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

echo GatherPress\Docs\Post_Type::breadcrumbs( $gatherpress_docs_post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped during construction.
