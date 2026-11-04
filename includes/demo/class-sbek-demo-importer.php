<?php
/**
 * Demo content importer / remover, run in small resumable batches.
 *
 * Used by both the Sbek Theme → Tools buttons (one batch per AJAX request,
 * so no single request can hit a PHP or web-server timeout) and the WP-CLI
 * command (which simply loops over the batches).
 *
 *   $importer = new Sbek_Demo_Importer();
 *   $importer->start( 'import', array( 'site_setup' => true ) );
 *   do { $step = $importer->run_batch(); } while ( ! $step['done'] );
 *
 * State lives server-side in the `sbek_demo_job` option (never trusted from
 * the client). Everything created is recorded in `sbek_demo_manifest` after
 * every batch, so "remove" deletes exactly that — never pre-existing posts,
 * terms or media — even if an import was interrupted half-way.
 *
 * Images are generated with GD (no binary fixtures in the repo). All
 * headlines and body text are original placeholders.
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

class Sbek_Demo_Importer {

	public const MANIFEST = 'sbek_demo_manifest';
	public const JOB      = 'sbek_demo_job';
	private const LOCK    = 'sbek_demo_lock';
	private const ZWSP    = "\u{200B}";

	// Units of work per request, sized to stay well under typical timeouts.
	private const IMAGES_PER_BATCH      = 6;
	private const POSTS_PER_BATCH       = 20;
	private const DELETE_POSTS_PER_BATCH = 25;
	private const DELETE_MEDIA_PER_BATCH = 10;
	private const IMAGES_PER_PALETTE    = 6;
	private const ENGLISH_PER_CATEGORY  = 5;

	/** @var array<string,mixed> Current job. */
	private array $job = array();

	/** @var array<string,mixed> Objects created so far. */
	private array $manifest = array();

	// ─── Public API ───────────────────────────────────────────────────────

	/**
	 * Status for the UI: none | running | installed.
	 *
	 * @return array{state:string,type:string,progress:int,posts:int,images:int,installed:int}
	 */
	public static function status(): array {
		$job      = get_option( self::JOB );
		$manifest = get_option( self::MANIFEST );

		return array(
			'state'     => is_array( $job ) ? 'running' : ( is_array( $manifest ) ? 'installed' : 'none' ),
			'type'      => is_array( $job ) ? (string) $job['type'] : '',
			'progress'  => is_array( $job ) ? self::percent( $job ) : 0,
			'posts'     => is_array( $manifest ) ? count( (array) ( $manifest['posts'] ?? array() ) ) : 0,
			'images'    => is_array( $manifest ) ? count( (array) ( $manifest['attachments'] ?? array() ) ) : 0,
			'installed' => is_array( $manifest ) ? (int) ( $manifest['completed'] ?? 0 ) : 0,
		);
	}

	/**
	 * Create a new import or removal job.
	 *
	 * @param string              $type    import | reset.
	 * @param array<string,mixed> $options site_setup (bool), per_category (int).
	 * @throws RuntimeException When the job can't start.
	 */
	public function start( string $type, array $options = array() ): void {
		if ( get_option( self::JOB ) ) {
			throw new RuntimeException( esc_html__( 'Another demo-content job is still in progress. Continue or remove it first.', 'sbek-companion' ) );
		}

		if ( 'import' === $type ) {
			if ( get_option( self::MANIFEST ) ) {
				throw new RuntimeException( esc_html__( 'Demo content is already installed. Remove it first to import again.', 'sbek-companion' ) );
			}
			if ( ! function_exists( 'imagecreatetruecolor' ) ) {
				throw new RuntimeException( esc_html__( 'The PHP GD extension is required to generate the demo images.', 'sbek-companion' ) );
			}

			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);
			if ( ! $admins ) {
				throw new RuntimeException( esc_html__( 'No administrator account found to author the demo posts.', 'sbek-companion' ) );
			}

			$per_category = max( 9, min( 60, absint( $options['per_category'] ?? 24 ) ) );
			$queue_length = count( self::khmer_queue( $per_category ) );

			$job = array(
				'type'         => 'import',
				'phase'        => 'setup',
				'offset'       => 0,
				'site_setup'   => ! empty( $options['site_setup'] ),
				'per_category' => $per_category,
				'author'       => (int) ( get_current_user_id() ?: $admins[0] ),
				'now'          => time(),
				'terms'        => array(),
				'images'       => array(),
				'km'           => array(),
				'done_units'   => 0,
				'total_units'  => 1 + count( self::palettes() ) * self::IMAGES_PER_PALETTE + $queue_length + count( self::categories() ) * self::ENGLISH_PER_CATEGORY + 2,
			);

			update_option( self::MANIFEST, self::empty_manifest(), false );
		} elseif ( 'reset' === $type ) {
			$manifest = get_option( self::MANIFEST );
			if ( ! is_array( $manifest ) ) {
				throw new RuntimeException( esc_html__( 'No demo content is installed.', 'sbek-companion' ) );
			}

			$job = array(
				'type'        => 'reset',
				'phase'       => 'posts',
				'offset'      => 0,
				'done_units'  => 0,
				'total_units' => count( (array) ( $manifest['posts'] ?? array() ) ) + count( (array) ( $manifest['attachments'] ?? array() ) ) + 1,
			);
		} else {
			throw new RuntimeException( 'Unknown job type.' );
		}

		update_option( self::JOB, $job, false );
	}

	/**
	 * Abandon the current job (e.g. an interrupted import). The manifest is
	 * kept, so whatever was already created can still be removed.
	 *
	 * @throws RuntimeException While a batch is running.
	 */
	public function abort(): void {
		if ( ! $this->lock() ) {
			throw new RuntimeException( esc_html__( 'A batch is already running in another request. Please wait a moment.', 'sbek-companion' ) );
		}

		delete_option( self::JOB );
		$this->unlock();
	}

	/**
	 * Run the next batch of the current job.
	 *
	 * @return array{done:bool,progress:int,message:string,type:string}
	 * @throws RuntimeException On failure (the job is kept, so it can be retried).
	 */
	public function run_batch(): array {
		$job = get_option( self::JOB );
		if ( ! is_array( $job ) ) {
			throw new RuntimeException( esc_html__( 'There is no demo-content job to run.', 'sbek-companion' ) );
		}

		if ( ! $this->lock() ) {
			throw new RuntimeException( esc_html__( 'A batch is already running in another request. Please wait a moment.', 'sbek-companion' ) );
		}

		$this->job      = $job;
		$this->manifest = wp_parse_args( (array) get_option( self::MANIFEST, array() ), self::empty_manifest() );

		try {
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- one bounded batch.
			}
			wp_raise_memory_limit( 'image' );
			wp_defer_term_counting( true );

			$message = 'import' === $this->job['type'] ? $this->import_batch() : $this->reset_batch();

			wp_defer_term_counting( false );
		} finally {
			// Persist progress even when a step throws, so nothing created is orphaned.
			if ( 'reset' !== $this->job['type'] || 'finished' !== $this->job['phase'] ) {
				update_option( self::MANIFEST, $this->manifest, false );
			}
			$this->unlock();
		}

		$done = 'finished' === $this->job['phase'];

		if ( $done ) {
			delete_option( self::JOB );
			sbek_bump_cache_version();
		} else {
			update_option( self::JOB, $this->job, false );
		}

		return array(
			'done'     => $done,
			'progress' => $done ? 100 : self::percent( $this->job ),
			'message'  => $message,
			'type'     => (string) $this->job['type'],
		);
	}

	// ─── Import phases ────────────────────────────────────────────────────

	private function import_batch(): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		wp_set_current_user( (int) $this->job['author'] );

		switch ( $this->job['phase'] ) {
			case 'setup':
				sbek_ensure_language_terms();
				$warning = $this->job['site_setup'] ? $this->setup_site() : '';
				$this->job['terms'] = $this->create_terms();
				$this->advance( 'images', 1 );
				return trim( __( 'Site options and categories ready.', 'sbek-companion' ) . ' ' . $warning );

			case 'images':
				$names = array_keys( self::palettes() );
				$total = count( $names ) * self::IMAGES_PER_PALETTE;
				$end   = min( $total, $this->job['offset'] + self::IMAGES_PER_BATCH );

				for ( $k = $this->job['offset']; $k < $end; $k++ ) {
					$name = $names[ intdiv( $k, self::IMAGES_PER_PALETTE ) ];
					$this->create_image( $name, $k % self::IMAGES_PER_PALETTE + 1, ( 7 + $k ) * 97 );
				}

				$this->step( $end, $total, 'km_posts', $end - $this->job['offset'] );
				/* translators: 1: images done, 2: total images. */
				return sprintf( __( 'Generated images %1$d of %2$d.', 'sbek-companion' ), $end, $total );

			case 'km_posts':
				$queue = self::khmer_queue( (int) $this->job['per_category'] );
				$end   = min( count( $queue ), $this->job['offset'] + self::POSTS_PER_BATCH );

				for ( $n = $this->job['offset']; $n < $end; $n++ ) {
					$this->create_khmer_post( $n, $queue[ $n ] );
				}

				$this->step( $end, count( $queue ), 'en_posts', $end - $this->job['offset'] );
				/* translators: 1: posts done, 2: total posts. */
				return sprintf( __( 'Created Khmer posts %1$d of %2$d.', 'sbek-companion' ), $end, count( $queue ) );

			case 'en_posts':
				$total = count( self::categories() ) * self::ENGLISH_PER_CATEGORY;
				$end   = min( $total, $this->job['offset'] + self::POSTS_PER_BATCH );

				for ( $n = $this->job['offset']; $n < $end; $n++ ) {
					$this->create_english_post( $n );
				}

				$this->step( $end, $total, 'pages', $end - $this->job['offset'] );
				/* translators: 1: posts done, 2: total posts. */
				return sprintf( __( 'Created English posts %1$d of %2$d.', 'sbek-companion' ), $end, $total );

			case 'pages':
				$this->create_pages();
				$this->advance( 'finish', 1 );
				return __( 'Created pages.', 'sbek-companion' );

			case 'finish':
				if ( $this->job['site_setup'] ) {
					$this->create_menus();
					$this->create_widgets();
					$this->save_site_settings();
				}
				$this->manifest['completed'] = time();
				$this->advance( 'finished', 1 );
				return __( 'Demo content imported.', 'sbek-companion' );
		}

		throw new RuntimeException( 'Unknown import phase.' );
	}

	// ─── Reset phases ─────────────────────────────────────────────────────

	private function reset_batch(): string {
		switch ( $this->job['phase'] ) {
			case 'posts':
				$ids   = (array) $this->manifest['posts'];
				$batch = array_slice( $ids, $this->job['offset'], self::DELETE_POSTS_PER_BATCH );

				foreach ( $batch as $id ) {
					wp_delete_post( (int) $id, true );
				}

				$end = $this->job['offset'] + count( $batch );
				$this->step( $end, count( $ids ), 'attachments', count( $batch ) );
				/* translators: 1: deleted count, 2: total. */
				return sprintf( __( 'Deleted posts and pages %1$d of %2$d.', 'sbek-companion' ), $end, count( $ids ) );

			case 'attachments':
				$ids   = (array) $this->manifest['attachments'];
				$batch = array_slice( $ids, $this->job['offset'], self::DELETE_MEDIA_PER_BATCH );

				foreach ( $batch as $id ) {
					wp_delete_attachment( (int) $id, true );
				}

				$end = $this->job['offset'] + count( $batch );
				$this->step( $end, count( $ids ), 'cleanup', count( $batch ) );
				/* translators: 1: deleted count, 2: total. */
				return sprintf( __( 'Deleted images %1$d of %2$d.', 'sbek-companion' ), $end, count( $ids ) );

			case 'cleanup':
				$this->cleanup();
				$this->advance( 'finished', 1 );
				return __( 'Demo content removed. Site options such as language and permalinks were left as they are.', 'sbek-companion' );
		}

		throw new RuntimeException( 'Unknown reset phase.' );
	}

	private function cleanup(): void {
		foreach ( (array) $this->manifest['terms'] as $entry ) {
			list( $taxonomy, $term_id ) = array_pad( explode( ':', (string) $entry ), 2, 0 );
			if ( taxonomy_exists( $taxonomy ) ) {
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}

		foreach ( (array) $this->manifest['menus'] as $menu_id ) {
			wp_delete_nav_menu( (int) $menu_id );
		}

		$instances = (array) get_option( 'widget_block', array() );
		$sidebars  = (array) get_option( 'sidebars_widgets', array() );
		foreach ( (array) $this->manifest['widgets'] as $number ) {
			unset( $instances[ (int) $number ] );
			foreach ( $sidebars as $sidebar => $widgets ) {
				if ( is_array( $widgets ) ) {
					$sidebars[ $sidebar ] = array_values( array_diff( $widgets, array( 'block-' . (int) $number ) ) );
				}
			}
		}
		update_option( 'widget_block', $instances );
		update_option( 'sidebars_widgets', $sidebars );

		// Sbek Theme is kept (the site owner may have edited it since the
		// import) — only references to images that were just deleted are cleared.
		$deleted = array_map( 'intval', (array) $this->manifest['attachments'] );
		if ( in_array( (int) get_theme_mod( 'custom_logo' ), $deleted, true ) ) {
			remove_theme_mod( 'custom_logo' );
		}
		$settings = get_option( SBEK_COMPANION_OPTION );
		if ( is_array( $settings ) && in_array( (int) ( $settings['og_image'] ?? 0 ), $deleted, true ) ) {
			$settings['og_image'] = 0;
			update_option( SBEK_COMPANION_OPTION, $settings );
		}
		if ( in_array( (int) get_option( 'site_icon' ), $deleted, true ) ) {
			update_option( 'site_icon', 0 );
		}

		delete_option( self::MANIFEST );
	}

	// ─── Job bookkeeping ──────────────────────────────────────────────────

	/**
	 * Move to $next_phase once $done reaches $total, else stay at offset $done.
	 */
	private function step( int $done, int $total, string $next_phase, int $units ): void {
		if ( $done >= $total ) {
			$this->advance( $next_phase, $units );
			return;
		}

		$this->job['offset']      = $done;
		$this->job['done_units'] += $units;
	}

	private function advance( string $phase, int $units ): void {
		$this->job['phase']       = $phase;
		$this->job['offset']      = 0;
		$this->job['done_units'] += $units;
	}

	/**
	 * @param array<string,mixed> $job Job.
	 */
	private static function percent( array $job ): int {
		$total = max( 1, (int) ( $job['total_units'] ?? 1 ) );

		return (int) min( 99, floor( 100 * (int) ( $job['done_units'] ?? 0 ) / $total ) );
	}

	/**
	 * Single-flight lock: two tabs (or a double click) can't run batches of
	 * the same job at once. A stale lock (crashed request) expires after 3 min.
	 */
	private function lock(): bool {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}

		if ( time() - (int) get_option( self::LOCK ) > 3 * MINUTE_IN_SECONDS ) {
			update_option( self::LOCK, time(), false );
			return true;
		}

		return false;
	}

	private function unlock(): void {
		delete_option( self::LOCK );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function empty_manifest(): array {
		return array(
			'posts'       => array(),
			'attachments' => array(),
			'terms'       => array(),
			'menus'       => array(),
			'widgets'     => array(),
		);
	}

	// ─── Data definitions ─────────────────────────────────────────────────

	/**
	 * slug => [km name, en name, color, image palette].
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:array{0:string,1:string}}>
	 */
	private static function categories(): array {
		return array(
			'entertainment' => array( 'កម្សាន្ត', 'Entertainment', '#fa5480', array( '#fa5480', '#7b2ff7' ) ),
			'technology'    => array( 'បច្ចេកវិទ្យា', 'Technology', '#4390f8', array( '#4390f8', '#0b2a5b' ) ),
			'life'          => array( 'ជីវិតនិងសង្គម', 'Life & Society', '#38c378', array( '#38c378', '#0f4c3a' ) ),
			'sport'         => array( 'កីឡា', 'Sport', '#f9a62b', array( '#f9a62b', '#b8360f' ) ),
		);
	}

	/**
	 * Image palettes in generation order.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function palettes(): array {
		$palettes = array(
			'video' => array( '#f2272f', '#1a1a1a' ),
			'auto'  => array( '#fa1939', '#2b2d42' ),
		);
		foreach ( self::categories() as $slug => $category ) {
			$palettes[ $slug ] = $category[3];
		}

		return $palettes;
	}

	/**
	 * Khmer stories, interleaved so every section has recent posts.
	 *
	 * @return array<int,array{0:string,1:string,2:int}> [kind, category slug, index]
	 */
	private static function khmer_queue( int $per_category ): array {
		$queue = array();
		for ( $i = 0; $i < $per_category; $i++ ) {
			foreach ( array_keys( self::categories() ) as $slug ) {
				$queue[] = array( 'section', $slug, $i );
			}
			if ( $i < 14 ) {
				$queue[] = array( 'autotalk', 'technology', $i );
			}
			if ( $i < 11 ) {
				$queue[] = array( 'video', 'entertainment', $i );
			}
		}

		return $queue;
	}

	// ─── Site & terms ─────────────────────────────────────────────────────

	/**
	 * Demo site options. Returns a warning message ('' if none).
	 */
	private function setup_site(): string {
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure( '/article/%post_id%/' );
		$wp_rewrite->set_category_base( 'topics' );
		$wp_rewrite->set_tag_base( 'article/tag' );
		// category/post_tag registered their permastructs with the old bases
		// during init — re-register them, or the flush keeps stale rules.
		create_initial_taxonomies();
		flush_rewrite_rules( false );

		update_option( 'timezone_string', 'Asia/Phnom_Penh' );
		update_option( 'posts_per_page', 8 );
		update_option( 'blogname', 'Sbek News' );
		update_option( 'blogdescription', 'ព័ត៌មានថ្មីៗ រហ័ស និងទុកចិត្តបាន' );

		$warning = '';
		if ( ! in_array( 'km', get_available_languages(), true ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			ob_start(); // The upgrader skin may print; keep AJAX responses clean.
			$installed = wp_download_language_pack( 'km' );
			ob_end_clean();
			if ( ! $installed ) {
				$warning = __( 'The Khmer core language pack could not be downloaded; theme texts are still translated.', 'sbek-companion' );
			}
		}
		update_option( 'WPLANG', 'km' );

		return $warning;
	}

	/**
	 * @return array<string,int> slug => term ID
	 */
	private function create_terms(): array {
		$ids   = array();
		$order = 1;

		foreach ( self::categories() as $slug => $category ) {
			$ids[ $slug ] = $this->ensure_term( 'category', $slug, $category[0] );
			update_term_meta( $ids[ $slug ], 'sbek_color', $category[2] );
			update_term_meta( $ids[ $slug ], 'sbek_name_en', $category[1] );
			update_term_meta( $ids[ $slug ], 'sbek_home_order', $order++ );
		}

		$ids['autotalk'] = $this->ensure_term( 'post_tag', 'autotalk', 'AutoTalk' );
		update_term_meta( $ids['autotalk'], 'sbek_color', '#fa1939' );

		$ids['tag-news']      = $this->ensure_term( 'post_tag', 'news', 'ព័ត៌មាន' );
		$ids['tag-highlight'] = $this->ensure_term( 'post_tag', 'highlight', 'អត្ថបទសំខាន់' );
		update_term_meta( $ids['tag-news'], 'sbek_name_en', 'News' );
		update_term_meta( $ids['tag-highlight'], 'sbek_name_en', 'Highlights' );

		return $ids;
	}

	/**
	 * Reuse a term with this slug, or create it (and record it for removal).
	 *
	 * @throws RuntimeException When the term can't be created.
	 */
	private function ensure_term( string $taxonomy, string $slug, string $name ): int {
		$existing = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing instanceof WP_Term ) {
			return $existing->term_id;
		}

		$created = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
		if ( is_wp_error( $created ) ) {
			throw new RuntimeException( esc_html( $created->get_error_message() ) );
		}

		$this->manifest['terms'][] = $taxonomy . ':' . $created['term_id'];

		return (int) $created['term_id'];
	}

	// ─── Images ───────────────────────────────────────────────────────────

	/**
	 * @throws RuntimeException When the upload fails.
	 */
	private function create_image( string $palette, int $number, int $seed ): void {
		$file = $this->render_image( $seed, self::palettes()[ $palette ] );
		$id   = media_handle_sideload(
			array(
				'name'     => "sbek-demo-{$palette}-{$number}.jpg",
				'tmp_name' => $file,
			),
			0,
			'Sbek demo image'
		);

		if ( is_wp_error( $id ) ) {
			wp_delete_file( $file );
			throw new RuntimeException( esc_html( $id->get_error_message() ) );
		}

		update_post_meta( $id, '_wp_attachment_image_alt', 'រូបភាពគំរូ' );
		$this->manifest['attachments'][]       = $id;
		$this->job['images'][ $palette ][] = $id;
	}

	/**
	 * Draw a 1280×720 placeholder (diagonal gradient, soft circles, picture
	 * glyph) and return the temp file path.
	 *
	 * @param int                      $seed   Deterministic seed.
	 * @param array{0:string,1:string} $colors Gradient endpoints.
	 */
	private function render_image( int $seed, array $colors ): string {
		$rand = static function ( float $n ) use ( &$seed ): float {
			$seed = ( $seed * 9301 + 49297 ) % 233280;
			return ( $seed / 233280 ) * $n;
		};

		$w   = 640;
		$h   = 360;
		$img = imagecreatetruecolor( $w, $h );
		$a   = sscanf( $colors[0], '#%02x%02x%02x' );
		$b   = sscanf( $colors[1], '#%02x%02x%02x' );

		$steps = $w + $h;
		for ( $d = 0; $d <= $steps; $d++ ) {
			$t     = $d / $steps;
			$color = imagecolorallocate(
				$img,
				(int) round( $a[0] + ( $b[0] - $a[0] ) * $t ),
				(int) round( $a[1] + ( $b[1] - $a[1] ) * $t ),
				(int) round( $a[2] + ( $b[2] - $a[2] ) * $t )
			);
			imageline( $img, $d, 0, $d - $h, $h, $color );
		}

		imagealphablending( $img, true );
		for ( $i = 0; $i < 6; $i++ ) {
			$size = (int) ( 60 + $rand( 280 ) );
			imagefilledellipse( $img, (int) $rand( $w ), (int) $rand( $h ), $size, $size, imagecolorallocatealpha( $img, 255, 255, 255, (int) ( 105 + $rand( 15 ) ) ) );
		}

		$glyph = imagecolorallocatealpha( $img, 255, 255, 255, 80 );
		imagesetthickness( $img, 6 );
		imagerectangle( $img, 270, 150, 370, 210, $glyph );
		imagefilledellipse( $img, 295, 170, 16, 16, $glyph );
		imagefilledpolygon( $img, array( 276, 204, 306, 178, 324, 192, 342, 172, 364, 204 ), $glyph );

		$out = imagecreatetruecolor( 1280, 720 );
		imagecopyresampled( $out, $img, 0, 0, 0, 0, 1280, 720, $w, $h );

		$file = wp_tempnam( 'sbek-demo' ) . '.jpg';
		imagejpeg( $out, $file, 82 );

		return $file;
	}

	private function image_for( string $palette, int $i ): int {
		$images = (array) $this->job['images'];
		$set    = $images[ $palette ] ?? reset( $images ) ?: array( 0 );

		return (int) $set[ $i % count( $set ) ];
	}

	// ─── Text ─────────────────────────────────────────────────────────────

	private static function khmer_digits( int $n ): string {
		return strtr( (string) $n, array_combine( range( 0, 9 ), array( '០', '១', '២', '៣', '៤', '៥', '៦', '៧', '៨', '៩' ) ) );
	}

	/**
	 * Original placeholder Khmer headline of varying length.
	 */
	private static function khmer_title( string $topic, int $i ): string {
		$leads = array( 'ចំណងជើងព័ត៌មានគំរូ', 'អត្ថបទគំរូសម្រាប់ការបង្ហាញ', 'ព័ត៌មានថ្មីៗប្រចាំថ្ងៃ' );
		$tails = array(
			'សម្រាប់បង្ហាញប្លង់ទំព័រដើម និងរចនាបថរបស់គេហទំព័រ',
			'ដែលប្រើជាទិន្នន័យសាកល្បងសម្រាប់ការអភិវឌ្ឍន៍',
			'ខណៈក្រុមការងារកំពុងរៀបចំមាតិកាថ្មីសម្រាប់អ្នកអាន',
			'និងព័ត៌មានលម្អិតបន្ថែមនឹងបង្ហាញនៅពេលក្រោយ',
			'Demo Headline ដែលមានពាក្យអង់គ្លេសលាយឡំ',
		);

		return sprintf( '%s %s ទី%s %s', $topic, $leads[ $i % 3 ], self::khmer_digits( $i + 1 ), $tails[ $i % 5 ] );
	}

	/**
	 * Khmer body as block markup. Words are separated with U+200B (the
	 * "Show word breaks" switch reveals them).
	 */
	private static function khmer_body(): string {
		$words = static fn( string $s ): string => esc_html( str_replace( ' ', self::ZWSP, $s ) );

		$paragraphs = array(
			'នេះ ជា កថាខណ្ឌ គំរូ ទី មួយ ដែល ប្រើ សម្រាប់ បង្ហាញ ប្លង់ ទំព័រ អត្ថបទ លម្អិត។ មាតិកា ពិត ប្រាកដ នឹង ត្រូវ ជំនួស ដោយ អត្ថបទ ពី ប្រព័ន្ធ គ្រប់គ្រង មាតិកា នៅ ពេល ក្រោយ។',
			'កថាខណ្ឌ ទី ពីរ បង្ហាញ ពី របៀប ដែល អត្ថបទ វែង ៗ ត្រូវ បាន បំបែក ជា បន្ទាត់ ច្រើន ដោយ មាន គម្លាត បន្ទាត់ ២៤ ភីកសែល និង ពណ៌ អក្សរ ខ្មៅ ស្រាល ដើម្បី ងាយ ស្រួល អាន។ Demo text ជា ភាសា អង់គ្លេស ក៏ អាច លាយ ឡំ បាន ដែរ។',
			'ក្រុម ការងារ អាច ប្រើ ទំព័រ នេះ ដើម្បី សាកល្បង រូបភាព ចំណង ជើង ព័ត៌មាន ពាក្យ គន្លឹះ និង អត្ថបទ ទាក់ទង ដោយ មិន ចាំបាច់ ភ្ជាប់ ទៅ ម៉ាស៊ីន មេ ពិត ប្រាកដ ឡើយ។',
			'កថាខណ្ឌ ចុង ក្រោយ នេះ បញ្ចប់ អត្ថបទ គំរូ។ សូម អរគុណ ដែល បាន អាន ដល់ ទី បញ្ចប់។',
		);

		$p = static fn( string $text ): string => "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";

		return implode(
			"\n\n",
			array(
				$p( $words( $paragraphs[0] ) ),
				$p( $words( $paragraphs[1] ) ),
				"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . $words( 'ចំណុច សំខាន់ ៗ' ) . "</h2>\n<!-- /wp:heading -->",
				"<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>" . $words( 'ចំណុច ទី មួយ សម្រាប់ សាកល្បង បញ្ជី' ) . "</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>" . $words( 'ចំណុច ទី ពីរ ដែល មាន អត្ថបទ វែង ជាង' ) . "</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
				$p( $words( $paragraphs[2] ) ),
				"<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>" . $words( 'នេះ ជា សម្រង់ សម្ដី គំរូ សម្រាប់ បង្ហាញ រចនាបថ។' ) . "</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->",
				$p( $words( $paragraphs[3] ) ),
			)
		);
	}

	private static function english_body(): string {
		$p = static fn( string $text ): string => "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";

		return implode(
			"\n\n",
			array(
				$p( 'This is the first placeholder paragraph of a demo article. Real reporting will replace it once the newsroom starts publishing in English.' ),
				$p( 'The second paragraph shows how longer copy wraps across several lines with comfortable line height and a soft text color, so the article page can be reviewed without real content.' ),
				"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Key points</h2>\n<!-- /wp:heading -->",
				"<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>A first point to test list styling</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>A second, slightly longer point</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
				$p( 'Editors can use this page to check images, headlines, tags and related stories before the site goes live.' ),
				$p( 'This closing paragraph ends the demo article. Thanks for reading to the end.' ),
			)
		);
	}

	// ─── Posts ────────────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $data Post fields + `sbek` extras.
	 * @throws RuntimeException When the post can't be created.
	 */
	private function insert_post( array $data ): int {
		$extras = $data['sbek'] ?? array();
		unset( $data['sbek'] );

		$id = wp_insert_post(
			array_merge(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'post_author'    => (int) $this->job['author'],
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'meta_input'     => array( '_sbek_demo' => 1 ),
				),
				$data
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( esc_html( $id->get_error_message() ) );
		}

		$this->manifest['posts'][] = $id;

		wp_set_object_terms( $id, array( $extras['lang'] ?? SBEK_DEFAULT_LANGUAGE ), 'language' );

		if ( ! empty( $extras['categories'] ) ) {
			wp_set_post_categories( $id, $extras['categories'] );
		}
		if ( ! empty( $extras['tags'] ) ) {
			wp_set_object_terms( $id, $extras['tags'], 'post_tag' );
		}
		if ( ! empty( $extras['image'] ) ) {
			set_post_thumbnail( $id, $extras['image'] );
		}
		if ( ! empty( $extras['format'] ) ) {
			set_post_format( $id, $extras['format'] );
		}
		if ( ! empty( $extras['sticky'] ) ) {
			stick_post( $id );
		}

		return $id;
	}

	/**
	 * @param int                           $n    Position in the queue (drives date, tags, flags).
	 * @param array{0:string,1:string,2:int} $item [kind, category slug, index].
	 */
	private function create_khmer_post( int $n, array $item ): void {
		list( $kind, $slug, $i ) = $item;

		$terms  = (array) $this->job['terms'];
		$topics = array(
			'entertainment' => 'តារាសម្ដែង',
			'technology'    => 'បច្ចេកវិទ្យាថ្មី',
			'life'          => 'សុខភាព',
			'sport'         => 'បាល់ទាត់',
		);
		$tags   = array( 0 === $n % 3 ? $terms['tag-highlight'] : $terms['tag-news'] );

		switch ( $kind ) {
			case 'autotalk':
				$title   = self::khmer_title( 'រថយន្តម៉ូដែលថ្មី', $i + 3 );
				$palette = 'auto';
				$tags[]  = $terms['autotalk'];
				break;
			case 'video':
				$title   = self::khmer_title( 'វីដេអូ', $i + 4 );
				$palette = 'video';
				break;
			default:
				$title   = self::khmer_title( $topics[ $slug ], $i );
				$palette = $slug;
		}

		$timestamp = (int) $this->job['now'] - $n * 47 * MINUTE_IN_SECONDS - ( $n % 7 ) * 3 * MINUTE_IN_SECONDS;

		$id = $this->insert_post(
			array(
				'post_title'    => $title,
				'post_content'  => self::khmer_body(),
				'post_excerpt'  => 'នេះជាអត្ថបទសង្ខេបគំរូ សម្រាប់បង្ហាញក្នុងប្លង់ទំព័រ និងការសាកល្បងមុខងារផ្សេងៗរបស់គេហទំព័រប៉ុណ្ណោះ។',
				'post_date'     => wp_date( 'Y-m-d H:i:s', $timestamp ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'meta_input'    => array_filter(
					array(
						'_sbek_demo'           => 1,
						'_sbek_source_name'    => 'Demo Source',
						'_sbek_source_url'     => 'https://example.com/',
						'_sbek_trending_score' => 0 === $n % 4 ? 40 + ( $n * 7 ) % 60 : null,
						'_sbek_is_breaking'    => $n < 4 ? 1 : null,
					)
				),
				'sbek'          => array(
					'lang'       => 'km',
					'categories' => array( $terms[ $slug ] ),
					'tags'       => $tags,
					'image'      => $this->image_for( $palette, $n ),
					'format'     => 'video' === $kind ? 'video' : '',
					'sticky'     => in_array( $n, array( 1, 3, 6, 8 ), true ),
				),
			)
		);

		$this->job['km']['by_category'][ $slug ][] = $id;
	}

	/**
	 * English story $n, linked as the translation of a recent Khmer story so
	 * the language switcher and hreflang have pairs.
	 */
	private function create_english_post( int $n ): void {
		$slugs    = array_keys( self::categories() );
		$slug     = $slugs[ intdiv( $n, self::ENGLISH_PER_CATEGORY ) ];
		$i        = $n % self::ENGLISH_PER_CATEGORY;
		$category = self::categories()[ $slug ];
		$terms    = (array) $this->job['terms'];

		$timestamp = (int) $this->job['now'] - $n * 95 * MINUTE_IN_SECONDS;

		$id = $this->insert_post(
			array(
				'post_title'    => sprintf( '%s demo story %d: placeholder headline for layout testing', $category[1], $i + 1 ),
				'post_content'  => self::english_body(),
				'post_excerpt'  => 'A short placeholder summary used to preview the English edition of the layout.',
				'post_date'     => wp_date( 'Y-m-d H:i:s', $timestamp ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'meta_input'    => array(
					'_sbek_demo'        => 1,
					'_sbek_source_name' => 'Demo Source',
					'_sbek_source_url'  => 'https://example.com/',
				),
				'sbek'          => array(
					'lang'       => 'en',
					'categories' => array( $terms[ $slug ] ),
					'tags'       => array( $terms['tag-news'] ),
					'image'      => $this->image_for( $slug, $i + 2 ),
					'format'     => $i < 1 ? 'video' : '',
				),
			)
		);

		$km_id = (int) ( $this->job['km']['by_category'][ $slug ][ $i ] ?? 0 );
		if ( $km_id ) {
			sbek_set_translation( $id, $km_id );
		}
	}

	private function create_pages(): void {
		$pages = array(
			'privacy' => array(
				'km' => array( 'គោលការណ៍ភាពឯកជន', 'privacy-policy' ),
				'en' => array( 'Privacy Policy', 'privacy-policy-en' ),
			),
			'about'   => array(
				'km' => array( 'អំពីយើង', 'about' ),
				'en' => array( 'About us', 'about-en' ),
			),
		);

		foreach ( $pages as $key => $languages ) {
			$ids = array();
			foreach ( $languages as $lang => list( $title, $slug ) ) {
				$body = 'km' === $lang
					? '<!-- wp:paragraph --><p>' . esc_html( str_replace( ' ', self::ZWSP, 'នេះ ជា ទំព័រ គំរូ។ សូម ជំនួស មាតិកា នេះ ដោយ ព័ត៌មាន ពិត របស់ អ្នក។' ) ) . '</p><!-- /wp:paragraph -->'
					: '<!-- wp:paragraph --><p>This is a placeholder page. Replace this text with your own content.</p><!-- /wp:paragraph -->';

				$ids[ $lang ] = $this->insert_post(
					array(
						'post_type'    => 'page',
						'post_title'   => $title,
						'post_name'    => $slug,
						'post_content' => $body,
						'sbek'         => array( 'lang' => $lang ),
					)
				);
			}

			sbek_set_translation( $ids['en'], $ids['km'] );

			if ( 'privacy' === $key && $this->job['site_setup'] ) {
				update_option( 'wp_page_for_privacy_policy', $ids['km'] );
			}
		}
	}

	// ─── Menus, widgets, settings ─────────────────────────────────────────

	private function create_menus(): void {
		$terms     = (array) $this->job['terms'];
		$locations = get_theme_mod( 'nav_menu_locations', array() );

		foreach ( array( 'primary' => 'Sbek primary (ខ្មែរ)', 'primary-en' => 'Sbek primary (English)' ) as $location => $name ) {
			if ( wp_get_nav_menu_object( $name ) ) {
				continue; // Keep a menu the site already has.
			}

			$menu_id = wp_create_nav_menu( $name );
			if ( is_wp_error( $menu_id ) ) {
				continue;
			}
			$this->manifest['menus'][] = $menu_id;

			wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'  => 'primary' === $location ? 'ទំព័រដើម' : 'Home',
					'menu-item-url'    => home_url( '/' ),
					'menu-item-type'   => 'custom',
					'menu-item-status' => 'publish',
				)
			);

			foreach ( array_keys( self::categories() ) as $slug ) {
				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-object-id' => $terms[ $slug ],
						'menu-item-object'    => 'category',
						'menu-item-type'      => 'taxonomy',
						'menu-item-status'    => 'publish',
					)
				);
			}

			wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'     => 'AUTO TALK',
					'menu-item-object-id' => $terms['autotalk'],
					'menu-item-object'    => 'post_tag',
					'menu-item-type'      => 'taxonomy',
					'menu-item-status'    => 'publish',
				)
			);

			$locations[ $location ] = $menu_id;
		}

		set_theme_mod( 'nav_menu_locations', $locations );
	}

	/**
	 * Placeholder ad boxes in the ad widget areas (Custom HTML blocks).
	 */
	private function create_widgets(): void {
		$instances = (array) get_option( 'widget_block', array() );
		$sidebars  = (array) get_option( 'sidebars_widgets', array() );
		$next      = max( array_merge( array( 1 ), array_filter( array_keys( $instances ), 'is_int' ) ) ) + 1;

		$ads = array(
			'sbek-ad-header' => array( 'sbek-ad-placeholder sbek-ad-placeholder--leaderboard', 'Advertisement · 728×90' ),
			'sbek-ad-hero'   => array( 'sbek-ad-placeholder', 'Advertisement · 300×250' ),
			'sbek-ad-video'  => array( 'sbek-ad-placeholder', 'Advertisement · 300×250' ),
		);

		foreach ( $ads as $sidebar => list( $class, $label ) ) {
			$count = 'sbek-ad-hero' === $sidebar ? 2 : 1;
			for ( $i = 0; $i < $count; $i++ ) {
				$instances[ $next ] = array(
					'content' => "<!-- wp:html -->\n<div class=\"{$class}\" role=\"img\" aria-label=\"{$label}\">{$label}</div>\n<!-- /wp:html -->",
				);
				$sidebars[ $sidebar ]        = array_merge( (array) ( $sidebars[ $sidebar ] ?? array() ), array( 'block-' . $next ) );
				$this->manifest['widgets'][] = $next;
				++$next;
			}
		}

		$instances['_multiwidget'] = 1;
		update_option( 'widget_block', $instances );
		update_option( 'sidebars_widgets', $sidebars );
	}

	/**
	 * Fill Sbek Theme with demo details — only fields that are still empty,
	 * so values the site owner has set are never overwritten.
	 */
	private function save_site_settings(): void {
		$theme   = (array) get_option( 'sbek_site_settings', array() );
		$plugin  = sbek_companion_setting();
		$current = array_merge( $theme, $plugin );
		$is_set  = static fn( string $key ): bool => ! empty( $current[ $key ] );

		$logo_id = 0;
		if ( ! get_theme_mod( 'custom_logo' ) || ! get_option( 'site_icon' ) ) {
			$logo_file = wp_tempnam( 'sbek-logo' ) . '.png';
			copy( SBEK_COMPANION_DIR . '/assets/images/demo-logo.png', $logo_file );
			$uploaded = media_handle_sideload(
				array(
					'name'     => 'sbek-demo-logo.png',
					'tmp_name' => $logo_file,
				),
				0,
				'Sbek demo logo'
			);
			if ( ! is_wp_error( $uploaded ) ) {
				$logo_id                         = (int) $uploaded;
				$this->manifest['attachments'][] = $logo_id;
			}
		}

		$demo = array(
			'name_en'        => 'Sbek News',
			'tagline_en'     => 'Fast, trusted news',
			'og_image'       => $this->image_for( 'technology', 0 ),
			'lat'            => '11.5564',
			'lng'            => '104.9282',
			'description'    => 'Sbek News ជាគេហទំព័រគំរូសម្រាប់បង្ហាញប្លង់ និងរចនាបថ។ មិនមែនជាសារព័ត៌មានពិតទេ។',
			'description_en' => 'Sbek News is a demo site for previewing the layout and design. It is not a real news outlet.',
			'address'        => "អាសយដ្ឋានគំរូ\nភ្នំពេញ កម្ពុជា",
			'address_en'     => "Sample address\nPhnom Penh, Cambodia",
			'email'          => 'newsroom@example.com',
			'phones'         => array(
				array(
					'label'  => '',
					'number' => '+855 00 000 000',
				),
			),
			'socials'        => array(
				array( 'platform' => 'Facebook', 'url' => 'https://www.facebook.com/' ),
				array( 'platform' => 'Instagram', 'url' => 'https://www.instagram.com/' ),
				array( 'platform' => 'YouTube', 'url' => 'https://www.youtube.com/' ),
				array( 'platform' => 'TikTok', 'url' => 'https://www.tiktok.com/' ),
				array( 'platform' => 'Telegram', 'url' => 'https://telegram.org/' ),
			),
		);

		foreach ( $demo as $key => $value ) {
			if ( $is_set( $key ) || empty( $value ) ) {
				continue;
			}
			// Each value goes to whichever option owns the key.
			if ( array_key_exists( $key, sbek_companion_defaults() ) ) {
				$plugin[ $key ] = $value;
			} else {
				$theme[ $key ] = $value;
			}
		}

		update_option( 'sbek_site_settings', $theme ); // Both run their registered sanitizer.
		update_option( SBEK_COMPANION_OPTION, $plugin );

		if ( $logo_id && ! get_theme_mod( 'custom_logo' ) ) {
			set_theme_mod( 'custom_logo', $logo_id );
		}

		if ( $logo_id && ! get_option( 'site_icon' ) ) {
			update_option( 'site_icon', $logo_id );
		}

	}
}
