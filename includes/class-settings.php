<?php
/**
 * The settings page.
 *
 * @package GatherPress\Docs
 * @since 0.1.0
 */

namespace GatherPress\Docs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Settings.
 *
 * Settings → GitHub Docs: the source repository, branch, and path; the root
 * page documents nest beneath; the sync frequency; an optional API token;
 * and a manual "Sync now" button with the last run's status.
 *
 * @since 0.1.0
 */
final class Settings {

	/**
	 * Option name.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION = 'gatherpress_docs_settings';

	/**
	 * Settings-page slug.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PAGE = 'gatherpress-docs';

	/**
	 * Get the settings merged over their defaults.
	 *
	 * @since 0.1.0
	 *
	 * @return array Settings.
	 */
	public static function get() {
		return array_merge(
			array(
				'repo'      => '',
				'branch'    => 'main',
				'path'      => 'docs',
				'root_page' => 0,
				'frequency' => 'daily',
				'token'     => '',
			),
			(array) get_option( self::OPTION, array() )
		);
	}

	/**
	 * The available sync frequencies.
	 *
	 * @since 0.1.0
	 *
	 * @return array Map of schedule slug => label.
	 */
	public static function frequencies() {
		return array(
			'hourly'                     => __( 'Every hour', 'gatherpress-docs' ),
			'gatherpress_docs_six_hours' => __( 'Every 6 hours', 'gatherpress-docs' ),
			'twicedaily'                 => __( 'Twice daily', 'gatherpress-docs' ),
			'daily'                      => __( 'Once daily', 'gatherpress-docs' ),
		);
	}

	/**
	 * Register the option and the settings page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);

		add_options_page(
			__( 'GitHub Docs', 'gatherpress-docs' ),
			__( 'GitHub Docs', 'gatherpress-docs' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Raw input.
	 *
	 * @return array Clean settings.
	 */
	public static function sanitize( $input ) {
		$input    = (array) $input;
		$previous = self::get();
		$repo     = sanitize_text_field( isset( $input['repo'] ) ? $input['repo'] : '' );

		if ( '' !== $repo && ! preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ) {
			add_settings_error(
				self::OPTION,
				'gatherpress_docs_bad_repo',
				__( 'The repository must be in owner/name form, for example GatherPress/gatherpress.', 'gatherpress-docs' )
			);
			$repo = $previous['repo'];
		}

		$frequency = isset( $input['frequency'] ) ? sanitize_key( $input['frequency'] ) : 'daily';

		if ( ! array_key_exists( $frequency, self::frequencies() ) ) {
			$frequency = 'daily';
		}

		$clean = array(
			'repo'      => $repo,
			'branch'    => sanitize_text_field( isset( $input['branch'] ) ? $input['branch'] : 'main' ),
			'path'      => trim( sanitize_text_field( isset( $input['path'] ) ? $input['path'] : '' ), '/' ),
			'root_page' => absint( isset( $input['root_page'] ) ? $input['root_page'] : 0 ),
			'frequency' => $frequency,
			'token'     => trim( sanitize_text_field( isset( $input['token'] ) ? $input['token'] : '' ) ),
		);

		if ( '' === $clean['branch'] ) {
			$clean['branch'] = 'main';
		}

		// The root page shapes the post type's rewrite slug — schedule a
		// rules rebuild when it changes.
		if ( $clean['root_page'] !== (int) $previous['root_page'] ) {
			delete_option( 'rewrite_rules' );
		}

		return $clean;
	}

	/**
	 * Render the settings page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get();
		$status   = (array) get_option( Sync::STATUS_OPTION, array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GitHub Docs', 'gatherpress-docs' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="gatherpress-docs-repo"><?php esc_html_e( 'Repository', 'gatherpress-docs' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[repo]" id="gatherpress-docs-repo"
								type="text" class="regular-text" placeholder="GatherPress/gatherpress"
								value="<?php echo esc_attr( $settings['repo'] ); ?>" />
							<p class="description"><?php esc_html_e( 'GitHub repository in owner/name form.', 'gatherpress-docs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gatherpress-docs-branch"><?php esc_html_e( 'Branch', 'gatherpress-docs' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[branch]" id="gatherpress-docs-branch"
								type="text" class="regular-text" value="<?php echo esc_attr( $settings['branch'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gatherpress-docs-path"><?php esc_html_e( 'Path', 'gatherpress-docs' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[path]" id="gatherpress-docs-path"
								type="text" class="regular-text" placeholder="docs"
								value="<?php echo esc_attr( $settings['path'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Directory within the repository to mirror. Leave empty for the whole repository.', 'gatherpress-docs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gatherpress-docs-root-page"><?php esc_html_e( 'Root page', 'gatherpress-docs' ); ?></label>
						</th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => esc_attr( self::OPTION . '[root_page]' ),
									'id'                => 'gatherpress-docs-root-page',
									'selected'          => (int) $settings['root_page'],
									'show_option_none'  => esc_html__( '— Select —', 'gatherpress-docs' ),
									'option_none_value' => '0',
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Documents nest beneath this page in the URL structure.', 'gatherpress-docs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gatherpress-docs-frequency"><?php esc_html_e( 'Update frequency', 'gatherpress-docs' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[frequency]" id="gatherpress-docs-frequency">
								<?php foreach ( self::frequencies() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['frequency'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gatherpress-docs-token"><?php esc_html_e( 'GitHub token', 'gatherpress-docs' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[token]" id="gatherpress-docs-token"
								type="password" class="regular-text" autocomplete="off"
								value="<?php echo esc_attr( $settings['token'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional. Raises the API rate limit from 60 to 5,000 requests per hour and allows private repositories.', 'gatherpress-docs' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Sync', 'gatherpress-docs' ); ?></h2>

			<?php if ( ! empty( $status['time'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %1$s: human time diff, %2$s: status message. */
						esc_html__( 'Last run %1$s ago: %2$s', 'gatherpress-docs' ),
						esc_html( human_time_diff( (int) $status['time'] ) ),
						esc_html( (string) $status['message'] )
					);
					?>
				</p>
				<p>
					<?php
					printf(
						/* translators: 1: created count, 2: updated count, 3: skipped count, 4: trashed count, 5: error count. */
						esc_html__( 'Created %1$d, updated %2$d, skipped %3$d, trashed %4$d, errors %5$d.', 'gatherpress-docs' ),
						(int) ( isset( $status['created'] ) ? $status['created'] : 0 ),
						(int) ( isset( $status['updated'] ) ? $status['updated'] : 0 ),
						(int) ( isset( $status['skipped'] ) ? $status['skipped'] : 0 ),
						(int) ( isset( $status['trashed'] ) ? $status['trashed'] : 0 ),
						(int) ( isset( $status['errors'] ) ? $status['errors'] : 0 )
					);
					?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'No sync has run yet.', 'gatherpress-docs' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gatherpress_docs_sync" />
				<?php wp_nonce_field( 'gatherpress_docs_sync' ); ?>
				<?php submit_button( __( 'Sync now', 'gatherpress-docs' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
