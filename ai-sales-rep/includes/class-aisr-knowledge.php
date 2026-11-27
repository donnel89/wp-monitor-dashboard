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
		$lines[] = 'Business hours: ' . $s['hours_start'] . '-' . $s['hours_end'] . ', days ' . implode( ',', self::day_names( (array) $s['work_days'] ) ) . ( $s['hours_friday_end'] ? ' (Friday until ' . $s['hours_friday_end'] . ')' : '' );

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

		$owner = AISR_Learning::general();
		if ( $owner ) {
			$lines[] = '';
			$lines[] = '# Answers the store owner gave to questions shoppers asked (verified, these override anything above)';
			foreach ( $owner as $e ) {
				$lines[] = 'Q: ' . $e['q'] . "\nA: " . $e['a'];
			}
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

	/** Lowest free-shipping threshold across the store's shipping zones, 0 when there is none. */
	public static function free_shipping_min(): float {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return 0;
		}
		$min = 0;
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			foreach ( $zone['shipping_methods'] as $m ) {
				if ( 'yes' === $m->enabled && 'free_shipping' === $m->id ) {
					$v = (float) $m->get_option( 'min_amount' );
					if ( $v > 0 && ( ! $min || $v < $min ) ) {
						$min = $v;
					}
				}
			}
		}
		return $min;
	}

	/** Business days (Sunday to Thursday) from tomorrow up to the day before $date. */
	public static function business_days_until( string $date, string $from = 'tomorrow' ): int {
		$tz    = wp_timezone();
		$day   = new DateTime( $from, $tz );
		$end   = new DateTime( $date, $tz );
		$count = 0;
		while ( $day < $end && $count < 400 ) {
			if ( (int) $day->format( 'w' ) <= 4 ) {
				++$count;
			}
			$day->modify( '+1 day' );
		}
		return $count;
	}

	/** A workshop is an experience in the studio, not a shipped item: no stock, holiday shipping or card dates. */
	public static function is_workshop( int $product_id ): bool {
		$p = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $p ) {
			return false;
		}
		$pid  = $p->get_parent_id() ?: $p->get_id();
		$cats = implode( ' ', wp_list_pluck( get_the_terms( $pid, 'product_cat' ) ?: array(), 'name' ) );
		return (bool) preg_match( '/סדנ|workshop/ui', $cats . ' ' . get_the_title( $pid ) );
	}

	/** The upcoming holiday from settings, while it is within 45 days: name, date, days left, what arrives in time. */
	public static function holiday(): ?array {
		$s = AISR_Settings::all();
		if ( '' === $s['holiday_name'] || '' === $s['holiday_date'] ) {
			return null;
		}
		$today = new DateTime( 'today', wp_timezone() );
		$date  = new DateTime( $s['holiday_date'], wp_timezone() );
		if ( $date <= $today || $today->diff( $date )->days > 45 ) {
			return null;
		}
		$left = self::business_days_until( $s['holiday_date'] );
		// The last day to order an in-stock item so it still arrives before the holiday.
		$deadline = null;
		for ( $d = clone $today, $i = 0; $i < 60 && $d < $date; $d->modify( '+1 day' ), $i++ ) {
			$next = ( clone $d )->modify( '+1 day' )->format( 'Y-m-d' );
			if ( self::business_days_until( $s['holiday_date'], $next ) >= (int) $s['ready_days'] ) {
				$deadline = clone $d;
			}
		}
		$days = array( 'יום ראשון', 'יום שני', 'יום שלישי', 'יום רביעי', 'יום חמישי', 'יום שישי', 'מוצאי שבת' );
		return array(
			'name'      => $s['holiday_name'],
			'date'      => $s['holiday_date'],
			'dateText'  => $date->format( 'j.n' ),
			'daysLeft'  => $left,
			'readyOk'   => $left >= (int) $s['ready_days'],
			'madeOk'    => $left >= (int) $s['made_days'],
			'deadline'  => $deadline ? $deadline->format( 'Y-m-d' ) : '',
			'deadlineText' => $deadline ? $days[ (int) $deadline->format( 'w' ) ] . ' ' . $deadline->format( 'j.n' ) : '',
		);
	}

	/* ---------- what shoppers ask about each product (learned from chats, no AI call) ---------- */

	const TOPICS = array(
		'size'      => array( 'המידות', '/מידה|מידות|גודל|גובה|קוטר|נפח|ס"מ|סמ|מ"ל/u' ),
		'dishwash'  => array( 'מדיח ומיקרוגל', '/מדיח|מיקרוגל|תנור/u' ),
		'delivery'  => array( 'זמן ההגעה', '/מתי (יגיע|זה מגיע)|כמה זמן|זמן (אספקה|הכנה|משלוח)|עד (יום|החג)/u' ),
		'name'      => array( 'הכיתוב האישי', '/שם|הקדשה|חריטה|כיתוב|לוגו/u' ),
		'gift'      => array( 'אריזת מתנה', '/מתנה|אריזה|ברכה/u' ),
		'color'     => array( 'הצבע', '/צבע|גוון/u' ),
		'returns'   => array( 'החזרות', '/החזר|החלפ/u' ),
	);

	public static function record_topic( int $product_id, string $message ): void {
		if ( ! $product_id || 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) ) {
			return; // The id comes from the browser: only count real, published products.
		}
		$counts = (array) get_post_meta( $product_id, '_aisr_topics', true );
		$hit    = false;
		foreach ( self::TOPICS as $key => $t ) {
			if ( preg_match( $t[1], $message ) ) {
				$counts[ $key ] = (int) ( $counts[ $key ] ?? 0 ) + 1;
				$hit            = true;
			}
		}
		if ( $hit ) {
			update_post_meta( $product_id, '_aisr_topics', $counts );
		}
	}

	/** The topic shoppers asked about most on this product, once it came up at least 3 times. */
	public static function top_topic( int $product_id ): string {
		$counts = array_filter( (array) get_post_meta( $product_id, '_aisr_topics', true ) );
		if ( ! $counts ) {
			return '';
		}
		arsort( $counts );
		$key = (string) array_key_first( $counts );
		return ( $counts[ $key ] >= 3 && isset( self::TOPICS[ $key ] ) ) ? self::TOPICS[ $key ][0] : '';
	}

	/**
	 * The nearest occasion from settings within 30 days ("name | YYYY-MM-DD | category or words"),
	 * with up to 6 store products for it (price, ready or made to order). Cached for an hour.
	 */
	public static function upcoming_occasion(): ?array {
		$raw = trim( (string) AISR_Settings::get( 'occasions' ) );
		if ( '' === $raw ) {
			return null;
		}
		$today = new DateTime( 'today', wp_timezone() );
		$best  = null;
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line ) );
			if ( count( $p ) < 3 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $p[1] ) ) {
				continue;
			}
			$d    = new DateTime( $p[1], wp_timezone() );
			$days = (int) $today->diff( $d )->format( '%r%a' );
			if ( $days >= -7 && $days <= 30 && ( ! $best || $days < $best['days'] ) ) { // Until a week into it (Hanukkah lasts 8 days).
				$best = array( 'name' => sanitize_text_field( $p[0] ), 'date' => $p[1], 'days' => $days, 'match' => sanitize_text_field( $p[2] ) );
			}
		}
		if ( ! $best ) {
			return null;
		}
		$key    = 'aisr_occ_' . md5( wp_json_encode( $best ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$ids  = array();
		$term = get_term_by( 'name', $best['match'], 'product_cat' );
		if ( $term ) {
			$ids = wc_get_products( array( 'status' => 'publish', 'limit' => 6, 'category' => array( $term->slug ), 'return' => 'ids', 'orderby' => 'popularity' ) );
		}
		if ( ! $ids ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', $best['match'] ) ) ) as $w ) {
				$ids = array_merge( $ids, wc_get_products( array( 'status' => 'publish', 'limit' => 3, 's' => $w, 'return' => 'ids' ) ) );
			}
		}
		$items = array();
		foreach ( array_slice( array_unique( $ids ), 0, 6 ) as $id ) {
			$pr = wc_get_product( $id );
			if ( $pr && $pr->is_in_stock() ) {
				$items[] = array( 'id' => $id, 'name' => $pr->get_name(), 'price' => (float) $pr->get_price(), 'made_to_order' => 'onbackorder' === $pr->get_stock_status() );
			}
		}
		$best['products'] = $items;
		set_transient( $key, $best, HOUR_IN_SECONDS );
		return $best;
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
				if ( ! empty( $d['workshop'] ) ) {
					$lines[] = 'This is a WORKSHOP page (an experience in the studio, not a shipped item). Follow the workshop rules. Holiday order-by dates and stock do not apply to it. Call get_product for this id before you describe it or quote a price.';
				}
			}
		}
		$ws_page = ! $pid && ( ! empty( $page['workshop'] ) || preg_match( '/סדנ|workshop/ui', ( $page['category'] ?? '' ) . ' ' . ( $page['title'] ?? '' ) ) );
		if ( $ws_page ) {
			$lines[] = 'The visitor is browsing the WORKSHOPS pages: they are interested in a workshop with Mai, not in buying items. Follow the workshop rules (search_products "סדנ" to see the workshops). Do not recommend cups, sets or other items unless they ask for them.';
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
		if ( ! empty( $page['order_items'] ) ) {
			$lines[] = 'Order just placed (thank-you page): ' . sanitize_text_field( $page['order_items'] );
		}
		if ( ! empty( $page['category'] ) ) {
			$lines[] = 'Category: ' . sanitize_text_field( $page['category'] );
		}
		$cart = self::cart_summary();
		if ( $cart ) {
			$lines[] = 'Cart: ' . $cart;
		}
		$min = self::free_shipping_min();
		if ( $min && function_exists( 'WC' ) && WC()->cart ) {
			$sub = (float) WC()->cart->get_displayed_subtotal();
			$gap = $min - $sub;
			$lines[] = $gap > 0
				? 'Free shipping from ' . $min . '. Cart subtotal ' . $sub . ', missing ' . ceil( $gap ) . '.'
				: 'Free shipping from ' . $min . ' (this cart already qualifies).';
		}
		$occ = self::upcoming_occasion();
		if ( $occ && $occ['products'] ) {
			$lines[] = 'Upcoming occasion: ' . $occ['name'] . ' (' . $occ['date'] . ', ' . ( $occ['days'] >= 0 ? 'in ' . $occ['days'] . ' days' : 'started ' . abs( $occ['days'] ) . ' days ago' ) . '). Store products for it: ' . wp_json_encode( $occ['products'], JSON_UNESCAPED_UNICODE );
		}
		$h = self::holiday();
		if ( $h ) {
			$s       = AISR_Settings::all();
			$lines[] = sprintf(
				'Upcoming holiday: %s on %s, %d business days left. In-stock items (%d business days): %s. Made-to-order items (about %d business days): %s.',
				$h['name'],
				$h['date'],
				$h['daysLeft'],
				$s['ready_days'],
				$h['readyOk'] ? 'arrive in time' : 'may not arrive in time, suggest pickup',
				$s['made_days'],
				$h['madeOk'] ? 'arrive in time' : 'will NOT arrive in time'
			);
			if ( $h['deadline'] ) {
				$lines[] = 'Order-by date for in-stock items to arrive before ' . $h['name'] . ': ' . $h['deadlineText'] . ' (' . $h['deadline'] . ', today is ' . current_datetime()->format( 'Y-m-d' ) . '). Say this date in Hebrew exactly as written ("' . $h['deadlineText'] . '").';
			}
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

	/** Price as the shopper sees it, without the screen-reader text WooCommerce adds to sale prices. */
	public static function plain_price( WC_Product $p ): string {
		$html = preg_replace( '#<span[^>]*class="[^"]*screen-reader-text[^"]*"[^>]*>.*?</span>#s', '', (string) $p->get_price_html() );
		$html = preg_replace( '#<del[^>]*>(.*?)</del>\s*<ins[^>]*>(.*?)</ins>#s', '$2 (במקום $1)', (string) $html );
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $html ) ) ) );
	}

	/**
	 * Can this product go into the cart without choices on its page? Simple, purchasable and in stock is not
	 * enough: product-option plugins (e.g. Advanced Product Fields) block adding it without their required fields.
	 * Runs WooCommerce's own add-to-cart validation with no fields posted, and caches the answer for a day.
	 */
	public static function quick_addable( WC_Product $p ): bool {
		if ( ! $p->is_type( 'simple' ) || ! $p->is_purchasable() || ! $p->is_in_stock() ) {
			return false;
		}
		$key    = 'aisr_qa_' . $p->get_id();
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}
		$ok = true;
		if ( function_exists( 'wc_notice_count' ) && function_exists( 'WC' ) && WC()->session ) {
			$before = wc_get_notices();
			$ok     = (bool) apply_filters( 'woocommerce_add_to_cart_validation', true, $p->get_id(), 1 );
			wc_set_notices( $before ); // Validation may add 'please choose…' notices: drop them, this was only a check.
		}
		set_transient( $key, $ok ? 'yes' : 'no', DAY_IN_SECONDS );
		return $ok;
	}

	/**
	 * The choices a product-options plugin (Advanced Product Fields) asks for on the product page, e.g. "pick 3 cups".
	 * Read from the fields the plugin prints before the add-to-cart button; cached until the product changes.
	 * Each field: id, label, type, required, min, max, and choices (value + label) when it is a pick list.
	 */
	public static function product_options( WC_Product $p ): array {
		$mod = $p->get_date_modified();
		$key = 'aisr_opts_' . $p->get_id() . '_' . ( $mod ? $mod->getTimestamp() : 0 );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$html = '';
		if ( has_action( 'woocommerce_before_add_to_cart_button' ) ) {
			global $product, $post;
			$keep_product = $product;
			$keep_post    = $post;
			$product      = $p; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			$post         = get_post( $p->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			setup_postdata( $post );
			ob_start();
			try {
				do_action( 'woocommerce_before_add_to_cart_button' );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$html    = (string) ob_get_clean();
			$product = $keep_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			$post    = $keep_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			if ( $post ) {
				setup_postdata( $post );
			} else {
				wp_reset_postdata();
			}
		}
		if ( false === strpos( $html, 'wapf-field-container' ) ) {
			$res  = wp_remote_get( get_permalink( $p->get_id() ), array( 'timeout' => 8, 'user-agent' => 'Mozilla/5.0 (AISR options)' ) );
			$html = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
		}
		$out = array( 'groups' => '', 'fields' => array() );
		if ( preg_match( '/name="wapf_field_groups"[^>]*value="([^"]*)"|value="([^"]*)"[^>]*name="wapf_field_groups"/', $html, $g ) ) {
			$out['groups'] = sanitize_text_field( $g[1] ?: $g[2] );
		}
		$parts = preg_split( '/<div class="wapf-field-container /', $html );
		array_shift( $parts );
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^wapf-field-([a-z0-9-]+)\s+field-([a-z0-9]+)/', $part, $m ) ) {
				continue;
			}
			$type  = $m[1];
			$label = preg_match( '/wapf-field-label"><label><span>(.*?)<\/span>/s', $part, $l ) ? trim( html_entity_decode( wp_strip_all_tags( $l[1] ) ), " :\t\n" ) : '';
			$field = array(
				'id'       => $m[2],
				'label'    => $label,
				'type'     => $type,
				'required' => (bool) preg_match( '/<abbr class="required"/', substr( $part, 0, 600 ) ),
			);
			if ( preg_match( '/data-minc="(\d+)"/', $part, $mn ) ) {
				$field['min'] = (int) $mn[1];
			}
			if ( preg_match( '/data-maxc="(\d+)"/', $part, $mx ) ) {
				$field['max'] = (int) $mx[1];
			}
			preg_match_all( '/value="([^"]+)"[^>]*data-wapf-label="([^"]*)"/', $part, $cm, PREG_SET_ORDER );
			$choices = array();
			foreach ( $cm as $c ) {
				$choices[ $c[1] ] = array( 'value' => $c[1], 'label' => html_entity_decode( $c[2], ENT_QUOTES ) );
			}
			if ( $choices ) {
				$field['choices'] = array_values( $choices );
				$field['multi']   = false !== strpos( $type, 'multi' ) || false !== strpos( $type, 'checkbox' );
			}
			$out['fields'][] = $field;
		}
		set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	public static function product_card( WC_Product $p ): array {
		$img = wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' );
		return array(
			'id'          => $p->get_id(),
			'name'        => $p->get_name(),
			'price'       => self::plain_price( $p ),
			'image'       => $img ?: wc_placeholder_img_src(),
			'url'         => get_permalink( $p->get_id() ),
			'in_stock'    => $p->is_in_stock(),
			'made_to_order' => 'onbackorder' === $p->get_stock_status(),
			'simple'      => self::quick_addable( $p ), // false -> the card opens the product page to choose options.
			'rating'      => (float) $p->get_average_rating(),
			'workshop'    => self::is_workshop( $p->get_id() ),
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
			'made_to_order' => 'onbackorder' === $p->get_stock_status(),
			'stock_qty'     => $p->get_stock_quantity(),
			'type'          => $p->get_type(),
			'categories'    => wp_list_pluck( get_the_terms( $p->get_id(), 'product_cat' ) ?: array(), 'name' ),
			'short'         => self::clean_text( $p->get_short_description(), 400 ),
			'rating'        => (float) $p->get_average_rating(),
			'review_count'  => $p->get_review_count(),
			'url'           => urldecode( (string) get_permalink( $p->get_id() ) ),
		);
		if ( self::is_workshop( $p->get_id() ) ) {
			$d['workshop'] = true; // An experience in the studio: the date is set with the owner on WhatsApp.
			unset( $d['in_stock'], $d['made_to_order'], $d['stock_qty'] );
		}
		$sold = AISR_Growth::sales_30d()[ $p->get_id() ] ?? 0;
		if ( $sold ) {
			$d['sold_30d'] = $sold; // Real units from paid orders, for honest social proof.
		}
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
		$owner = AISR_Learning::for_product( $p->get_id() );
		if ( $owner ) {
			$d['owner_answers'] = $owner; // Verified by the store owner for this product.
		}
		if ( $p->get_weight() ) {
			$d['weight'] = $p->get_weight() . ' ' . get_option( 'woocommerce_weight_unit' );
		}
		if ( $full ) {
			$d['description'] = self::clean_text( $p->get_description(), 2500 );
			if ( $p->is_type( 'simple' ) && ! self::quick_addable( $p ) ) {
				$opts = self::product_options( $p );
				if ( $opts['fields'] ) {
					$d['options'] = $opts['fields']; // Choices the shopper makes on the product page; add_to_cart takes them too.
				}
			}
			if ( $p->is_type( 'variable' ) ) {
				$vars = array();
				foreach ( array_slice( $p->get_available_variations(), 0, 30 ) as $v ) {
					$vars[] = array(
						'variation_id' => $v['variation_id'],
						'attributes'   => $v['attributes'],
						'price'        => $v['display_price'],
						'in_stock'     => (bool) $v['is_in_stock'],
						'made_to_order' => 'onbackorder' === ( wc_get_product( $v['variation_id'] ) ?: $p )->get_stock_status(),
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
			// Purchasable now: in stock, or made to order (backorders allowed).
			$query['stock_status'] = array( 'instock', 'onbackorder' );
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
