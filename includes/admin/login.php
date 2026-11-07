<?php
/**
 * Branded wp-login.php (split layout: brand panel left, form right) — hooks
 * and CSS only, so it survives core updates.
 *
 * Language: core's own login-screen language dropdown handles switching
 * (it appears once more than one language pack is installed), and every
 * string here is a plain __() call, so it follows that choice automatically.
 * The site name, home link and privacy-policy link follow it too
 * (sbek_login_language()).
 *
 * Colors and fonts come from the theme's Design settings (sbek_login_design()
 * below, using the theme's color and font helpers).
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

function sbek_login_enqueue(): void {
	wp_enqueue_style( 'sbek-login', SBEK_COMPANION_URL . '/assets/css/login.css', array( 'login' ), sbek_companion_asset_version( 'assets/css/login.css' ) );

	// Placeholders on the username/password fields (core markup has no filter).
	wp_enqueue_script( 'sbek-login', SBEK_COMPANION_URL . '/assets/js/login.js', array(), sbek_companion_asset_version( 'assets/js/login.js' ), true );
	wp_localize_script(
		'sbek-login',
		'sbekLoginPlaceholders',
		array(
			'user_login' => __( 'Username or email', 'sbek-companion' ),
			'user_pass'  => __( 'Password', 'sbek-companion' ),
		)
	);
}
add_action( 'login_enqueue_scripts', 'sbek_login_enqueue' );

/**
 * Brand the login screen with the theme's Design colors and fonts: overrides
 * the --sbek-* tokens login.css declares on body.login, and adds the
 * @font-face rules of the selected body font (login.css loads no fonts).
 */
function sbek_login_design(): void {
	if ( ! function_exists( 'sbek_design' ) || ! function_exists( 'sbek_font_face_css' ) ) {
		return;
	}

	$d      = sbek_design();
	$fonts  = sbek_font_choices();
	$family = $fonts[ $d['font_body'] ]['family'];
	$latin  = sbek_latin_font_choices()[ $d['font_latin'] ]['stack'];

	// The panel text sits mid-gradient; it is normal-size text, so it needs
	// 4.5:1 there (sbek_readable_on() targets 3:1 for large/bold UI text).
	$panel_end = sbek_mix_hex( $d['brand'], '#000000', 0.5 );
	$panel_mid = sbek_mix_hex( $d['brand'], '#000000', 0.25 );
	$on_panel  = 1.05 / ( sbek_luminance( $panel_mid ) + 0.05 ) >= 4.5 ? '#ffffff' : '#111111';

	$vars = array(
		'--sbek-primary'       => $d['brand'],
		'--sbek-primary-hover' => $d['brand_dark'],
		'--sbek-on-primary'    => sbek_readable_on( $d['brand'] ),
		'--sbek-ring'          => implode( ' ', sbek_hex_to_rgb( $d['brand'] ) ),
		'--sbek-panel-from'    => $d['brand'],
		'--sbek-panel-to'      => $panel_end,
		'--sbek-on-panel'      => $on_panel,
		'--sbek-font'          => '"' . $family . '", ' . $latin,
	);

	$css = 'body.login{';
	foreach ( $vars as $name => $value ) {
		$css .= $name . ':' . $value . ';';
	}
	$css .= '}';

	wp_add_inline_style( 'sbek-login', sbek_font_face_css( $family ) . $css );
}
add_action( 'login_enqueue_scripts', 'sbek_login_design', 20 );

/**
 * Theme language matching the login screen's locale (core's language
 * dropdown / wp_lang cookie), falling back to the default language.
 */
function sbek_login_language(): string {
	$locale = determine_locale();

	foreach ( sbek_languages() as $slug => $language ) {
		if ( $language['locale'] === $locale ) {
			return $slug;
		}
	}

	return SBEK_DEFAULT_LANGUAGE;
}

/**
 * Site title in the login screen's language (English title from Sbek Theme
 * → English on the English screen).
 */
function sbek_login_site_name(): string {
	$lang = sbek_login_language();
	$name = SBEK_DEFAULT_LANGUAGE !== $lang ? trim( (string) sbek_companion_setting( 'name_' . $lang, '' ) ) : '';

	return '' !== $name ? $name : (string) get_bloginfo( 'name' );
}

add_filter( 'login_headerurl', static fn(): string => sbek_home_url( sbek_login_language() ) );
add_filter( 'login_headertext', 'sbek_login_site_name' );

/**
 * "← Go to {site}" footer link: home page and site name in the screen's
 * language. Core builds it with the default-language title and home URL.
 *
 * @param string $link Core link HTML.
 * @return string
 */
function sbek_login_site_link( string $link ): string {
	$lang = sbek_login_language();

	if ( SBEK_DEFAULT_LANGUAGE === $lang ) {
		return $link;
	}

	$link = str_replace( esc_url( home_url( '/' ) ), esc_url( sbek_home_url( $lang ) ), $link );

	return str_replace( get_bloginfo( 'title', 'display' ), esc_html( sbek_login_site_name() ), $link );
}
add_filter( 'login_site_html_link', 'sbek_login_site_link' );

/**
 * Privacy-policy link on the login screen: point at the policy page's
 * translation (with its own title) when the screen is in another language.
 *
 * @param string $link Core link HTML ('' when no policy page is set).
 * @return string
 */
function sbek_login_privacy_link( string $link ): string {
	if ( '' === $link || ! did_action( 'login_init' ) ) {
		return $link;
	}

	$lang = sbek_login_language();

	if ( SBEK_DEFAULT_LANGUAGE === $lang ) {
		return $link;
	}

	$translation = sbek_get_translation( (int) get_option( 'wp_page_for_privacy_policy' ), $lang );

	if ( ! $translation ) {
		return $link;
	}

	return sprintf(
		'<a class="privacy-policy-link" href="%s" rel="privacy-policy">%s</a>',
		esc_url( sbek_localize_url( (string) get_permalink( $translation ), $lang ) ),
		esc_html( get_the_title( $translation ) )
	);
}
add_filter( 'the_privacy_policy_link', 'sbek_login_privacy_link' );

/**
 * Swap the WordPress mark for the site logo (no filter exists for that
 * markup, so CSS added to login.css is the update-safe way). Without a logo
 * the site name below it is enough, so the mark is hidden.
 */
function sbek_login_logo_styles(): void {
	$logo = sbek_companion_logo_url( 'medium' );
	$css  = $logo
		? '#login h1.wp-login-logo a{background-image:url("' . esc_url_raw( $logo ) . '");}'
		: '#login h1.wp-login-logo{display:none;}';

	wp_add_inline_style( 'sbek-login', $css );
}
add_action( 'login_enqueue_scripts', 'sbek_login_logo_styles', 20 );

function sbek_login_message( string $message ): string {
	$intro = sprintf(
		'<div class="sbek-login-intro"><p class="sbek-login-intro__site">%1$s</p><p class="sbek-login-intro__desc">%2$s</p></div>',
		esc_html( sbek_login_site_name() ),
		esc_html__( 'Welcome back! Sign in to manage the newsroom.', 'sbek-companion' )
	);

	return $intro . $message;
}
add_filter( 'login_message', 'sbek_login_message' );

/**
 * Left brand panel (sibling of #login, laid out by login.css).
 */
function sbek_login_brand_panel(): void {
	?>
	<div class="sbek-login-panel">
		<div class="sbek-login-panel__grid" aria-hidden="true"></div>
		<span class="sbek-login-shape sbek-login-shape--1" aria-hidden="true"></span>
		<span class="sbek-login-shape sbek-login-shape--2" aria-hidden="true"></span>
		<span class="sbek-login-shape sbek-login-shape--3" aria-hidden="true"></span>
		<div class="sbek-login-panel__content">
			<p class="sbek-login-panel__title"><?php esc_html_e( 'Fast, trusted news.', 'sbek-companion' ); ?></p>
			<p class="sbek-login-panel__subtitle"><?php esc_html_e( 'Write, edit and publish stories for your readers.', 'sbek-companion' ); ?></p>
		</div>
	</div>
	<?php
}
add_action( 'login_header', 'sbek_login_brand_panel' );
