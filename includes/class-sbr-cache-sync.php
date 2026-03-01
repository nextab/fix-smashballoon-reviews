<?php
/**
 * Synchronisiert SBR Reviews-Posts in die Feed-Cache-Tabelle.
 *
 * @package FixSmashballoonReviews
 */

defined( 'ABSPATH' ) || exit;

class SBR_Cache_Sync {

	const CRON_HOOK = 'fsbr_cache_sync_daily';
	const OPTION_LAST_SYNC = 'fsbr_last_sync_result';
	const OPTION_ADMIN_NOTICE = 'fsbr_admin_notice';

	public function register_hooks() {
		add_action( self::CRON_HOOK, [ $this, 'run_sync' ] );
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_manual_sync' ] );
		add_action( 'admin_notices', [ $this, 'show_admin_notices' ] );
		add_action( 'wp', [ $this, 'schedule_cron_if_needed' ] );
	}

	public function schedule_cron_if_needed() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + 300, 'daily', self::CRON_HOOK );
	}

	public function add_admin_menu() {
		add_options_page(
			__( 'SBR Cache Sync', 'fix-smashballoon-reviews' ),
			__( 'SBR Cache Sync', 'fix-smashballoon-reviews' ),
			'manage_options',
			'fsbr-cache-sync',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page() {
		$last_result = get_option( self::OPTION_LAST_SYNC, null );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SBR Cache Sync', 'fix-smashballoon-reviews' ); ?></h1>
			<p><?php esc_html_e( 'Synchronisiert die Reviews aus der Post-Tabelle in die Cache-Tabelle des Smash Balloon Reviews Feed Plugins.', 'fix-smashballoon-reviews' ); ?></p>
			<?php if ( $last_result !== null ) : ?>
				<p>
					<?php
					if ( $last_result['success'] ) {
						printf(
							/* translators: %d: number of posts synced */
							esc_html__( 'Letzter Sync: %d Posts übertragen.', 'fix-smashballoon-reviews' ),
							(int) $last_result['count']
						);
					} else {
						printf(
							/* translators: %s: error message */
							esc_html__( 'Letzter Sync fehlgeschlagen: %s', 'fix-smashballoon-reviews' ),
							esc_html( $last_result['message'] ?? 'Unbekannter Fehler' )
						);
					}
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'fsbr_manual_sync', 'fsbr_nonce' ); ?>
				<p>
					<button type="submit" name="fsbr_sync" class="button button-primary">
						<?php esc_html_e( 'Jetzt synchronisieren', 'fix-smashballoon-reviews' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	public function handle_manual_sync() {
		if ( ! isset( $_POST['fsbr_sync'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( $_POST['fsbr_nonce'] ?? '' ), 'fsbr_manual_sync' ) ) {
			update_option( self::OPTION_ADMIN_NOTICE, [ 'type' => 'error', 'message' => __( 'Sicherheitsprüfung fehlgeschlagen.', 'fix-smashballoon-reviews' ) ] );
			wp_safe_redirect( admin_url( 'options-general.php?page=fsbr-cache-sync' ) );
			exit;
		}
		$result = $this->sync_posts_to_cache( '1' );
		update_option( self::OPTION_LAST_SYNC, $result );
		$type = $result['success'] ? 'success' : 'error';
		$message = $result['success']
			? sprintf( __( '%d Posts erfolgreich in den Cache übertragen.', 'fix-smashballoon-reviews' ), $result['count'] )
			: ( $result['message'] ?? __( 'Sync fehlgeschlagen.', 'fix-smashballoon-reviews' ) );
		update_option( self::OPTION_ADMIN_NOTICE, [ 'type' => $type, 'message' => $message ] );
		wp_safe_redirect( admin_url( 'options-general.php?page=fsbr-cache-sync' ) );
		exit;
	}

	public function show_admin_notices() {
		$notice = get_option( self::OPTION_ADMIN_NOTICE, null );
		if ( $notice === null || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_option( self::OPTION_ADMIN_NOTICE );
		$class = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	public function run_sync() {
		$result = $this->sync_posts_to_cache( '1' );
		update_option( self::OPTION_LAST_SYNC, $result );
	}

	/**
	 * Synchronisiert alle Posts aus sbr_reviews_posts in den Cache für den angegebenen Feed.
	 *
	 * @param string $feed_id Feed-ID (Standard: '1').
	 * @return array{success: bool, count?: int, message?: string}
	 */
	public function sync_posts_to_cache( $feed_id = '1' ) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$posts_table = $prefix . 'sbr_reviews_posts';
		$cache_table = $prefix . 'sbr_feed_caches';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $posts_table ) ) !== $posts_table ) {
			return [ 'success' => false, 'message' => __( 'Tabelle sbr_reviews_posts existiert nicht.', 'fix-smashballoon-reviews' ) ];
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache_table ) ) !== $cache_table ) {
			return [ 'success' => false, 'message' => __( 'Tabelle sbr_feed_caches existiert nicht.', 'fix-smashballoon-reviews' ) ];
		}

		$rows = $wpdb->get_results(
			"SELECT post_id, json_data, avatar_id, provider_id FROM `{$posts_table}` ORDER BY id ASC",
			ARRAY_A
		);

		$cache_posts = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$post = $this->transform_row_to_cache_post( $row );
				if ( $post !== null ) {
					$cache_posts[] = $post;
				}
			}
			usort( $cache_posts, function ( $a, $b ) {
				return ( $b['time'] ?? 0 ) - ( $a['time'] ?? 0 );
			} );
		}
		$cache_json = wp_json_encode( $cache_posts );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, last_updated FROM {$cache_table} WHERE feed_id = %s AND cache_key = 'posts'",
				$feed_id
			),
			ARRAY_A
		);

		$now = current_time( 'mysql' );
		if ( $existing ) {
			$updated = $wpdb->update(
				$cache_table,
				[
					'cache_value'  => $cache_json,
					'last_updated' => $now,
					'cron_update'  => 'yes',
				],
				[ 'id' => $existing['id'] ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$updated = $wpdb->insert(
				$cache_table,
				[
					'feed_id'      => $feed_id,
					'cache_key'    => 'posts',
					'cache_value'  => $cache_json,
					'cron_update'  => 'yes',
					'last_updated' => $now,
				],
				[ '%s', '%s', '%s', '%s', '%s' ]
			);
		}

		if ( $updated === false ) {
			return [ 'success' => false, 'message' => $wpdb->last_error ?: __( 'Datenbankfehler beim Schreiben.', 'fix-smashballoon-reviews' ) ];
		}

		$count = count( $cache_posts );
		return [ 'success' => true, 'count' => $count ];
	}

	/**
	 * Transformiert eine Zeile aus sbr_reviews_posts ins Cache-Format.
	 *
	 * @param array $row Zeile mit post_id, json_data, avatar_id, provider_id.
	 * @return array|null Cache-Post-Objekt oder null bei Fehler.
	 */
	private function transform_row_to_cache_post( $row ) {
		$json_data = $row['json_data'] ?? '';
		$data = json_decode( $json_data, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$review_id = $row['post_id'] ?? ( $data['review_id'] ?? '' );
		$text = $data['text'] ?? '';
		$rating = isset( $data['rating'] ) ? (int) $data['rating'] : 5;
		$time = isset( $data['time'] ) ? (int) $data['time'] : 0;

		$reviewer = $data['reviewer'] ?? [];
		$reviewer = is_array( $reviewer ) ? $reviewer : [];
		$avatar_id = trim( $row['avatar_id'] ?? '' );
		if ( $avatar_id !== '' ) {
			$reviewer['avatar_local'] = content_url( 'uploads/sbr-feed-images/' . $avatar_id . '.png' );
		}

		$source = $data['source'] ?? [];
		$source = is_array( $source ) ? $source : [];
		$source['url'] = '';
		if ( empty( $source['id'] ) && ! empty( $row['provider_id'] ) ) {
			$source['id'] = $row['provider_id'];
		}

		$provider = $data['provider'] ?? [];
		$provider_name = is_array( $provider ) && isset( $provider['name'] ) ? $provider['name'] : 'google';

		return [
			'review_id' => $review_id,
			'text'      => $text,
			'rating'    => $rating,
			'time'      => $time,
			'reviewer'  => $reviewer,
			'provider'  => [ 'name' => $provider_name ],
			'source'    => $source,
			'media'     => [],
		];
	}
}
