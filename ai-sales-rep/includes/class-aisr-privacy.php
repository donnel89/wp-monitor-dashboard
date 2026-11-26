<?php
/**
 * Privacy helpers: masking, WP privacy exporter/eraser for leads.
 */

defined( 'ABSPATH' ) || exit;

class AISR_Privacy {

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_text' ) );
	}

	/** Mask emails and phone numbers before a transcript is stored for analytics. */
	public static function mask( string $text ): string {
		$text = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[מייל]', $text );
		$text = preg_replace( '/(?:\+?972|0)[\s\-]?(?:\d[\s\-]?){8,9}/', '[טלפון]', $text );
		return (string) $text;
	}

	/** Stable, non-reversible visitor id (no raw IP is stored). */
	public static function visitor_hash( string $client_id ): string {
		return substr( hash( 'sha256', $client_id . wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters['ai-sales-rep'] = array(
			'exporter_friendly_name' => 'AI Sales Rep',
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers['ai-sales-rep'] = array(
			'eraser_friendly_name' => 'AI Sales Rep',
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . AISR_DB::conversations() . ' WHERE lead_email = %s', $email ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'group_id'    => 'aisr',
				'group_label' => 'שיחות עם נציג ה-AI',
				'item_id'     => 'aisr-' . $r['uuid'],
				'data'        => array(
					array( 'name' => 'תאריך', 'value' => $r['started_at'] ),
					array( 'name' => 'שם', 'value' => $r['lead_name'] ),
					array( 'name' => 'טלפון', 'value' => $r['lead_phone'] ),
					array( 'name' => 'תמליל', 'value' => $r['transcript'] ),
				),
			);
		}
		return array( 'data' => $items, 'done' => true );
	}

	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$n = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . AISR_DB::conversations() . ' WHERE lead_email = %s', $email ) ); // phpcs:ignore WordPress.DB
		return array(
			'items_removed'  => (bool) $n,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	public static function privacy_policy_text(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'AI Sales Rep',
				'<p>באתר פועל עוזר דיגיטלי מבוסס בינה מלאכותית. הודעות שנכתבות בצ\'אט נשלחות לעיבוד אצל Anthropic לצורך מתן תשובה. פרטי קשר נשמרים רק אם הגולש מסר אותם ואישר. השיחות נמחקות אוטומטית בתום תקופת השמירה שהוגדרה.</p>'
			);
		}
	}
}
