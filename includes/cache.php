<?php
/**
 * Caching for the theme's block queries.
 *
 * The theme runs every block query through sbek_post_ids(), which offers two
 * hooks: `sbek_pre_post_ids` (return an ID list to skip the query) and
 * `sbek_post_ids_queried` (the fresh result). This file caches those ID lists
 * in transients keyed `sbek_q_{name}_{lang}_v{version}`. The version (option
 * `sbek_cache_version`) is bumped whenever content changes, so there is no
 * list of keys to flush — stale entries simply expire.
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

function sbek_cache_version(): int {
	return (int) get_option( 'sbek_cache_version', 1 );
}

function sbek_bump_cache_version(): void {
	static $bumped = false; // Once per request is enough.

	if ( ! $bumped ) {
		$bumped = true;
		update_option( 'sbek_cache_version', sbek_cache_version() + 1, true );
	}
}

/**
 * Transient key for a cached query.
 */
function sbek_query_cache_key( string $name ): string {
	return sprintf( 'sbek_q_%s_%s_v%d', $name, sbek_lang(), sbek_cache_version() );
}

/**
 * Serve a cached ID list.
 *
 * @param int[]|null $ids  Null unless another callback already answered.
 * @param string     $name Query name.
 * @return int[]|null
 */
function sbek_cache_pre_post_ids( $ids, string $name ) {
	if ( is_array( $ids ) ) {
		return $ids;
	}

	$cached = get_transient( sbek_query_cache_key( $name ) );

	return is_array( $cached ) ? $cached : null;
}
add_filter( 'sbek_pre_post_ids', 'sbek_cache_pre_post_ids', 10, 2 );

/**
 * Store a fresh ID list.
 *
 * @param int[]  $ids  Post IDs.
 * @param string $name Query name.
 * @param array  $args Query args.
 * @param int    $ttl  Lifetime in seconds.
 */
function sbek_cache_store_post_ids( array $ids, string $name, array $args, int $ttl ): void {
	set_transient( sbek_query_cache_key( $name ), $ids, $ttl );
}
add_action( 'sbek_post_ids_queried', 'sbek_cache_store_post_ids', 10, 4 );

/**
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 */
function sbek_bump_cache_on_save( int $post_id, WP_Post $post ): void {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}

	sbek_bump_cache_version();
}
add_action( 'save_post', 'sbek_bump_cache_on_save', 99, 2 );

/**
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 */
function sbek_bump_cache_on_status( string $new_status, string $old_status, WP_Post $post ): void {
	if ( $new_status !== $old_status && 'post' === $post->post_type ) {
		sbek_bump_cache_version();
	}
}
add_action( 'transition_post_status', 'sbek_bump_cache_on_status', 10, 3 );

foreach ( array( 'deleted_post', 'update_option_sticky_posts', 'edited_category', 'delete_category', 'edited_post_tag', 'delete_post_tag', 'sbek_theme_options_saved', 'update_option_sbek_site_settings' ) as $sbek_hook ) {
	add_action( $sbek_hook, 'sbek_bump_cache_version' );
}
unset( $sbek_hook );
