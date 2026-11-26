<?php
/**
 * Attribution: an order counts for the bot when the shopper replied or clicked in a conversation
 * and ordered within the attribution window, or used a coupon the bot issued.
 */

defined( 'ABSPATH' ) || exit;

class AISR_Attribution {

	public static function init(): void {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'classic_checkout' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'tag_order' ), 10, 1 );
	}

	public static function classic_checkout( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::tag_order( $order );
		}
	}

	public static function tag_order( $order ): void {
		if ( ! $order instanceof WC_Order || $order->get_meta( '_aisr_checked' ) ) {
			return;
		}
		$order->update_meta_data( '_aisr_checked', 1 );

		$grp = isset( $_COOKIE['aisr_grp'] ) && 'control' === $_COOKIE['aisr_grp'] ? 'control' : ( isset( $_COOKIE['aisr_grp'] ) ? 'test' : '' );
		if ( $grp ) {
			$order->update_meta_data( '_aisr_group', $grp );
		}

		$uuid = '';
		$how  = '';

		// 1) A coupon issued by the bot proves the source.
		foreach ( $order->get_coupon_codes() as $code ) {
			if ( 0 === strpos( strtolower( $code ), 'aisr-' ) ) {
				global $wpdb;
				$uuid = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT uuid FROM ' . AISR_DB::conversations() . ' WHERE coupon_code = %s', strtolower( $code ) ) ); // phpcs:ignore WordPress.DB
				$how  = 'coupon';
				break;
			}
		}

		// 2) Engaged conversation within the window.
		if ( ! $how && ! empty( $_COOKIE['aisr_conv'] ) ) {
			$raw          = sanitize_text_field( wp_unslash( $_COOKIE['aisr_conv'] ) );
			[ $cid, $ts ] = array_pad( explode( '.', $raw, 2 ), 2, '0' );
			$days         = (int) AISR_Settings::get( 'attribution_days' );
			$conv         = AISR_DB::get_conversation( $cid );
			if ( $conv && (int) $conv['engaged'] && ( time() - (int) $ts ) <= $days * DAY_IN_SECONDS ) {
				$uuid = $cid;
				$how  = 'conversation';
			}
		}

		if ( $how ) {
			$order->update_meta_data( '_aisr_attributed', 1 );
			$order->update_meta_data( '_aisr_attribution', $how );
			if ( $uuid ) {
				$order->update_meta_data( '_aisr_conversation', $uuid );
			}
			$order->add_order_note( 'ההזמנה שויכה לנציג ה-AI (' . ( 'coupon' === $how ? 'קופון מהצ\'אט' : 'שיחה בחלון השיוך' ) . ').' );
		}
		$order->save();
	}
}
