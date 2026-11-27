<?php
/**
 * Sales features that live outside a single chat turn: gift messages on the order, back-in-stock waitlist,
 * holiday reminders, real sales counts for social proof, a daily task email for the store, and a weekly report.
 * Nothing here contacts a shopper by itself: the store gets ready wa.me / mailto links and sends them.
 */

defined( 'ABSPATH' ) || exit;

class AISR_Growth {

	public static function init(): void {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'gift_message_to_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'gift_message_to_order' ), 20, 1 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'gift_message_admin' ), 10, 1 );
		add_filter( 'woocommerce_email_order_meta_fields', array( __CLASS__, 'gift_message_email' ), 10, 3 );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'stock_changed' ), 10, 2 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'variation_stock_changed' ), 10, 2 );
		add_action( 'admin_post_aisr_weekly_report_now', array( __CLASS__, 'report_now' ) );
		add_action( 'admin_post_aisr_growth_remove', array( __CLASS__, 'remove_entry' ) );
		add_action( 'admin_post_aisr_reset_limits', array( __CLASS__, 'reset_limits' ) );
	}

	/* ---------- gift message ---------- */

	/**
	 * Saves the greeting in the shopper's WooCommerce session and with the conversation, so it reaches the order
	 * even when the session did not exist yet (a new visitor whose cart was still empty).
	 */
	public static function set_gift_message( string $text, string $uuid = '' ): string {
		$msg = mb_substr( trim( sanitize_textarea_field( $text ) ), 0, 400 );
		if ( '' === $msg ) {
			return '';
		}
		if ( $uuid ) {
			set_transient( 'aisr_gift_' . md5( $uuid ), $msg, 30 * DAY_IN_SECONDS );
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			if ( ! WC()->session->has_session() && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
				WC()->session->set_customer_session_cookie( true );
			}
			WC()->session->set( 'aisr_gift_message', $msg );
		}
		return $msg;
	}

	/** The greeting waiting for this shopper's order: from the session, or from their chat conversation. */
	public static function pending_gift_message( string $uuid = '' ): string {
		$msg = function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get( 'aisr_gift_message', '' ) : '';
		if ( '' === $msg && '' === $uuid && ! empty( $_COOKIE['aisr_conv'] ) ) {
			$uuid = explode( '.', sanitize_text_field( wp_unslash( $_COOKIE['aisr_conv'] ) ) )[0];
		}
		if ( '' === $msg && $uuid ) {
			$msg = (string) get_transient( 'aisr_gift_' . md5( $uuid ) );
		}
		return $msg;
	}

	public static function gift_message_admin( $order ): void {
		$msg = $order instanceof WC_Order ? (string) $order->get_meta( '_aisr_gift_message' ) : '';
		if ( '' !== $msg ) {
			echo '<p><strong>ברכה למתנה (מהצ\'אט):</strong><br>' . nl2br( esc_html( $msg ) ) . '</p>';
		}
	}

	public static function gift_message_email( $fields, $sent_to_admin, $order ) {
		$msg = $order instanceof WC_Order ? (string) $order->get_meta( '_aisr_gift_message' ) : '';
		if ( '' !== $msg ) {
			$fields['aisr_gift'] = array( 'label' => 'ברכה למתנה', 'value' => $msg );
		}
		return $fields;
	}

	/** Adds the greeting written in the chat to the order's customer note, so the studio sees it with the order. */
	public static function gift_message_to_order( $order ): void {
		if ( ! $order instanceof WC_Order || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$msg = self::pending_gift_message();
		if ( '' === $msg || $order->get_meta( '_aisr_gift_message' ) ) {
			return;
		}
		$note = trim( $order->get_customer_note() );
		if ( false === mb_strpos( $note, $msg ) ) {
			$order->set_customer_note( trim( $note . "\n\nברכה למתנה (נכתבה בצ'אט): " . $msg ) );
		}
		$order->update_meta_data( '_aisr_gift_message', $msg );
		if ( $order->get_id() ) { // On the classic checkout hook the order is not saved yet; the customer note carries it.
			$order->add_order_note( "ברכה למתנה מהצ'אט: " . $msg );
		}
		WC()->session->set( 'aisr_gift_message', '' );
		if ( ! empty( $_COOKIE['aisr_conv'] ) ) {
			delete_transient( 'aisr_gift_' . md5( explode( '.', sanitize_text_field( wp_unslash( $_COOKIE['aisr_conv'] ) ) )[0] ) );
		}
	}

	/* ---------- back-in-stock waitlist ---------- */

	public static function join_waitlist( int $product_id, string $name, string $phone ): bool {
		$p = wc_get_product( $product_id );
		if ( ! $p || '' === preg_replace( '/\D/', '', $phone ) ) {
			return false;
		}
		$parent = $p->get_parent_id() ?: $product_id;
		$list   = (array) get_option( 'aisr_waitlist', array() );
		$list[ $parent ][] = array( 'name' => sanitize_text_field( $name ), 'phone' => sanitize_text_field( $phone ), 't' => time() );
		$list[ $parent ]   = array_slice( $list[ $parent ], -50 );
		update_option( 'aisr_waitlist', $list, false );
		return true;
	}

	public static function stock_changed( $product_id, $status ): void {
		if ( 'instock' !== $status ) {
			return;
		}
		$list = (array) get_option( 'aisr_waitlist', array() );
		$pid  = (int) $product_id;
		if ( empty( $list[ $pid ] ) ) {
			return;
		}
		$ready         = (array) get_option( 'aisr_waitlist_ready', array() );
		$ready[ $pid ] = array_merge( $ready[ $pid ] ?? array(), $list[ $pid ] );
		unset( $list[ $pid ] );
		update_option( 'aisr_waitlist', $list, false );
		update_option( 'aisr_waitlist_ready', $ready, false );
	}

	public static function variation_stock_changed( $variation_id, $status ): void {
		$v = wc_get_product( $variation_id );
		if ( $v ) {
			self::stock_changed( $v->get_parent_id(), $status );
		}
	}

	/* ---------- reminders before an occasion ---------- */

	public static function add_reminder( string $date, string $occasion, string $name, string $phone, string $email, array $product_ids ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ( '' === preg_replace( '/\D/', '', $phone ) && ! is_email( $email ) ) ) {
			return false;
		}
		$list   = (array) get_option( 'aisr_reminders', array() );
		$list[] = array(
			'date'     => $date,
			'occasion' => sanitize_text_field( $occasion ),
			'name'     => sanitize_text_field( $name ),
			'phone'    => sanitize_text_field( $phone ),
			'email'    => sanitize_email( $email ),
			'products' => array_slice( array_map( 'absint', $product_ids ), 0, 3 ),
		);
		update_option( 'aisr_reminders', array_slice( $list, -300 ), false );
		return true;
	}

	/* ---------- real sales for social proof ---------- */

	/** Units sold per product (parent id) in paid orders of the last 30 days. Cached for 12 hours. */
	public static function sales_30d(): array {
		$cached = get_transient( 'aisr_sales_30d' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out    = array();
		$orders = wc_get_orders(
			array(
				'limit'        => 500,
				'status'       => array( 'wc-processing', 'wc-completed' ),
				'date_created' => '>=' . ( time() - 30 * DAY_IN_SECONDS ),
			)
		);
		foreach ( $orders as $o ) {
			foreach ( $o->get_items() as $item ) {
				$pid         = (int) $item->get_product_id();
				$out[ $pid ] = ( $out[ $pid ] ?? 0 ) + (int) $item->get_quantity();
			}
		}
		set_transient( 'aisr_sales_30d', $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/* ---------- daily email with ready-to-send links ---------- */

	public static function wa_link( string $phone, string $text ): string {
		$d = preg_replace( '/\D/', '', $phone );
		if ( str_starts_with( $d, '0' ) ) {
			$d = '972' . substr( $d, 1 );
		}
		return 'https://wa.me/' . $d . '?text=' . rawurlencode( $text );
	}

	public static function daily_tasks(): void {
		$to = AISR_Settings::get( 'handoff_email' );
		if ( ! $to || get_transient( 'aisr_daily_tasks_sent' ) ) {
			return;
		}
		$store = AISR_Settings::get( 'business_name' );
		$lines = array();

		$ready = (array) get_option( 'aisr_waitlist_ready', array() );
		foreach ( $ready as $pid => $people ) {
			$p = wc_get_product( $pid );
			if ( ! $p ) {
				continue;
			}
			foreach ( $people as $w ) {
				$msg     = ( $w['name'] ? 'היי ' . $w['name'] . ', ' : 'היי, ' ) . 'כאן ' . $store . '. ' . $p->get_name() . ' חזר למלאי: ' . get_permalink( $pid );
				$lines[] = 'חזר למלאי · ' . $p->get_name() . ' · ' . ( $w['name'] ?: 'ללא שם' ) . "\n" . self::wa_link( $w['phone'], $msg );
			}
		}

		$today = current_datetime()->format( 'Y-m-d' );
		$keep  = array();
		foreach ( (array) get_option( 'aisr_reminders', array() ) as $r ) {
			if ( $r['date'] > $today ) {
				$keep[] = $r;
				continue;
			}
			$names = array();
			foreach ( $r['products'] as $pid ) {
				$p = wc_get_product( $pid );
				if ( $p ) {
					$names[] = $p->get_name() . ' ' . get_permalink( $pid );
				}
			}
			$msg     = ( $r['name'] ? 'היי ' . $r['name'] . ', ' : 'היי, ' ) . 'כאן ' . $store . '. ביקשתם תזכורת לקראת ' . $r['occasion'] . ( $names ? '. מה שראיתם אצלנו: ' . implode( ', ', $names ) : '' );
			$link    = $r['phone'] ? self::wa_link( $r['phone'], $msg ) : 'mailto:' . $r['email'] . '?subject=' . rawurlencode( 'תזכורת מ' . $store ) . '&body=' . rawurlencode( $msg );
			$lines[] = 'תזכורת ל' . $r['occasion'] . ' · ' . ( $r['name'] ?: 'ללא שם' ) . "\n" . $link;
		}

		$fu = AISR_Followup::email_lines();
		if ( $fu ) {
			$lines[] = "וואטסאפ לשלוח היום (לחיצה מסמנת שנשלח ופותחת את ההודעה):";
			$lines   = array_merge( $lines, $fu );
		}
		if ( ! $lines ) {
			return;
		}
		update_option( 'aisr_waitlist_ready', array(), false );
		update_option( 'aisr_reminders', $keep, false );
		set_transient( 'aisr_daily_tasks_sent', 1, 20 * HOUR_IN_SECONDS );
		wp_mail( $to, 'הודעות לשליחה מהבוט (' . count( $lines ) . ')', "גולשים שביקשו עדכון. לחיצה על קישור פותחת הודעה מוכנה:\n\n" . implode( "\n\n", $lines ) );
	}

	/* ---------- weekly report ---------- */

	public static function maybe_weekly_report(): void {
		$last = (int) get_option( 'aisr_weekly_report_at', 0 );
		if ( ! $last ) {
			update_option( 'aisr_weekly_report_at', time(), false ); // First report a week after installing, not now.
			return;
		}
		if ( time() - $last >= 7 * DAY_IN_SECONDS ) {
			self::weekly_report( true );
		}
	}

	/** Summarizes the week's chats with the fast model: why people did not buy and what to fix. */
	public static function weekly_report( bool $email ): string {
		global $wpdb;
		update_option( 'aisr_weekly_report_at', time(), false );
		$table = AISR_DB::conversations();
		$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT transcript, insights, unanswered, trigger_key FROM $table WHERE started_at >= %s AND user_turns > 0 ORDER BY started_at DESC LIMIT 60", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			update_option( 'aisr_report_error', 'no chats with shopper messages in the last 7 days', false );
			return '';
		}
		delete_option( 'aisr_report_error' );
		$chats = array();
		foreach ( $rows as $r ) {
			$t = json_decode( (string) $r['transcript'], true ) ?: array();
			$u = array();
			foreach ( $t as $m ) {
				if ( 'u' === ( $m['r'] ?? '' ) ) {
					$u[] = $m['t'];
				}
			}
			$chats[] = '- (' . $r['trigger_key'] . ') ' . mb_substr( implode( ' | ', $u ), 0, 400 ) . ( $r['unanswered'] && '[]' !== $r['unanswered'] ? ' [no answer: ' . mb_substr( $r['unanswered'], 0, 200 ) . ']' : '' );
		}
		$searches = array_slice( array_column( (array) get_option( 'aisr_failed_searches', array() ), 'q' ), 0, 15 );
		$prompt   = "These are shopper messages from this week's chats on an online store (one line per chat, trigger in brackets). Write a short report in Hebrew for the store owner, plain text, no markdown:\n"
			. "1) The 5 main reasons shoppers hesitated or did not buy, each with how many chats showed it.\n2) Questions the store did not answer, and the exact text to add to the site or product pages.\n3) Three concrete changes for next week.\nOnly use what is in the data. Do not invent numbers.\n"
			. "4) Then a line with only ---, and after it up to 6 lines for the chat assistant's knowledge base, one per line, in Hebrew, in the form: shopper question || draft answer. Pick questions that shoppers asked and the store did not answer. Write a draft answer only if the data or a shopper message clearly gives it; otherwise leave the part after || empty. The owner checks every line before it is used.\n\nChats:\n" . implode( "\n", $chats )
			. ( $searches ? "\n\nSite searches that found nothing: " . implode( ', ', $searches ) : '' );
		$text = AISR_Claude::summarize( $prompt );
		if ( '' === $text ) {
			return '';
		}
		[ $text, $sugg ] = AISR_Learning::split_report( $text );
		update_option( 'aisr_weekly_report', array( 't' => time(), 'text' => $text, 'chats' => count( $rows ), 'suggestions' => $sugg ), false );
		$to = AISR_Settings::get( 'handoff_email' );
		if ( $email && $to ) {
			wp_mail( $to, 'דוח שבועי מנציג ה-AI: למה לא קנו ומה לתקן', $text . ( $sugg ? "\n\n" . count( $sugg ) . ' הצעות לתשובות מחכות לאישור בתור הלמידה.' : '' ) . "\n\n" . admin_url( 'admin.php?page=aisr-dashboard' ) . '#aisr-learn' );
		}
		return $text;
	}

	/** Dashboard: delete one waitlist or reminder entry (a test, or someone who asked to be removed). */
	public static function remove_entry(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'aisr_growth_remove' );
		$kind = sanitize_key( (string) ( $_POST['kind'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$key  = sanitize_text_field( wp_unslash( (string) ( $_POST['key'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'reminder' === $kind ) {
			$list = (array) get_option( 'aisr_reminders', array() );
			unset( $list[ (int) $key ] );
			update_option( 'aisr_reminders', array_values( $list ), false );
		} elseif ( in_array( $kind, array( 'waitlist', 'waitlist_ready' ), true ) ) {
			[ $pid, $i ] = array_map( 'intval', explode( ':', $key . ':' ) );
			$opt         = 'waitlist' === $kind ? 'aisr_waitlist' : 'aisr_waitlist_ready';
			$list        = (array) get_option( $opt, array() );
			if ( isset( $list[ $pid ][ $i ] ) ) {
				unset( $list[ $pid ][ $i ] );
				$list[ $pid ] = array_values( $list[ $pid ] );
				if ( ! $list[ $pid ] ) {
					unset( $list[ $pid ] );
				}
				update_option( $opt, $list, false );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=aisr-dashboard' ) . '#aisr-lists' );
		exit;
	}

	/** Dashboard: reset the per-visitor message limits for everyone (e.g. during a testing period). */
	public static function reset_limits(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'aisr_reset_limits' );
		update_option( 'aisr_rl_epoch', (int) get_option( 'aisr_rl_epoch', 0 ) + 1, false );
		update_option( 'aisr_rl_reset_at', time(), false );
		wp_safe_redirect( admin_url( 'admin.php?page=aisr-dashboard' ) . '#aisr-limits' );
		exit;
	}

	public static function report_now(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'aisr_weekly_report_now' );
		self::weekly_report( false );
		wp_safe_redirect( admin_url( 'admin.php?page=aisr-dashboard' ) . '#aisr-weekly' );
		exit;
	}
}
