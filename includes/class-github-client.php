<?php
/**
 * Thin GitHub API client.
 *
 * @package GatherPress\Docs
 * @since 0.1.0
 */

namespace GatherPress\Docs;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class GitHub_Client.
 *
 * Wraps the handful of GitHub endpoints the sync needs: the recursive tree
 * (one call for the whole file list, with blob SHAs), raw file contents,
 * GitHub's own Markdown renderer, and the last-commit date for a path.
 *
 * All requests work unauthenticated against public repositories; an optional
 * token raises the API rate limit from 60 to 5,000 requests per hour and
 * unlocks private repositories.
 *
 * @since 0.1.0
 */
final class GitHub_Client {

	/**
	 * Repository in owner/name form.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $repo;

	/**
	 * Branch name.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $branch;

	/**
	 * Optional API token.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $token;

	/**
	 * Remaining API rate limit from the most recent response, or null before
	 * any API call has been made.
	 *
	 * @since 0.1.0
	 * @var int|null
	 */
	private $rate_remaining = null;

	/**
	 * Class constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $repo   Repository in owner/name form.
	 * @param string $branch Branch name.
	 * @param string $token  Optional API token.
	 */
	public function __construct( $repo, $branch, $token = '' ) {
		$this->repo   = $repo;
		$this->branch = $branch;
		$this->token  = $token;
	}

	/**
	 * Remaining API requests in the current rate-limit window.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null Remaining requests, or null before any API call.
	 */
	public function rate_remaining() {
		return $this->rate_remaining;
	}

	/**
	 * Fetch the repository's full tree for the branch, one API call.
	 *
	 * @since 0.1.0
	 *
	 * @return array|WP_Error Array of tree entries ({path, type, sha}), or an error.
	 */
	public function get_tree() {
		$response = $this->api_request(
			sprintf(
				'https://api.github.com/repos/%s/git/trees/%s?recursive=1',
				$this->repo,
				rawurlencode( $this->branch )
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['truncated'] ) ) {
			return new WP_Error(
				'gatherpress_docs_tree_truncated',
				__( 'The repository tree is too large for the GitHub API to return in full.', 'gatherpress-docs' )
			);
		}

		return isset( $response['tree'] ) && is_array( $response['tree'] ) ? $response['tree'] : array();
	}

	/**
	 * Fetch a file's raw contents.
	 *
	 * Served from raw.githubusercontent.com, which does not count against the
	 * API rate limit.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path File path within the repository.
	 *
	 * @return string|WP_Error File contents, or an error.
	 */
	public function get_raw_file( $path ) {
		$response = wp_remote_get( $this->raw_url( $path ), $this->request_args() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'gatherpress_docs_raw_failed',
				sprintf(
					/* translators: %1$s: file path, %2$d: HTTP status. */
					__( 'Could not fetch %1$s (HTTP %2$d).', 'gatherpress-docs' ),
					$path,
					$code
				)
			);
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Render Markdown to HTML using GitHub's own renderer.
	 *
	 * GitHub-flavored mode with the repository as context, so tables, task
	 * lists, alerts, and issue references render exactly as they do on
	 * github.com.
	 *
	 * @since 0.1.0
	 *
	 * @param string $markdown Markdown source.
	 *
	 * @return string|WP_Error Rendered HTML, or an error.
	 */
	public function render_markdown( $markdown ) {
		$response = wp_remote_post(
			'https://api.github.com/markdown',
			array_merge(
				$this->request_args(),
				array(
					'body' => wp_json_encode(
						array(
							'text'    => $markdown,
							'mode'    => 'gfm',
							'context' => $this->repo,
						)
					),
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->remember_rate_limit( $response );
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'gatherpress_docs_render_failed',
				sprintf(
					/* translators: %d: HTTP status. */
					__( 'The GitHub Markdown renderer returned HTTP %d.', 'gatherpress-docs' ),
					$code
				)
			);
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * The ISO 8601 date of the last commit touching a path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path File path within the repository.
	 *
	 * @return string ISO 8601 date, or an empty string when unavailable.
	 */
	public function get_last_commit_date( $path ) {
		$response = $this->api_request(
			sprintf(
				'https://api.github.com/repos/%s/commits?path=%s&sha=%s&per_page=1',
				$this->repo,
				rawurlencode( $path ),
				rawurlencode( $this->branch )
			)
		);

		if ( is_wp_error( $response ) || empty( $response[0]['commit']['committer']['date'] ) ) {
			return '';
		}

		return (string) $response[0]['commit']['committer']['date'];
	}

	/**
	 * The raw.githubusercontent.com URL for a path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path File path within the repository.
	 *
	 * @return string The raw URL.
	 */
	public function raw_url( $path ) {
		return sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s',
			$this->repo,
			rawurlencode( $this->branch ),
			implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) )
		);
	}

	/**
	 * The github.com blob URL for a path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path File path within the repository.
	 *
	 * @return string The blob URL.
	 */
	public function blob_url( $path ) {
		return sprintf(
			'https://github.com/%s/blob/%s/%s',
			$this->repo,
			rawurlencode( $this->branch ),
			implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) )
		);
	}

	/**
	 * Perform a GET request against the GitHub API and decode the response.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Full API URL.
	 *
	 * @return array|WP_Error Decoded JSON, or an error.
	 */
	private function api_request( $url ) {
		$response = wp_remote_get( $url, $this->request_args() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->remember_rate_limit( $response );
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$message = ( 403 === $code && 0 === (int) $this->rate_remaining )
				? __( 'GitHub API rate limit reached. Add an access token in the settings to raise the limit.', 'gatherpress-docs' )
				: sprintf(
					/* translators: %d: HTTP status. */
					__( 'GitHub API request failed with HTTP %d.', 'gatherpress-docs' ),
					$code
				);

			return new WP_Error( 'gatherpress_docs_api_failed', $message );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'gatherpress_docs_bad_json',
				__( 'The GitHub API returned an unreadable response.', 'gatherpress-docs' )
			);
		}

		return $decoded;
	}

	/**
	 * Shared request arguments.
	 *
	 * @since 0.1.0
	 *
	 * @return array Arguments for wp_remote_get()/wp_remote_post().
	 */
	private function request_args() {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		if ( '' !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		return array(
			'timeout' => 30,
			'headers' => $headers,
		);
	}

	/**
	 * Record the rate-limit header from an API response.
	 *
	 * @since 0.1.0
	 *
	 * @param array $response A wp_remote_* response.
	 *
	 * @return void
	 */
	private function remember_rate_limit( $response ) {
		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );

		if ( '' !== $remaining && null !== $remaining ) {
			$this->rate_remaining = (int) $remaining;
		}
	}
}
