/**
 * Sbek Companion demo content (Appearance → Sbek Theme → Tools): runs the
 * batched import / removal through admin-ajax.php with a progress bar, and
 * offers "Continue" after a failure because the job is kept server-side.
 * The inline "Are you sure?" step is handled by the theme's settings script.
 */
( function () {
	'use strict';

	const l10n = window.sbekDemo || {};

	const demo = document.querySelector( '[data-sbek-demo]' );

	if ( demo ) {
		const progress = demo.querySelector( '[data-sbek-demo-progress]' );
		const fill = demo.querySelector( '[data-sbek-demo-fill]' );
		const message = demo.querySelector( '[data-sbek-demo-message]' );
		const siteSetup = demo.querySelector( 'input[type="checkbox"][name="sbek_demo_site_setup"]' );

		const show = ( percent, text ) => {
			progress.hidden = false;
			if ( null !== percent ) {
				fill.style.width = percent + '%';
			}
			message.textContent = text;
		};

		const setBusy = ( busy ) => {
			demo.classList.toggle( 'is-busy', busy );
			demo.querySelectorAll( 'button, input' ).forEach( ( el ) => {
				el.disabled = busy;
			} );
		};

		const call = async ( data ) => {
			const body = new FormData();
			body.append( 'action', 'sbek_demo' );
			body.append( '_ajax_nonce', l10n.nonce );
			Object.keys( data ).forEach( ( key ) => body.append( key, data[ key ] ) );

			const response = await fetch( l10n.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
			const json = await response.json().catch( () => null );
			if ( ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.message ) || l10n.failed );
			}
			return json.data;
		};

		const run = async ( kind ) => {
			setBusy( true );
			demo.classList.remove( 'is-error' );

			try {
				let step = 'continue' === kind
					? { done: false, progress: 0, message: '…' }
					: await call( { task: 'start', type: kind, site_setup: siteSetup && siteSetup.checked ? '1' : '' } );
				show( step.progress, step.message );

				while ( ! step.done ) {
					step = await call( { task: 'next' } );
					show( step.progress, step.message );
				}

				show( 100, step.message + ' ' + ( l10n.done || '' ) );
				window.setTimeout( () => window.location.reload(), 1200 );
			} catch ( error ) {
				show( null, error.message );
				demo.classList.add( 'is-error' );
				setBusy( false );

				// The job is kept server-side: offer to continue where it stopped.
				const primary = demo.querySelector( '[data-sbek-demo-run="import"], [data-sbek-demo-run="continue"]' );
				if ( primary && 'reset' !== kind ) {
					primary.dataset.sbekDemoRun = 'continue';
					primary.textContent = primary.dataset.continueLabel || primary.textContent;
				}
			}
		};

		demo.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '[data-sbek-demo-run]' );
			if ( button ) {
				run( button.dataset.sbekDemoRun );
			}
		} );
	}
}() );
