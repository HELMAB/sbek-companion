<?php
/**
 * Third-party integrations: Google Analytics 4 and Google Tag Manager, driven
 * by Appearance → Sbek Theme → Integrations (this plugin's settings). Only the
 * IDs are stored; the code is generated here and enqueued like any script.
 * Nothing loads until an ID is entered there.
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Google Tag Manager container. Google's snippet only pushes the start event
 * and loads gtm.js asynchronously, so that is done here with an enqueued
 * script and its inline "before" code.
 */
function sbek_integrations_gtm(): void {
	$gtm_id = sbek_companion_setting( 'gtm_id', '' );

	if ( ! $gtm_id ) {
		return;
	}

	wp_enqueue_script(
		'sbek-gtm',
		'https://www.googletagmanager.com/gtm.js?id=' . rawurlencode( $gtm_id ),
		array(),
		null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google serves the current version; a ?ver would only break caching.
		array( 'strategy' => 'async' )
	);
	wp_add_inline_script(
		'sbek-gtm',
		"window.dataLayer=window.dataLayer||[];window.dataLayer.push({'gtm.start':new Date().getTime(),event:'gtm.js'});",
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'sbek_integrations_gtm' );

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
