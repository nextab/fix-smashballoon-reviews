<?php
/**
 * Plugin Name: Fix Smash Balloon Reviews Cache
 * Description: Synchronisiert die Posts aus der SBR Post-Tabelle in die Cache-Tabelle, wenn der native SBR-Cache fehlerhaft aufgebaut wird.
 * Version: 1.0.0
 * Author: nexTab
 * Text Domain: fix-smashballoon-reviews
 * Requires at least: 5.9
 * Requires PHP: 7.4
 *
 * @package FixSmashballoonReviews
 */

defined( 'ABSPATH' ) || exit;

define( 'FSBR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSBR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once FSBR_PLUGIN_DIR . 'includes/class-sbr-cache-sync.php';
require_once FSBR_PLUGIN_DIR . 'includes/class-fsbr-feed-update-trigger.php';

add_action( 'init', 'fsbr_init' );
add_filter( 'plugin_action_links_' . FSBR_PLUGIN_BASENAME, 'fsbr_plugin_action_links' );
register_activation_hook( __FILE__, 'fsbr_activate' );
register_deactivation_hook( __FILE__, 'fsbr_deactivate' );

function fsbr_plugin_action_links( $links ) {
	$url = admin_url( 'options-general.php?page=fsbr-cache-sync' );
	$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Einstellungen', 'fix-smashballoon-reviews' ) . '</a>';
	return $links;
}

function fsbr_init() {
	$sync = new SBR_Cache_Sync();
	$sync->register_hooks();
}

function fsbr_activate() {
	if ( ! wp_next_scheduled( SBR_Cache_Sync::CRON_HOOK ) ) {
		wp_schedule_event( time() + 300, 'daily', SBR_Cache_Sync::CRON_HOOK );
	}
}

function fsbr_deactivate() {
	wp_clear_scheduled_hook( SBR_Cache_Sync::CRON_HOOK );
}
