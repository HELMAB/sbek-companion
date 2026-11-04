<?php
/**
 * Demo content import / removal: the Tools tab of Appearance → Sbek Theme
 * (added through the theme's `sbek_theme_option_tabs` filter).
 *
 * Demo content runs in batches through admin-ajax.php (action `sbek_demo`):
 * the browser asks for `start`, then calls `next` until the job reports done,
 * showing a progress bar. Every request checks a nonce and manage_options,
 * and the job itself lives server-side (Sbek_Demo_Importer), so the client
 * can only say "continue" — it never supplies what to create or delete.
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

const SBEK_DEMO_NONCE = 'sbek_demo';

// ─── Demo content (AJAX) ───────────────────────────────────────────────────────

function sbek_ajax_demo(): void {
	check_ajax_referer( SBEK_DEMO_NONCE );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'sbek-companion' ) ), 403 );
	}

	$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
	$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

	$importer = new Sbek_Demo_Importer();

	try {
		switch ( $task ) {
			case 'start':
				if ( ! in_array( $type, array( 'import', 'reset' ), true ) ) {
					throw new RuntimeException( 'Invalid request.' );
				}
				// "Remove" also clears an interrupted import before removing what it created.
				if ( 'reset' === $type && 'running' === Sbek_Demo_Importer::status()['state'] ) {
					$importer->abort();
				}
				$importer->start( $type, array( 'site_setup' => ! empty( $_POST['site_setup'] ) ) );
				wp_send_json_success(
					array(
						'done'     => false,
						'progress' => 0,
						'message'  => 'import' === $type ? __( 'Starting import…', 'sbek-companion' ) : __( 'Starting removal…', 'sbek-companion' ),
					)
				);
				break;

			case 'next':
				wp_send_json_success( $importer->run_batch() );
				break;

			case 'abort':
				$importer->abort();
				wp_send_json_success(
					array(
						'done'     => true,
						'progress' => 0,
						'message'  => __( 'Job cancelled. Anything already created can still be removed.', 'sbek-companion' ),
					)
				);
				break;

			default:
				throw new RuntimeException( 'Invalid request.' );
		}
	} catch ( Throwable $e ) {
		wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
	}
}
add_action( 'wp_ajax_sbek_demo', 'sbek_ajax_demo' );

// ─── UI ────────────────────────────────────────────────────────────────────────

/**
 * The Tools tab: demo content card.
 */
function sbek_render_demo_tools(): void {
	$status = Sbek_Demo_Importer::status();

	sbek_section_open( 'rocket', __( 'Demo content', 'sbek-companion' ), __( 'Sample categories, about 140 Khmer and English posts with images, pages, menus and ad placeholders — handy for previewing the theme. Removing deletes only what the import created; your own content and Sbek Theme values are kept.', 'sbek-companion' ) );
	?>
	<div class="sbek-demo" data-sbek-demo data-state="<?php echo esc_attr( $status['state'] ); ?>">
		<p class="sbek-demo__status">
			<?php
			if ( 'running' === $status['state'] ) {
				echo esc_html(
					sprintf(
						/* translators: 1: "import" or "removal", 2: percent. */
						__( 'An unfinished %1$s stopped at %2$d%%. Continue it, or remove what was created so far.', 'sbek-companion' ),
						'import' === $status['type'] ? __( 'import', 'sbek-companion' ) : __( 'removal', 'sbek-companion' ),
						$status['progress']
					)
				);
			} elseif ( 'installed' === $status['state'] && $status['installed'] ) {
				echo esc_html(
					sprintf(
						/* translators: 1: date, 2: posts and pages, 3: images. */
						__( 'Installed %1$s: %2$d posts and pages, %3$d images.', 'sbek-companion' ),
						wp_date( get_option( 'date_format' ), $status['installed'] ),
						$status['posts'],
						$status['images']
					)
				);
			} elseif ( 'installed' === $status['state'] ) {
				echo esc_html(
					sprintf(
						/* translators: 1: posts and pages, 2: images. */
						__( 'An incomplete import left %1$d posts and pages and %2$d images. Remove them before importing again.', 'sbek-companion' ),
						$status['posts'],
						$status['images']
					)
				);
			} else {
				esc_html_e( 'No demo content is installed.', 'sbek-companion' );
			}
			?>
		</p>

		<?php if ( 'none' === $status['state'] ) : ?>
			<?php
			sbek_option_toggle(
				'sbek_demo_site_setup',
				__( 'Also apply the demo site settings', 'sbek-companion' ),
				__( 'Site title, Khmer language, Phnom Penh time zone, 8 posts per page, /article/123/ links, menus, ad placeholders, privacy page, and Sbek Theme details (only fields that are still empty). These are not undone by "Remove".', 'sbek-companion' ),
				true
			);
			?>
		<?php endif; ?>

		<div class="sbek-demo__progress" data-sbek-demo-progress hidden>
			<div class="sbek-demo__track"><span class="sbek-demo__fill" data-sbek-demo-fill></span></div>
			<p class="sbek-demo__message" role="status" aria-live="polite" data-sbek-demo-message></p>
		</div>

		<div class="sbek-demo__actions">
			<?php if ( 'none' === $status['state'] ) : ?>
				<button type="button" class="sbek-btn sbek-btn--brand" data-sbek-demo-run="import" data-continue-label="<?php esc_attr_e( 'Continue', 'sbek-companion' ); ?>"><?php esc_html_e( 'Import demo content', 'sbek-companion' ); ?></button>
			<?php elseif ( 'running' === $status['state'] ) : ?>
				<button type="button" class="sbek-btn sbek-btn--brand" data-sbek-demo-run="continue"><?php esc_html_e( 'Continue', 'sbek-companion' ); ?></button>
			<?php endif; ?>

			<?php
			if ( 'none' !== $status['state'] ) {
				sbek_confirm_control(
					__( 'Remove demo content', 'sbek-companion' ),
					__( 'Delete all demo posts, pages, images, categories, menus and widgets?', 'sbek-companion' ),
					'<button type="button" class="sbek-btn sbek-btn--danger" data-sbek-demo-run="reset">' . esc_html__( 'Yes, remove', 'sbek-companion' ) . '</button>'
				);
			}
			?>
		</div>
	</div>
	<?php
	sbek_section_close();
}

/**
 * Tools tab on the theme's settings page (administrators only).
 *
 * @param array<string,array{0:string,1:string}> $tabs Tabs: slug => [ label, icon ].
 * @return array<string,array{0:string,1:string}>
 */
function sbek_companion_tools_tab( array $tabs ): array {
	if ( current_user_can( 'manage_options' ) ) {
		$tabs['tools'] = array( __( 'Tools', 'sbek-companion' ), 'rocket', true ); // Actions only: no Save button.
	}

	return $tabs;
}
add_filter( 'sbek_theme_option_tabs', 'sbek_companion_tools_tab', 30 );
add_action( 'sbek_theme_options_panel_tools', 'sbek_render_demo_tools' );

/**
 * Batched import / removal script for the Tools tab.
 */
function sbek_companion_tools_assets(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_enqueue_script(
		'sbek-companion-demo',
		SBEK_COMPANION_URL . '/assets/js/admin-demo.js',
		array(),
		sbek_companion_asset_version( 'assets/js/admin-demo.js' ),
		true
	);
	wp_localize_script(
		'sbek-companion-demo',
		'sbekDemo',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( SBEK_DEMO_NONCE ),
			'failed'  => __( 'Something went wrong. You can continue the job or remove what was created.', 'sbek-companion' ),
			'done'    => __( 'Done! Reloading…', 'sbek-companion' ),
		)
	);
}
add_action( 'sbek_theme_options_enqueue', 'sbek_companion_tools_assets' );
