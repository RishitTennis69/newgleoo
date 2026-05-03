<?php
/**
 * Runs when the plugin is deleted (not just deactivated).
 * Drops the crawler-hits table and removes Gleo options.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$table = $wpdb->prefix . 'gleo_crawler_hits';
$wpdb->query( "DROP TABLE IF EXISTS $table" );

$options = [
	'gleo_settings',
	'gleo_last_scan',
	'gleo_changelog',
	'gleo_robots_addendum',
	'gleo_llms_txt',
	'gleo_organization_schema',
	'gleo_probe_results',
];
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Per-post meta cleanup.
$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key IN ( '_gleo_article_schema', '_gleo_faq_schema' )" );

// Unschedule any pending probe cron events.
$ts = wp_next_scheduled( 'gleo_probe_run_hook' );
while ( $ts ) {
	wp_unschedule_event( $ts, 'gleo_probe_run_hook' );
	$ts = wp_next_scheduled( 'gleo_probe_run_hook' );
}
