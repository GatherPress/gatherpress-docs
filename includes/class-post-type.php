<?php
/**
 * Registers the docs custom post type.
 *
 * @package GatherPress\Docs
 * @since 0.1.0
 */

namespace GatherPress\Docs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Post_Type.
 *
 * The mirrored documents live in a hierarchical, public custom post type with
 * no admin UI: the sync engine owns every post in it, so there is nothing for
 * a human to edit — changes belong in the source repository.
 *
 * @since 0.1.0
 */
final class Post_Type {

	/**
	 * The post type slug.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const POST_TYPE = 'gatherpress_doc';

	/**
	 * Meta key: the file's path within the repository. The sync key.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const META_PATH = '_gatherpress_docs_path';

	/**
	 * Meta key: the blob SHA last synced. The change detector.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const META_SHA = '_gatherpress_docs_sha';

	/**
	 * Meta key: ISO 8601 date of the file's last commit.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const META_UPDATED = '_gatherpress_docs_updated';

	/**
	 * Meta key: the file's page on github.com.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const META_SOURCE_URL = '_gatherpress_docs_source_url';

	/**
	 * Register the post type.
	 *
	 * The rewrite slug comes from the configured root page's URI, so document
	 * permalinks nest beneath it — a root page at /docs/ yields
	 * /docs/contributor/release-process/ and so on. Changing the root page
	 * schedules a rewrite flush (see Settings::sanitize()).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register() {
		$settings = Settings::get();
		$slug     = 'docs';

		if ( ! empty( $settings['root_page'] ) ) {
			$uri = get_page_uri( (int) $settings['root_page'] );

			if ( $uri ) {
				$slug = $uri;
			}
		}

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Docs', 'gatherpress-docs' ),
					'singular_name' => __( 'Doc', 'gatherpress-docs' ),
				),
				'public'              => true,
				'hierarchical'        => true,
				'show_ui'             => false,
				// REST exposure is what lets the Site Editor offer a
				// "Single item: Doc" template; the admin UI stays hidden.
				'show_in_rest'        => true,
				'exclude_from_search' => false,
				'supports'            => array( 'title', 'editor', 'page-attributes' ),
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => $slug,
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Wrap document content with navigation where it belongs.
	 *
	 * The configured root page always gets the top-level document listing
	 * appended after its own content, so the docs are discoverable from the
	 * front door. Every document gets breadcrumbs prepended, walking from
	 * the root page down through its directory ancestors, and its child
	 * listing appended — after the README content when there is one, as the
	 * whole body when there is not. The listing is rendered dynamically
	 * rather than stored, so it never goes stale.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content The post content.
	 *
	 * @return string The content, with navigation added when applicable.
	 */
	public static function maybe_list_children( $content ) {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$root = (int) Settings::get()['root_page'];

		if ( $root && is_page( $root ) ) {
			return $content . self::child_list( 0 );
		}

		if ( is_singular( self::POST_TYPE ) ) {
			$content .= self::child_list( (int) get_the_ID() );
			$content  = self::breadcrumbs( (int) get_the_ID(), $root ) . $content;
		}

		return $content;
	}

	/**
	 * Breadcrumb trail for a document.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id The document's post ID.
	 * @param int $root    The configured root page ID (0 for none).
	 *
	 * @return string Breadcrumb markup.
	 */
	private static function breadcrumbs( $post_id, $root ) {
		$trail = array();

		if ( $root ) {
			$trail[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( (string) get_permalink( $root ) ),
				esc_html( get_the_title( $root ) )
			);
		}

		foreach ( array_reverse( get_post_ancestors( $post_id ) ) as $ancestor ) {
			$trail[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( (string) get_permalink( $ancestor ) ),
				esc_html( get_the_title( $ancestor ) )
			);
		}

		$trail[] = sprintf( '<span aria-current="page">%s</span>', esc_html( get_the_title( $post_id ) ) );

		return sprintf(
			'<nav class="gatherpress-docs-breadcrumbs" aria-label="%s">%s</nav>',
			esc_attr__( 'Breadcrumbs', 'gatherpress-docs' ),
			implode( '<span class="gatherpress-docs-breadcrumbs-sep" aria-hidden="true"> / </span>', $trail )
		);
	}

	/**
	 * A linked list of the documents under a parent.
	 *
	 * @since 0.1.0
	 *
	 * @param int $parent Parent post ID (0 for top-level documents).
	 *
	 * @return string List markup, or '' when there are no children.
	 */
	private static function child_list( $parent ) {
		$children = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_parent'    => $parent,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'posts_per_page' => -1,
			)
		);

		if ( empty( $children ) ) {
			return '';
		}

		$list = '<ul class="gatherpress-docs-children">';

		foreach ( $children as $child ) {
			$list .= sprintf(
				'<li><a href="%s">%s</a></li>',
				esc_url( (string) get_permalink( $child ) ),
				esc_html( get_the_title( $child ) )
			);
		}

		return $list . '</ul>';
	}
}
