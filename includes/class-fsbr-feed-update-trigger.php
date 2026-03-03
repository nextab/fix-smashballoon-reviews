<?php
/**
 * Triggert manuell den Abruf neuer Reviews von Google (und anderen Quellen) über die SBR-API.
 *
 * @package FixSmashballoonReviews
 */

defined( 'ABSPATH' ) || exit;

class FSBR_Feed_Update_Trigger {

	const OPTION_LAST_FETCH = 'fsbr_last_fetch_result';
	const OPTION_ADMIN_NOTICE = 'fsbr_fetch_admin_notice';

	/**
	 * Prüft, ob das Smash Balloon Reviews Plugin geladen ist.
	 *
	 * @return bool
	 */
	public static function is_sbr_available() {
		return class_exists( 'SmashBalloon\Reviews\Common\Util' )
			&& class_exists( 'SmashBalloon\Reviews\Common\FeedCache' )
			&& ( class_exists( 'SmashBalloon\Reviews\Common\Feed' ) || class_exists( 'SmashBalloon\Reviews\Pro\Feed' ) );
	}

	/**
	 * Holt alle Feed-IDs aus der Cache-Tabelle, die per Cron aktualisiert werden sollen.
	 *
	 * @return array<int|string>
	 */
	public static function get_feed_ids_to_update() {
		global $wpdb;
		$table = $wpdb->prefix . 'sbr_feed_caches';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}

		$results = $wpdb->get_col(
			"SELECT DISTINCT feed_id FROM {$table} WHERE cron_update = 'yes' AND cache_key = 'posts'"
		);

		return is_array( $results ) ? array_map( 'strval', $results ) : [];
	}

	/**
	 * Führt den manuellen Abruf neuer Reviews für alle Feeds durch.
	 *
	 * @return array{success: bool, count?: int, message?: string, errors?: array}
	 */
	public static function run_manual_fetch() {
		if ( ! self::is_sbr_available() ) {
			return [
				'success' => false,
				'message' => __( 'Smash Balloon Reviews Plugin ist nicht aktiv oder nicht geladen.', 'fix-smashballoon-reviews' ),
			];
		}

		$feed_ids = self::get_feed_ids_to_update();
		if ( empty( $feed_ids ) ) {
			return [
				'success' => true,
				'count'   => 0,
				'message' => __( 'Keine Feeds mit Cron-Update gefunden.', 'fix-smashballoon-reviews' ),
			];
		}

		$updated = 0;
		$errors  = [];

		foreach ( $feed_ids as $feed_id ) {
			$result = self::update_single_feed( $feed_id );
			if ( $result['success'] ) {
				++$updated;
			} elseif ( ! empty( $result['message'] ) ) {
				$errors[] = sprintf( 'Feed %s: %s', $feed_id, $result['message'] );
			}
		}

		$statuses = get_option( 'sbr_statuses', [] );
		$statuses['last_cron_update'] = time();
		update_option( 'sbr_statuses', $statuses );

		return [
			'success' => true,
			'count'   => $updated,
			'errors'  => $errors,
		];
	}

	/**
	 * Aktualisiert einen einzelnen Feed (holt neue Reviews von der API).
	 *
	 * @param string $feed_id Feed-ID.
	 * @return array{success: bool, message?: string}
	 */
	private static function update_single_feed( $feed_id ) {
		$settings = self::get_feed_settings( $feed_id );
		if ( empty( $settings ) || ( empty( $settings['sources'] ) && empty( $settings['singleManualReview'] ) ) ) {
			return [ 'success' => false, 'message' => __( 'Keine Feed-Einstellungen oder Quellen.', 'fix-smashballoon-reviews' ) ];
		}

		$feed_cache = new \SmashBalloon\Reviews\Common\FeedCache( $feed_id, 0 );
		$feed       = \SmashBalloon\Reviews\Common\Util::sbr_is_pro()
			? new \SmashBalloon\Reviews\Pro\Feed( $settings, $feed_id, $feed_cache )
			: new \SmashBalloon\Reviews\Common\Feed( $settings, $feed_id, $feed_cache );

		$feed->init();
		$feed->get_set_cache();

		$feed_errors = $feed->get_errors();
		if ( ! empty( $feed_errors ) ) {
			$msg = is_array( $feed_errors[0] ) ? ( $feed_errors[0]['message'] ?? '' ) : (string) $feed_errors[0];
			return [ 'success' => false, 'message' => $msg ];
		}

		return [ 'success' => true ];
	}

	/**
	 * Lädt die Feed-Einstellungen für eine Feed-ID.
	 *
	 * @param string $feed_id Feed-ID.
	 * @return array|false
	 */
	private static function get_feed_settings( $feed_id ) {
		if ( class_exists( 'SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver' ) ) {
			$saver = new \SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver( $feed_id );
			return $saver->get_feed_settings();
		}
		if ( class_exists( 'SmashBalloon\Reviews\Common\SBR_Settings' ) ) {
			return \SmashBalloon\Reviews\Common\SBR_Settings::get_settings_by_feed_id( $feed_id, false, false );
		}
		return false;
	}
}
