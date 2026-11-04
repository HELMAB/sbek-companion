/**
 * Translation meta box: search older posts in the other language and add the
 * matches to the "Translated version" list (admin-ajax
 * `sbek_translation_search`, see inc/admin/translation.php).
 */
( function () {
	'use strict';

	const input = document.querySelector( '[data-sbek-translation-search]' );
	const select = document.getElementById( 'sbek_translation_id' );
	const status = document.querySelector( '[data-sbek-translation-status]' );
	if ( ! input || ! select || ! window.ajaxurl ) {
		return;
	}

	let timer = 0;
	let controller = null;
	let group = null;

	const search = async () => {
		const term = input.value.trim();
		if ( term.length < 2 ) {
			status.textContent = '';
			return;
		}

		if ( controller ) {
			controller.abort();
		}
		controller = new AbortController();

		const url = new URL( window.ajaxurl, window.location.href );
		url.search = new URLSearchParams( {
			action: 'sbek_translation_search',
			nonce: input.dataset.nonce,
			post: input.dataset.post,
			q: term,
		} ).toString();

		try {
			const response = await fetch( url, { credentials: 'same-origin', signal: controller.signal } );
			const body = await response.json();
			const items = body && body.success ? body.data : [];

			if ( ! group ) {
				group = document.createElement( 'optgroup' );
				group.label = input.placeholder;
				select.appendChild( group );
			}

			// Keep the selected option; replace earlier results.
			Array.from( group.children ).forEach( ( option ) => {
				if ( ! option.selected ) {
					option.remove();
				}
			} );

			const present = new Set( Array.from( select.options ).map( ( option ) => option.value ) );
			let added = 0;
			items.forEach( ( item ) => {
				if ( ! present.has( String( item.id ) ) ) {
					group.appendChild( new Option( item.label, item.id ) );
					added++;
				}
			} );

			if ( ! items.length ) {
				status.textContent = input.dataset.none;
			} else {
				status.textContent = added ? input.dataset.found : input.dataset.listed;
			}
		} catch ( error ) {
			if ( 'AbortError' !== error.name ) {
				status.textContent = '';
			}
		}
	};

	input.addEventListener( 'input', () => {
		window.clearTimeout( timer );
		timer = window.setTimeout( search, 300 );
	} );

	// Enter would submit the whole post form.
	input.addEventListener( 'keydown', ( event ) => {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			window.clearTimeout( timer );
			search();
		}
	} );
}() );
