<?php
/**
 * Footer newsletter form (handled by includes/frontend/newsletter.php).
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

$sbek_notice = sbek_subscribe_notice();
?>
<div class="newsletter" id="newsletter">
	<p class="newsletter__title"><strong><?php esc_html_e( 'Newsletter', 'sbek-companion' ); ?></strong></p>
	<p><?php esc_html_e( 'Get the top stories in your inbox.', 'sbek-companion' ); ?></p>

	<?php if ( $sbek_notice ) : ?>
		<p class="newsletter__notice<?php echo $sbek_notice['error'] ? ' is-error' : ''; ?>" role="status"><?php echo esc_html( $sbek_notice['message'] ); ?></p>
	<?php endif; ?>

	<form class="newsletter__form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<input type="hidden" name="action" value="sbek_subscribe">
		<input type="hidden" name="lang" value="<?php echo esc_attr( sbek_lang() ); ?>">
		<?php wp_nonce_field( SBEK_SUBSCRIBE_NONCE, 'sbek_subscribe_nonce', false ); // Stale-cache handling: see includes/frontend/newsletter.php. ?>
		<p class="newsletter__hp" aria-hidden="true">
			<label>Website <input type="text" name="sbek_website" tabindex="-1" autocomplete="off"></label>
		</p>
		<label for="sbek-newsletter-email" class="screen-reader-text"><?php esc_html_e( 'Email address', 'sbek-companion' ); ?></label>
		<input type="email" id="sbek-newsletter-email" name="email" required autocomplete="email" placeholder="<?php esc_attr_e( 'Email address', 'sbek-companion' ); ?>" class="newsletter__input">
		<button type="submit" class="newsletter__submit"><?php esc_html_e( 'Subscribe', 'sbek-companion' ); ?></button>
	</form>
	<p class="newsletter__consent">
		<?php esc_html_e( 'We will email you a link to confirm. You can unsubscribe at any time.', 'sbek-companion' ); ?>
		<?php if ( get_privacy_policy_url() ) : ?>
			<a href="<?php echo esc_url( get_privacy_policy_url() ); ?>"><?php esc_html_e( 'Privacy Policy', 'sbek-companion' ); ?></a>
		<?php endif; ?>
	</p>
</div>
