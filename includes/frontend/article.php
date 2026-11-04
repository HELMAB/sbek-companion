<?php
/**
 * Article-page output for the News details fields (admin/post-meta.php) and
 * the share buttons, rendered through the theme's hooks:
 *
 *   sbek_is_sponsored            filter  "Sponsored" labels on cards and articles
 *   sbek_has_lead_video          filter  theme skips the in-text cover image
 *   sbek_before_article_content  action  video embed above the text
 *   sbek_article_footer          action  source line, then the share buttons
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * @param bool    $sponsored Theme default (false).
 * @param WP_Post $post      Post.
 */
function sbek_companion_is_sponsored( $sponsored, $post ): bool {
	return $post instanceof WP_Post ? (bool) get_post_meta( $post->ID, '_sbek_is_sponsored', true ) : (bool) $sponsored;
}
add_filter( 'sbek_is_sponsored', 'sbek_companion_is_sponsored', 10, 2 );

/**
 * @param bool    $has_video Theme default (false).
 * @param WP_Post $post      Post.
 */
function sbek_companion_has_lead_video( $has_video, $post ): bool {
	return $post instanceof WP_Post ? '' !== (string) get_post_meta( $post->ID, '_sbek_video_url', true ) : (bool) $has_video;
}
add_filter( 'sbek_has_lead_video', 'sbek_companion_has_lead_video', 10, 2 );

/**
 * Video embed above the article text.
 */
function sbek_companion_article_video( WP_Post $post ): void {
	$video = (string) get_post_meta( $post->ID, '_sbek_video_url', true );

	if ( '' === $video ) {
		return;
	}

	$embed = wp_oembed_get( $video, array( 'width' => 730 ) );
	?>
	<figure class="article__video">
		<?php if ( $embed ) : ?>
			<?php echo $embed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- oEmbed HTML from WordPress' provider allow-list. ?>
		<?php else : ?>
			<a href="<?php echo esc_url( $video ); ?>" target="_blank" rel="noopener noreferrer" class="btn"><?php esc_html_e( 'Watch the video', 'sbek-companion' ); ?></a>
		<?php endif; ?>
	</figure>
	<?php
}
add_action( 'sbek_before_article_content', 'sbek_companion_article_video' );

/**
 * "Source: …" line (rel="sponsored" on sponsored posts, nofollow otherwise).
 */
function sbek_companion_article_source( WP_Post $post ): void {
	$source = (string) get_post_meta( $post->ID, '_sbek_source_name', true );
	$url    = (string) get_post_meta( $post->ID, '_sbek_source_url', true );

	if ( '' === $source ) {
		return;
	}
	?>
	<p>
		<?php esc_html_e( 'Source:', 'sbek-companion' ); ?>
		<?php if ( $url ) : ?>
			<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="<?php echo esc_attr( 'noopener noreferrer ' . ( sbek_companion_is_sponsored( false, $post ) ? 'sponsored' : 'nofollow' ) ); ?>"><?php echo esc_html( $source ); ?></a>
		<?php else : ?>
			<?php echo esc_html( $source ); ?>
		<?php endif; ?>
	</p>
	<?php
}
add_action( 'sbek_article_footer', 'sbek_companion_article_source', 5 );

/**
 * Share buttons: Facebook, Telegram, X, email, copy link, and the native
 * share sheet where the browser has one (assets/js/share.js).
 */
function sbek_companion_share_links( WP_Post $post ): void {
	$url   = get_permalink( $post );
	$title = wp_strip_all_tags( get_the_title( $post ) );

	if ( ! $url ) {
		return;
	}

	$networks = array(
		'fb'       => array( 'facebook', __( 'Share on Facebook', 'sbek-companion' ), 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ) ),
		'telegram' => array( 'telegram', __( 'Share on Telegram', 'sbek-companion' ), 'https://t.me/share/url?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title ) ),
		'x'        => array( 'x', __( 'Share on X', 'sbek-companion' ), 'https://twitter.com/intent/tweet?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title ) ),
		'mail'     => array( 'at-sign', __( 'Share by email', 'sbek-companion' ), 'mailto:?subject=' . rawurlencode( $title ) . '&body=' . rawurlencode( $url ) ),
	);

	$icon = static fn( string $name ): string => function_exists( 'sbek_icon' ) ? sbek_icon( $name, array( 'width' => 15, 'height' => 15 ) ) : '';

	wp_enqueue_script( 'sbek-companion-share' );
	?>
	<ul class="share-links" aria-label="<?php esc_attr_e( 'Share', 'sbek-companion' ); ?>">
		<li class="share-links__native-item" hidden>
			<button type="button" class="share-links__native" data-sbek-share data-url="<?php echo esc_url( $url ); ?>" data-title="<?php echo esc_attr( $title ); ?>">
				<?php echo $icon( 'share-2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Share…', 'sbek-companion' ); ?></span>
			</button>
		</li>
		<?php foreach ( $networks as $key => $network ) : ?>
			<li>
				<a class="share-links__<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( $network[2] ); ?>"<?php echo 'mail' === $key ? '' : ' target="_blank" rel="noopener noreferrer"'; ?>>
					<?php echo $icon( $network[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<span class="screen-reader-text"><?php echo esc_html( $network[1] ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
		<li>
			<button type="button" class="share-links__copy" data-sbek-copy="<?php echo esc_url( $url ); ?>" data-copied="<?php esc_attr_e( 'Link copied', 'sbek-companion' ); ?>">
				<?php echo $icon( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Copy link', 'sbek-companion' ); ?></span>
			</button>
		</li>
	</ul>
	<p class="share-links__status screen-reader-text" role="status" data-sbek-copy-status></p>
	<?php
}
add_action( 'sbek_article_footer', 'sbek_companion_share_links', 20 );

function sbek_companion_register_share_script(): void {
	wp_register_script(
		'sbek-companion-share',
		SBEK_COMPANION_URL . '/assets/js/share.js',
		array(),
		sbek_companion_asset_version( 'assets/js/share.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'sbek_companion_register_share_script' );
