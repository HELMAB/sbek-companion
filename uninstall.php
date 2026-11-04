<?php
/**
 * Runs when the plugin is deleted from Plugins → Installed Plugins (not on
 * deactivation). Removes everything the plugin stored; the theme's own data
 * (sbek_site_settings, theme mods, sbek_color / sbek_home_order term meta)
 * is left alone.
 *
 * Kept on purpose: demo posts, images, menus and widgets that were imported
 * are ordinary site content by now, so they stay. Only the records that let
 * the plugin find them again (`_sbek_demo`, `sbek_demo_manifest`) go; remove
 * the demo under Tools before deleting the plugin to get rid of it.
 *
 * The plugin isn't loaded here, so names are spelled out instead of using
 * its constants and functions.
 *
 * @package sbek-companion
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete the plugin's data on the current site.
 */
function sbek_companion_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'sbek_companion', 'sbek_companion_version', 'sbek_cache_version', 'sbek_demo_manifest', 'sbek_demo_job', 'sbek_demo_lock' ) as $option ) {
		delete_option( $option );
	}

	// Newsletter subscribers (personal data), in every status including trash.
	do {
		$ids = get_posts(
			array(
				'post_type'   => 'sbek_subscriber',
				'post_status' => array_keys( get_post_stati() ),
				'numberposts' => 200,
				'fields'      => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	} while ( $ids );

	// News details, translation links and demo markers on posts and pages.
	foreach ( array( '_sbek_trending_score', '_sbek_video_url', '_sbek_source_name', '_sbek_source_url', '_sbek_is_sponsored', '_sbek_is_breaking', '_sbek_translation_id', '_sbek_demo' ) as $key ) {
		delete_post_meta_by_key( $key );
	}

	// English category and tag names / descriptions.
	foreach ( array( 'sbek_name_en', 'sbek_description_en' ) as $key ) {
		delete_metadata( 'term', 0, $key, '', true );
	}

	// The `language` terms and their links to posts. The taxonomy has to be
	// registered for wp_delete_term() to accept it.
	if ( ! taxonomy_exists( 'language' ) ) {
		register_taxonomy( 'language', array( 'post', 'page' ), array( 'public' => false ) );
	}
	$terms = get_terms(
		array(
			'taxonomy'   => 'language',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	foreach ( is_array( $terms ) ? $terms : array() as $term_id ) {
		wp_delete_term( (int) $term_id, 'language' );
	}

	// Transients: query caches, term-language caches, login lockout and
	// newsletter throttle counters. They have per-ID names, so they are
	// matched by prefix. (With a persistent object cache they aren't in the
	// options table and simply expire.)
	foreach ( array( 'sbek_q_', 'sbek_term_lang_', 'sbek_login_attempts_', 'sbek_sub_rate_' ) as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API deletes transients by prefix; runs once on uninstall.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $sbek_site_id ) {
		switch_to_blog( (int) $sbek_site_id );
		sbek_companion_uninstall_site();
		restore_current_blog();
	}
} else {
	sbek_companion_uninstall_site();
}
