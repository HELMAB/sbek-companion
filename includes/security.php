<?php
/**
 * Security and performance hardening shared by admin and front-end
 * requests. Each hardening feature is a switch on the Security tab
 * (sbek_security_options() has the defaults) and reads it when its hook
 * runs. Removing the generator tag and the emoji polyfill is always on.
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a Security tab switch is on.
 */
function sbek_security_enabled( string $key ): bool {
	return (bool) sbek_companion_setting( $key, 0 );
}

/**
 * Baseline security response headers.
 */
function sbek_security_headers(): void {
	if ( headers_sent() || ! sbek_security_enabled( 'security_headers' ) ) {
		return;
	}

	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
}
add_action( 'send_headers', 'sbek_security_headers' );

/**
 * The same effect as DISALLOW_FILE_EDIT, without defining a core constant
 * for the whole site: nobody gets the file-editor capabilities.
 *
 * @param string[] $caps Primitive capabilities the user needs.
 * @param string   $cap  Capability being checked.
 * @return string[]
 */
function sbek_disallow_file_edit( array $caps, string $cap ): array {
	if ( in_array( $cap, array( 'edit_files', 'edit_plugins', 'edit_themes' ), true ) && sbek_security_enabled( 'disable_file_edit' ) ) {
		$caps[] = 'do_not_allow';
	}

	return $caps;
}
add_filter( 'map_meta_cap', 'sbek_disallow_file_edit', 10, 2 );

// No generator meta tag. (Asset ?ver= strings are kept on purpose: they are
// the cache-busting mechanism, and hiding them doesn't hide the version.)
remove_action( 'wp_head', 'wp_generator' );

/**
 * XML-RPC is a common brute-force target; off only when the owner says so.
 */
function sbek_xmlrpc_enabled( bool $enabled ): bool {
	return $enabled && ! sbek_security_enabled( 'disable_xmlrpc' );
}
add_filter( 'xmlrpc_enabled', 'sbek_xmlrpc_enabled' );

/**
 * Don't let REST user enumeration (/wp/v2/users) leak author logins to
 * anonymous visitors.
 *
 * @param array<string,mixed> $endpoints REST endpoints.
 * @return array<string,mixed>
 */
function sbek_restrict_user_endpoints( array $endpoints ): array {
	if ( ! is_user_logged_in() && sbek_security_enabled( 'hide_rest_users' ) ) {
		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}

	return $endpoints;
}
add_filter( 'rest_endpoints', 'sbek_restrict_user_endpoints' );

// ─── Login rate limiting: 5 failures per IP → 15-minute lockout ────────────────

const SBEK_LOGIN_MAX_ATTEMPTS = 5;

function sbek_login_attempts_key(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return filter_var( $ip, FILTER_VALIDATE_IP ) ? 'sbek_login_attempts_' . md5( $ip ) : '';
}

/**
 * @param WP_User|WP_Error|null $user     Result so far.
 * @param string                $username Submitted username.
 * @param string                $password Submitted password.
 * @return WP_User|WP_Error|null
 */
function sbek_check_login_lockout( $user, $username, $password ) {
	if ( ( empty( $username ) && empty( $password ) ) || ! sbek_security_enabled( 'login_lockout' ) ) {
		return $user; // Not a submission (initial form render), or lockout off.
	}

	$key = sbek_login_attempts_key();

	if ( $key && (int) get_transient( $key ) >= SBEK_LOGIN_MAX_ATTEMPTS ) {
		return new WP_Error(
			'sbek_too_many_attempts',
			esc_html__( 'Too many failed login attempts. Please try again in 15 minutes.', 'sbek-companion' )
		);
	}

	return $user;
}
add_filter( 'authenticate', 'sbek_check_login_lockout', 30, 3 );

function sbek_record_login_failure(): void {
	$key = sbek_security_enabled( 'login_lockout' ) ? sbek_login_attempts_key() : '';

	if ( $key ) {
		set_transient( $key, (int) get_transient( $key ) + 1, 15 * MINUTE_IN_SECONDS );
	}
}
add_action( 'wp_login_failed', 'sbek_record_login_failure' );

function sbek_clear_login_attempts(): void {
	$key = sbek_login_attempts_key();

	if ( $key ) {
		delete_transient( $key );
	}
}
add_action( 'wp_login', 'sbek_clear_login_attempts' );

/**
 * The emoji polyfill (inline script + stylesheet on every page) isn't needed
 * by any modern browser.
 */
function sbek_disable_emoji(): void {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
}
add_action( 'init', 'sbek_disable_emoji' );
