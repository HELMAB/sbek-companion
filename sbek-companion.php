<?php
/**
 * Plugin Name:       Sbek Companion
 * Plugin URI:        https://github.com/HELMAB/sbek-companion
 * Description:       The non-design features of the Sbek news theme: Khmer / English URLs and linked translations, news fields (video, source, sponsored, breaking, trending), share buttons, double opt-in newsletter, SEO tags, analytics, login branding and lockout, query caching and demo content.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Hel Mab
 * Author URI:        https://github.com/HELMAB
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sbek-companion
 * Domain Path:       /languages
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

define( 'SBEK_COMPANION_VERSION', '1.0.0' );
define( 'SBEK_COMPANION_FILE', __FILE__ );
define( 'SBEK_COMPANION_DIR', __DIR__ );
define( 'SBEK_COMPANION_URL', plugins_url( '', __FILE__ ) );

/**
 * The features are built for the Sbek theme (they render through its hooks
 * and settings page), so they only load while Sbek or a child theme of it is
 * active. get_template() reads the stored option, so it works this early.
 */
function sbek_companion_theme_is_active(): bool {
	return 'sbek' === get_template();
}

if ( ! sbek_companion_theme_is_active() ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			wp_admin_notice(
				esc_html__( 'Sbek Companion does nothing on its own: it adds features to the Sbek theme. Activate the Sbek theme, or deactivate this plugin.', 'sbek-companion' ),
				array(
					'type'        => 'warning',
					'dismissible' => true,
				)
			);
		}
	);
	return;
}

/**
 * Plugin translations (bundled Khmer; WordPress.org language packs take
 * precedence once they exist).
 */
function sbek_companion_load_textdomain(): void {
	load_plugin_textdomain( 'sbek-companion', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'sbek_companion_load_textdomain', 0 );

/**
 * Cache-busting version for a plugin asset (its modification time).
 */
function sbek_companion_asset_version( string $relative_path ): string {
	$file = SBEK_COMPANION_DIR . '/' . ltrim( $relative_path, '/' );

	return file_exists( $file ) ? (string) filemtime( $file ) : SBEK_COMPANION_VERSION;
}

// Order matters: i18n.php routes the URL language when it is included (before
// WordPress parses the request), so it comes first.
$sbek_companion_modules = array(
	'includes/i18n.php',
	'includes/settings.php',
	'includes/cache.php',
	'includes/security.php',
	'includes/term-names.php',

	'includes/admin/post-meta.php',
	'includes/admin/translation.php',
	'includes/admin/settings-panels.php',
	'includes/admin/dashboard-widget.php',
	'includes/admin/login.php',

	'includes/frontend/article.php',
	'includes/frontend/newsletter.php',
	'includes/frontend/seo.php',
	'includes/frontend/integrations.php',
);

foreach ( $sbek_companion_modules as $sbek_companion_module ) {
	require SBEK_COMPANION_DIR . '/' . $sbek_companion_module;
}
unset( $sbek_companion_modules, $sbek_companion_module );

// Demo content: wp-admin (Sbek Theme → Tools) and WP-CLI only.
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require SBEK_COMPANION_DIR . '/includes/demo/class-sbek-demo-importer.php';
	require SBEK_COMPANION_DIR . '/includes/admin/tools.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require SBEK_COMPANION_DIR . '/includes/cli/class-sbek-demo-command.php';
}

register_activation_hook( __FILE__, 'sbek_companion_activate' );

/**
 * Activation: language terms, settings migrated from the theme, fresh caches.
 */
function sbek_companion_activate(): void {
	if ( ! sbek_companion_theme_is_active() ) {
		return;
	}

	sbek_companion_maybe_upgrade();
}
