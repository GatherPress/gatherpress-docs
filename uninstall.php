<?php
/**
 * Uninstall cleanup.
 *
 * Removes the plugin's options and every mirrored document. The documents are
 * a generated mirror of the source repository, so nothing original is lost —
 * reinstalling and syncing recreates them.
 *
 * @package GatherPress\Docs
 * @since 0.1.0
 */

// Exit if not called by WordPress during uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'gatherpress_docs_settings' );
delete_option( 'gatherpress_docs_status' );
delete_transient( 'gatherpress_docs_sync_lock' );

$gatherpress_docs_posts = get_posts(
	array(
		'post_type'      => 'gatherpress_doc',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $gatherpress_docs_posts as $gatherpress_docs_post_id ) {
	wp_delete_post( $gatherpress_docs_post_id, true );
}
