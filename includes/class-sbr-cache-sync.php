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
		add_action( 'admin_init', [ $this, 'handle_manual_fetch' ] );
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
		$last_fetch  = get_option( FSBR_Feed_Update_Trigger::OPTION_LAST_FETCH, null );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SBR Cache Sync', 'fix-smashballoon-reviews' ); ?></h1>
			<p><?php esc_html_e( 'Synchronisiert die Reviews aus der Post-Tabelle in die Cache-Tabelle des Smash Balloon Reviews Feed Plugins. Der Stern-Filter (z. B. nur 4–5 Sterne) aus den Feed-Einstellungen wird berücksichtigt.', 'fix-smashballoon-reviews' ); ?></p>
			<?php if ( $last_result !== null ) : ?>
				<p>
					<?php
					if ( $last_result['success'] ) {
						printf(
							/* translators: %d: number of posts synced */
							esc_html__( 'Letzter Sync: %d Posts übertragen.', 'fix-smashballoon-reviews' ),
							(int) ( $last_result['count'] ?? 0 )
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
			<form method="post" action="" style="display: inline-block; margin-right: 10px;">
				<?php wp_nonce_field( 'fsbr_manual_sync', 'fsbr_nonce' ); ?>
				<p>
					<button type="submit" name="fsbr_sync" class="button button-primary">
						<?php esc_html_e( 'Cache synchronisieren', 'fix-smashballoon-reviews' ); ?>
					</button>
				</p>
			</form>
			<?php if ( FSBR_Feed_Update_Trigger::is_sbr_available() ) : ?>
				<form method="post" action="" style="display: inline-block;">
					<?php wp_nonce_field( 'fsbr_manual_fetch', 'fsbr_fetch_nonce' ); ?>
					<p>
						<button type="submit" name="fsbr_fetch" class="button button-secondary">
							<?php esc_html_e( 'Reviews von Google abrufen', 'fix-smashballoon-reviews' ); ?>
						</button>
					</p>
				</form>
				<?php if ( $last_fetch !== null ) : ?>
					<p>
						<?php
						if ( $last_fetch['success'] ) {
							printf(
								/* translators: %d: number of feeds updated */
								esc_html__( 'Letzter Abruf: %d Feed(s) aktualisiert.', 'fix-smashballoon-reviews' ),
								(int) ( $last_fetch['count'] ?? 0 )
							);
							if ( ! empty( $last_fetch['errors'] ) ) {
								echo ' ' . esc_html__( 'Teilweise Fehler:', 'fix-smashballoon-reviews' ) . ' ' . esc_html( implode( '; ', $last_fetch['errors'] ) );
							}
						} else {
							printf(
								/* translators: %s: error message */
								esc_html__( 'Letzter Abruf fehlgeschlagen: %s', 'fix-smashballoon-reviews' ),
								esc_html( $last_fetch['message'] ?? 'Unbekannter Fehler' )
							);
						}
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
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

	public function handle_manual_fetch() {
		if ( ! isset( $_POST['fsbr_fetch'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( $_POST['fsbr_fetch_nonce'] ?? '' ), 'fsbr_manual_fetch' ) ) {
			update_option( self::OPTION_ADMIN_NOTICE, [ 'type' => 'error', 'message' => __( 'Sicherheitsprüfung fehlgeschlagen.', 'fix-smashballoon-reviews' ) ] );
			wp_safe_redirect( admin_url( 'options-general.php?page=fsbr-cache-sync' ) );
			exit;
		}
		$result = FSBR_Feed_Update_Trigger::run_manual_fetch();
		update_option( FSBR_Feed_Update_Trigger::OPTION_LAST_FETCH, $result );
		$type = $result['success'] ? 'success' : 'error';
		$message = $result['success']
			? sprintf( __( '%d Feed(s) aktualisiert – neue Reviews von Google abgerufen.', 'fix-smashballoon-reviews' ), $result['count'] ?? 0 )
			: ( $result['message'] ?? __( 'Abruf fehlgeschlagen.', 'fix-smashballoon-reviews' ) );
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

		$star_filters = $this->get_feed_star_filters( $feed_id );
		$cache_posts  = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$post = $this->transform_row_to_cache_post( $row );
				if ( $post === null ) {
					continue;
				}
				if ( ! $this->passes_star_filter( $post, $star_filters ) ) {
					continue;
				}
				$cache_posts[] = $post;
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
	 * Holt die erlaubten Stern-Bewertungen aus den Feed-Einstellungen des SBR-Plugins.
	 *
	 * @param string $feed_id Feed-ID.
	 * @return array<int> Leeres Array = alle Bewertungen erlauben; sonst z.B. [4, 5] für 4–5 Sterne.
	 */
	private function get_feed_star_filters( $feed_id ) {
		$settings = $this->get_feed_settings( $feed_id );
		if ( empty( $settings['includedStarFilters'] ) || ! is_array( $settings['includedStarFilters'] ) ) {
			return [];
		}
		return array_map( 'intval', $settings['includedStarFilters'] );
	}

	/**
	 * Prüft, ob ein Post den Stern-Filter erfüllt.
	 *
	 * @param array $post Cache-Post mit rating und provider.
	 * @param array<int> $star_filters Erlaubte Bewertungen (leer = alle erlauben).
	 * @return bool
	 */
	private function passes_star_filter( $post, $star_filters ) {
		if ( empty( $star_filters ) ) {
			return true;
		}
		$rating = $post['rating'] ?? 5;
		if ( ! empty( $post['provider']['name'] ) && $post['provider']['name'] === 'facebook' ) {
			if ( in_array( $rating, [ 'positive', 'negative' ], true ) ) {
				$rating = $rating === 'positive' ? 5 : 1;
			}
		}
		$rating = (int) $rating;
		return in_array( $rating, $star_filters, true );
	}

	/**
	 * Lädt die Feed-Einstellungen für eine Feed-ID.
	 *
	 * @param string $feed_id Feed-ID.
	 * @return array<string, mixed>|false
	 */
	private function get_feed_settings( $feed_id ) {
		if ( class_exists( 'SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver' ) ) {
			$saver = new \SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver( $feed_id );
			return $saver->get_feed_settings();
		}
		if ( class_exists( 'SmashBalloon\Reviews\Common\SBR_Settings' ) ) {
			return \SmashBalloon\Reviews\Common\SBR_Settings::get_settings_by_feed_id( $feed_id, false, false );
		}
		return false;
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
