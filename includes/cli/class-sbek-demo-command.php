<?php
/**
 * WP-CLI front end for the demo importer (inc/demo/class-sbek-demo-importer.php).
 *
 *   wp sbek demo import [--skip-site-setup] [--posts=<n>]
 *   wp sbek demo reset [--yes]
 *   wp sbek demo status
 *
 * The same batches run from Sbek Theme → Tools; here they simply run in a
 * loop. Loaded only under WP-CLI (see functions.php).
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * Create and remove Sbek demo content.
 */
class Sbek_Demo_Command {

	/**
	 * Import demo content.
	 *
	 * ## OPTIONS
	 *
	 * [--skip-site-setup]
	 * : Don't touch site options (language, permalinks, timezone, title, menus, widgets, Sbek Theme).
	 *
	 * [--posts=<n>]
	 * : Khmer posts per category.
	 * ---
	 * default: 24
	 * ---
	 *
	 * @subcommand import
	 *
	 * @param string[]             $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function import( array $args, array $assoc_args ): void {
		$this->run(
			'import',
			array(
				'site_setup'   => ! WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-site-setup', false ),
				'per_category' => (int) ( $assoc_args['posts'] ?? 24 ),
			)
		);
		WP_CLI::success( sprintf( 'Demo content imported. Visit %s', home_url( '/' ) ) );
	}

	/**
	 * Remove everything the import created.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @subcommand reset
	 *
	 * @param string[]             $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function reset( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'Delete all Sbek demo posts, pages, images, terms, menus and widgets?', $assoc_args );

		// An interrupted import leaves a job behind; drop it so removal can start.
		if ( 'running' === Sbek_Demo_Importer::status()['state'] ) {
			( new Sbek_Demo_Importer() )->abort();
		}

		$this->run( 'reset', array() );
		WP_CLI::success( 'Demo content removed. Site options such as language and permalinks were left as they are.' );
	}

	/**
	 * Show whether demo content is installed.
	 *
	 * @subcommand status
	 */
	public function status(): void {
		$status = Sbek_Demo_Importer::status();
		WP_CLI::log( sprintf( 'State: %s · posts/pages: %d · images: %d', $status['state'], $status['posts'], $status['images'] ) );
	}

	/**
	 * @param string              $type    import | reset.
	 * @param array<string,mixed> $options Job options.
	 */
	private function run( string $type, array $options ): void {
		$importer = new Sbek_Demo_Importer();

		try {
			$importer->start( $type, $options );
			$bar = WP_CLI\Utils\make_progress_bar( 'import' === $type ? 'Importing' : 'Removing', 100 );
			$at  = 0;

			do {
				$step = $importer->run_batch();
				$bar->tick( $step['progress'] - $at );
				$at = $step['progress'];
			} while ( ! $step['done'] );

			$bar->finish();
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}
}

WP_CLI::add_command( 'sbek demo', 'Sbek_Demo_Command' );
