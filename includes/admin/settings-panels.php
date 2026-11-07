<?php
/**
 * This plugin's fields on the theme's Appearance → Sbek Theme page, added
 * through the page's hooks (inc/admin/theme-options.php in the theme):
 *
 *   sbek_theme_option_tabs            filter  adds English, Integrations, Security (and Tools, admin/tools.php)
 *   sbek_theme_options_panel_{slug}   action  cards at the end of a tab
 *   sbek_theme_options_save           filter  saves `sbek_companion`, returns "not saved" messages
 *
 * Field names are `sbek_companion[...]`. The save filter checks the form's
 * nonce and edit_theme_options itself, although the theme's save handler has
 * already done so, so it never depends on who applies the filter.
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nonce action of the theme's settings form (SBEK_SETTINGS_ACTION in the
 * theme's inc/admin/theme-options.php, printed with wp_nonce_field()).
 */
const SBEK_COMPANION_SAVE_ACTION = 'sbek_save_theme_options';

/**
 * @param array<string,array> $tabs Tabs: slug => [ label, icon, no_save? ].
 * @return array<string,array>
 */
function sbek_companion_option_tabs( array $tabs ): array {
	$tabs['english'] = array( __( 'English', 'sbek-companion' ), 'globe' );

	if ( current_user_can( 'manage_options' ) ) {
		$tabs['integrations'] = array( __( 'Integrations', 'sbek-companion' ), 'trending-up' );
		$tabs['security']     = array( __( 'Security', 'sbek-companion' ), 'asterisk' );
	}

	return $tabs;
}
add_filter( 'sbek_theme_option_tabs', 'sbek_companion_option_tabs', 20 );

/**
 * Full input name for a plugin setting.
 */
function sbek_companion_field( string $key ): string {
	return SBEK_COMPANION_OPTION . '[' . $key . ']';
}

// ─── General: sharing ──────────────────────────────────────────────────────────

function sbek_companion_panel_general(): void {
	$s = sbek_companion_setting();

	sbek_section_open( 'share-2', __( 'Sharing', 'sbek-companion' ), __( 'The image used when a page without its own picture is shared on social networks.', 'sbek-companion' ) );
	?>
	<div class="sbek-grid sbek-grid--3">
		<div class="sbek-field">
			<span class="sbek-field__label"><?php esc_html_e( 'Default share image', 'sbek-companion' ); ?></span>
			<?php sbek_render_media_field( sbek_companion_field( 'og_image' ), (int) $s['og_image'], __( 'Choose an image', 'sbek-companion' ), __( 'JPG or PNG, 1200×630px', 'sbek-companion' ) ); ?>
		</div>
	</div>
	<?php
	sbek_section_close();
}
add_action( 'sbek_theme_options_panel_general', 'sbek_companion_panel_general' );

// ─── Contact & Footer: location for search engines, newsletter ────────────────

function sbek_companion_panel_contact(): void {
	$s = sbek_companion_setting();

	sbek_section_open( 'map-pin', __( 'Location for search engines', 'sbek-companion' ), __( 'Not shown to visitors: the coordinates tell search engines where your newsroom is (structured data).', 'sbek-companion' ) );
	?>
	<div class="sbek-grid sbek-grid--2">
		<?php
		sbek_option_input(
			sbek_companion_field( 'lat' ),
			__( 'Latitude', 'sbek-companion' ),
			(string) $s['lat'],
			array(
				'type'        => 'number',
				'placeholder' => '11.5564',
				'dir'         => 'ltr',
				'attrs'       => array( 'min' => '-90', 'max' => '90', 'step' => 'any' ),
			)
		);
		sbek_option_input(
			sbek_companion_field( 'lng' ),
			__( 'Longitude', 'sbek-companion' ),
			(string) $s['lng'],
			array(
				'type'        => 'number',
				'placeholder' => '104.9282',
				'dir'         => 'ltr',
				'attrs'       => array( 'min' => '-180', 'max' => '180', 'step' => 'any' ),
			)
		);
		?>
	</div>
	<?php
	sbek_section_close();

	$subscribers = sbek_subscriber_count( 'confirmed' );
	$pending     = sbek_subscriber_count( 'pending' );

	sbek_section_open( 'mail', __( 'Newsletter', 'sbek-companion' ), __( 'Email signup form in the footer.', 'sbek-companion' ) );
	sbek_option_toggle( sbek_companion_field( 'show_newsletter' ), __( 'Newsletter signup', 'sbek-companion' ), __( 'Addresses are stored on this site, under Subscribers, and count only after the visitor clicks the link in the confirmation email (double opt-in). Your site must be able to send email.', 'sbek-companion' ), (bool) $s['show_newsletter'] );
	?>
	<div class="sbek-inline-actions">
		<span class="sbek-inline-actions__text">
			<?php
			/* translators: %s: number of subscribers. */
			echo esc_html( sprintf( _n( '%s subscriber', '%s subscribers', $subscribers, 'sbek-companion' ), number_format_i18n( $subscribers ) ) );
			if ( $pending ) {
				echo ' · ' . esc_html(
					/* translators: %s: number of unconfirmed signups. */
					sprintf( _n( '%s waiting for confirmation', '%s waiting for confirmation', $pending, 'sbek-companion' ), number_format_i18n( $pending ) )
				);
			}
			?>
		</span>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a class="sbek-btn sbek-btn--plain" href="<?php echo esc_url( admin_url( 'edit.php?post_type=sbek_subscriber' ) ); ?>"><?php esc_html_e( 'View subscribers', 'sbek-companion' ); ?></a>
			<?php if ( $subscribers || $pending ) : ?>
				<a class="sbek-btn sbek-btn--plain" href="<?php echo esc_url( sbek_subscribers_export_url() ); ?>">
					<?php echo sbek_icon( 'download', array( 'width' => 15, 'height' => 15 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<?php esc_html_e( 'Export CSV', 'sbek-companion' ); ?>
				</a>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
	sbek_section_close();
}
add_action( 'sbek_theme_options_panel_contact', 'sbek_companion_panel_contact' );

// ─── English tab ───────────────────────────────────────────────────────────────

function sbek_companion_panel_english(): void {
	$s     = sbek_companion_setting();
	$theme = sbek_site_setting();

	sbek_section_open( 'globe', __( 'Site identity in English', 'sbek-companion' ), __( 'Used on /en/ pages. Leave a field empty to reuse the Khmer text.', 'sbek-companion' ) );
	?>
	<div class="sbek-grid sbek-grid--2">
		<?php
		sbek_option_input( sbek_companion_field( 'name_en' ), __( 'Site title (English)', 'sbek-companion' ), (string) $s['name_en'], array( 'placeholder' => (string) get_option( 'blogname' ) ) );
		sbek_option_input( sbek_companion_field( 'tagline_en' ), __( 'Tagline (English)', 'sbek-companion' ), (string) $s['tagline_en'], array( 'placeholder' => (string) get_option( 'blogdescription' ) ) );
		?>
	</div>
	<?php
	sbek_section_close();

	sbek_section_open( 'panel-bottom', __( 'Footer in English', 'sbek-companion' ), __( 'English versions of the About text, the address and the copyright line (you can use {year} and {site}).', 'sbek-companion' ) );
	?>
	<div class="sbek-grid sbek-grid--2">
		<?php
		sbek_option_textarea( sbek_companion_field( 'description_en' ), __( 'Description (English)', 'sbek-companion' ), (string) $s['description_en'], array( 'rows' => '4', 'placeholder' => (string) ( $theme['description'] ?? '' ) ) );
		sbek_option_textarea( sbek_companion_field( 'address_en' ), __( 'Address (English)', 'sbek-companion' ), (string) $s['address_en'], array( 'rows' => '4', 'placeholder' => (string) ( $theme['address'] ?? '' ) ) );
		sbek_option_input( sbek_companion_field( 'copyright_en' ), __( 'Copyright (English)', 'sbek-companion' ), (string) $s['copyright_en'], array( 'placeholder' => '© {year} {site}. All rights reserved.' ) );
		?>
	</div>
	<?php
	sbek_section_close();

	$categories = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'exclude'    => array( (int) get_option( 'default_category' ) ),
		)
	);
	$categories = is_wp_error( $categories ) ? array() : $categories;

	sbek_section_open( 'grid', __( 'Category names in English', 'sbek-companion' ), __( 'Shown in menus, badges and archive titles on English pages. Leave empty to use the Khmer name. Tags and descriptions are edited on each term\'s own screen.', 'sbek-companion' ) );
	if ( $categories ) :
		?>
		<div class="sbek-table-wrap">
			<table class="sbek-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Category', 'sbek-companion' ); ?></th>
						<th scope="col"><?php esc_html_e( 'English name', 'sbek-companion' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $categories as $category ) : ?>
						<tr>
							<th scope="row"><span class="sbek-table__name"><?php echo esc_html( $category->name ); ?></span></th>
							<td>
								<input type="text" class="sbek-input" name="<?php echo esc_attr( 'sbek_companion_terms[' . $category->term_id . ']' ); ?>" value="<?php echo esc_attr( (string) get_term_meta( $category->term_id, 'sbek_name_en', true ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: category. */ __( 'English name for %s', 'sbek-companion' ), $category->name ) ); ?>">
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	else :
		?>
		<p class="sbek-empty"><?php esc_html_e( 'No categories yet.', 'sbek-companion' ); ?></p>
		<?php
	endif;
	sbek_section_close();
}
add_action( 'sbek_theme_options_panel_english', 'sbek_companion_panel_english' );

// ─── Integrations tab ──────────────────────────────────────────────────────────

function sbek_companion_panel_integrations(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$s = sbek_companion_setting();

	sbek_section_open( 'trending-up', __( 'Analytics', 'sbek-companion' ), __( 'Nothing is tracked until you enter an ID here. Google Analytics 4 loads only when no Tag Manager container is set. Mention the tracking in your privacy policy.', 'sbek-companion' ) );
	?>
	<div class="sbek-grid sbek-grid--2">
		<?php
		sbek_option_input(
			sbek_companion_field( 'ga4_id' ),
			__( 'Google Analytics 4 ID', 'sbek-companion' ),
			(string) $s['ga4_id'],
			array(
				'placeholder' => 'G-XXXXXXXXXX',
				'dir'         => 'ltr',
				'help'        => __( 'Starts with G- (Admin → Data streams in Google Analytics).', 'sbek-companion' ),
				'attrs'       => array( 'pattern' => '[Gg]-[A-Za-z0-9]{4,}', 'title' => 'G-XXXXXXXXXX', 'spellcheck' => 'false' ),
			)
		);
		sbek_option_input(
			sbek_companion_field( 'gtm_id' ),
			__( 'Google Tag Manager ID', 'sbek-companion' ),
			(string) $s['gtm_id'],
			array(
				'placeholder' => 'GTM-XXXXXXX',
				'dir'         => 'ltr',
				'help'        => __( 'Starts with GTM-. When set, the GA4 ID above is ignored — add GA4 inside Tag Manager instead.', 'sbek-companion' ),
				'attrs'       => array( 'pattern' => '[Gg][Tt][Mm]-[A-Za-z0-9]{4,}', 'title' => 'GTM-XXXXXXX', 'spellcheck' => 'false' ),
			)
		);
		?>
	</div>
	<?php
	sbek_section_close();
}
add_action( 'sbek_theme_options_panel_integrations', 'sbek_companion_panel_integrations' );

// ─── Security tab ──────────────────────────────────────────────────────────────

function sbek_companion_panel_security(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$s      = sbek_companion_setting();
	$labels = array(
		'login_lockout'     => array(
			__( 'Login lockout', 'sbek-companion' ),
			__( 'Block an IP address for 15 minutes after 5 failed logins. A one-way hash of the address is kept for that time.', 'sbek-companion' ),
		),
		'security_headers'  => array(
			__( 'Security headers', 'sbek-companion' ),
			__( 'Send the X-Content-Type-Options, X-Frame-Options, Referrer-Policy and Permissions-Policy headers. Other sites can then no longer show your pages in a frame.', 'sbek-companion' ),
		),
		'disable_xmlrpc'    => array(
			__( 'Turn off XML-RPC', 'sbek-companion' ),
			__( 'XML-RPC is a common brute-force target. Keep it on if you use the WordPress mobile app, Jetpack or a remote publishing tool.', 'sbek-companion' ),
		),
		'disable_file_edit' => array(
			__( 'Turn off the file editors', 'sbek-companion' ),
			__( 'Remove the theme and plugin file editors from wp-admin, so a stolen admin login cannot change the site\'s PHP code.', 'sbek-companion' ),
		),
		'hide_rest_users'   => array(
			__( 'Hide the user list from visitors', 'sbek-companion' ),
			__( 'Remove the REST API users endpoint for visitors who are not logged in, so author logins cannot be listed. Keep it off if an app or headless front end reads author data.', 'sbek-companion' ),
		),
	);

	sbek_section_open( 'asterisk', __( 'Hardening', 'sbek-companion' ), __( 'Optional protections for the whole site. Only the login lockout is on by default; turn the others on if nothing you use depends on what they block.', 'sbek-companion' ) );
	foreach ( $labels as $key => $text ) {
		sbek_option_toggle( sbek_companion_field( $key ), $text[0], $text[1], (bool) $s[ $key ] );
	}
	sbek_section_close();
}
add_action( 'sbek_theme_options_panel_security', 'sbek_companion_panel_security' );

// ─── Save ──────────────────────────────────────────────────────────────────────

/**
 * Save this plugin's fields with the theme page. Needs the form's nonce and
 * edit_theme_options; Integrations and Security also need manage_options,
 * without which their stored values are kept.
 *
 * @param string[] $problems "Not saved" messages collected so far.
 * @return string[]
 */
function sbek_companion_save_settings( array $problems ): array {
	$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, SBEK_COMPANION_SAVE_ACTION ) || ! current_user_can( 'edit_theme_options' ) ) {
		return $problems;
	}

	if ( ! isset( $_POST[ SBEK_COMPANION_OPTION ] ) || ! is_array( $_POST[ SBEK_COMPANION_OPTION ] ) ) {
		return $problems;
	}

	$input   = wp_unslash( $_POST[ SBEK_COMPANION_OPTION ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by the registered callback in update_option().
	$current = sbek_companion_setting();

	if ( ! current_user_can( 'manage_options' ) ) {
		foreach ( array_merge( array( 'ga4_id', 'gtm_id' ), array_keys( sbek_security_options() ) ) as $key ) {
			$input[ $key ] = $current[ $key ];
		}
	}

	$problems = array_merge( $problems, sbek_companion_input_problems( $input ) );
	update_option( SBEK_COMPANION_OPTION, array_merge( $current, $input ) );

	// English category names.
	$terms = isset( $_POST['sbek_companion_terms'] ) && is_array( $_POST['sbek_companion_terms'] ) ? wp_unslash( $_POST['sbek_companion_terms'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by the registered term meta callback.

	foreach ( $terms as $term_id => $name ) {
		$term_id = absint( $term_id );
		if ( ! get_term( $term_id, 'category' ) instanceof WP_Term || ! current_user_can( 'edit_term', $term_id ) ) {
			continue;
		}

		$name = trim( (string) $name );
		if ( '' === $name ) {
			delete_term_meta( $term_id, 'sbek_name_en' );
		} else {
			update_term_meta( $term_id, 'sbek_name_en', $name );
		}
	}

	return $problems;
}
add_filter( 'sbek_theme_options_save', 'sbek_companion_save_settings' );

/**
 * Values the sanitizer would drop, explained (shown as "not saved").
 *
 * @param array<string,mixed> $input Unslashed input.
 * @return string[]
 */
function sbek_companion_input_problems( array $input ): array {
	$problems = array();

	foreach ( array( 'lat' => 90, 'lng' => 180 ) as $key => $max ) {
		$value = trim( (string) ( $input[ $key ] ?? '' ) );
		if ( '' !== $value && ( ! is_numeric( $value ) || abs( (float) $value ) > $max ) ) {
			$problems[] = 'lat' === $key
				? __( 'Latitude must be a number between -90 and 90.', 'sbek-companion' )
				: __( 'Longitude must be a number between -180 and 180.', 'sbek-companion' );
		}
	}

	$examples = array(
		'ga4_id' => array( __( 'Google Analytics 4 ID', 'sbek-companion' ), 'G-XXXXXXXXXX' ),
		'gtm_id' => array( __( 'Google Tag Manager ID', 'sbek-companion' ), 'GTM-XXXXXXX' ),
	);
	foreach ( sbek_tracking_id_patterns() as $key => $pattern ) {
		$value = strtoupper( trim( (string) ( $input[ $key ] ?? '' ) ) );
		if ( '' !== $value && ! preg_match( $pattern, $value ) ) {
			/* translators: 1: field label, 2: example ID. */
			$problems[] = sprintf( __( '%1$s must look like %2$s.', 'sbek-companion' ), $examples[ $key ][0], $examples[ $key ][1] );
		}
	}

	return $problems;
}
