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
	 * Register the plugin's blocks.
	 *
	 * Both are server-rendered: Doc Breadcrumbs and Doc Child Pages. Place
	 * them in the docs single template (or anywhere — they render for the
	 * contextual post).
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function register_blocks() {
		register_block_type( GATHERPRESS_DOCS_PATH . '/blocks/breadcrumbs' );
		register_block_type( GATHERPRESS_DOCS_PATH . '/blocks/children' );
	}

	/**
	 * Auto-append the top-level document listing to an empty root page.
	 *
	 * Only the configured root page, and only when it has no content of its
	 * own — a root page with content composes its own layout (the Doc Child
	 * Pages block is available for that). Document pages get their navigation
	 * from the blocks in the template, not from this filter.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content The post content.
	 *
	 * @return string The content, with the listing appended when applicable.
	 */
	public static function maybe_list_children( $content ) {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$root = (int) Settings::get()['root_page'];

		if ( $root && is_page( $root ) && '' === trim( $content ) ) {
			return $content . self::child_list( 0 );
		}

		return $content;
	}

	/**
	 * Breadcrumb trail for a document.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id The document's post ID.
	 *
	 * @return string Breadcrumb markup, or '' for non-documents.
	 */
	public static function breadcrumbs( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return '';
		}

		$root  = (int) Settings::get()['root_page'];
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
	public static function child_list( $parent ) {
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
