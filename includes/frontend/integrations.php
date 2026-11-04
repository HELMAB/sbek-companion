<?php
/**
 * Third-party integrations: Google Analytics 4, Google Tag Manager, and
 * custom header/footer scripts — all driven by Appearance → Sbek Theme →
 * Integrations (this plugin's settings). Nothing loads until an ID or a
 * script is entered there.
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Head output: GTM container and raw header scripts (GA4 is enqueued below).
 * Skipped in the admin and for logged-in editors to keep analytics clean.
 */
function sbek_integrations_head(): void {
	if ( is_admin() ) {
		return;
	}

	$gtm_id         = sbek_companion_setting( 'gtm_id', '' );
	$ga4_id         = sbek_companion_setting( 'ga4_id', '' );
	$header_scripts = sbek_companion_setting( 'header_scripts', '' );

	// Google Tag Manager (head half).
	if ( $gtm_id ) {
		printf(
			"<!-- Google Tag Manager -->\n<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','%s');</script>\n<!-- End Google Tag Manager -->\n",
			esc_js( $gtm_id )
		);
	}

	// Raw custom header scripts (already capability-gated on save).
	if ( $header_scripts ) {
		echo "\n" . $header_scripts . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action( 'wp_head', 'sbek_integrations_head', 20 );

/**
 * Google Analytics 4 (gtag.js) — only when no Tag Manager container handles
 * it. Enqueued like any script; the config call runs right after it loads.
 */
function sbek_integrations_ga4(): void {
	$ga4_id = sbek_companion_setting( 'ga4_id', '' );

	if ( ! $ga4_id || sbek_companion_setting( 'gtm_id', '' ) ) {
		return;
	}

	wp_enqueue_script(
		'sbek-ga4',
		'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ga4_id ),
		array(),
		null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google serves the current version; a ?ver would only break caching.
		array( 'strategy' => 'async' )
	);
	wp_add_inline_script(
		'sbek-ga4',
		sprintf( "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','%s');", esc_js( $ga4_id ) ),
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'sbek_integrations_ga4' );

/**
 * Immediately-after-<body> output: the GTM <noscript> fallback.
 */
function sbek_integrations_body_open(): void {
	if ( is_admin() ) {
		return;
	}

	$gtm_id = sbek_companion_setting( 'gtm_id', '' );

	if ( $gtm_id ) {
		printf(
			'<!-- Google Tag Manager (noscript) --><noscript><iframe src="https://www.googletagmanager.com/ns.html?id=%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n",
			esc_attr( rawurlencode( $gtm_id ) )
		);
	}
}
add_action( 'wp_body_open', 'sbek_integrations_body_open' );

/**
 * Footer output: raw custom footer scripts.
 */
function sbek_integrations_footer(): void {
	if ( is_admin() ) {
		return;
	}

	$footer_scripts = sbek_companion_setting( 'footer_scripts', '' );

	if ( $footer_scripts ) {
		echo "\n" . $footer_scripts . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action( 'wp_footer', 'sbek_integrations_footer', 20 );
