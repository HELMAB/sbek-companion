/**
 * News details meta box: entering a Video URL switches the post format to
 * "Video" right away in the editor (block or classic), so the editor's own
 * format control shows what will be saved. The save handler in
 * inc/admin/custom-fields.php does the same server-side as a fallback.
 */
( function () {
	'use strict';

	const input = document.getElementById( 'sbek_video_url' );
	if ( ! input ) {
		return;
	}

	const useVideoFormat = () => {
		if ( '' === input.value.trim() ) {
			return;
		}

		// Block editor.
		const data = window.wp && window.wp.data;
		const editor = data && data.select( 'core/editor' );
		if ( editor && editor.getEditedPostAttribute ) {
			const format = editor.getEditedPostAttribute( 'format' );
			if ( ! format || 'standard' === format ) {
				data.dispatch( 'core/editor' ).editPost( { format: 'video' } );
			}
			return;
		}

		// Classic editor: the Format meta box radios.
		const standard = document.getElementById( 'post-format-0' );
		const video = document.getElementById( 'post-format-video' );
		if ( video && ( ! standard || standard.checked ) ) {
			video.checked = true;
		}
	};

	input.addEventListener( 'change', useVideoFormat );
}() );
