<?php
/**
 * Custom tables: conversations (analytics, masked transcript) and events (triggers, visits).
 */

defined( 'ABSPATH' ) || exit;

class AISR_DB {

	const SCHEMA_VERSION = '2';

	public static function conversations(): string {
		global $wpdb;
		return $wpdb->prefix . 'aisr_conversations';
	}

	public static function events(): string {
		global $wpdb;
		return $wpdb->prefix . 'aisr_events';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$c       = self::conversations();
		$e       = self::events();

		dbDelta(
			"CREATE TABLE $c (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				visitor char(32) NOT NULL DEFAULT '',
				grp varchar(10) NOT NULL DEFAULT 'test',
				started_at datetime NOT NULL,
				last_at datetime NOT NULL,
				engaged tinyint(1) NOT NULL DEFAULT 0,
				trigger_key varchar(32) NOT NULL DEFAULT '',
				page_type varchar(20) NOT NULL DEFAULT '',
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_turns int(11) NOT NULL DEFAULT 0,
				transcript longtext NULL,
				insights longtext NULL,
				unanswered longtext NULL,
				model_used varchar(40) NOT NULL DEFAULT '',
				input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
				output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
				cost_usd decimal(12,6) NOT NULL DEFAULT 0,
				coupon_code varchar(40) NOT NULL DEFAULT '',
				handoff tinyint(1) NOT NULL DEFAULT 0,
				lead_name varchar(120) NOT NULL DEFAULT '',
				lead_phone varchar(40) NOT NULL DEFAULT '',
				lead_email varchar(190) NOT NULL DEFAULT '',
				lead_marketing tinyint(1) NOT NULL DEFAULT 0,
				lead_cart longtext NULL,
				reminder_sent tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY started_at (started_at),
				KEY lead_email (lead_email)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $e (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				visitor char(32) NOT NULL DEFAULT '',
				conv_uuid char(36) NOT NULL DEFAULT '',
				type varchar(20) NOT NULL,
				trigger_key varchar(32) NOT NULL DEFAULT '',
				page_type varchar(20) NOT NULL DEFAULT '',
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				grp varchar(10) NOT NULL DEFAULT 'test',
				PRIMARY KEY  (id),
				KEY created_type (created_at,type),
				KEY conv_uuid (conv_uuid)
			) $charset;"
		);

		dbDelta( AISR_Followup::schema( $charset ) ); // Phone leads and WhatsApp follow-ups.

		update_option( 'aisr_schema', self::SCHEMA_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'aisr_schema' ) !== self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	public static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function get_conversation( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::conversations() . ' WHERE uuid = %s', $uuid ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function create_conversation( array $data ): array {
		global $wpdb;
		$now  = self::now();
		$row  = array_merge(
			array(
				'uuid'       => wp_generate_uuid4(),
				'visitor'    => '',
				'grp'        => 'test',
				'started_at' => $now,
				'last_at'    => $now,
				'transcript' => '[]',
				'insights'   => '{}',
				'unanswered' => '[]',
			),
			$data
		);
		$wpdb->insert( self::conversations(), $row ); // phpcs:ignore WordPress.DB
		return self::get_conversation( $row['uuid'] );
	}

	public static function update_conversation( string $uuid, array $data ): void {
		global $wpdb;
		$data['last_at'] = self::now();
		$wpdb->update( self::conversations(), $data, array( 'uuid' => $uuid ) ); // phpcs:ignore WordPress.DB
	}

	public static function add_event( array $data ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			self::events(),
			array_merge(
				array(
					'created_at'  => self::now(),
					'visitor'     => '',
					'conv_uuid'   => '',
					'trigger_key' => '',
					'page_type'   => '',
					'product_id'  => 0,
					'grp'         => 'test',
				),
				$data
			)
		);
	}

	/** Delete conversations and events past the retention window. */
	public static function purge_old(): void {
		global $wpdb;
		$days   = (int) AISR_Settings::get( 'retention_days' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::conversations() . ' WHERE last_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::events() . ' WHERE created_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB
	}
}
