<?php
/**
 * Translated category / tag names and descriptions:
 *   sbek_name_{lang}        Name on pages in that language.
 *   sbek_description_{lang} Archive description on pages in that language.
 *
 * The fields join the theme's own term fields (accent color, homepage
 * position) through the theme's `sbek_term_form_fields` filter, so the theme
 * renders and saves them. i18n.php swaps the names in on English pages.
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translated-name meta keys, one per non-default language.
 *
 * @return array<string,string> meta key => language label
 */
function sbek_term_name_fields(): array {
	$fields = array();

	foreach ( sbek_languages() as $slug => $language ) {
		if ( SBEK_DEFAULT_LANGUAGE !== $slug ) {
			$fields[ 'sbek_name_' . $slug ] = $language['label'];
		}
	}

	return $fields;
}

function sbek_register_term_name_meta(): void {
	$auth = static fn( $allowed, $meta_key, $term_id ): bool => current_user_can( 'edit_term', $term_id );

	foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
		foreach ( array_keys( sbek_term_name_fields() ) as $key ) {
			register_term_meta(
				$taxonomy,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => $auth,
					'show_in_rest'      => true,
				)
			);
			register_term_meta(
				$taxonomy,
				str_replace( 'sbek_name_', 'sbek_description_', $key ),
				array(
					'type'              => 'string',
					'single'            => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => $auth,
					'show_in_rest'      => true,
				)
			);
		}
	}
}
add_action( 'init', 'sbek_register_term_name_meta' );

/**
 * Add the name / description fields to the theme's category and tag forms.
 *
 * @param array<string,array{label:string,type:string,description:string}> $fields   Theme fields.
 * @param string                                                            $taxonomy Taxonomy.
 * @return array<string,array{label:string,type:string,description:string}>
 */
function sbek_term_name_form_fields( array $fields, string $taxonomy ): array {
	if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
		return $fields;
	}

	foreach ( sbek_term_name_fields() as $key => $label ) {
		$fields[ $key ] = array(
			/* translators: %s: language name. */
			'label'       => sprintf( __( 'Name (%s)', 'sbek-companion' ), $label ),
			'type'        => 'text',
			'description' => __( 'Shown on pages in that language. Leave empty to use the main name.', 'sbek-companion' ),
		);
		$fields[ str_replace( 'sbek_name_', 'sbek_description_', $key ) ] = array(
			/* translators: %s: language name. */
			'label'       => sprintf( __( 'Description (%s)', 'sbek-companion' ), $label ),
			'type'        => 'textarea',
			'description' => __( 'Shown under the archive title on pages in that language. Leave empty to show no description there.', 'sbek-companion' ),
		);
	}

	return $fields;
}
add_filter( 'sbek_term_form_fields', 'sbek_term_name_form_fields', 10, 2 );
