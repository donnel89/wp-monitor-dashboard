<?php
/**
 * Store knowledge: what the bot knows about the shop (auto-read from WooCommerce + settings),
 * and product lookups used by the tools.
 */

defined( 'ABSPATH' ) || exit;

class AISR_Knowledge {

	const CACHE_KEY = 'aisr_store_knowledge';

	public static function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/** Static store knowledge. Cached, and kept byte-stable so prompt caching works. */
	public static function store_context(): string {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$s     = AISR_Settings::all();
		$lines = array();

		$lines[] = '# Store';
		$lines[] = 'Name: ' . $s['business_name'];
		$lines[] = 'Website: ' . home_url();
		$lines[] = 'Currency: ' . get_woocommerce_currency() . ' (' . html_entity_decode( get_woocommerce_currency_symbol() ) . ')';
		foreach (
			array(
				'Physical address / store' => $s['business_address'],
				'In business'              => $s['business_years'],
				'Phone'                    => $s['business_phone'],
				'Shipping'                 => $s['shipping_summary'],
				'Returns'                  => $s['returns_summary'],
				'Warranty'                 => $s['warranty_summary'],
				'Payment'                  => $s['payment_summary'],
			) as $label => $value
		) {
			if ( '' !== trim( (string) $value ) ) {
				$lines[] = $label . ': ' . $value;
			}
		}
		$lines[] = 'Business hours: ' . $s['hours_start'] . '-' . $s['hours_end'] . ', days ' . implode( ',', self::day_names( (array) $s['work_days'] ) );

		$adv = AISR_Settings::advantages();
		if ( $adv ) {
			$lines[] = '';
			$lines[] = '# Why buy here (store advantages, use these in comparisons)';
			foreach ( $adv as $a ) {
				$lines[] = '- ' . $a;
			}
		}

		$zones = self::shipping_zones_summary();
		if ( $zones ) {
			$lines[] = '';
			$lines[] = '# Shipping methods (from WooCommerce)';
			$lines[] = $zones;
		}

		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 60 ) );
		if ( ! is_wp_error( $cats ) && $cats ) {
			$lines[] = '';
			$lines[] = '# Product categories';
			$lines[] = implode( ', ', wp_list_pluck( $cats, 'name' ) );
		}

		foreach ( (array) $s['policy_page_ids'] as $pid ) {
			$p = get_post( (int) $pid );
			if ( ! $p || 'publish' !== $p->post_status ) {
				continue;
			}
			$text    = self::clean_text( apply_filters( 'the_content', $p->post_content ), 6000 );
			$lines[] = '';
			$lines[] = '# Page: ' . $p->post_title;
			$lines[] = $text;
		}

		if ( '' !== trim( $s['diagnostic_questions'] ) ) {
			$lines[] = '';
			$lines[] = '# Discovery questions by category (set by the store)';
			$lines[] = $s['diagnostic_questions'];
		}

		if ( '' !== trim( $s['knowledge_extra'] ) ) {
			$lines[] = '';
			$lines[] = '# Additional facts and corrections from the store owner (these override anything above)';
			$lines[] = $s['knowledge_extra'];
		}

		$out = implode( "\n", $lines );
		set_transient( self::CACHE_KEY, $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	private static function day_names( array $days ): array {
		$names = array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' );
		return array_map( static fn( $d ) => $names[ (int) $d ] ?? '', $days );
	}

	private static function shipping_zones_summary(): string {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return '';
		}
		$out = array();
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			$methods = array();
			foreach ( $zone['shipping_methods'] as $m ) {
				if ( 'yes' !== $m->enabled ) {
					continue;
				}
				$desc = $m->get_title();
				$cost = $m->get_option( 'cost' );
				if ( '' !== (string) $cost ) {
					$desc .= ' (' . $cost . ')';
				}
				$min = $m->get_option( 'min_amount' );
				if ( '' !== (string) $min ) {
					$desc .= ' (free above ' . $min . ')';
				}
				$methods[] = $desc;
			}
			if ( $methods ) {
				$out[] = '- ' . $zone['zone_name'] . ': ' . implode( '; ', $methods );
			}
		}
		return implode( "\n", $out );
	}

	public static function clean_text( string $html, int $max ): string {
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n\s*\n+/', "\n", (string) $text );
		$text = trim( (string) $text );
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '…' : $text;
	}

	/** Context for the page the visitor is on. Sent uncached, after the cached store block. */
	public static function page_context( array $page ): string {
		$lines   = array( '# Where the visitor is now' );
		$lines[] = 'Page type: ' . ( $page['type'] ?? 'other' );
		if ( ! empty( $page['title'] ) ) {
			$lines[] = 'Page title: ' . sanitize_text_field( $page['title'] );
		}
		$pid = (int) ( $page['product_id'] ?? 0 );
		if ( $pid ) {
			$d = self::product_details( $pid );
			if ( $d ) {
				$lines[] = 'Product the visitor is viewing: ' . wp_json_encode( $d, JSON_UNESCAPED_UNICODE );
			}
		}
		if ( ! empty( $page['viewed'] ) && is_array( $page['viewed'] ) ) {
			$names = array();
			foreach ( array_slice( array_map( 'absint', $page['viewed'] ), 0, 6 ) as $vid ) {
				$p = wc_get_product( $vid );
				if ( $p ) {
					$names[] = $p->get_name() . ' (id ' . $vid . ')';
				}
			}
			if ( $names ) {
				$lines[] = 'Products viewed earlier in this visit: ' . implode( ', ', $names );
			}
		}
		$cart = self::cart_summary();
		if ( $cart ) {
			$lines[] = 'Cart: ' . $cart;
		}
		return implode( "\n", $lines );
	}

	public static function cart_summary(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}
		$items = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$p = $item['data'] ?? null;
			if ( $p ) {
				$items[] = $p->get_name() . ' x' . (int) $item['quantity'];
			}
		}
		if ( ! $items ) {
			return '';
		}
		return implode( ', ', $items ) . ' | subtotal ' . wc_format_decimal( WC()->cart->get_subtotal(), 2 );
	}

	public static function product_card( WC_Product $p ): array {
		$img = wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' );
		return array(
			'id'          => $p->get_id(),
			'name'        => $p->get_name(),
			'price'       => html_entity_decode( wp_strip_all_tags( $p->get_price_html() ) ),
			'image'       => $img ?: wc_placeholder_img_src(),
			'url'         => get_permalink( $p->get_id() ),
			'in_stock'    => $p->is_in_stock(),
			'simple'      => $p->is_type( 'simple' ) && $p->is_purchasable(),
			'rating'      => (float) $p->get_average_rating(),
		);
	}

	/** Compact product facts for the model. */
	public static function product_details( int $id, bool $full = false ): ?array {
		$p = wc_get_product( $id );
		if ( ! $p || 'publish' !== $p->get_status() ) {
			return null;
		}
		$d = array(
			'id'            => $p->get_id(),
			'name'          => $p->get_name(),
			'price'         => $p->get_price(),
			'regular_price' => $p->get_regular_price(),
			'on_sale'       => $p->is_on_sale(),
			'in_stock'      => $p->is_in_stock(),
			'stock_qty'     => $p->get_stock_quantity(),
			'type'          => $p->get_type(),
			'categories'    => wp_list_pluck( get_the_terms( $p->get_id(), 'product_cat' ) ?: array(), 'name' ),
			'short'         => self::clean_text( $p->get_short_description(), 400 ),
			'rating'        => (float) $p->get_average_rating(),
			'review_count'  => $p->get_review_count(),
			'url'           => urldecode( (string) get_permalink( $p->get_id() ) ),
		);
		$attrs = array();
		foreach ( $p->get_attributes() as $attr ) {
			if ( $attr instanceof WC_Product_Attribute ) {
				$name           = wc_attribute_label( $attr->get_name() );
				$values         = $attr->is_taxonomy() ? wc_get_product_terms( $p->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) : $attr->get_options();
				$attrs[ $name ] = implode( ', ', array_map( 'strval', $values ) );
			}
		}
		if ( $attrs ) {
			$d['attributes'] = $attrs;
		}
		if ( $p->get_weight() ) {
			$d['weight'] = $p->get_weight() . ' ' . get_option( 'woocommerce_weight_unit' );
		}
		if ( $full ) {
			$d['description'] = self::clean_text( $p->get_description(), 2500 );
			if ( $p->is_type( 'variable' ) ) {
				$vars = array();
				foreach ( array_slice( $p->get_available_variations(), 0, 30 ) as $v ) {
					$vars[] = array(
						'variation_id' => $v['variation_id'],
						'attributes'   => $v['attributes'],
						'price'        => $v['display_price'],
						'in_stock'     => (bool) $v['is_in_stock'],
					);
				}
				$d['variations'] = $vars;
			}
			$d['recent_reviews'] = self::recent_reviews( $p->get_id() );
			$d['related_ids']    = array_slice( wc_get_related_products( $p->get_id(), 5 ), 0, 5 );
			$d['upsell_ids']     = $p->get_upsell_ids();
			$d['cross_sell_ids'] = $p->get_cross_sell_ids();
		}
		return $d;
	}

	private static function recent_reviews( int $id ): array {
		$comments = get_comments(
			array(
				'post_id' => $id,
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => 4,
			)
		);
		$out = array();
		foreach ( $comments as $c ) {
			$out[] = array(
				'rating' => (int) get_comment_meta( $c->comment_ID, 'rating', true ),
				'text'   => self::clean_text( $c->comment_content, 300 ),
			);
		}
		return $out;
	}

	/** Catalog search for the search_products tool. */
	public static function search( array $args ): array {
		$limit = max( 1, min( 8, (int) ( $args['limit'] ?? 6 ) ) );
		$query = array(
			'status'  => 'publish',
			'limit'   => $limit,
			'orderby' => 'popularity',
			'return'  => 'objects',
		);
		if ( ! empty( $args['query'] ) ) {
			$query['s'] = sanitize_text_field( $args['query'] );
		}
		if ( ! empty( $args['category'] ) ) {
			$term = get_term_by( 'name', sanitize_text_field( $args['category'] ), 'product_cat' );
			if ( $term ) {
				$query['category'] = array( $term->slug );
			}
		}
		if ( ! empty( $args['in_stock_only'] ) || ! isset( $args['in_stock_only'] ) ) {
			$query['stock_status'] = 'instock';
		}
		$products = wc_get_products( $query );

		// Fallback: no keyword hit, try each word separately.
		if ( ! $products && ! empty( $args['query'] ) ) {
			$seen = array();
			foreach ( preg_split( '/\s+/u', (string) $args['query'] ) as $word ) {
				if ( mb_strlen( $word ) < 2 ) {
					continue;
				}
				$query['s'] = $word;
				foreach ( wc_get_products( $query ) as $p ) {
					$seen[ $p->get_id() ] = $p;
				}
			}
			$products = array_slice( array_values( $seen ), 0, $limit );
		}

		$min = isset( $args['min_price'] ) ? (float) $args['min_price'] : null;
		$max = isset( $args['max_price'] ) ? (float) $args['max_price'] : null;
		$out = array();
		foreach ( $products as $p ) {
			$price = (float) $p->get_price();
			if ( ( null !== $min && $price < $min ) || ( null !== $max && $max > 0 && $price > $max ) ) {
				continue;
			}
			$out[] = self::product_details( $p->get_id() );
		}
		return array_values( array_filter( $out ) );
	}
}
