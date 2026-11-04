/**
 * Sbek Companion share buttons: shows the native share sheet button when the
 * browser has one, and copies the link (with a screen-reader status message).
 * Loaded only on pages that render the share buttons.
 */
( function () {
	'use strict';

	function initShare() {
		if ( navigator.share ) {
			document.querySelectorAll( '[data-sbek-share]' ).forEach( ( button ) => {
				button.closest( 'li' ).hidden = false;
				button.addEventListener( 'click', () => {
					navigator.share( { title: button.dataset.title, url: button.dataset.url } ).catch( () => {} );
				} );
			} );
		}

		const status = document.querySelector( '[data-sbek-copy-status]' );
		document.querySelectorAll( '[data-sbek-copy]' ).forEach( ( button ) => {
			button.addEventListener( 'click', async () => {
				const url = button.dataset.sbekCopy;
				try {
					await navigator.clipboard.writeText( url );
				} catch ( e ) {
					// Older browsers / insecure origins: select-and-copy fallback.
					const field = document.createElement( 'textarea' );
					field.value = url;
					field.setAttribute( 'readonly', '' );
					field.style.position = 'fixed';
					field.style.opacity = '0';
					document.body.appendChild( field );
					field.select();
					document.execCommand( 'copy' );
					field.remove();
				}
				button.classList.add( 'is-copied' );
				if ( status ) {
					status.textContent = button.dataset.copied || '';
				}
				window.setTimeout( () => button.classList.remove( 'is-copied' ), 2000 );
			} );
		} );
	}

	initShare();
}() );
