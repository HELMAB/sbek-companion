/**
 * Sbek Companion login screen: placeholders on the username / password
 * fields (core's wp-login.php markup has no filter for them). The texts come
 * from wp_localize_script() as sbekLoginPlaceholders: { field id: text }.
 */
( function ( placeholders ) {
	'use strict';

	Object.keys( placeholders || {} ).forEach( ( id ) => {
		const field = document.getElementById( id );
		if ( field ) {
			field.setAttribute( 'placeholder', placeholders[ id ] );
		}
	} );
}( window.sbekLoginPlaceholders ) );
