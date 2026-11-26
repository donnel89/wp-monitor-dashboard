<?php
/**
 * Public REST endpoints for the widget: chat turns and behavior events.
 * No nonce (cached pages would break it); protected by origin check and rate limits.
 */

defined( 'ABSPATH' ) || exit;

class AISR_REST {

	const NS = 'aisr/v1';

	const EVENT_TYPES = array( 'visit', 'shown', 'dismissed', 'opened', 'clicked', 'add_to_cart', 'coupon_applied' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'chat' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/event',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'event' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
			)
		);
	}

	/** Same-origin requests only (browsers always send Origin on cross-site POSTs). */
	public static function public_permission( WP_REST_Request $req ): bool {
		$origin = $req->get_header( 'origin' );
		if ( ! $origin ) {
			return true;
		}
		$allowed = array_filter( array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) ) );
		return in_array( wp_parse_url( $origin, PHP_URL_HOST ), $allowed, true );
	}

	private static function rate_limited( string $bucket, int $max, int $window ): bool {
		$ip  = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key = 'aisr_rl_' . $bucket . '_' . md5( $ip . wp_salt( 'nonce' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return true;
		}
		set_transient( $key, $n + 1, $window );
		return false;
	}

	private static function clean_page( $page ): array {
		$page = is_array( $page ) ? $page : array();
		return array(
			'type'       => sanitize_key( (string) ( $page['type'] ?? 'other' ) ),
			'product_id' => absint( $page['product_id'] ?? 0 ),
			'title'      => sanitize_text_field( (string) ( $page['title'] ?? '' ) ),
			'viewed'     => array_slice( array_map( 'absint', (array) ( $page['viewed'] ?? array() ) ), 0, 6 ),
			'order_items' => mb_substr( sanitize_text_field( (string) ( $page['order_items'] ?? '' ) ), 0, 200 ),
			'category'   => mb_substr( sanitize_text_field( (string) ( $page['category'] ?? '' ) ), 0, 80 ),
		);
	}

	private static function clean_group( $grp ): string {
		return 'control' === $grp ? 'control' : 'test';
	}

	/** First-party cookie that ties a later order to an engaged conversation (attribution window). */
	public static function set_attribution_cookie( string $uuid ): void {
		$days = (int) AISR_Settings::get( 'attribution_days' );
		setcookie(
			'aisr_conv',
			$uuid . '.' . time(),
			array(
				'expires'  => time() + $days * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	public static function chat( WP_REST_Request $req ) {
		$s = AISR_Settings::all();
		if ( empty( $s['enabled'] ) || '' === AISR_Settings::api_key() ) {
			return new WP_Error( 'aisr_disabled', 'chat disabled', array( 'status' => 503 ) );
		}
		if ( 'all' !== $s['visibility'] && ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'aisr_test_mode', 'chat is in test mode', array( 'status' => 403 ) );
		}
		if ( self::rate_limited( 'chat', 40, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'aisr_rate', 'too many messages', array( 'status' => 429 ) );
		}

		$message = trim( sanitize_textarea_field( (string) $req->get_param( 'message' ) ) );
		if ( '' === $message ) {
			return new WP_Error( 'aisr_empty', 'empty message', array( 'status' => 400 ) );
		}
		$message = mb_substr( $message, 0, 600 );
		$page    = self::clean_page( $req->get_param( 'page' ) );
		$visitor = AISR_Privacy::visitor_hash( sanitize_text_field( (string) $req->get_param( 'visitor' ) ) );
		$uuid    = sanitize_text_field( (string) $req->get_param( 'conversation_id' ) );
		$opener  = sanitize_text_field( (string) $req->get_param( 'opener' ) );

		AISR_Knowledge::record_topic( (int) $page['product_id'], $message ); // What shoppers ask about each product.

		// The visitor's cart (for coupons, leads and context).
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$conv = $uuid ? AISR_DB::get_conversation( $uuid ) : null;
		if ( $conv && $conv['visitor'] !== $visitor ) {
			$conv = null; // Never continue someone else's conversation.
		}
		if ( ! $conv ) {
			$conv = AISR_DB::create_conversation(
				array(
					'visitor'     => $visitor,
					'grp'         => self::clean_group( $req->get_param( 'grp' ) ),
					'trigger_key' => sanitize_key( (string) $req->get_param( 'trigger' ) ),
					'page_type'   => $page['type'],
					'product_id'  => $page['product_id'],
				)
			);
		}
		if ( (int) $conv['user_turns'] >= 30 ) {
			return rest_ensure_response(
				array(
					'conversation_id' => $conv['uuid'],
					'bubbles'         => array( 'השיחה ארוכה מאוד. כדי לא לפספס כלום, הכי טוב להמשיך עם הצוות.' ),
					'cards'           => array(),
					'replies'         => array(),
					'actions'         => array(),
				)
			);
		}

		// A photo from the shopper (resized in the browser): jpeg/png/webp data URL, at most ~1.5 MB.
		$image = null;
		$raw   = (string) $req->get_param( 'image' );
		if ( '' !== $raw ) {
			if ( self::rate_limited( 'image', 8, HOUR_IN_SECONDS ) ) {
				return new WP_Error( 'aisr_rate', 'too many photos', array( 'status' => 429 ) );
			}
			if ( strlen( $raw ) > 2100000 || ! preg_match( '#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', $raw, $m ) ) {
				return new WP_Error( 'aisr_image', 'bad image', array( 'status' => 400 ) );
			}
			$image = array( 'type' => $m[1], 'data' => $m[2] );
		}

		$result = AISR_Claude::chat( $conv, $message, $page, $opener, $image ); // The latest proactive message, also mid-conversation.
		self::set_attribution_cookie( $conv['uuid'] );

		return rest_ensure_response(
			array(
				'conversation_id' => $conv['uuid'],
				'bubbles'         => $result['bubbles'],
				'cards'           => $result['cards'],
				'replies'         => $result['replies'],
				'actions'         => $result['actions'],
			)
		);
	}

	public static function event( WP_REST_Request $req ) {
		if ( self::rate_limited( 'event', 200, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'aisr_rate', 'too many events', array( 'status' => 429 ) );
		}
		$type = sanitize_key( (string) $req->get_param( 'type' ) );
		if ( ! in_array( $type, self::EVENT_TYPES, true ) ) {
			return new WP_Error( 'aisr_type', 'bad type', array( 'status' => 400 ) );
		}
		$page    = self::clean_page( $req->get_param( 'page' ) );
		$visitor = AISR_Privacy::visitor_hash( sanitize_text_field( (string) $req->get_param( 'visitor' ) ) );
		$uuid    = sanitize_text_field( (string) $req->get_param( 'conversation_id' ) );

		AISR_DB::add_event(
			array(
				'visitor'     => $visitor,
				'conv_uuid'   => $uuid,
				'type'        => $type,
				'trigger_key' => sanitize_key( (string) $req->get_param( 'trigger' ) ),
				'page_type'   => $page['type'],
				'product_id'  => $page['product_id'],
				'grp'         => self::clean_group( $req->get_param( 'grp' ) ),
			)
		);

		// A click on a card or an add-to-cart from the chat counts as engagement.
		if ( $uuid && in_array( $type, array( 'clicked', 'add_to_cart', 'coupon_applied' ), true ) ) {
			$conv = AISR_DB::get_conversation( $uuid );
			if ( $conv && $conv['visitor'] === $visitor ) {
				AISR_DB::update_conversation( $uuid, array( 'engaged' => 1 ) );
				self::set_attribution_cookie( $uuid );
			}
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
