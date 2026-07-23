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
				'show_in_rest'        => false,
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
	 * Append a child listing to directory documents that have no content.
	 *
	 * A directory without a README renders as an always-current list of its
	 * children rather than storing a generated list that would go stale.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content The post content.
	 *
	 * @return string The content, with a child listing appended when applicable.
	 */
	public static function maybe_list_children( $content ) {
		if ( ! is_singular( self::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		if ( '' !== trim( $content ) ) {
			return $content;
		}

		$children = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_parent'    => get_the_ID(),
				'orderby'        => 'title',
				'order'          => 'ASC',
				'posts_per_page' => -1,
			)
		);

		if ( empty( $children ) ) {
			return $content;
		}

		$list = '<ul class="gatherpress-docs-children">';

		foreach ( $children as $child ) {
			$list .= sprintf(
				'<li><a href="%s">%s</a></li>',
				esc_url( (string) get_permalink( $child ) ),
				esc_html( get_the_title( $child ) )
			);
		}

		$list .= '</ul>';

		return $content . $list;
	}
}
