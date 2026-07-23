<?php
/**
 * Plugin wiring.
 *
 * @package GatherPress\Docs
 * @since 0.1.0
 */

namespace GatherPress\Docs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Setup.
 *
 * Wires the post type, settings page, cron schedule, and the manual sync
 * action together.
 *
 * @since 0.1.0
 */
final class Setup {

	/**
	 * Recurring sync cron hook.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const CRON_HOOK = 'gatherpress_docs_sync';

	/**
	 * One-off resume hook, scheduled when a run stops on a budget guard.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const CRON_RESUME_HOOK = 'gatherpress_docs_sync_resume';

	/**
	 * The single instance.
	 *
	 * @since 0.1.0
	 * @var Setup|null
	 */
	private static $instance = null;

	/**
	 * Get the single instance.
	 *
	 * @since 0.1.0
	 *
	 * @return Setup The instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Class constructor.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		add_action( 'init', array( Post_Type::class, 'register' ) );
		add_action( 'admin_menu', array( Settings::class, 'register' ) );
		add_filter( 'the_content', array( Post_Type::class, 'maybe_list_children' ) );

		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 6 hours is not a short interval.
		add_action( self::CRON_HOOK, array( Sync::class, 'run' ) );
		add_action( self::CRON_RESUME_HOOK, array( Sync::class, 'run' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'reschedule' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'schedule_new' ), 10, 2 );
		add_action( 'admin_post_gatherpress_docs_sync', array( $this, 'handle_manual_sync' ) );
		add_filter( 'display_post_states', array( $this, 'add_root_page_state' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'root_page_editor_notice' ) );
	}

	/**
	 * Badge the configured root page in the Pages list.
	 *
	 * Mirrors how core labels the posts page: the special role is visible
	 * right where the page is listed.
	 *
	 * @since 0.1.0
	 *
	 * @param array    $post_states Post state labels.
	 * @param \WP_Post $post        The post.
	 *
	 * @return array Post states including ours when applicable.
	 */
	public function add_root_page_state( $post_states, $post ) {
		$settings = Settings::get();

		if ( 'page' === $post->post_type && (int) $settings['root_page'] === (int) $post->ID ) {
			$post_states['gatherpress_docs_root'] = __( 'GitHub Docs Root', 'gatherpress-docs' );
		}

		return $post_states;
	}

	/**
	 * Explain the page's role when the root page is being edited.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function root_page_editor_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'post' !== $screen->base || 'page' !== $screen->post_type ) {
			return;
		}

		$settings = Settings::get();
		$post_id  = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen context.

		if ( ! $post_id || (int) $settings['root_page'] !== $post_id ) {
			return;
		}

		wp_admin_notice(
			sprintf(
				/* translators: %s: repository in owner/name form. */
				esc_html__( 'This page is the GitHub Docs root — documents synced from %s nest beneath it, and its content is followed by the document listing.', 'gatherpress-docs' ),
				'<code>' . esc_html( $settings['repo'] ) . '</code>'
			),
			array( 'type' => 'info' )
		);
	}

	/**
	 * Register the six-hour cron interval.
	 *
	 * @since 0.1.0
	 *
	 * @param array $schedules Registered schedules.
	 *
	 * @return array Schedules including ours.
	 */
	public function add_schedules( $schedules ) {
		$schedules['gatherpress_docs_six_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours', 'gatherpress-docs' ),
		);

		return $schedules;
	}

	/**
	 * Reschedule the recurring sync when the settings change.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 *
	 * @return void
	 */
	public function reschedule( $old_value, $value ) {
		unset( $old_value );
		$this->schedule( (array) $value );
	}

	/**
	 * Schedule the recurring sync when the settings are first saved.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New settings.
	 *
	 * @return void
	 */
	public function schedule_new( $option, $value ) {
		unset( $option );
		$this->schedule( (array) $value );
	}

	/**
	 * (Re)schedule the recurring sync from a settings array.
	 *
	 * @since 0.1.0
	 *
	 * @param array $settings Plugin settings.
	 *
	 * @return void
	 */
	private function schedule( $settings ) {
		wp_clear_scheduled_hook( self::CRON_HOOK );

		if ( empty( $settings['repo'] ) ) {
			return;
		}

		$frequency = isset( $settings['frequency'] ) ? (string) $settings['frequency'] : 'daily';

		if ( ! array_key_exists( $frequency, Settings::frequencies() ) ) {
			$frequency = 'daily';
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, $frequency, self::CRON_HOOK );
	}

	/**
	 * Handle the manual "Sync now" button.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_manual_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'gatherpress-docs' ) );
		}

		check_admin_referer( 'gatherpress_docs_sync' );

		Sync::run();

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => Settings::PAGE ),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
