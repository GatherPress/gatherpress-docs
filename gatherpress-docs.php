<?php
/**
 * Plugin Name:       GatherPress Docs
 * Plugin URI:        https://github.com/GatherPress/gatherpress-docs
 * Description:       Mirror a GitHub repository's Markdown documentation as hierarchical pages on your WordPress site, kept in sync automatically.
 * Author:            The GatherPress Community
 * Author URI:        https://gatherpress.org/
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Text Domain:       gatherpress-docs
 * License:           GNU General Public License v2.0 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package GatherPress\Docs
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Bail when a sibling copy is already loaded.
if ( defined( 'GATHERPRESS_DOCS_VERSION' ) ) {
	return;
}

define( 'GATHERPRESS_DOCS_VERSION', '0.2.0' );
define( 'GATHERPRESS_DOCS_FILE', __FILE__ );
define( 'GATHERPRESS_DOCS_PATH', __DIR__ );

require_once GATHERPRESS_DOCS_PATH . '/includes/class-post-type.php';
require_once GATHERPRESS_DOCS_PATH . '/includes/class-github-client.php';
require_once GATHERPRESS_DOCS_PATH . '/includes/class-sync.php';
require_once GATHERPRESS_DOCS_PATH . '/includes/class-settings.php';
require_once GATHERPRESS_DOCS_PATH . '/includes/class-setup.php';

GatherPress\Docs\Setup::get_instance();

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( GatherPress\Docs\Setup::CRON_HOOK );
		wp_clear_scheduled_hook( GatherPress\Docs\Setup::CRON_RESUME_HOOK );
	}
);
