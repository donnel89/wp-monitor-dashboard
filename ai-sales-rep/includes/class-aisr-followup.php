<?php
/**
 * Phone leads from the chat and the WhatsApp follow-up for shoppers who did not buy.
 *
 * The bot offers a phone card only when the shopper gets something for it (their choice saved, an update when
 * an item is back or ready, a reminder, a name sketch, workshop dates). The card has an unchecked marketing
 * checkbox; the exact consent text, time and conversation are stored as proof.
 * - Service messages the shopper asked for (sketch, dates, their saved choice) are due at once.
 * - Marketing follow-ups (only with consent): day 1 and day 4, starting with "פרסומת" and an opt-out line,
 *   stopped as soon as the phone appears on a paid order or the owner marks "הסר".
 * The owner sends each message from the studio's WhatsApp: one tap on a signed link marks it sent and opens
 * WhatsApp with the text ready (from the dashboard or the daily email, no login needed for the link).
 */

defined( 'ABSPATH' ) || exit;

class AISR_Followup {

	const REASONS = array(
		'save_choice'    => 'לשלוח את מה שבחר',
		'back_in_stock'  => 'עדכון כשחוזר למלאי',
		'ready_update'   => 'עדכון כשההזמנה מראש מוכנה',
		'reminder'       => 'תזכורת לפני אירוע',
		'name_sketch'    => 'סקיצה של השם לפני הזמנה',
		'workshop_dates' => 'תאריכים פנויים לסדנה',
		'price_drop'     => 'עדכון על מבצע',
		'general'        => 'חזרה מהסטודיו',
	);

	/** Reasons where the shopper asked the studio to get back to them now. */
	const SERVICE_NOW = array( 'save_choice', 'name_sketch', 'workshop_dates', 'general' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aisr_followups';
	}

	public static function schema( string $charset ): string {
		$t = self::table();
		return "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			phone varchar(20) NOT NULL,
			name varchar(80) NOT NULL DEFAULT '',
			conv_uuid char(36) NOT NULL DEFAULT '',
			reason varchar(20) NOT NULL DEFAULT 'general',
			products text NULL,
			occasion varchar(80) NOT NULL DEFAULT '',
			marketing tinyint(1) NOT NULL DEFAULT 0,
			consent_text text NULL,
			page_url varchar(255) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'active',
			step tinyint(3) unsigned NOT NULL DEFAULT 0,
			next_at datetime NULL,
			last_sent_at datetime NULL,
			sent_count tinyint(3) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_total decimal(12,2) NOT NULL DEFAULT 0,
			bought_at datetime NULL,
			PRIMARY KEY  (id),
			KEY phone (phone),
			KEY status_next (status,next_at)
		) $charset;";
	}

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'order_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'order_paid' ), 20, 1 );
		add_action( 'admin_post_aisr_fu_optout', array( __CLASS__, 'admin_optout' ) );
		add_action( 'admin_post_aisr_fu_skip', array( __CLASS__, 'admin_skip' ) );
		add_action( 'admin_post_aisr_fu_delete', array( __CLASS__, 'admin_delete' ) );
	}

	public static function store_name(): string {
		return (string) AISR_Settings::get( 'business_name' );
	}

	/** The exact marketing consent sentence shown next to the checkbox (and stored as proof). */
	public static function consent_text(): string {
		return 'אני מאשר/ת לקבל מ' . self::store_name() . ' הודעות בוואטסאפ על המוצרים שבחרתי ועל מבצעים. אפשר להסיר בכל רגע בהודעה "הסר".';
	}

	/** 05X-XXXXXXX or +972… → 9725XXXXXXXX; empty when it is not an Israeli phone. */
	public static function normalize_phone( string $raw ): string {
		$d = preg_replace( '/\D/', '', $raw );
		if ( str_starts_with( $d, '00972' ) ) {
			$d = substr( $d, 2 );
		}
		if ( str_starts_with( $d, '0' ) ) {
			$d = '972' . substr( $d, 1 );
		}
		return preg_match( '/^972(5\d{8}|[2-9]\d{7})$/', $d ) ? $d : '';
	}

	private static function local_phone( string $p ): string {
		return '0' . substr( $p, 3 );
	}

	private static function opted_out( string $phone ): bool {
		return in_array( $phone, (array) get_option( 'aisr_optout_phones', array() ), true );
	}

	/* ---------- the card in the chat ---------- */

	/** Card texts per reason: title (what they get) and the confirmation after saving. */
	public static function card( string $reason, array $product_ids, string $occasion = '' ): array {
		$names = array();
		foreach ( array_slice( $product_ids, 0, 3 ) as $pid ) {
			$p = wc_get_product( $pid );
			if ( $p ) {
				$names[] = $p->get_name();
			}
		}
		$what   = $names ? implode( ', ', $names ) : '';
		$titles = array(
			'save_choice'    => 'לשלוח לך בוואטסאפ את מה שבחרנו' . ( $what ? ': ' . $what : '' ),
			'back_in_stock'  => 'נעדכן אותך בוואטסאפ כש' . ( $what ?: 'המוצר' ) . ' חוזר למלאי',
			'ready_update'   => 'נעדכן אותך בוואטסאפ על ' . ( $what ?: 'ההזמנה' ) . ' ומתי הוא מוכן',
			'reminder'       => 'נזכיר לך בוואטסאפ שבוע לפני ' . ( $occasion ?: 'האירוע' ),
			'name_sketch'    => 'מאי תשלח לך סקיצה של השם בוואטסאפ לפני שמזמינים',
			'workshop_dates' => 'מאי תשלח לך בוואטסאפ תאריכים פנויים' . ( $what ? ' ל' . $what : '' ),
			'price_drop'     => 'נעדכן אותך בוואטסאפ כשיש מבצע' . ( $what ? ' על ' . $what : '' ),
			'general'        => 'הסטודיו יחזור אליך בוואטסאפ',
		);
		$done = array(
			'save_choice'    => 'נשמר. הבחירה תגיע אליך בוואטסאפ מהסטודיו.',
			'back_in_stock'  => 'נשמר. נעדכן אותך ברגע שזה חוזר.',
			'ready_update'   => 'נשמר. נעדכן אותך בוואטסאפ.',
			'reminder'       => 'נשמר. נזכיר לך לפני ' . ( $occasion ?: 'האירוע' ) . '.',
			'name_sketch'    => 'נשמר. מאי תשלח לך סקיצה בוואטסאפ.',
			'workshop_dates' => 'נשמר. מאי תחזור אליך עם תאריכים.',
			'price_drop'     => 'נשמר. נעדכן אותך כשיש מבצע.',
			'general'        => 'נשמר. הסטודיו יחזור אליך.',
		);
		return array(
			'title'   => $titles[ $reason ] ?? $titles['general'],
			'done'    => $done[ $reason ] ?? $done['general'],
			'consent' => self::consent_text(),
		);
	}

	public static function routes(): void {
		register_rest_route(
			AISR_REST::NS,
			'/lead',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_from_card' ),
				'permission_callback' => array( 'AISR_REST', 'public_permission' ),
			)
		);
		register_rest_route(
			AISR_REST::NS,
			'/fu/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'open_link' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** The phone card was submitted. */
	public static function save_from_card( WP_REST_Request $req ) {
		$ip  = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key = 'aisr_rl_lead_' . md5( $ip . wp_salt( 'nonce' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= 8 && ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'aisr_rate', 'too many', array( 'status' => 429 ) );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		$phone = self::normalize_phone( (string) $req->get_param( 'phone' ) );
		if ( '' === $phone ) {
			return new WP_Error( 'aisr_phone', 'phone', array( 'status' => 400 ) );
		}
		$uuid    = sanitize_text_field( (string) $req->get_param( 'conversation_id' ) );
		$visitor = AISR_Privacy::visitor_hash( sanitize_text_field( (string) $req->get_param( 'visitor' ) ) );
		$conv    = $uuid ? AISR_DB::get_conversation( $uuid ) : null;
		if ( ! $conv || $conv['visitor'] !== $visitor ) {
			return new WP_Error( 'aisr_conv', 'conversation', array( 'status' => 400 ) );
		}
		$reason   = sanitize_key( (string) $req->get_param( 'reason' ) );
		$reason   = isset( self::REASONS[ $reason ] ) ? $reason : 'general';
		$products = array_values( array_filter( array_slice( array_map( 'absint', (array) $req->get_param( 'product_ids' ) ), 0, 3 ) ) );
		$name     = mb_substr( sanitize_text_field( (string) $req->get_param( 'name' ) ), 0, 60 );
		$occasion = mb_substr( sanitize_text_field( (string) $req->get_param( 'occasion' ) ), 0, 60 );
		$date     = sanitize_text_field( (string) $req->get_param( 'date' ) );
		$market   = ! empty( $req->get_param( 'marketing' ) ) && ! self::opted_out( $phone );

		self::add( $phone, $name, $uuid, $reason, $products, $occasion, $market, esc_url_raw( (string) $req->get_param( 'url' ) ) );

		// The requests that already have their own flow.
		if ( 'back_in_stock' === $reason ) {
			foreach ( $products as $pid ) {
				AISR_Growth::join_waitlist( $pid, $name, self::local_phone( $phone ) );
			}
		}
		if ( 'reminder' === $reason && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			AISR_Growth::add_reminder( $date, $occasion, $name, self::local_phone( $phone ), '', $products );
		}
		AISR_DB::update_conversation(
			$uuid,
			array(
				'lead_phone'     => self::local_phone( $phone ),
				'lead_name'      => $name ?: (string) $conv['lead_name'],
				'lead_marketing' => $market ? 1 : (int) $conv['lead_marketing'],
				'engaged'        => 1,
			)
		);
		AISR_REST::set_attribution_cookie( $uuid );
		if ( in_array( $reason, self::SERVICE_NOW, true ) ) { // They are waiting for an answer: tell the studio now.
			$to = AISR_Settings::get( 'handoff_email' );
			if ( $to ) {
				global $wpdb;
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE phone = %s ORDER BY id DESC LIMIT 1', $phone ), ARRAY_A ); // phpcs:ignore WordPress.DB
				if ( $row ) {
					wp_mail( $to, 'פנייה מהצ\'אט: ' . self::REASONS[ $reason ] . ( $name ? ' · ' . $name : '' ), ( $name ?: 'גולש/ת' ) . ' השאיר/ה טלפון בצ\'אט: ' . self::local_phone( $phone ) . "\n" . self::REASONS[ $reason ] . "\n\nלחיצה פותחת וואטסאפ עם הודעה מוכנה:\n" . self::send_link( $row ) );
				}
			}
		}
		return rest_ensure_response( array( 'ok' => true, 'message' => self::card( $reason, $products, $occasion )['done'] ) );
	}

	public static function add( string $phone, string $name, string $uuid, string $reason, array $products, string $occasion, bool $marketing, string $url = '' ): int {
		global $wpdb;
		$now     = time();
		$service = in_array( $reason, self::SERVICE_NOW, true );
		// Step 0 = the service message they asked for (now); steps 1-2 = marketing follow-ups (day 1, day 4).
		$step = $service ? 0 : 1;
		$next = $service ? $now : $now + 22 * HOUR_IN_SECONDS;
		if ( ! $service && ! $marketing ) {
			$next = null; // Waitlist / reminder only: those flows send their own message.
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'created_at'   => gmdate( 'Y-m-d H:i:s', $now ),
				'phone'        => $phone,
				'name'         => $name,
				'conv_uuid'    => $uuid,
				'reason'       => $reason,
				'products'     => wp_json_encode( $products ),
				'occasion'     => $occasion,
				'marketing'    => $marketing ? 1 : 0,
				'consent_text' => $marketing ? self::consent_text() . ' | ' . current_time( 'mysql' ) . ' | שיחה ' . $uuid : '',
				'page_url'     => mb_substr( $url, 0, 250 ),
				'status'       => null === $next ? 'done' : 'active',
				'step'         => $step,
				'next_at'      => null === $next ? null : gmdate( 'Y-m-d H:i:s', $next ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/* ---------- the messages ---------- */

	private static function products_line( array $ids ): array {
		$names = array();
		$urls  = array();
		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid );
			if ( $p ) {
				$names[] = $p->get_name();
				$urls[]  = urldecode( (string) get_permalink( $pid ) );
			}
		}
		return array( implode( ', ', $names ), implode( ' ', $urls ) );
	}

	public static function message( array $r ): string {
		$store          = self::store_name();
		$hi             = $r['name'] ? 'היי ' . $r['name'] . ', ' : 'היי, ';
		[ $what, $urls ] = self::products_line( json_decode( (string) $r['products'], true ) ?: array() );
		$optout         = "\nלהסרה מרשימת התפוצה השיבו \"הסר\".";
		if ( 0 === (int) $r['step'] ) {
			switch ( $r['reason'] ) {
				case 'name_sketch':
					return $hi . 'כאן מאי מ' . $store . '. ביקשת סקיצה של השם' . ( $what ? ' על ' . $what : '' ) . '. איזה שם לכתוב, ובאיזה סגנון? אשלח לך סקיצה לפני שמזמינים 🙂';
				case 'workshop_dates':
					return $hi . 'כאן מאי מ' . $store . '. ראיתי שהתעניינת ב' . ( $what ?: 'סדנה' ) . '. לכמה אנשים ולאיזה תאריך בערך? אשלח לך את התאריכים הפנויים.';
				case 'save_choice':
					return $hi . 'כאן ' . $store . '. שמרנו לך את מה שבחרת בצ\'אט באתר: ' . $what . "\n" . $urls . "\nיש שאלה על צבע, מידה או משלוח? אפשר לשאול כאן.";
				default:
					return $hi . 'כאן ' . $store . '. השארת לנו פרטים בצ\'אט באתר' . ( $what ? ' לגבי ' . $what : '' ) . '. איך אפשר לעזור?';
			}
		}
		if ( 1 === (int) $r['step'] ) {
			return 'פרסומת: ' . $hi . 'כאן ' . $store . '. ' . ( $what ? $what . ' עדיין מחכה לך: ' . $urls : 'מה שראית אצלנו בצ\'אט עדיין מחכה לך.' ) . "\nאם נשארה שאלה על צבע, גודל או משלוח, פשוט עונים כאן." . $optout;
		}
		$h    = AISR_Knowledge::holiday();
		$when = ! empty( $h['deadline'] ) && current_datetime()->format( 'Y-m-d' ) <= $h['deadline'] ? 'מזמינים עד ' . $h['deadlineText'] . ' וזה מגיע לפני ' . $h['name'] . '. ' : '';
		return 'פרסומת: ' . $hi . 'כאן ' . $store . ' בפעם האחרונה 🙂 ' . $when . ( $what ? $what . ': ' . $urls : '' ) . "\nכל כלי נעשה אצלנו בעבודת יד. אם תרצו עזרה בבחירה, אנחנו כאן." . $optout;
	}

	public static function wa_url( array $r ): string {
		return 'https://wa.me/' . $r['phone'] . '?text=' . rawurlencode( self::message( $r ) );
	}

	/** Signed link: marks the message sent and opens WhatsApp with it. Works from the email without login. */
	public static function send_link( array $r ): string {
		$sig = substr( hash_hmac( 'sha256', $r['id'] . '|' . $r['step'], wp_salt( 'auth' ) ), 0, 24 );
		return rest_url( AISR_REST::NS . '/fu/' . $r['id'] ) . '?s=' . (int) $r['step'] . '&t=' . $sig;
	}

	public static function open_link( WP_REST_Request $req ) {
		global $wpdb;
		$id   = absint( $req['id'] );
		$step = (int) $req->get_param( 's' );
		$r    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$sig  = $r ? substr( hash_hmac( 'sha256', $id . '|' . $step, wp_salt( 'auth' ) ), 0, 24 ) : '';
		if ( ! $r || ! hash_equals( $sig, (string) $req->get_param( 't' ) ) ) {
			return new WP_Error( 'aisr_link', 'הקישור לא תקין', array( 'status' => 403 ) );
		}
		$url = self::wa_url( array_merge( $r, array( 'step' => $step ) ) );
		if ( (int) $r['step'] === $step && 'active' === $r['status'] ) {
			self::advance( $r );
		}
		header( 'Location: ' . $url, true, 302 );
		exit;
	}

	/** After a message is sent: the next step, or done. */
	private static function advance( array $r ): void {
		global $wpdb;
		$step = (int) $r['step'];
		$data = array( 'last_sent_at' => gmdate( 'Y-m-d H:i:s' ), 'sent_count' => (int) $r['sent_count'] + 1 );
		if ( 0 === $step && $r['marketing'] ) {
			$data += array( 'step' => 1, 'next_at' => gmdate( 'Y-m-d H:i:s', time() + 22 * HOUR_IN_SECONDS ) );
		} elseif ( 1 === $step ) {
			$data += array( 'step' => 2, 'next_at' => gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ) );
		} else {
			$data += array( 'status' => 'done', 'next_at' => null );
		}
		$wpdb->update( self::table(), $data, array( 'id' => $r['id'] ) ); // phpcs:ignore WordPress.DB
	}

	/** Due now: active, not bought (checked here too), not opted out. */
	public static function due( int $limit = 40 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'active' AND next_at IS NOT NULL AND next_at <= %s ORDER BY next_at ASC LIMIT %d", gmdate( 'Y-m-d H:i:s' ), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows as $r ) {
			if ( self::opted_out( $r['phone'] ) ) {
				$wpdb->update( self::table(), array( 'status' => 'optout', 'next_at' => null ), array( 'id' => $r['id'] ) ); // phpcs:ignore WordPress.DB
				continue;
			}
			if ( (int) $r['step'] > 0 && self::find_order( $r['phone'], $r['created_at'] ) ) {
				continue; // order_paid() marks it; skip until then.
			}
			$out[] = $r;
		}
		return $out;
	}

	private static function find_order( string $phone, string $since_gmt ): int {
		foreach ( array( self::local_phone( $phone ), $phone, '+' . $phone ) as $p ) {
			$ids = wc_get_orders(
				array(
					'billing_phone' => $p,
					'status'        => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
					'date_created'  => '>=' . strtotime( $since_gmt . ' UTC' ),
					'limit'         => 1,
					'return'        => 'ids',
				)
			);
			if ( $ids ) {
				return (int) $ids[0];
			}
		}
		return 0;
	}

	/** A paid order from a phone we follow: stop the follow-up and count the sale. */
	public static function order_paid( $order_id ): void {
		global $wpdb;
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$phone = self::normalize_phone( (string) $order->get_billing_phone() );
		if ( '' === $phone ) {
			return;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE phone = %s AND order_id = 0 AND created_at >= %s", $phone, gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return;
		}
		foreach ( $rows as $row ) {
			$wpdb->update( self::table(), array( 'status' => 'bought', 'next_at' => null, 'order_id' => $order->get_id(), 'order_total' => (float) $order->get_total(), 'bought_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB
		}
		if ( ! $order->get_meta( '_aisr_followup' ) ) {
			$order->update_meta_data( '_aisr_followup', 1 );
			$order->add_order_note( 'הלקוח/ה השאיר/ה טלפון בצ\'אט של הבוט לפני ההזמנה.' );
			$order->save();
		}
	}

	/* ---------- admin ---------- */

	private static function check_admin( string $action ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'אין הרשאה' );
		}
		check_admin_referer( $action );
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $_POST['id'] ?? 0 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB, WordPress.Security.NonceVerification
		return $r ?: array();
	}

	public static function admin_optout(): void {
		$r = self::check_admin( 'aisr_fu_optout' );
		if ( $r ) {
			$list   = (array) get_option( 'aisr_optout_phones', array() );
			$list[] = $r['phone'];
			update_option( 'aisr_optout_phones', array_values( array_unique( $list ) ), false );
			global $wpdb;
			$wpdb->update( self::table(), array( 'status' => 'optout', 'next_at' => null, 'marketing' => 0 ), array( 'phone' => $r['phone'] ) ); // phpcs:ignore WordPress.DB
		}
		wp_safe_redirect( admin_url( 'admin.php?page=aisr-dashboard#aisr-followups' ) );
		exit;
	}

	public static function admin_delete(): void {
		$r = self::check_admin( 'aisr_fu_delete' );
		if ( $r ) {
			global $wpdb;
			$wpdb->delete( self::table(), array( 'id' => $r['id'] ) ); // phpcs:ignore WordPress.DB
		}
		wp_safe_redirect( admin_url( 'admin.php?page=aisr-dashboard#aisr-followups' ) );
		exit;
	}

	public static function admin_skip(): void {
		$r = self::check_admin( 'aisr_fu_skip' );
		if ( $r ) {
			self::advance( $r );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=aisr-dashboard#aisr-followups' ) );
		exit;
	}

	public static function stats( int $days ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) leads, SUM(marketing) marketing, SUM(sent_count) sent, SUM(status = \'bought\') bought, SUM(order_total) revenue, SUM(status = \'optout\') optout FROM ' . self::table() . ' WHERE created_at >= %s', $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( 'floatval', (array) $row );
	}

	public static function render( int $days ): void {
		$due  = self::due();
		$st   = self::stats( $days );
		$post = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<h2 id="aisr-followups">טלפונים מהצ'אט ומעקב בוואטסאפ</h2>
		<p class="description">
			<?php echo esc_html( 'ב-' . $days . ' הימים האחרונים: ' . (int) $st['leads'] . ' השאירו טלפון, ' . (int) $st['marketing'] . ' אישרו הודעות שיווק, ' . (int) $st['sent'] . ' הודעות נשלחו, ' . (int) $st['bought'] . ' קנו אחרי שהשאירו טלפון (' . number_format_i18n( (float) $st['revenue'] ) . ' ₪), ' . (int) $st['optout'] . ' ביקשו הסרה.' ); ?>
		</p>
		<p class="description">לחיצה על "לשלוח בוואטסאפ" פותחת וואטסאפ עם ההודעה מוכנה ומסמנת שנשלחה. מי שקנה יוצא מהרשימה לבד. מי שענה "הסר": לוחצים "הסר" והוא לא יקבל יותר.</p>
		<?php if ( ! $due ) : ?>
			<p>אין הודעות לשליחה כרגע.</p>
		<?php endif; ?>
		<?php foreach ( $due as $r ) : ?>
			<?php
			$label = 0 === (int) $r['step'] ? self::REASONS[ $r['reason'] ] ?? 'חזרה' : ( 1 === (int) $r['step'] ? 'מעקב ראשון (לא קנה)' : 'מעקב אחרון' );
			?>
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 14px;margin:8px 0;max-width:900px">
				<div style="font-weight:600"><?php echo esc_html( ( $r['name'] ?: 'ללא שם' ) . ' · ' . self::local_phone( $r['phone'] ) . ' · ' . $label ); ?></div>
				<div style="white-space:pre-wrap;color:#50575e;margin:6px 0;font-size:13px"><?php echo esc_html( self::message( $r ) ); ?></div>
				<a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( self::send_link( $r ) ); ?>">לשלוח בוואטסאפ</a>
				<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="display:inline">
					<input type="hidden" name="action" value="aisr_fu_skip"><input type="hidden" name="id" value="<?php echo esc_attr( $r['id'] ); ?>">
					<?php wp_nonce_field( 'aisr_fu_skip' ); ?>
					<button class="button" type="submit">לדלג</button>
				</form>
				<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="display:inline">
					<input type="hidden" name="action" value="aisr_fu_optout"><input type="hidden" name="id" value="<?php echo esc_attr( $r['id'] ); ?>">
					<?php wp_nonce_field( 'aisr_fu_optout' ); ?>
					<button class="button-link" type="submit" style="color:#b32d2e;margin-right:10px">הסר (ביקש/ה לא לקבל)</button>
				</form>
			</div>
		<?php endforeach; ?>
		<?php
		global $wpdb;
		$recent = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 30', ARRAY_A ); // phpcs:ignore WordPress.DB
		$status = array( 'active' => 'במעקב', 'done' => 'הסתיים', 'bought' => 'קנה/תה ✓', 'optout' => 'ביקש/ה הסרה' );
		if ( $recent ) :
			?>
			<details style="max-width:900px;margin:10px 0"><summary style="cursor:pointer;font-weight:600">כל הטלפונים האחרונים (<?php echo (int) count( $recent ); ?>)</summary>
			<table class="widefat striped" style="margin-top:8px"><thead><tr><th>מתי</th><th>שם</th><th>טלפון</th><th>למה</th><th>שיווק</th><th>מצב</th><th>הודעה הבאה</th><th></th></tr></thead><tbody>
			<?php foreach ( $recent as $r ) : ?>
				<tr>
					<td><?php echo esc_html( wp_date( 'd/m H:i', strtotime( $r['created_at'] . ' UTC' ) ) ); ?></td>
					<td><?php echo esc_html( $r['name'] ?: '—' ); ?></td>
					<td dir="ltr"><?php echo esc_html( self::local_phone( $r['phone'] ) ); ?></td>
					<td><?php echo esc_html( self::REASONS[ $r['reason'] ] ?? $r['reason'] ); ?></td>
					<td><?php echo $r['marketing'] ? '✓' : '—'; ?></td>
					<td><?php echo esc_html( ( $status[ $r['status'] ] ?? $r['status'] ) . ( $r['order_id'] ? ' #' . $r['order_id'] : '' ) ); ?></td>
					<td><?php echo esc_html( $r['next_at'] && 'active' === $r['status'] ? wp_date( 'd/m H:i', strtotime( $r['next_at'] . ' UTC' ) ) : '—' ); ?></td>
					<td>
						<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="display:inline">
							<input type="hidden" name="action" value="aisr_fu_optout"><input type="hidden" name="id" value="<?php echo esc_attr( $r['id'] ); ?>">
							<?php wp_nonce_field( 'aisr_fu_optout' ); ?>
							<button class="button-link" type="submit">הסר</button>
						</form>
						·
						<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="display:inline">
							<input type="hidden" name="action" value="aisr_fu_delete"><input type="hidden" name="id" value="<?php echo esc_attr( $r['id'] ); ?>">
							<?php wp_nonce_field( 'aisr_fu_delete' ); ?>
							<button class="button-link" type="submit" style="color:#b32d2e">מחיקה</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table></details>
			<?php
		endif;
	}

	/** Lines for the daily email: one signed link per due message. */
	public static function email_lines(): array {
		$lines = array();
		foreach ( self::due() as $r ) {
			$label   = 0 === (int) $r['step'] ? self::REASONS[ $r['reason'] ] ?? 'חזרה' : ( 1 === (int) $r['step'] ? 'מעקב ראשון' : 'מעקב אחרון' );
			$lines[] = $label . ' · ' . ( $r['name'] ?: 'ללא שם' ) . ' · ' . self::local_phone( $r['phone'] ) . "\n" . self::send_link( $r );
		}
		return $lines;
	}
}
