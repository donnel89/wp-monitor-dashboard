<?php
/**
 * Removes the plugin's tables and options when it is deleted from the Plugins screen.
 * Orders keep their attribution meta, so past commission stays auditable.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aisr_conversations" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aisr_events" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'aisr\\_%' OR option_name LIKE '\\_transient\\_aisr\\_%' OR option_name LIKE '\\_transient\\_timeout\\_aisr\\_%'" ); // phpcs:ignore WordPress.DB
wp_clear_scheduled_hook( 'aisr_hourly' );
