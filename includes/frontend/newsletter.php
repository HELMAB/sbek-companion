<?php
/**
 * Newsletter subscriptions — self-contained, no third-party service.
 *
 * Subscribers are stored as a private `sbek_subscriber` post type (title =
 * email address) so editors get a native admin list. Signup is double
 * opt-in:
 *
 *   1. The footer form posts to admin-post.php. The handler checks the
 *      honeypot, the request origin, a per-IP throttle and the email, then
 *      stores the address as `pending` and emails a confirmation link.
 *   2. The link (admin-post.php?action=sbek_newsletter&do=confirm) marks it
 *      `confirmed`. The same token powers the unsubscribe link
 *      (sbek_subscriber_unsubscribe_url()), which marks it `unsubscribed`.
 *
 * Every step redirects back with a ?subscribed= flag that the form reads to
 * show a message.
 *
 * The form carries a nonce (SBEK_SUBSCRIBE_NONCE), checked before anything
 * else. Visitors who aren't logged in all share one nonce, so page-cached
 * footers keep working while it is valid (12–24 hours). A page cached for
 * longer gets the `expired` message; its ?subscribed= URL is one page caches
 * usually don't serve from cache, so the form there has a fresh nonce for the
 * retry. The same-origin check and double opt-in remain as further guards.
 *
 * Subscribers saved before double opt-in existed have no status meta and
 * count as confirmed.
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

const SBEK_SUBSCRIBE_MAX_PER_HOUR = 5;
const SBEK_SUBSCRIBE_NONCE        = 'sbek_subscribe';

function sbek_register_subscriber_cpt(): void {
	register_post_type(
		'sbek_subscriber',
		array(
			'labels'          => array(
				'name'          => __( 'Subscribers', 'sbek-companion' ),
				'singular_name' => __( 'Subscriber', 'sbek-companion' ),
				'menu_name'     => __( 'Subscribers', 'sbek-companion' ),
				'search_items'  => __( 'Search Subscribers', 'sbek-companion' ),
				'not_found'     => __( 'No subscribers yet.', 'sbek-companion' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'show_in_rest'    => false,
			'menu_icon'       => 'dashicons-email',
			'capability_type' => array( 'sbek_subscriber', 'sbek_subscribers' ),
			'capabilities'    => array(
				// Personal data: only administrators see or delete the list.
				'edit_posts'             => 'manage_options',
				'edit_others_posts'      => 'manage_options',
				'delete_posts'           => 'manage_options',
				'delete_others_posts'    => 'manage_options',
				'read_private_posts'     => 'manage_options',
				'edit_private_posts'     => 'manage_options',
				'delete_private_posts'   => 'manage_options',
				'edit_published_posts'   => 'manage_options',
				'delete_published_posts' => 'manage_options',
				'publish_posts'          => 'do_not_allow',
				'create_posts'           => 'do_not_allow', // Capture only via the front-end form.
			),
			'map_meta_cap'    => true,
			'supports'        => array( 'title' ),
			'rewrite'         => false,
			'query_var'       => false,
		)
	);
}
add_action( 'init', 'sbek_register_subscriber_cpt' );

// ─── Status & tokens ───────────────────────────────────────────────────────────

/**
 * Subscription status: pending | confirmed | unsubscribed.
 */
function sbek_subscriber_status( int $id ): string {
	$status = (string) get_post_meta( $id, '_sbek_sub_status', true );

	return in_array( $status, array( 'pending', 'confirmed', 'unsubscribed' ), true ) ? $status : 'confirmed';
}

/**
 * Translated label for a status.
 */
function sbek_subscriber_status_label( string $status ): string {
	$labels = array(
		'pending'      => __( 'Waiting for confirmation', 'sbek-companion' ),
		'confirmed'    => __( 'Confirmed', 'sbek-companion' ),
		'unsubscribed' => __( 'Unsubscribed', 'sbek-companion' ),
	);

	return $labels[ $status ] ?? $status;
}

/**
 * Number of subscribers with a status (legacy rows without one count as
 * confirmed).
 */
function sbek_subscriber_count( string $status = 'confirmed' ): int {
	$meta_query = 'confirmed' === $status
		? array(
			'relation' => 'OR',
			array(
				'key'   => '_sbek_sub_status',
				'value' => 'confirmed',
			),
			array(
				'key'     => '_sbek_sub_status',
				'compare' => 'NOT EXISTS',
			),
		)
		: array(
			array(
				'key'   => '_sbek_sub_status',
				'value' => $status,
			),
		);

	$query = new WP_Query(
		array(
			'post_type'      => 'sbek_subscriber',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		)
	);

	return (int) $query->found_posts;
}

/**
 * Give a subscriber a fresh secret token and return it. Only its hash is
 * stored, so a database leak doesn't expose working links.
 */
function sbek_subscriber_new_token( int $id ): string {
	$token = wp_generate_password( 32, false, false );
	update_post_meta( $id, '_sbek_sub_token', wp_hash( $token ) );

	return $token;
}

/**
 * Link that confirms or cancels a subscription.
 *
 * @param string $task  confirm | unsubscribe.
 */
function sbek_subscriber_link( int $id, string $token, string $task ): string {
	return add_query_arg(
		array(
			'action' => 'sbek_newsletter',
			'do'     => $task,
			'id'     => $id,
			'token'  => $token,
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * One-click unsubscribe link for a subscriber, for newsletters sent from
 * this site. Rotates the token, so call it once per mailing and reuse it.
 */
function sbek_subscriber_unsubscribe_url( int $id ): string {
	return sbek_subscriber_link( $id, sbek_subscriber_new_token( $id ), 'unsubscribe' );
}

// ─── Signup ────────────────────────────────────────────────────────────────────

/**
 * Redirect to a page with a ?subscribed= status flag.
 *
 * @param string      $status Flag value.
 * @param string|null $target Page to return to (default: the referring page).
 */
function sbek_subscribe_redirect( string $status, ?string $target = null ): void {
	if ( null === $target ) {
		$referer = wp_get_referer(); // Validated against allowed hosts by core.
		$target  = $referer ? $referer : home_url( '/' );
	}

	wp_safe_redirect( add_query_arg( 'subscribed', $status, remove_query_arg( 'subscribed', $target ) ) . '#newsletter' );
	exit;
}

/**
 * Whether a POST came from a page on this site (Origin, else Referer). A
 * request with neither header (some privacy tools strip both) is allowed:
 * double opt-in still guards it.
 */
function sbek_subscribe_same_origin(): bool {
	$source = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
	if ( '' === $source || 'null' === $source ) {
		$source = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
	}
	if ( '' === $source ) {
		return true;
	}

	$host = wp_parse_url( $source, PHP_URL_HOST );

	return $host && strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
}

function sbek_handle_subscribe(): void {
	$nonce = isset( $_POST['sbek_subscribe_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sbek_subscribe_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, SBEK_SUBSCRIBE_NONCE ) ) {
		sbek_subscribe_redirect( 'expired' );
	}

	// Honeypot: bots fill the hidden field. Pretend success, store nothing.
	if ( ! empty( $_POST['sbek_website'] ) ) {
		sbek_subscribe_redirect( 'pending' );
	}

	if ( ! sbek_subscribe_same_origin() ) {
		sbek_subscribe_redirect( 'error' );
	}

	$ip_hash      = sbek_subscriber_ip_hash();
	$throttle_key = 'sbek_sub_rate_' . md5( $ip_hash );
	$attempts     = (int) get_transient( $throttle_key );
	if ( $ip_hash && $attempts >= SBEK_SUBSCRIBE_MAX_PER_HOUR ) {
		sbek_subscribe_redirect( 'error' );
	}
	if ( $ip_hash ) {
		set_transient( $throttle_key, $attempts + 1, HOUR_IN_SECONDS );
	}

	$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	if ( ! is_email( $email ) ) {
		sbek_subscribe_redirect( 'error' );
	}

	$lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
	if ( ! isset( sbek_languages()[ $lang ] ) ) {
		$lang = SBEK_DEFAULT_LANGUAGE;
	}

	$existing = get_posts(
		array(
			'post_type'              => 'sbek_subscriber',
			'post_status'            => 'any',
			'title'                  => $email,
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);

	if ( $existing ) {
		$post_id = (int) $existing[0];
		if ( 'confirmed' === sbek_subscriber_status( $post_id ) ) {
			sbek_subscribe_redirect( 'duplicate' );
		}
		// Pending or unsubscribed: (re)start the confirmation.
		update_post_meta( $post_id, '_sbek_sub_status', 'pending' );
		update_post_meta( $post_id, '_sbek_sub_lang', $lang );
	} else {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'sbek_subscriber',
				'post_title'  => $email,
				'post_status' => 'private',
				'meta_input'  => array(
					'_sbek_sub_lang'   => $lang,
					'_sbek_sub_ip'     => $ip_hash,
					'_sbek_sub_status' => 'pending',
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			sbek_subscribe_redirect( 'error' );
		}
	}

	sbek_subscribe_redirect( sbek_send_confirmation_email( (int) $post_id, $email, $lang ) ? 'pending' : 'mailfail' );
}
add_action( 'admin_post_nopriv_sbek_subscribe', 'sbek_handle_subscribe' );
add_action( 'admin_post_sbek_subscribe', 'sbek_handle_subscribe' );

/**
 * Email the confirm link, written in the subscriber's language.
 */
function sbek_send_confirmation_email( int $id, string $email, string $lang ): bool {
	$token  = sbek_subscriber_new_token( $id );
	$locale = sbek_languages()[ $lang ]['locale'] ?? '';
	$switch = $locale && switch_to_locale( $locale );

	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	if ( 'en' === $lang ) {
		$site = (string) ( sbek_companion_setting( 'name_en', '' ) ?: $site );
	}

	/* translators: %s: site name. */
	$subject = sprintf( __( 'Confirm your subscription to %s', 'sbek-companion' ), $site );
	$body    = implode(
		"\n\n",
		array(
			/* translators: %s: site name. */
			sprintf( __( 'Someone (hopefully you) asked to receive the top stories from %s at this address.', 'sbek-companion' ), $site ),
			__( 'To confirm, open this link:', 'sbek-companion' ) . "\n" . sbek_subscriber_link( $id, $token, 'confirm' ),
			__( 'If you did not ask for this, ignore this email and you will not be subscribed.', 'sbek-companion' ),
			__( 'To unsubscribe at any time:', 'sbek-companion' ) . "\n" . sbek_subscriber_link( $id, $token, 'unsubscribe' ),
		)
	);

	$sent = wp_mail( $email, $subject, $body );

	if ( $switch ) {
		restore_previous_locale();
	}

	return $sent;
}

/**
 * Confirm / unsubscribe links from the emails.
 */
function sbek_handle_newsletter_link(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the per-subscriber token is the credential.
	$task  = sanitize_key( wp_unslash( $_GET['do'] ?? '' ) );
	$id    = absint( $_GET['id'] ?? 0 );
	$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
	// phpcs:enable

	$post   = $id ? get_post( $id ) : null;
	$stored = $post && 'sbek_subscriber' === $post->post_type ? (string) get_post_meta( $id, '_sbek_sub_token', true ) : '';
	$lang   = $post ? (string) get_post_meta( $id, '_sbek_sub_lang', true ) : '';
	$home   = sbek_home_url( isset( sbek_languages()[ $lang ] ) ? $lang : SBEK_DEFAULT_LANGUAGE );

	if ( '' === $stored || '' === $token || ! hash_equals( $stored, wp_hash( $token ) ) || ! in_array( $task, array( 'confirm', 'unsubscribe' ), true ) ) {
		sbek_subscribe_redirect( 'invalid', $home );
	}

	if ( 'confirm' === $task ) {
		if ( 'unsubscribed' === sbek_subscriber_status( $id ) ) {
			sbek_subscribe_redirect( 'invalid', $home ); // An old email can't undo an unsubscribe.
		}
		update_post_meta( $id, '_sbek_sub_status', 'confirmed' );
		if ( ! get_post_meta( $id, '_sbek_sub_confirmed', true ) ) {
			update_post_meta( $id, '_sbek_sub_confirmed', gmdate( 'Y-m-d H:i:s' ) );
		}
		sbek_subscribe_redirect( 'confirmed', $home );
	}

	update_post_meta( $id, '_sbek_sub_status', 'unsubscribed' );
	sbek_subscribe_redirect( 'unsubscribed', $home );
}
add_action( 'admin_post_nopriv_sbek_newsletter', 'sbek_handle_newsletter_link' );
add_action( 'admin_post_sbek_newsletter', 'sbek_handle_newsletter_link' );

/**
 * Salted hash of the submitter's IP — enough to throttle and spot abuse
 * without storing raw personal data.
 */
function sbek_subscriber_ip_hash(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return filter_var( $ip, FILTER_VALIDATE_IP ) ? wp_hash( $ip ) : '';
}

/**
 * Message for the current ?subscribed= flag, or null when there is none.
 *
 * @return array{message:string,error:bool}|null
 */
function sbek_subscribe_notice(): ?array {
	if ( ! isset( $_GET['subscribed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag.
		return null;
	}

	$messages = array(
		'pending'      => array( __( 'Almost done! Check your inbox and click the link to confirm your subscription.', 'sbek-companion' ), false ),
		'confirmed'    => array( __( 'Thanks! Your subscription is confirmed.', 'sbek-companion' ), false ),
		'duplicate'    => array( __( 'This email address is already subscribed.', 'sbek-companion' ), false ),
		'unsubscribed' => array( __( 'You have been unsubscribed. Sorry to see you go.', 'sbek-companion' ), false ),
		'invalid'      => array( __( 'That link is not valid any more. Please sign up again.', 'sbek-companion' ), true ),
		'expired'      => array( __( 'The form had expired. Please enter your email address again.', 'sbek-companion' ), true ),
		'mailfail'     => array( __( 'We could not send the confirmation email. Please try again later.', 'sbek-companion' ), true ),
		'error'        => array( __( 'Sorry, that did not work. Please check the address and try again.', 'sbek-companion' ), true ),
	);

	$flag = sanitize_key( wp_unslash( $_GET['subscribed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$item = $messages[ $flag ] ?? $messages['error'];

	return array(
		'message' => $item[0],
		'error'   => $item[1],
	);
}

// ─── Admin: CSV export ─────────────────────────────────────────────────────────

const SBEK_EXPORT_SUBSCRIBERS_ACTION = 'sbek_export_subscribers';

/**
 * Nonce-protected download link for the subscriber CSV.
 */
function sbek_subscribers_export_url(): string {
	return wp_nonce_url( admin_url( 'admin-post.php?action=' . SBEK_EXPORT_SUBSCRIBERS_ACTION ), SBEK_EXPORT_SUBSCRIBERS_ACTION );
}

/**
 * Stream every subscriber as CSV (email, language, status, dates).
 * Administrators only. Mail only the `confirmed` rows.
 */
function sbek_export_subscribers(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'sbek-companion' ), 403 );
	}

	check_admin_referer( SBEK_EXPORT_SUBSCRIBERS_ACTION );

	$ids = get_posts(
		array(
			'post_type'      => 'sbek_subscriber',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="subscribers-' . wp_date( 'Y-m-d' ) . '.csv"' );

	echo "\xEF\xBB\xBF"; // UTF-8 BOM so spreadsheet apps detect the encoding.
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the response body, not a file.
	fputcsv( $out, array( 'email', 'language', 'status', 'subscribed', 'confirmed' ) );

	// A leading = + - @ would be run as a formula by spreadsheet apps.
	$cell = static fn( string $value ): string => preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;

	foreach ( $ids as $id ) {
		fputcsv(
			$out,
			array(
				$cell( get_the_title( $id ) ),
				$cell( (string) get_post_meta( $id, '_sbek_sub_lang', true ) ),
				sbek_subscriber_status( $id ),
				get_the_date( 'Y-m-d H:i', $id ),
				(string) get_post_meta( $id, '_sbek_sub_confirmed', true ),
			)
		);
	}

	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}
add_action( 'admin_post_' . SBEK_EXPORT_SUBSCRIBERS_ACTION, 'sbek_export_subscribers' );

/**
 * "Export CSV" button above the Subscribers list.
 *
 * @param string $which top | bottom.
 */
function sbek_subscribers_export_button( string $which ): void {
	if ( 'top' !== $which || 'sbek_subscriber' !== get_current_screen()?->post_type || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	printf(
		'<div class="alignleft actions"><a class="button" href="%s">%s</a></div>',
		esc_url( sbek_subscribers_export_url() ),
		esc_html__( 'Export CSV', 'sbek-companion' )
	);
}
add_action( 'manage_posts_extra_tablenav', 'sbek_subscribers_export_button' );

// ─── Admin: list columns ───────────────────────────────────────────────────────

/**
 * @param array<string,string> $columns List columns.
 * @return array<string,string>
 */
function sbek_subscriber_columns( array $columns ): array {
	$date = $columns['date'] ?? null;
	unset( $columns['date'] );

	$columns['sbek_status'] = __( 'Status', 'sbek-companion' );
	$columns['sbek_lang']   = __( 'Language', 'sbek-companion' );
	if ( $date ) {
		$columns['date'] = $date;
	}

	return $columns;
}
add_filter( 'manage_sbek_subscriber_posts_columns', 'sbek_subscriber_columns' );

/**
 * @param string $column  Column key.
 * @param int    $post_id Subscriber ID.
 */
function sbek_subscriber_column_content( string $column, int $post_id ): void {
	if ( 'sbek_status' === $column ) {
		echo esc_html( sbek_subscriber_status_label( sbek_subscriber_status( $post_id ) ) );
	} elseif ( 'sbek_lang' === $column ) {
		$lang = (string) get_post_meta( $post_id, '_sbek_sub_lang', true );
		echo esc_html( sbek_languages()[ $lang ]['label'] ?? $lang );
	}
}
add_action( 'manage_sbek_subscriber_posts_custom_column', 'sbek_subscriber_column_content', 10, 2 );

/**
 * Footer signup form, in the theme's "About us" footer column (toggle:
 * Sbek Theme → Contact & Footer → Newsletter).
 */
function sbek_render_newsletter_form(): void {
	if ( ! sbek_companion_setting( 'show_newsletter', 1 ) ) {
		return;
	}

	require SBEK_COMPANION_DIR . '/templates/newsletter-form.php';
}
add_action( 'sbek_footer_about', 'sbek_render_newsletter_form' );
