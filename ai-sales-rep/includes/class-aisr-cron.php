<?php
/**
 * Hourly jobs: one abandoned-cart reminder email (only with marketing consent) and data retention.
 */

defined( 'ABSPATH' ) || exit;

class AISR_Cron {

	const HOOK = 'aisr_hourly';

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run(): void {
		AISR_DB::purge_old();
		self::cart_reminders();
		if ( 'whatsapp_digest' === AISR_Settings::get( 'reminder_channel' ) ) {
			self::whatsapp_digest();
		}
	}

	/** Leads with email + explicit marketing consent + a cart, 2-48h old, no attributed order since. */
	public static function cart_reminders(): void {
		global $wpdb;
		$table = AISR_DB::conversations();
		$from  = gmdate( 'Y-m-d H:i:s', time() - 48 * HOUR_IN_SECONDS );
		$to    = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE lead_email <> '' AND lead_marketing = 1 AND reminder_sent = 0 AND last_at BETWEEN %s AND %s LIMIT 50", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB

		foreach ( $rows as $r ) {
			AISR_DB::update_conversation( $r['uuid'], array( 'reminder_sent' => 1 ) );
			$cart = json_decode( (string) $r['lead_cart'], true ) ?: array();
			if ( ! $cart || self::has_order_since( $r['lead_email'], $r['started_at'] ) ) {
				continue;
			}
			$lines = array();
			foreach ( $cart as $item ) {
				$p = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
				if ( $p && $p->is_in_stock() ) {
					$lines[] = $p->get_name() . ': ' . get_permalink( $item['product_id'] );
				}
			}
			if ( ! $lines ) {
				continue;
			}
			$name    = AISR_Settings::get( 'business_name' );
			$hello   = $r['lead_name'] ? 'היי ' . $r['lead_name'] . ',' : 'היי,';
			$body    = $hello . "\n\nהמוצרים שבחרתם אצלנו עדיין מחכים:\n" . implode( "\n", $lines ) . "\n\nיש שאלה? פשוט עונים למייל הזה.\n" . $name;
			$headers = array();
			$reply   = AISR_Settings::get( 'handoff_email' );
			if ( $reply ) {
				$headers[] = 'Reply-To: ' . $reply;
			}
			wp_mail( $r['lead_email'], 'שכחתם משהו ב' . $name . '?', $body, $headers );
		}
	}

	/**
	 * Leads who gave a phone and explicit marketing consent, left a cart 2-48h ago and did not order:
	 * one email to the store with a ready wa.me link per lead, so the team sends the reminder from its own WhatsApp.
	 * Sent once a day, at most one reminder per lead.
	 */
	public static function whatsapp_digest(): void {
		$to = AISR_Settings::get( 'handoff_email' );
		if ( ! $to || get_transient( 'aisr_wa_digest_sent' ) ) {
			return;
		}
		global $wpdb;
		$table = AISR_DB::conversations();
		$from  = gmdate( 'Y-m-d H:i:s', time() - 48 * HOUR_IN_SECONDS );
		$until = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE lead_phone <> '' AND lead_marketing = 1 AND reminder_sent = 0 AND last_at BETWEEN %s AND %s LIMIT 30", $from, $until ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items = array();
		foreach ( $rows as $r ) {
			AISR_DB::update_conversation( $r['uuid'], array( 'reminder_sent' => 1 ) );
			$cart = json_decode( (string) $r['lead_cart'], true ) ?: array();
			if ( ! $cart || self::has_order_by_phone( $r['lead_phone'], $r['started_at'] ) ) {
				continue;
			}
			$names = array();
			foreach ( $cart as $item ) {
				$p = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
				if ( $p ) {
					$names[] = $p->get_name();
				}
			}
			if ( ! $names ) {
				continue;
			}
			$digits = preg_replace( '/\D/', '', (string) $r['lead_phone'] );
			if ( str_starts_with( $digits, '0' ) ) {
				$digits = '972' . substr( $digits, 1 );
			}
			$msg     = ( $r['lead_name'] ? 'היי ' . $r['lead_name'] . ', ' : 'היי, ' ) . 'כאן ' . AISR_Settings::get( 'business_name' ) . '. ראינו שבחרתם ' . implode( ', ', $names ) . ' ולא סיימתם את ההזמנה. נשארה שאלה? נשמח לעזור. ' . wc_get_cart_url();
			$items[] = ( $r['lead_name'] ?: 'ללא שם' ) . ' · ' . implode( ', ', $names ) . "\nלשליחה בוואטסאפ: https://wa.me/" . $digits . '?text=' . rawurlencode( $msg );
		}
		if ( ! $items ) {
			return;
		}
		set_transient( 'aisr_wa_digest_sent', 1, 20 * HOUR_IN_SECONDS );
		wp_mail( $to, 'תזכורות עגלה נטושה לשליחה בוואטסאפ (' . count( $items ) . ')', "גולשים שהשאירו טלפון, אישרו דיוור ולא סיימו הזמנה. לחיצה על כל קישור פותחת וואטסאפ עם הודעה מוכנה:\n\n" . implode( "\n\n", $items ) );
	}

	private static function has_order_by_phone( string $phone, string $since_gmt ): bool {
		$ids = wc_get_orders(
			array(
				'billing_phone' => $phone,
				'date_created'  => '>=' . strtotime( $since_gmt . ' UTC' ),
				'limit'         => 1,
				'return'        => 'ids',
			)
		);
		return (bool) $ids;
	}

	private static function has_order_since( string $email, string $since_gmt ): bool {
		$ids = wc_get_orders(
			array(
				'billing_email' => $email,
				'date_created'  => '>=' . strtotime( $since_gmt . ' UTC' ),
				'limit'         => 1,
				'return'        => 'ids',
			)
		);
		return (bool) $ids;
	}
}
