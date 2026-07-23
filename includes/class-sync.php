<?php
/**
 * The sync engine.
 *
 * @package GatherPress\Docs
 * @since 0.1.0
 */

namespace GatherPress\Docs;

use WP_HTML_Tag_Processor;
use WP_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Sync.
 *
 * Mirrors the configured repository path into the docs post type:
 *
 *  1. One tree API call lists every file with its blob SHA.
 *  2. Directories become parent posts; `.md` files become child posts;
 *     a directory's README.md becomes the directory post's own content.
 *  3. A file whose SHA matches the stored one is skipped untouched — the
 *     SHA is the change detector, so steady-state syncs cost almost nothing.
 *  4. Changed files are fetched raw, rendered by GitHub's Markdown API,
 *     rewritten (relative doc links point at local permalinks, images at
 *     raw.githubusercontent.com), and saved with the last-commit date.
 *  5. Posts whose source file disappeared are trashed.
 *
 * Runs are budgeted: when the API rate limit or a time guard is hit the run
 * stops cleanly, records what remains, and schedules a resume — the SHA skip
 * makes re-entry free.
 *
 * @since 0.1.0
 */
final class Sync {

	/**
	 * Option holding the last sync status report.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const STATUS_OPTION = 'gatherpress_docs_status';

	/**
	 * Transient guarding against concurrent runs.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const LOCK_TRANSIENT = 'gatherpress_docs_sync_lock';

	/**
	 * Seconds of processing after which a run stops and schedules a resume.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const TIME_BUDGET = 45;

	/**
	 * Stop when the API rate limit drops below this many remaining requests.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const RATE_FLOOR = 5;

	/**
	 * Run a sync.
	 *
	 * @since 0.1.0
	 *
	 * @return array The status report also stored in STATUS_OPTION.
	 */
	public static function run() {
		$settings = Settings::get();

		if ( empty( $settings['repo'] ) ) {
			return self::finish(
				array( 'message' => __( 'Not configured yet — set a repository in the settings.', 'gatherpress-docs' ) )
			);
		}

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return (array) get_option( self::STATUS_OPTION, array() );
		}

		set_transient( self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

		$report = self::do_run( $settings );

		delete_transient( self::LOCK_TRANSIENT );

		return self::finish( $report );
	}

	/**
	 * The sync proper.
	 *
	 * @since 0.1.0
	 *
	 * @param array $settings Plugin settings.
	 *
	 * @return array Partial status report.
	 */
	private static function do_run( $settings ) {
		$started = time();
		$client  = new GitHub_Client( $settings['repo'], $settings['branch'], $settings['token'] );
		$tree    = $client->get_tree();

		if ( is_wp_error( $tree ) ) {
			return array(
				'message' => $tree->get_error_message(),
				'errors'  => 1,
			);
		}

		$base    = trim( (string) $settings['path'], '/' );
		$sources = self::collect_sources( $tree, $base );
		$posts   = self::existing_posts();
		$report  = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'trashed' => 0,
			'errors'  => 0,
		);
		$partial = false;

		foreach ( $sources as $path => $source ) {
			// Budget guards: stop cleanly and resume later rather than dying
			// mid-run on a host time limit or the API rate limit.
			$rate = $client->rate_remaining();

			if ( ( time() - $started ) > self::TIME_BUDGET || ( null !== $rate && $rate < self::RATE_FLOOR ) ) {
				$partial = true;
				break;
			}

			$existing  = isset( $posts[ $path ] ) ? $posts[ $path ] : null;
			$unchanged = $existing
				&& $existing['sha'] === $source['sha']
				&& 'trash' !== $existing['status'];

			if ( $unchanged ) {
				++$report['skipped'];
				continue;
			}

			$parent_id = 0;

			if ( '' !== $source['parent'] && isset( $posts[ $source['parent'] ] ) ) {
				$parent_id = $posts[ $source['parent'] ]['id'];
			}

			$post_id = self::sync_item( $client, $settings, $path, $source, $existing, $parent_id );

			if ( ! $post_id ) {
				++$report['errors'];
				continue;
			}

			++$report[ $existing ? 'updated' : 'created' ];

			$posts[ $path ] = array(
				'id'     => $post_id,
				'sha'    => $source['sha'],
				'status' => 'publish',
			);
		}

		// Only prune on a complete pass — a partial run has not seen the
		// whole picture and must not trash documents it simply never reached.
		if ( ! $partial ) {
			foreach ( $posts as $path => $post ) {
				if ( ! isset( $sources[ $path ] ) && 'trash' !== $post['status'] ) {
					wp_trash_post( $post['id'] );
					++$report['trashed'];
				}
			}
		} else {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, Setup::CRON_RESUME_HOOK );
		}

		$report['partial'] = $partial;
		$report['message'] = $partial
			? __( 'Partial sync — resuming shortly. Add a GitHub token to raise the API rate limit.', 'gatherpress-docs' )
			: __( 'Sync complete.', 'gatherpress-docs' );

		return $report;
	}

	/**
	 * Reduce the repository tree to the documents to mirror.
	 *
	 * Directories become entries keyed by their path with the README (when
	 * present) as their content source; `.md` files become entries of their
	 * own. A README at the base path itself is skipped — the configured root
	 * page is the human-owned front door.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $tree Tree entries from the GitHub API.
	 * @param string $base Base path within the repository ('' for the root).
	 *
	 * @return array Map of repo path => {type, sha, parent, content_path}, parents first.
	 */
	private static function collect_sources( $tree, $base ) {
		$prefix  = '' === $base ? '' : $base . '/';
		$sources = array();
		$readmes = array();

		foreach ( $tree as $entry ) {
			$path = isset( $entry['path'] ) ? (string) $entry['path'] : '';
			$type = isset( $entry['type'] ) ? (string) $entry['type'] : '';
			$sha  = isset( $entry['sha'] ) ? (string) $entry['sha'] : '';

			if ( '' !== $prefix && 0 !== strpos( $path, $prefix ) ) {
				continue;
			}

			if ( 'tree' === $type ) {
				$sources[ $path ] = array(
					'type'         => 'dir',
					'sha'          => '',
					'parent'       => self::parent_path( $path, $base ),
					'content_path' => '',
				);
				continue;
			}

			if ( 'blob' !== $type || ! preg_match( '/\.md$/i', $path ) ) {
				continue;
			}

			if ( preg_match( '#(^|/)readme\.md$#i', $path ) ) {
				$dir = dirname( $path );

				// The base path's own README has no directory post to live on.
				if ( $dir !== $base && '.' !== $dir ) {
					$readmes[ $dir ] = array(
						'sha'  => $sha,
						'path' => $path,
					);
				}
				continue;
			}

			$sources[ $path ] = array(
				'type'         => 'file',
				'sha'          => $sha,
				'parent'       => self::parent_path( $path, $base ),
				'content_path' => $path,
			);
		}

		// Attach READMEs to their directories: the README's SHA becomes the
		// directory's change detector.
		foreach ( $readmes as $dir => $readme ) {
			if ( isset( $sources[ $dir ] ) ) {
				$sources[ $dir ]['sha']          = $readme['sha'];
				$sources[ $dir ]['content_path'] = $readme['path'];
			}
		}

		// Parents before children: ancestors always have fewer segments.
		uksort(
			$sources,
			static function ( $a, $b ) {
				return substr_count( $a, '/' ) <=> substr_count( $b, '/' );
			}
		);

		return $sources;
	}

	/**
	 * Create or update the post for one source item.
	 *
	 * @since 0.1.0
	 *
	 * @param GitHub_Client $client    The API client.
	 * @param array         $settings  Plugin settings.
	 * @param string        $path      Repo path of the item.
	 * @param array         $source    Source descriptor from collect_sources().
	 * @param array|null    $existing  Existing post record, if any.
	 * @param int           $parent_id Parent post ID.
	 *
	 * @return int Post ID, or 0 on failure.
	 */
	private static function sync_item( $client, $settings, $path, $source, $existing, $parent_id ) {
		$title   = self::prettify_name( basename( $path ) );
		$content = '';
		$updated = '';

		if ( '' !== $source['content_path'] ) {
			$markdown = $client->get_raw_file( $source['content_path'] );

			if ( is_wp_error( $markdown ) ) {
				return 0;
			}

			$heading = self::extract_title( $markdown );

			if ( '' !== $heading ) {
				$title = $heading;

				// The heading becomes the post title, which the theme renders
				// itself -- leaving it in the body would show it twice.
				$markdown = preg_replace( '/^#\s+.+$/m', '', $markdown, 1 );
			}

			$html = $client->render_markdown( $markdown );

			if ( is_wp_error( $html ) ) {
				return 0;
			}

			$content = wp_kses_post(
				self::rewrite_urls( $html, $source['content_path'], $client, $settings )
			);
			$updated = $client->get_last_commit_date( $source['content_path'] );
		}

		$postarr = array(
			'post_type'   => Post_Type::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_name'   => sanitize_title( preg_replace( '/\.md$/i', '', basename( $path ) ) ),
			'post_parent' => $parent_id,
		);

		// Directories keep whatever a README gave them; without one their
		// content stays empty and the child listing renders dynamically.
		if ( 'file' === $source['type'] || '' !== $source['content_path'] ) {
			$postarr['post_content'] = $content;
		}

		if ( $existing ) {
			$postarr['ID'] = $existing['id'];

			if ( 'trash' === $existing['status'] ) {
				wp_untrash_post( $existing['id'] );
			}

			$post_id = wp_update_post( $postarr, false );
		} else {
			$post_id = wp_insert_post( $postarr, false );
		}

		if ( ! $post_id || is_wp_error( $post_id ) ) {
			return 0;
		}

		update_post_meta( $post_id, Post_Type::META_PATH, $path );
		update_post_meta( $post_id, Post_Type::META_SHA, $source['sha'] );
		update_post_meta(
			$post_id,
			Post_Type::META_SOURCE_URL,
			$client->blob_url( '' !== $source['content_path'] ? $source['content_path'] : $path )
		);

		if ( '' !== $updated ) {
			update_post_meta( $post_id, Post_Type::META_UPDATED, $updated );
		}

		return (int) $post_id;
	}

	/**
	 * Map every existing doc post by its repo path.
	 *
	 * @since 0.1.0
	 *
	 * @return array Map of repo path => {id, sha, status}.
	 */
	private static function existing_posts() {
		$query = new WP_Query(
			array(
				'post_type'              => Post_Type::POST_TYPE,
				'post_status'            => array( 'publish', 'trash' ),
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$map = array();

		foreach ( $query->posts as $post ) {
			$path = (string) get_post_meta( $post->ID, Post_Type::META_PATH, true );

			if ( '' === $path ) {
				continue;
			}

			$map[ $path ] = array(
				'id'     => $post->ID,
				'sha'    => (string) get_post_meta( $post->ID, Post_Type::META_SHA, true ),
				'status' => $post->post_status,
			);
		}

		return $map;
	}

	/**
	 * Rewrite relative URLs in rendered HTML.
	 *
	 * Relative links to `.md` files (and to directories) become local
	 * permalinks; relative images point at raw.githubusercontent.com; any
	 * other relative link points at the file's page on github.com. Absolute
	 * URLs, fragments, and mailto links pass through untouched.
	 *
	 * Local URLs are computed from the repo path rather than looked up, so
	 * forward links to documents created later in the same run still resolve.
	 *
	 * @since 0.1.0
	 *
	 * @param string        $html     Rendered HTML.
	 * @param string        $path     Repo path of the file the HTML came from.
	 * @param GitHub_Client $client   The API client (for raw/blob URLs).
	 * @param array         $settings Plugin settings.
	 *
	 * @return string The rewritten HTML.
	 */
	private static function rewrite_urls( $html, $path, $client, $settings ) {
		$processor = new WP_HTML_Tag_Processor( $html );
		$dir       = dirname( $path );
		$dir       = '.' === $dir ? '' : $dir;

		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();

			if ( 'A' === $tag ) {
				$href = (string) $processor->get_attribute( 'href' );

				if ( '' !== $href && self::is_relative( $href ) ) {
					$processor->set_attribute( 'href', self::map_link( $href, $dir, $client, $settings ) );
				}
			} elseif ( 'IMG' === $tag ) {
				$src = (string) $processor->get_attribute( 'src' );

				if ( '' !== $src && self::is_relative( $src ) ) {
					$processor->set_attribute(
						'src',
						$client->raw_url( self::resolve_path( $dir, $src ) )
					);
				}
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Map one relative link target to its rewritten URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string        $href     The relative href.
	 * @param string        $dir      Directory of the linking file within the repo.
	 * @param GitHub_Client $client   The API client.
	 * @param array         $settings Plugin settings.
	 *
	 * @return string The rewritten URL.
	 */
	private static function map_link( $href, $dir, $client, $settings ) {
		$fragment = '';
		$hash     = strpos( $href, '#' );

		if ( false !== $hash ) {
			$fragment = substr( $href, $hash );
			$href     = substr( $href, 0, $hash );
		}

		$target = self::resolve_path( $dir, $href );

		if ( preg_match( '/\.md$/i', $target ) ) {
			return self::local_url( $target, $settings ) . $fragment;
		}

		if ( preg_match( '/\.(png|jpe?g|gif|webp|svg)$/i', $target ) ) {
			return $client->raw_url( $target );
		}

		// A trailing slash (or no extension) reads as a directory link.
		if ( '/' === substr( $href, -1 ) || ! preg_match( '/\.[a-z0-9]+$/i', $target ) ) {
			return self::local_url( rtrim( $target, '/' ), $settings ) . $fragment;
		}

		return $client->blob_url( $target );
	}

	/**
	 * The local permalink for a repo path.
	 *
	 * Deterministic: root page URI + the path relative to the configured base,
	 * with any `.md` extension (and README naming) folded away — mirroring how
	 * posts and slugs are created.
	 *
	 * @since 0.1.0
	 *
	 * @param string $target   Repo path of a document or directory.
	 * @param array  $settings Plugin settings.
	 *
	 * @return string The local URL.
	 */
	private static function local_url( $target, $settings ) {
		$base = trim( (string) $settings['path'], '/' );

		$target = preg_replace( '#(^|/)readme\.md$#i', '', $target );
		$target = preg_replace( '/\.md$/i', '', (string) $target );
		$target = trim( (string) $target, '/' );

		if ( '' !== $base && 0 === strpos( $target, $base ) ) {
			$target = trim( substr( $target, strlen( $base ) ), '/' );
		}

		$root = 'docs';

		if ( ! empty( $settings['root_page'] ) ) {
			$uri = get_page_uri( (int) $settings['root_page'] );

			if ( $uri ) {
				$root = $uri;
			}
		}

		$segments = array_filter(
			array_map( 'sanitize_title', '' === $target ? array() : explode( '/', $target ) )
		);

		return user_trailingslashit( home_url( '/' . $root . ( $segments ? '/' . implode( '/', $segments ) : '' ) ) );
	}

	/**
	 * Whether a URL is relative (and therefore ours to rewrite).
	 *
	 * @since 0.1.0
	 *
	 * @param string $url The URL.
	 *
	 * @return bool True for relative paths.
	 */
	private static function is_relative( $url ) {
		return ! preg_match( '#^([a-z][a-z0-9+.-]*:|//|\#)#i', $url );
	}

	/**
	 * Resolve a relative reference against a directory, normalizing ../ and ./ segments.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir      Base directory within the repo ('' for the root).
	 * @param string $relative The relative reference.
	 *
	 * @return string The normalized repo path.
	 */
	private static function resolve_path( $dir, $relative ) {
		$combined = ( '' === $dir ? '' : $dir . '/' ) . ltrim( $relative, '/' );
		$parts    = array();

		foreach ( explode( '/', $combined ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $parts );
				continue;
			}

			$parts[] = $segment;
		}

		return implode( '/', $parts );
	}

	/**
	 * The parent path of an item, relative to the base ('' at the top level).
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Repo path.
	 * @param string $base Configured base path.
	 *
	 * @return string Parent repo path, or '' when the parent is the base itself.
	 */
	private static function parent_path( $path, $base ) {
		$parent = dirname( $path );

		if ( '.' === $parent || $parent === $base ) {
			return '';
		}

		return $parent;
	}

	/**
	 * The first ATX heading of a Markdown document, cleaned of inline markup.
	 *
	 * @since 0.1.0
	 *
	 * @param string $markdown Markdown source.
	 *
	 * @return string The title, or '' when the document has no heading.
	 */
	private static function extract_title( $markdown ) {
		if ( ! preg_match( '/^#\s+(.+)$/m', $markdown, $match ) ) {
			return '';
		}

		$title = trim( $match[1] );
		$title = preg_replace( '/\[([^\]]*)\]\([^)]*\)/', '$1', $title );
		$title = str_replace( array( '`', '**', '*', '_' ), '', (string) $title );

		return trim( (string) $title );
	}

	/**
	 * A human title from a file or directory name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name File or directory basename.
	 *
	 * @return string Prettified title.
	 */
	private static function prettify_name( $name ) {
		$name = preg_replace( '/\.md$/i', '', $name );

		return ucwords( str_replace( array( '-', '_' ), ' ', (string) $name ) );
	}

	/**
	 * Stamp and store the status report.
	 *
	 * @since 0.1.0
	 *
	 * @param array $report Partial report.
	 *
	 * @return array The completed report.
	 */
	private static function finish( $report ) {
		$report = array_merge(
			array(
				'time'    => time(),
				'message' => '',
				'partial' => false,
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
				'trashed' => 0,
				'errors'  => 0,
			),
			$report
		);

		update_option( self::STATUS_OPTION, $report, false );

		return $report;
	}
}
