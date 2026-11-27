<?php
/**
 * Smart add-ons: which one product makes sense to add to what the shopper is buying.
 * Ranks every store product by real signals: complements the owner set (cross-sells),
 * what customers bought together in paid orders, the same collection (a shared name word),
 * a different category (a complement, not a substitute), and closing the free-shipping gap.
 * Only one-click products (simple, in stock, no options), never pricier than the main item.
 */

defined( 'ABSPATH' ) || exit;

class AISR_Addons {

	/** Words that describe the kind of item, not the collection. */
	const STOP = array( 'ספל', 'ספלים', 'כוס', 'כוסות', 'בלי', 'עם', 'ידית', 'סט', 'צלחת', 'צלחות', 'קערה', 'קערות', 'קרמיקה', 'של', 'ל', 'ו', 'שמנת', 'חום', 'כחול', 'אדום', 'שחור', 'טבעי', 'לבן', 'אספרסו', 'קורטדו', 'קצר', 'ארוך', 'ראשונה', 'עיקרית', 'עציץ', 'עציצי', 'בשיתוף', 'המקעקעת', 'אישי', 'שם', 'קטן', 'גדול', 'זוג', 'להכנת' );

	/** Categories that say what an item is (cups, plates), without marketing ones (SALE, best sellers, holiday gifts). */
	public static function kind_cats( int $pid ): array {
		static $names = array();
		$out = array();
		foreach ( wc_get_product_cat_ids( $pid ) as $cid ) {
			if ( ! isset( $names[ $cid ] ) ) {
				$t             = get_term( $cid, 'product_cat' );
				$names[ $cid ] = $t && ! is_wp_error( $t ) ? $t->name : '';
			}
			if ( '' !== $names[ $cid ] && ! preg_match( '/sale|מבצע|נמכר|מתנ|חג|חדש|הכל|uncategorized/iu', $names[ $cid ] ) ) {
				$out[] = (int) $cid;
			}
		}
		return $out;
	}

	/** Product id => [ other product id => number of paid orders with both ], from the last year. */
	public static function bought_together(): array {
		$hit = get_transient( 'aisr_bought_together3' );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$out    = array();
		$orders = wc_get_orders(
			array(
				'limit'        => 1500,
				'status'       => array( 'wc-processing', 'wc-completed' ),
				'date_created' => '>=' . ( time() - 365 * DAY_IN_SECONDS ),
			)
		);
		$cats = array(); // Category id => [ other category id => orders with both ]: what kinds of items go together.
		foreach ( $orders as $o ) {
			$ids = array();
			$oc  = array();
			foreach ( $o->get_items() as $item ) {
				$ids[] = (int) $item->get_product_id();
				foreach ( self::kind_cats( (int) $item->get_product_id() ) as $cid ) {
					$oc[ $cid ][] = (int) $item->get_product_id();
				}
			}
			foreach ( $oc as $ca => $pa ) {
				foreach ( $oc as $cb => $pb ) {
					if ( $ca !== $cb && array_diff( $pb, $pa ) ) {
						$cats[ $ca ][ $cb ] = ( $cats[ $ca ][ $cb ] ?? 0 ) + 1;
					}
				}
			}
			$ids = array_values( array_unique( array_filter( $ids ) ) );
			foreach ( $ids as $a ) {
				foreach ( $ids as $b ) {
					if ( $a !== $b ) {
						$out[ $a ][ $b ] = ( $out[ $a ][ $b ] ?? 0 ) + 1;
					}
				}
			}
		}
		$out['_cats'] = $cats;
		set_transient( 'aisr_bought_together3', $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/** Light catalog: id, name, categories, collection words. */
	private static function catalog(): array {
		$hit = get_transient( 'aisr_addon_catalog3' );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$items = array();
		$df    = array();
		foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 400, 'return' => 'ids' ) ) as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$words = array();
			foreach ( preg_split( '/[^\p{L}\'׳’]+/u', mb_strtolower( $p->get_name() ) ) as $w ) {
				$w = trim( str_replace( array( '׳', '’' ), "'", $w ), "'" );
				if ( mb_strlen( $w ) >= 2 && ! in_array( $w, self::STOP, true ) ) {
					$words[ $w ] = true;
				}
			}
			foreach ( array_keys( $words ) as $w ) {
				$df[ $w ] = ( $df[ $w ] ?? 0 ) + 1;
			}
			$items[ $id ] = array( 'words' => array_keys( $words ), 'cats' => self::kind_cats( $id ) );
		}
		foreach ( $items as &$it ) { // A collection word is shared by a few products, not by half the store.
			$it['words'] = array_values( array_filter( $it['words'], static fn( $w ) => $df[ $w ] >= 2 && $df[ $w ] <= 10 ) );
		}
		unset( $it );
		set_transient( 'aisr_addon_catalog3', $items, 6 * HOUR_IN_SECONDS );
		return $items;
	}

	/**
	 * Ranked add-ons for a product. $ctx: cart_ids (already in the cart), cart_total (including the main item).
	 * Each: id, name, price (text), image, url, reason (Hebrew, shown to the shopper), score, free_ship.
	 */
	public static function for_product( int $pid, array $ctx = array(), int $limit = 3 ): array {
		$main = wc_get_product( $pid );
		if ( ! $main ) {
			return array();
		}
		if ( $main->is_type( 'variation' ) ) {
			$main = wc_get_product( $main->get_parent_id() );
			$pid  = $main ? $main->get_id() : 0;
		}
		if ( ! $main || AISR_Knowledge::is_workshop( $pid ) ) {
			return array();
		}
		$cat        = self::catalog();
		$all_bt     = self::bought_together();
		$bt         = $all_bt[ $pid ] ?? array();
		$cat_aff    = $all_bt['_cats'] ?? array();
		$cross      = array_map( 'intval', $main->get_cross_sell_ids() );
		$main_price = (float) ( $ctx['main_price'] ?? wc_get_price_to_display( $main ) );
		$in_cart    = array_map( 'intval', (array) ( $ctx['cart_ids'] ?? array() ) );
		$min        = (float) AISR_Knowledge::free_shipping_min();
		$gap        = $min > 0 && isset( $ctx['cart_total'] ) ? $min - (float) $ctx['cart_total'] : 0;
		$main_words = $cat[ $pid ]['words'] ?? array();
		$main_cats  = $cat[ $pid ]['cats'] ?? self::kind_cats( $pid );
		$sold       = AISR_Growth::sales_30d();
		$out        = array();
		foreach ( $cat as $id => $c ) {
			if ( $id === $pid || in_array( $id, $in_cart, true ) ) {
				continue;
			}
			$is_cross = in_array( $id, $cross, true );
			$n_bt     = (int) ( $bt[ $id ] ?? 0 );
			$shared   = array_values( array_intersect( $main_words, $c['words'] ) );
			if ( ! $is_cross && ! $n_bt && ! $shared && $gap <= 0 && ! array_diff( $c['cats'], $main_cats ) ) {
				continue; // No real reason to suggest it.
			}
			$p = wc_get_product( $id );
			if ( ! $p || ! $p->is_type( 'simple' ) || ! $p->is_purchasable() || ! $p->is_in_stock() || AISR_Knowledge::is_workshop( $id ) || ! AISR_Knowledge::quick_addable( $p ) ) {
				continue;
			}
			$price = (float) wc_get_price_to_display( $p );
			if ( $price <= 0 || ( ! $is_cross && $main_price > 0 && $price > $main_price ) ) {
				continue; // Never push something pricier than what they buy.
			}
			$same_cat  = (bool) array_intersect( $main_cats, $c['cats'] );
			$aff       = 0; // How often this kind of item was bought with the main item's kind.
			foreach ( $main_cats as $mc ) {
				foreach ( $c['cats'] as $cc ) {
					if ( $mc !== $cc ) {
						$aff = max( $aff, (int) ( $cat_aff[ $mc ][ $cc ] ?? 0 ) );
					}
				}
			}
			$free_ship = $gap > 0 && $price >= $gap && $price <= $gap + 120;
			$score     = ( $is_cross ? 6 : 0 ) + 2 * min( $n_bt, 3 ) + ( $shared ? 2 : 0 ) + ( $same_cat ? -1 : 1 ) + ( $free_ship ? 3 : 0 ) + ( $aff >= 5 ? 3 : ( $aff >= 2 ? 2 : 0 ) )
				+ ( 'onbackorder' === $p->get_stock_status() ? -2 : 0 ) + ( ! empty( $sold[ $id ] ) ? 1 : 0 );
			if ( $score < 3 ) {
				continue;
			}
			if ( $n_bt >= 2 ) {
				$reason = 'לקוחות שקנו את ' . $main->get_name() . ' הוסיפו גם את זה';
			} elseif ( $is_cross ) {
				$reason = 'משלים את ' . $main->get_name();
			} elseif ( $shared ) {
				$reason = 'מאותה סדרה (' . $shared[0] . ')';
			} elseif ( $n_bt ) {
				$reason = 'נקנה יחד עם ' . $main->get_name();
			} elseif ( $aff >= 2 ) {
				$reason = 'לקוחות מזמינים את זה יחד עם ' . $main->get_name();
			} else {
				$reason = 'משלים את ההזמנה';
			}
			if ( $free_ship ) {
				$reason .= ' · ואז המשלוח חינם';
			}
			$out[] = array(
				'id'        => $id,
				'name'      => $p->get_name(),
				'price'     => AISR_Knowledge::plain_price( $p ),
				'image'     => wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src(),
				'url'       => get_permalink( $id ),
				'reason'    => $reason,
				'score'     => $score,
				'free_ship' => $free_ship,
				'made_to_order' => 'onbackorder' === $p->get_stock_status(),
			);
		}
		usort( $out, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $out, 0, $limit );
	}

	/** What is in the visitor's cart now: product ids and the displayed subtotal. */
	public static function cart_state(): array {
		$ids   = array();
		$total = 0.0;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$ids[] = (int) $item['product_id'];
			}
			$total = (float) WC()->cart->get_displayed_subtotal();
		}
		return array( 'ids' => $ids, 'total' => $total );
	}
}
