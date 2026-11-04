<?php
/**
 * The plugin's own settings (option `sbek_companion`), edited on the theme's
 * Appearance → Sbek Theme page through the hooks in admin/settings-panels.php.
 *
 * Keys that used to live in the theme option `sbek_site_settings` (English
 * texts, share image, coordinates, newsletter toggle, analytics, scripts) are
 * copied over once by sbek_companion_maybe_upgrade().
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

const SBEK_COMPANION_OPTION = 'sbek_companion';

/**
 * Default shape of the settings array.
 *
 * @return array<string,mixed>
 */
function sbek_companion_defaults(): array {
	return array(
		// English versions of the theme's texts (empty = reuse the Khmer text).
		'name_en'         => '',
		'tagline_en'      => '',
		'description_en'  => '',
		'address_en'      => '',
		'copyright_en'    => '',
		// SEO.
		'og_image'        => 0,
		'lat'             => '',
		'lng'             => '',
		// Newsletter.
		'show_newsletter' => 1,
		// Integrations.
		'ga4_id'          => '',
		'gtm_id'          => '',
		'header_scripts'  => '',
		'footer_scripts'  => '',
	);
}

function sbek_companion_register_settings(): void {
	register_setting(
		'sbek_companion',
		SBEK_COMPANION_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'sbek_companion_sanitize_settings',
			'default'           => sbek_companion_defaults(),
			'show_in_rest'      => false,
		)
	);
}
// On `init` so the sanitizer also runs for update_option() outside wp-admin.
add_action( 'init', 'sbek_companion_register_settings' );

/**
 * Sanitize the settings array (expects unslashed input; missing keys keep
 * their default, missing toggles default to on).
 *
 * @param mixed $input Raw value.
 * @return array<string,mixed>
 */
function sbek_companion_sanitize_settings( $input ): array {
	$input = is_array( $input ) ? $input : array();
	$clean = sbek_companion_defaults();

	foreach ( array( 'name_en', 'tagline_en', 'copyright_en' ) as $key ) {
		$clean[ $key ] = sanitize_text_field( (string) ( $input[ $key ] ?? '' ) );
	}

	foreach ( array( 'description_en', 'address_en' ) as $key ) {
		$clean[ $key ] = sanitize_textarea_field( (string) ( $input[ $key ] ?? '' ) );
	}

	$image             = absint( $input['og_image'] ?? 0 );
	$clean['og_image'] = ( $image && wp_attachment_is_image( $image ) ) ? $image : 0;

	$lat          = $input['lat'] ?? '';
	$lng          = $input['lng'] ?? '';
	$clean['lat'] = is_numeric( $lat ) && abs( (float) $lat ) <= 90 ? (string) (float) $lat : '';
	$clean['lng'] = is_numeric( $lng ) && abs( (float) $lng ) <= 180 ? (string) (float) $lng : '';

	$clean['show_newsletter'] = array_key_exists( 'show_newsletter', $input ) ? (int) (bool) $input['show_newsletter'] : 1;

	// Tracking IDs: only well-formed ones, or the snippet would load nothing.
	foreach ( sbek_tracking_id_patterns() as $key => $pattern ) {
		$id            = strtoupper( sanitize_text_field( (string) ( $input[ $key ] ?? '' ) ) );
		$clean[ $key ] = preg_match( $pattern, $id ) ? $id : '';
	}

	// Raw scripts only for users allowed to post unfiltered HTML. For anyone
	// else (including WP-CLI / cron, where there is no user) the stored value
	// is kept rather than kses-filtered, so an automated update can't strip it.
	$stored  = (array) get_option( SBEK_COMPANION_OPTION, array() );
	$can_raw = current_user_can( 'unfiltered_html' );
	foreach ( array( 'header_scripts', 'footer_scripts' ) as $key ) {
		$value = (string) ( $input[ $key ] ?? '' );
		if ( $can_raw ) {
			$clean[ $key ] = $value;
		} elseif ( is_user_logged_in() ) {
			$clean[ $key ] = wp_kses_post( $value );
		} else {
			$clean[ $key ] = (string) ( $stored[ $key ] ?? '' );
		}
	}

	return $clean;
}

/**
 * Accepted formats of the tracking IDs (upper-cased before matching).
 *
 * @return array<string,string> setting key => regex.
 */
function sbek_tracking_id_patterns(): array {
	return array(
		'ga4_id' => '/^G-[A-Z0-9]{4,}$/',
		'gtm_id' => '/^GTM-[A-Z0-9]{4,}$/',
	);
}

/**
 * Read a plugin setting (or the whole array when $key is null).
 *
 * @param string|null $key     Setting key.
 * @param mixed       $default Fallback when unset.
 * @return mixed
 */
function sbek_companion_setting( ?string $key = null, $default = null ) {
	$settings = wp_parse_args( (array) get_option( SBEK_COMPANION_OPTION, array() ), sbek_companion_defaults() );

	if ( null === $key ) {
		return $settings;
	}

	return $settings[ $key ] ?? $default;
}

/**
 * URL of the default share image, or ''.
 */
function sbek_companion_image_url( string $key, string $size = 'full' ): string {
	$id = absint( sbek_companion_setting( $key, 0 ) );

	return $id ? (string) wp_get_attachment_image_url( $id, $size ) : '';
}

/**
 * Site logo URL (the theme's custom logo), or ''.
 */
function sbek_companion_logo_url( string $size = 'full' ): string {
	$id = absint( get_theme_mod( 'custom_logo', 0 ) );

	return $id ? (string) wp_get_attachment_image_url( $id, $size ) : '';
}

/**
 * One-off data setup on activation and plugin version changes: language
 * terms, settings copied from the theme option, and the option that used to
 * record the theme version (now this plugin's job).
 */
function sbek_companion_maybe_upgrade(): void {
	if ( get_option( 'sbek_companion_version' ) === SBEK_COMPANION_VERSION ) {
		return;
	}

	sbek_register_language_taxonomy();
	sbek_ensure_language_terms();

	// 1.x of the theme shipped an en/jp layout: drop the obsolete Japanese term.
	$jp = get_term_by( 'slug', 'jp', 'language' );
	if ( $jp instanceof WP_Term ) {
		wp_delete_term( $jp->term_id, 'language' );
	}

	// Settings that moved here from the theme option (first run only).
	if ( false === get_option( SBEK_COMPANION_OPTION ) ) {
		$theme    = (array) get_option( 'sbek_site_settings', array() );
		$migrated = array_intersect_key( $theme, sbek_companion_defaults() );

		// Stored as-is: these values were sanitized when the theme saved them,
		// and re-sanitizing here (no user during activation via WP-CLI) would
		// drop the raw scripts. So the registered sanitizer is unhooked for
		// this one write.
		remove_all_filters( 'sanitize_option_' . SBEK_COMPANION_OPTION );
		add_option( SBEK_COMPANION_OPTION, wp_parse_args( $migrated, sbek_companion_defaults() ) );
		sbek_companion_register_settings();
	}

	delete_option( 'sbek_theme_version' );

	update_option( 'sbek_companion_version', SBEK_COMPANION_VERSION, false );
}
add_action( 'init', 'sbek_companion_maybe_upgrade', 20 );
