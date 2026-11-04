<?php
/**
 * "Translation" meta box: pairs a post with its counterpart in the other
 * language via `_sbek_translation_id` (stored on both posts). Drives hreflang
 * tags and the header language switcher.
 *
 * Integrity rules enforced on save: same post type, different language, not
 * itself, editable by the current user; re-linking or unlinking also clears
 * the previous partner's back-reference so pairs never go one-sided.
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

function sbek_register_translation_meta(): void {
	foreach ( array( 'post', 'page' ) as $post_type ) {
		register_post_meta(
			$post_type,
			'_sbek_translation_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn( $allowed, $key, $post_id ): bool => current_user_can( 'edit_post', $post_id ),
				'show_in_rest'      => false,
			)
		);
	}
}
add_action( 'init', 'sbek_register_translation_meta' );

function sbek_add_translation_meta_box(): void {
	add_meta_box( 'sbek_translation', __( 'Translation', 'sbek-companion' ), 'sbek_render_translation_meta_box', array( 'post', 'page' ), 'side', 'default' );
}
add_action( 'add_meta_boxes', 'sbek_add_translation_meta_box' );

function sbek_render_translation_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'sbek_save_translation', 'sbek_translation_nonce' );

	$linked_id = (int) get_post_meta( $post->ID, '_sbek_translation_id', true );
	$own_lang  = sbek_post_language( $post->ID ) ?: SBEK_DEFAULT_LANGUAGE;
	$others    = array_diff( array_keys( sbek_languages() ), array( $own_lang ) );

	// Recent posts in the other language(s), plus the current link.
	$candidates = get_posts(
		array(
			'post_type'          => $post->post_type,
			'post_status'        => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page'     => 100,
			'post__not_in'       => array( $post->ID ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			'no_found_rows'      => true,
			'sbek_all_languages' => true,
			'tax_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'language',
					'field'    => 'slug',
					'terms'    => array_values( $others ),
				),
			),
		)
	);

	if ( $linked_id && ! in_array( $linked_id, wp_list_pluck( $candidates, 'ID' ), true ) ) {
		$linked = get_post( $linked_id );
		if ( $linked ) {
			array_unshift( $candidates, $linked );
		}
	}
	?>
	<p>
		<label for="sbek_translation_id" class="screen-reader-text"><?php esc_html_e( 'Translated version', 'sbek-companion' ); ?></label>
		<select id="sbek_translation_id" name="sbek_translation_id" class="widefat">
			<option value="0"><?php esc_html_e( '— Not translated —', 'sbek-companion' ); ?></option>
			<?php foreach ( $candidates as $candidate ) : ?>
				<option value="<?php echo esc_attr( (string) $candidate->ID ); ?>" <?php selected( $linked_id, $candidate->ID ); ?>>
					<?php echo esc_html( sprintf( '[%s] %s (#%d)', strtoupper( sbek_post_language( $candidate->ID ) ?: '?' ), get_the_title( $candidate ) ?: __( '(no title)', 'sbek-companion' ), $candidate->ID ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>
	<p class="sbek-translation-search">
		<label for="sbek_translation_search" class="screen-reader-text"><?php esc_html_e( 'Search for an older post', 'sbek-companion' ); ?></label>
		<input type="search" id="sbek_translation_search" class="widefat" placeholder="<?php esc_attr_e( 'Search older posts by title…', 'sbek-companion' ); ?>"
			data-sbek-translation-search
			data-post="<?php echo esc_attr( (string) $post->ID ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'sbek_translation_search' ) ); ?>"
			data-none="<?php esc_attr_e( 'No matching posts.', 'sbek-companion' ); ?>"
			data-found="<?php esc_attr_e( 'Matches were added to the list above.', 'sbek-companion' ); ?>"
			data-listed="<?php esc_attr_e( 'The matching posts are already in the list above.', 'sbek-companion' ); ?>"
			autocomplete="off">
		<span class="description" role="status" data-sbek-translation-status></span>
	</p>
	<p class="description"><?php esc_html_e( 'Pick the same story in the other language. The list shows the 100 most recent; search to find older ones. The link is saved on both posts.', 'sbek-companion' ); ?></p>
	<?php
	// One-click draft in a language that has no version yet.
	if ( ! $linked_id && 'auto-draft' !== $post->post_status && current_user_can( get_post_type_object( $post->post_type )->cap->create_posts ) ) :
		foreach ( $others as $other ) :
			?>
			<p>
				<a class="button" href="<?php echo esc_url( sbek_create_translation_url( $post->ID, $other ) ); ?>">
					<?php
					/* translators: %s: language name, e.g. "English". */
					echo esc_html( sprintf( __( 'Create the %s version', 'sbek-companion' ), sbek_languages()[ $other ]['label'] ) );
					?>
				</a>
			</p>
			<p class="description"><?php esc_html_e( 'Copies this post into a linked draft (title, text, image, categories, tags and news details) for you to translate. Save your changes here first.', 'sbek-companion' ); ?></p>
			<?php
		endforeach;
	endif;
}

/**
 * Admin-ajax: posts in the other language(s) whose title matches a search.
 */
function sbek_ajax_translation_search(): void {
	check_ajax_referer( 'sbek_translation_search', 'nonce' );

	$post_id = absint( $_GET['post'] ?? 0 );
	$term    = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
	$post    = get_post( $post_id );

	if ( ! $post || ! current_user_can( 'edit_post', $post_id ) || mb_strlen( $term ) < 2 ) {
		wp_send_json_success( array() );
	}

	$own_lang = sbek_post_language( $post_id ) ?: SBEK_DEFAULT_LANGUAGE;
	$others   = array_values( array_diff( array_keys( sbek_languages() ), array( $own_lang ) ) );
	$found    = get_posts(
		array(
			'post_type'          => $post->post_type,
			'post_status'        => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			's'                  => $term,
			'search_columns'     => array( 'post_title' ),
			'posts_per_page'     => 20,
			'post__not_in'       => array( $post_id ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			'no_found_rows'      => true,
			'sbek_all_languages' => true,
			'tax_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'language',
					'field'    => 'slug',
					'terms'    => $others,
				),
			),
		)
	);

	wp_send_json_success(
		array_map(
			static fn( WP_Post $item ): array => array(
				'id'    => $item->ID,
				'label' => sprintf( '[%s] %s (#%d)', strtoupper( sbek_post_language( $item->ID ) ?: '?' ), get_the_title( $item ) ?: __( '(no title)', 'sbek-companion' ), $item->ID ),
			),
			$found
		)
	);
}
add_action( 'wp_ajax_sbek_translation_search', 'sbek_ajax_translation_search' );

function sbek_translation_admin_assets( string $hook ): void {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	wp_enqueue_script(
		'sbek-admin-translation',
		SBEK_COMPANION_URL . '/assets/js/admin-translation.js',
		array(),
		sbek_companion_asset_version( 'assets/js/admin-translation.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'admin_enqueue_scripts', 'sbek_translation_admin_assets' );

// ─── Create a translation draft ────────────────────────────────────────────────

const SBEK_CREATE_TRANSLATION_ACTION = 'sbek_create_translation';

function sbek_create_translation_url( int $post_id, string $lang ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => SBEK_CREATE_TRANSLATION_ACTION,
				'post'   => $post_id,
				'lang'   => $lang,
			),
			admin_url( 'admin-post.php' )
		),
		SBEK_CREATE_TRANSLATION_ACTION . '_' . $post_id
	);
}

/**
 * Copy a post into a linked draft in another language and open it.
 */
function sbek_create_translation(): void {
	$post_id = absint( $_GET['post'] ?? 0 );
	$lang    = sanitize_key( wp_unslash( $_GET['lang'] ?? '' ) );

	check_admin_referer( SBEK_CREATE_TRANSLATION_ACTION . '_' . $post_id );

	$source = get_post( $post_id );
	if ( ! $source || ! isset( sbek_languages()[ $lang ] ) || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( get_post_type_object( $source->post_type )->cap->create_posts ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'sbek-companion' ), 403 );
	}

	if ( ( sbek_post_language( $post_id ) ?: SBEK_DEFAULT_LANGUAGE ) === $lang ) {
		wp_die( esc_html__( 'This post is already in that language.', 'sbek-companion' ), 400 );
	}

	// Already translated (e.g. a double click): open the existing version.
	$existing = (int) get_post_meta( $post_id, '_sbek_translation_id', true );
	if ( $existing && get_post( $existing ) && sbek_post_language( $existing ) === $lang ) {
		wp_safe_redirect( get_edit_post_link( $existing, 'raw' ) );
		exit;
	}

	$new_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'      => $source->post_type,
				'post_status'    => 'draft',
				'post_title'     => $source->post_title,
				'post_content'   => $source->post_content,
				'post_excerpt'   => $source->post_excerpt,
				'post_author'    => get_current_user_id(),
				'post_parent'    => $source->post_parent,
				'menu_order'     => $source->menu_order,
				'comment_status' => $source->comment_status,
			)
		),
		true
	);

	if ( is_wp_error( $new_id ) ) {
		wp_die( esc_html( $new_id->get_error_message() ), 500 );
	}

	foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
		if ( is_object_in_taxonomy( $source->post_type, $taxonomy ) ) {
			wp_set_object_terms( $new_id, wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) ), $taxonomy );
		}
	}

	$format = get_post_format( $source );
	if ( $format ) {
		set_post_format( $new_id, $format );
	}

	$thumbnail = get_post_thumbnail_id( $source );
	if ( $thumbnail ) {
		set_post_thumbnail( $new_id, $thumbnail );
	}

	// Editorial details travel; ranking flags stay with the original.
	foreach ( array( '_sbek_video_url', '_sbek_source_name', '_sbek_source_url', '_sbek_is_sponsored', '_wp_page_template' ) as $key ) {
		$value = get_post_meta( $post_id, $key, true );
		if ( '' !== $value && false !== $value ) {
			update_post_meta( $new_id, $key, $value );
		}
	}

	wp_set_object_terms( $new_id, array( $lang ), 'language' );
	sbek_set_translation( $post_id, $new_id );

	wp_safe_redirect( get_edit_post_link( $new_id, 'raw' ) );
	exit;
}
add_action( 'admin_post_' . SBEK_CREATE_TRANSLATION_ACTION, 'sbek_create_translation' );

// ─── Posts list: language filter ───────────────────────────────────────────────

/**
 * Language dropdown above the Posts / Pages lists.
 */
function sbek_language_filter_dropdown( string $post_type ): void {
	if ( ! is_object_in_taxonomy( $post_type, 'language' ) ) {
		return;
	}

	$current = sanitize_key( wp_unslash( $_GET['sbek_language'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
	?>
	<label for="sbek-language-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by language', 'sbek-companion' ); ?></label>
	<select name="sbek_language" id="sbek-language-filter">
		<option value=""><?php esc_html_e( 'All languages', 'sbek-companion' ); ?></option>
		<?php foreach ( sbek_languages() as $slug => $language ) : ?>
			<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>><?php echo esc_html( $language['label'] ); ?></option>
		<?php endforeach; ?>
		<option value="none" <?php selected( $current, 'none' ); ?>><?php esc_html_e( 'No language', 'sbek-companion' ); ?></option>
	</select>
	<?php
}
add_action( 'restrict_manage_posts', 'sbek_language_filter_dropdown' );

function sbek_apply_language_filter( WP_Query $query ): void {
	global $pagenow;

	if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
		return;
	}

	$lang = sanitize_key( wp_unslash( $_GET['sbek_language'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.

	if ( 'none' === $lang ) {
		$clause = array(
			'taxonomy' => 'language',
			'operator' => 'NOT EXISTS',
		);
	} elseif ( isset( sbek_languages()[ $lang ] ) ) {
		$clause = array(
			'taxonomy' => 'language',
			'field'    => 'slug',
			'terms'    => array( $lang ),
		);
	} else {
		return;
	}

	$tax_query   = (array) $query->get( 'tax_query' );
	$tax_query[] = $clause;
	$query->set( 'tax_query', $tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
}
add_action( 'pre_get_posts', 'sbek_apply_language_filter' );

/**
 * Point $post_id at $target (0 = unlink), keeping both sides consistent.
 */
function sbek_set_translation( int $post_id, int $target ): void {
	$previous = (int) get_post_meta( $post_id, '_sbek_translation_id', true );

	if ( $previous && $previous !== $target && (int) get_post_meta( $previous, '_sbek_translation_id', true ) === $post_id ) {
		delete_post_meta( $previous, '_sbek_translation_id' );
	}

	if ( ! $target ) {
		delete_post_meta( $post_id, '_sbek_translation_id' );
		return;
	}

	// The target may already be paired with a third post: release it.
	$targets_previous = (int) get_post_meta( $target, '_sbek_translation_id', true );
	if ( $targets_previous && $targets_previous !== $post_id ) {
		delete_post_meta( $targets_previous, '_sbek_translation_id' );
	}

	update_post_meta( $post_id, '_sbek_translation_id', $target );
	update_post_meta( $target, '_sbek_translation_id', $post_id );
}

/**
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post.
 */
function sbek_save_translation_meta( int $post_id, WP_Post $post ): void {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! isset( $_POST['sbek_translation_id'] ) ) {
		return;
	}

	$nonce = isset( $_POST['sbek_translation_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sbek_translation_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'sbek_save_translation' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$target = absint( $_POST['sbek_translation_id'] );

	if ( $target ) {
		$target_post = get_post( $target );
		$valid       = $target_post
			&& $target !== $post_id
			&& $target_post->post_type === $post->post_type
			&& current_user_can( 'edit_post', $target )
			&& sbek_post_language( $target ) !== ( sbek_post_language( $post_id ) ?: SBEK_DEFAULT_LANGUAGE );

		if ( ! $valid ) {
			return; // Leave the existing link untouched rather than storing garbage.
		}
	}

	sbek_set_translation( $post_id, $target );
}
add_action( 'save_post', 'sbek_save_translation_meta', 30, 2 );
