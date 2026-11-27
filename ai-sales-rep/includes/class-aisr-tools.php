<?php
/**
 * Tools the model can call. The server validates everything: the model can suggest,
 * but only these functions decide on coupons, order data and stored leads.
 */

defined( 'ABSPATH' ) || exit;

class AISR_Tools {

	/** Collected UI output for the current reply. */
	public array $cards   = array();
	public array $replies = array();
	public array $actions = array();
	private bool $reviews_shown = false;

	private array $conv;
	private array $page;

	public function __construct( array $conv, array $page ) {
		$this->conv = $conv;
		$this->page = $page;
	}

	public static function coupon_reason_labels(): array {
		return array(
			'exit_intent'     => 'הגולש עומד לעזוב',
			'cart_hesitation' => 'הגולש מתלבט עם סל מלא',
			'price_objection' => 'הגולש אומר שהמחיר יקר',
			'price_match'     => 'הגולש מוסר מחיר נמוך יותר אצל מתחרה',
		);
	}

	public static function definitions(): array {
		return array(
			array(
				'name'        => 'search_products',
				'description' => 'Search the store catalog. Use before recommending anything. Returns only published products; purchasable only (in stock or made to order) unless in_stock_only is false. made_to_order=true means the item is produced after ordering and takes longer to arrive.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'         => array( 'type' => 'string', 'description' => 'Keywords in the store language' ),
						'category'      => array( 'type' => 'string', 'description' => 'Exact category name from the category list' ),
						'min_price'     => array( 'type' => 'number' ),
						'max_price'     => array( 'type' => 'number' ),
						'in_stock_only' => array( 'type' => 'boolean' ),
						'limit'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 8 ),
					),
				),
			),
			array(
				'name'        => 'get_product',
				'description' => 'Full details of one product: description, attributes, variations with stock, recent reviews, related/upsell/cross-sell ids.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'product_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'product_id' ),
				),
			),
			array(
				'name'        => 'show_options',
				'description' => 'Show a picker in the chat for a product with options (color, size, a variation, or option fields such as choosing cups or a name), with pictures, prices and stock, and your recommendation preselected. The shopper picks and sends their choice back as a message starting with "בחרתי".',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array( 'type' => 'integer' ),
						'preselect'  => array( 'type' => 'object', 'description' => 'Option name => recommended choice label, a list of labels for a multi-choice option (as many as it requires), or text the shopper already gave (e.g. a name)' ),
					),
					'required'   => array( 'product_id' ),
				),
			),
			array(
				'name'        => 'add_to_cart',
				'description' => 'Add one product to the shopper\'s cart from the chat, when they ask you to add it, say yes to adding it, or confirm their choice. Pass the choices in options, keyed by the option name (e.g. "צבע", "בחירת ספלים", "שם"), each value a choice label, a list of labels for a multi-choice option, or the text for a text field. Variations are found from the options. After it is in the cart the chat takes the shopper to checkout, unless go_to_checkout is false.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array( 'type' => 'integer' ),
						'quantity'   => array( 'type' => 'integer' ),
						'options'    => array( 'type' => 'object', 'description' => 'Option name => choice label, list of labels, or text' ),
						'go_to_checkout' => array( 'type' => 'boolean', 'description' => 'Default true. False only if the shopper wants to keep shopping or add more items.' ),
					),
					'required'   => array( 'product_id' ),
				),
			),
			array(
				'name'        => 'show_bundle',
				'description' => 'Build a gift set from 2-4 different products that go together, shown with one button that adds all of them to the cart. Use it whenever the shopper asks for several items or a set. Only simple, purchasable products; check them with search_products/get_product first. Give the set a short title (e.g. "סט מתנה למארחת").',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'       => array( 'type' => 'string' ),
						'product_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'minItems' => 2, 'maxItems' => 4 ),
					),
					'required'   => array( 'title', 'product_ids' ),
				),
			),
			array(
				'name'        => 'show_reviews',
				'description' => 'Show a card with 1-2 real customer reviews from this store (quoted word for word, first name only) and the store rating on Google. Use it once per conversation when the shopper hesitates, asks about quality or whether the studio can be trusted, compares with another shop, says it is expensive, or the conversation opened because they copied the product name or were about to leave. Pass the product they are looking at; reviews of similar products are used when it has none. Do not quote or paraphrase reviews in your own text: the card shows them.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'product_id' => array( 'type' => 'integer' ) ),
				),
			),
			array(
				'name'        => 'set_gift_message',
				'description' => 'Save the gift greeting the shopper approved; it goes into their order automatically for the studio. Write the greeting with them first and show it; call this only after they say it is good, and before add_to_cart.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'message' => array( 'type' => 'string' ) ),
					'required'   => array( 'message' ),
				),
			),
			array(
				'name'        => 'join_waitlist',
				'description' => 'Add the shopper to the back-in-stock list for an out-of-stock product (or a workshop with no open date), so the store messages them when it is available. Only with their explicit consent and their phone number.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array( 'type' => 'integer' ),
						'name'       => array( 'type' => 'string' ),
						'phone'      => array( 'type' => 'string' ),
						'consent'    => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'product_id', 'phone', 'consent' ),
				),
			),
			array(
				'name'        => 'remind_me',
				'description' => 'Schedule a reminder before an occasion (a holiday, a birthday) with the products the shopper looked at. The store sends it on that date. Only with explicit consent and a phone or email. date is YYYY-MM-DD, about a week before the occasion.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'date'        => array( 'type' => 'string' ),
						'occasion'    => array( 'type' => 'string' ),
						'name'        => array( 'type' => 'string' ),
						'phone'       => array( 'type' => 'string' ),
						'email'       => array( 'type' => 'string' ),
						'product_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'consent'     => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'date', 'occasion', 'consent' ),
				),
			),
			array(
				'name'        => 'show_products',
				'description' => 'Show 1-3 product cards in the chat, with image, price and an add-to-cart button. Use when recommending or comparing products.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'maxItems' => 3 ),
					),
					'required'   => array( 'product_ids' ),
				),
			),
			array(
				'name'        => 'suggest_replies',
				'description' => 'Show 2-4 quick-reply buttons under your message, so the visitor can answer with one tap. Use with every discovery question.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'options' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'minItems' => 2, 'maxItems' => 4 ),
					),
					'required'   => array( 'options' ),
				),
			),
			array(
				'name'        => 'record_insight',
				'description' => 'Save what the visitor told you about their need (for the store owner statistics). Call once you learn any of these.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'use_case'          => array( 'type' => 'string', 'description' => 'What the product is for, in a few words' ),
						'recipient'         => array( 'type' => 'string', 'enum' => array( 'self', 'gift' ) ),
						'priority'          => array( 'type' => 'string', 'enum' => array( 'price', 'quality', 'design', 'fast_shipping', 'other' ) ),
						'budget_max'        => array( 'type' => 'number' ),
						'competitor'        => array( 'type' => 'string', 'description' => 'Competitor the visitor mentioned, if any' ),
						'competitor_price'  => array( 'type' => 'number' ),
						'buying_mode'       => array( 'type' => 'string', 'enum' => array( 'heart', 'head', 'mixed' ), 'description' => 'What leads the shopper right now: heart (excited, a gift for someone close, an occasion), head (sizes, dishwasher, price, delivery, bulk) or mixed' ),
						'job'               => array( 'type' => 'string', 'description' => 'The job the purchase does, in a few Hebrew words: the moment, how they want to feel or be seen (e.g. "יום נישואין, לרגש אותו")' ),
						'closeness'         => array( 'type' => 'string', 'enum' => array( 'close', 'distant', 'business' ), 'description' => 'Only for gifts: how close the recipient is' ),
					),
				),
			),
			array(
				'name'        => 'offer_coupon',
				'description' => 'Ask the store for a one-time discount code. The store rules decide if one is allowed and how much. Never promise a discount before this returns allowed=true.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'reason'           => array( 'type' => 'string', 'enum' => array_keys( self::coupon_reason_labels() ) ),
						'competitor_price' => array( 'type' => 'number', 'description' => 'Only for price_match: the total price the visitor saw elsewhere' ),
					),
					'required'   => array( 'reason' ),
				),
			),
			array(
				'name'        => 'track_order',
				'description' => 'Order status. Requires the order number AND the email or phone used in the order. Never reveal anything if they do not match.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_number'   => array( 'type' => 'string' ),
						'email_or_phone' => array( 'type' => 'string' ),
					),
					'required'   => array( 'order_number', 'email_or_phone' ),
				),
			),
			array(
				'name'        => 'request_human',
				'description' => 'Hand the conversation to a human at the store. Use when asked for a person, for complaints, exceptions you cannot decide, or when you cannot answer.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'summary' => array( 'type' => 'string', 'description' => 'The WhatsApp message the shopper will send, written in their language and in first person as the shopper (e.g. "היי, אני מתעניינת בספל שם אישי עם ידית למתנה ליום הולדת..."): what they want, which product. One or two sentences.' ),
					),
					'required'   => array( 'summary' ),
				),
			),
			array(
				'name'        => 'save_lead',
				'description' => 'Save contact details the visitor explicitly gave you so the store can get back to them. Ask for consent first. marketing_consent is true only if they explicitly agreed to receive reminders/offers.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'              => array( 'type' => 'string' ),
						'phone'             => array( 'type' => 'string' ),
						'email'             => array( 'type' => 'string' ),
						'marketing_consent' => array( 'type' => 'boolean' ),
						'note'              => array( 'type' => 'string' ),
					),
					'required'   => array( 'marketing_consent' ),
				),
			),
			array(
				'name'        => 'flag_unanswered',
				'description' => 'Log a visitor question you could not answer from the store information, so the owner can add it.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'question' => array( 'type' => 'string' ) ),
					'required'   => array( 'question' ),
				),
			),
		);
	}

	/** Run a tool; always returns a string for the tool_result. */
	public function run( string $name, array $input ): string {
		try {
			$result = match ( $name ) {
				'search_products' => array( 'products' => AISR_Knowledge::search( $input ) ),
				'get_product'     => AISR_Knowledge::product_details( (int) ( $input['product_id'] ?? 0 ), true ) ?? array( 'error' => 'not_found' ),
				'show_products'   => $this->show_products( $input ),
				'show_bundle'     => $this->show_bundle( $input ),
				'show_reviews'    => $this->show_reviews( $input ),
				'set_gift_message' => $this->set_gift_message( $input ),
				'join_waitlist'   => empty( $input['consent'] ) ? array( 'error' => 'consent_required' ) : array( 'saved' => AISR_Growth::join_waitlist( (int) ( $input['product_id'] ?? 0 ), (string) ( $input['name'] ?? '' ), (string) ( $input['phone'] ?? '' ) ) ),
				'remind_me'       => empty( $input['consent'] ) ? array( 'error' => 'consent_required' ) : array( 'saved' => AISR_Growth::add_reminder( (string) ( $input['date'] ?? '' ), (string) ( $input['occasion'] ?? '' ), (string) ( $input['name'] ?? '' ), (string) ( $input['phone'] ?? '' ), (string) ( $input['email'] ?? '' ), (array) ( $input['product_ids'] ?? array() ) ) ),
				'suggest_replies' => $this->suggest_replies( $input ),
				'record_insight'  => $this->record_insight( $input ),
				'add_to_cart'     => $this->add_to_cart( $input ),
				'show_options'    => $this->show_options( $input ),
				'offer_coupon'    => $this->offer_coupon( $input ),
				'track_order'     => $this->track_order( $input ),
				'request_human'   => $this->request_human( $input ),
				'save_lead'       => $this->save_lead( $input ),
				'flag_unanswered' => $this->flag_unanswered( $input ),
				default           => array( 'error' => 'unknown_tool' ),
			};
		} catch ( \Throwable $e ) {
			$result = array( 'error' => 'tool_failed' );
		}
		return (string) wp_json_encode( $result, JSON_UNESCAPED_UNICODE );
	}

	private function show_products( array $in ): array {
		$shown = array();
		foreach ( array_slice( array_map( 'absint', (array) ( $in['product_ids'] ?? array() ) ), 0, 3 ) as $id ) {
			$p = wc_get_product( $id );
			if ( $p && 'publish' === $p->get_status() ) {
				$this->cards[] = AISR_Knowledge::product_card( $p );
				$shown[]       = $id;
			}
		}
		return array( 'shown' => $shown );
	}

	/** A set the shopper can add in one tap: only simple, purchasable products. */
	private function show_bundle( array $in ): array {
		$items = array();
		$total = 0.0;
		foreach ( array_slice( array_map( 'absint', (array) ( $in['product_ids'] ?? array() ) ), 0, 4 ) as $id ) {
			$p = wc_get_product( $id );
			// Items that need a choice on their page (a color, a name) can be in the set: the button adds the rest.
			if ( $p && 'publish' === $p->get_status() && $p->is_purchasable() && $p->is_in_stock() ) {
				$c        = AISR_Knowledge::product_card( $p );
				$items[]  = $c;
				$total   += (float) wc_get_price_to_display( $p );
			}
		}
		if ( count( $items ) < 2 || ! array_filter( array_column( $items, 'simple' ) ) ) {
			return array( 'error' => 'need_two_products_that_can_be_added_without_choosing_options', 'hint' => 'Some products need a choice on their page (color, name). Pick other products, or show them with show_products.' );
		}
		$this->actions[] = array(
			'type'  => 'bundle',
			'title' => sanitize_text_field( (string) ( $in['title'] ?? 'סט' ) ),
			'items' => $items,
			'total' => html_entity_decode( wp_strip_all_tags( wc_price( $total ) ) ),
		);
		$choose = array_column( array_filter( $items, static fn( $c ) => empty( $c['simple'] ) ), 'name' );
		return array( 'shown' => array_column( $items, 'id' ), 'total' => $total, 'needs_choice_on_product_page' => $choose );
	}

	/** Real reviews from the store (same product, then same category, then any), plus the Google rating. */
	private function show_reviews( array $in ): array {
		if ( $this->reviews_shown ) {
			return array( 'error' => 'already_shown' );
		}
		$pid   = absint( $in['product_id'] ?? 0 );
		$p     = $pid ? wc_get_product( $pid ) : null;
		$pools = array();
		if ( $p ) {
			$pools[] = array( 'post_id' => $p->get_parent_id() ?: $pid );
			$cats    = $p->get_category_ids();
			if ( $cats ) {
				$ids = wc_get_products( array( 'status' => 'publish', 'limit' => 60, 'return' => 'ids', 'category' => array_map( static fn( $c ) => get_term( $c )->slug ?? '', $cats ) ) );
				if ( $ids ) {
					$pools[] = array( 'post__in' => $ids );
				}
			}
		}
		$pools[] = array( 'post_type' => 'product' );
		$picked  = array();
		foreach ( $pools as $q ) {
			$found = get_comments( $q + array( 'status' => 'approve', 'type' => 'review', 'number' => 40 ) );
			foreach ( $found as $c ) {
				$rating = (int) get_comment_meta( $c->comment_ID, 'rating', true );
				$text   = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $c->comment_content, ENT_QUOTES, 'UTF-8' ) ) ) );
				$len    = mb_strlen( $text );
				if ( $rating < 5 || $len < 25 || isset( $picked[ $c->comment_ID ] ) ) {
					continue;
				}
				if ( $len > 220 ) {
					$cut  = mb_substr( $text, 0, 220 );
					$stop = max( mb_strrpos( $cut, '.' ) ?: 0, mb_strrpos( $cut, '!' ) ?: 0 );
					$text = ( $stop > 60 ? mb_substr( $cut, 0, $stop + 1 ) : mb_substr( $cut, 0, mb_strrpos( $cut, ' ' ) ?: 220 ) ) . '…';
				}
				$first                     = preg_split( '/\s+/u', trim( (string) $c->comment_author ) )[0] ?? '';
				$picked[ $c->comment_ID ] = array(
					'name'    => sanitize_text_field( $first ),
					'rating'  => $rating,
					'text'    => $text,
					'product' => html_entity_decode( get_the_title( (int) $c->comment_post_ID ), ENT_QUOTES, 'UTF-8' ),
					'score'   => abs( $len - 110 ),
				);
			}
			if ( count( $picked ) >= 2 ) {
				break;
			}
		}
		uasort( $picked, static fn( $a, $b ) => $a['score'] <=> $b['score'] );
		$items  = array_map( static function ( $r ) {
			unset( $r['score'] );
			return $r;
		}, array_slice( array_values( $picked ), 0, 2 ) );
		$g_rate = (string) AISR_Settings::get( 'google_rating' );
		$google = '' !== trim( $g_rate ) ? array(
			'rating' => sanitize_text_field( $g_rate ),
			'count'  => sanitize_text_field( (string) AISR_Settings::get( 'google_count' ) ),
			'url'    => esc_url_raw( (string) AISR_Settings::get( 'google_url' ) ),
		) : null;
		if ( ! $items && ! $google ) {
			return array( 'error' => 'no_reviews' );
		}
		$this->reviews_shown = true;
		$this->actions[]     = array( 'type' => 'reviews', 'items' => $items, 'google' => $google );
		return array( 'shown' => count( $items ), 'google' => (bool) $google, 'note' => 'The card is shown right after your first paragraph. Make the first paragraph a short lead-in (e.g. "הנה מה שלקוחות כתבו:"). Do not quote the reviews or say the card is above.' );
	}

	private function suggest_replies( array $in ): array {
		$opts          = array_slice( array_filter( array_map( 'sanitize_text_field', (array) ( $in['options'] ?? array() ) ) ), 0, 4 );
		$this->replies = array_values( $opts );
		return array( 'shown' => count( $this->replies ) );
	}

	/** The approved greeting goes with the order; the chat shows it so the shopper knows it is saved. */
	private function set_gift_message( array $in ): array {
		$msg = AISR_Growth::set_gift_message( (string) ( $in['message'] ?? '' ), (string) ( $this->conv['uuid'] ?? '' ) );
		if ( '' === $msg ) {
			return array( 'saved' => false );
		}
		$this->actions[] = array( 'type' => 'gift', 'text' => $msg );
		return array( 'saved' => true, 'note' => 'Saved: it goes into the order automatically, as a note the studio sees with the order. A card with the greeting is shown under your message, so do not repeat the greeting text.' );
	}

	/** Queue an add-to-cart for the widget, after checking the product and every required choice. */
	private function add_to_cart( array $in ): array {
		$p = wc_get_product( absint( $in['product_id'] ?? 0 ) );
		if ( ! $p ) {
			return array( 'error' => 'not_found' );
		}
		$base = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
		if ( ! $base || 'publish' !== $base->get_status() ) {
			return array( 'error' => 'not_found' );
		}
		if ( AISR_Knowledge::is_workshop( $base->get_id() ) ) {
			return array( 'error' => 'workshop', 'note' => 'A workshop date is set with Mai on WhatsApp: use request_human.' );
		}
		$qty    = max( 1, min( 20, (int) ( $in['quantity'] ?? 1 ) ) );
		$given  = (array) ( $in['options'] ?? array() );
		$fields = array();
		$shown  = array();
		$norm   = array( 'AISR_Knowledge', 'norm' );
		$pick   = static function ( string $label, string $raw = '' ) use ( &$given, $norm ) {
			foreach ( $given as $k => $v ) {
				$nk = $norm( $k );
				if ( '' !== $nk && ( $nk === $norm( $label ) || $k === $raw || false !== mb_strpos( $norm( $label ), $nk ) ) ) {
					unset( $given[ $k ] );
					return $v;
				}
			}
			return null;
		};
		if ( $p->is_type( 'variable' ) ) {
			$want = array();
			foreach ( AISR_Knowledge::variation_attrs( $p ) as $at ) {
				$val = $pick( $at['label'], $at['key'] );
				$val = is_array( $val ) ? reset( $val ) : $val;
				$hit = null === $val ? null : AISR_Knowledge::match_choice( $val, $at['options'] );
				if ( ! $hit ) {
					return array( 'error' => null === $val ? 'missing_options' : 'invalid_choice', 'option' => $at['label'], 'choices' => wp_list_pluck( $at['options'], 'label' ), 'note' => 'Ask the shopper, or call show_options so they can pick in the chat.' );
				}
				$want[ $at['key'] ] = $hit['value'];
				$shown[]            = $at['label'] . ': ' . $hit['label'];
			}
			$vid = ( new WC_Product_Data_Store_CPT() )->find_matching_product_variation( $p, $want );
			$vp  = $vid ? wc_get_product( $vid ) : null;
			if ( ! $vp ) {
				return array( 'error' => 'unavailable_combination', 'chosen' => $shown );
			}
			$p = $vp;
		}
		if ( ! $p->is_purchasable() || ! $p->is_in_stock() ) {
			return array( 'error' => 'unavailable', 'chosen' => $shown, 'note' => 'This choice is out of stock: suggest another one (show_options) or the waitlist.' );
		}
		$opts   = ! $base->is_type( 'simple' ) || $given || ! AISR_Knowledge::quick_addable( $base ) ? AISR_Knowledge::product_options( $base ) : array( 'groups' => '', 'fields' => array() );
		foreach ( $opts['fields'] as $f ) {
			$val = $pick( $f['label'], $f['id'] );
			if ( ! empty( $f['choices'] ) ) {
				$picked = array();
				foreach ( array_filter( array_map( 'strval', (array) $val ), 'strlen' ) as $want ) {
					$hit = AISR_Knowledge::match_choice( $want, $f['choices'] );
					if ( ! $hit ) {
						return array( 'error' => 'invalid_choice', 'option' => $f['label'], 'given' => $want, 'choices' => wp_list_pluck( $f['choices'], 'label' ) );
					}
					$picked[ $hit['value'] ] = $hit['label'];
				}
				$min = $f['min'] ?? ( $f['required'] ? 1 : 0 );
				$max = $f['max'] ?? ( empty( $f['multi'] ) ? 1 : count( $f['choices'] ) );
				if ( count( $picked ) < $min || count( $picked ) > $max ) {
					return array( 'error' => 'missing_options', 'option' => $f['label'], 'need' => $min === $max ? "exactly $min different choices" : "between $min and $max choices", 'choices' => wp_list_pluck( $f['choices'], 'label' ), 'note' => 'Recommend choices that fit what the shopper said, confirm with them, then call add_to_cart again.' );
				}
				if ( $picked ) {
					$fields[ $f['id'] ] = array_keys( $picked );
					$shown[]            = implode( ', ', $picked );
				}
			} else {
				$text = mb_substr( sanitize_text_field( is_array( $val ) ? implode( ', ', $val ) : (string) $val ), 0, 200 );
				if ( '' === $text && $f['required'] ) {
					return array( 'error' => 'missing_options', 'option' => $f['label'], 'need' => 'text' );
				}
				if ( '' !== $text ) {
					$fields[ $f['id'] ] = $text;
					$shown[]            = $text;
				}
			}
		}
		$this->actions[] = array(
			'type'    => 'add_to_cart',
			'id'      => $p->get_id(),
			'qty'     => $qty,
			'groups'  => $opts['groups'],
			'fields'  => (object) $fields,
			'name'    => $base->get_name(),
			'summary' => implode( ' · ', $shown ),
			'url'     => get_permalink( $base->get_id() ),
			'checkout' => ! isset( $in['go_to_checkout'] ) || ! empty( $in['go_to_checkout'] ),
			'gift'     => '' !== AISR_Growth::pending_gift_message( (string) ( $this->conv['uuid'] ?? '' ) ),
		);
		// One smart add-on under the confirmation: what fits this item, by real signals (see AISR_Addons).
		$cart  = AISR_Addons::cart_state();
		$ids   = $cart['ids'];
		$total = $cart['total'];
		if ( ! in_array( $base->get_id(), $ids, true ) ) {
			$ids[]  = $base->get_id();
			$total += (float) wc_get_price_to_display( $p ) * $qty;
		}
		$addon = AISR_Addons::for_product( $base->get_id(), array( 'cart_ids' => $ids, 'cart_total' => $total, 'main_price' => (float) wc_get_price_to_display( $p ) ), 1 );
		if ( $addon ) {
			$this->actions[ count( $this->actions ) - 1 ]['addon'] = $addon[0];
		}
		$go = end( $this->actions )['checkout'];
		return array( 'queued' => true, 'choices' => $shown, 'note' => 'The chat adds it to the cart now and shows a confirmation under your message' . ( $go ? ', then takes the shopper to checkout' : '' ) . '. Say in one short sentence that you are adding it, with the choices' . ( $go ? ', and that it moves to payment' : '' ) . '. Do not ask another question.' );
	}

	/** A picker in the chat for colors, sizes and option fields, with the recommendation preselected. */
	private function show_options( array $in ): array {
		$p = wc_get_product( absint( $in['product_id'] ?? 0 ) );
		if ( $p && $p->is_type( 'variation' ) ) {
			$p = wc_get_product( $p->get_parent_id() );
		}
		if ( ! $p || 'publish' !== $p->get_status() ) {
			return array( 'error' => 'not_found' );
		}
		if ( AISR_Knowledge::is_workshop( $p->get_id() ) ) {
			return array( 'error' => 'workshop', 'note' => 'A workshop date is set with Mai on WhatsApp: use request_human.' );
		}
		if ( ! $p->is_in_stock() && ! $p->is_type( 'variable' ) ) {
			return array( 'error' => 'unavailable' );
		}
		$sheet = AISR_Knowledge::choice_sheet( $p, (array) ( $in['preselect'] ?? array() ) );
		if ( ! $sheet['attrs'] && ! $sheet['fields'] ) {
			return array( 'error' => 'no_options', 'note' => 'Nothing to choose: call add_to_cart directly when the shopper wants it.' );
		}
		$unmatched = $sheet['unmatched'];
		unset( $sheet['unmatched'] );
		if ( $unmatched ) {
			$names = array();
			foreach ( $sheet['attrs'] as $at ) {
				$names[ $at['label'] ] = wp_list_pluck( $at['options'], 'label' );
			}
			foreach ( $sheet['fields'] as $f ) {
				if ( ! empty( $f['choices'] ) ) {
					$names[ $f['label'] ] = wp_list_pluck( $f['choices'], 'label' );
				}
			}
			return array( 'error' => 'preselect_not_found', 'not_found' => $unmatched, 'choices' => $names, 'note' => 'Call show_options again using these exact choice labels.' );
		}
		$this->actions[] = array( 'type' => 'options', 'sheet' => $sheet );
		return array( 'shown' => true, 'preselected' => count( (array) $sheet['pre'] ), 'note' => 'The picker is under your message, with your recommendation selected. Say in one or two short sentences what you selected and why, and that they can change it and press "זה מה שאני רוצה". Their choice comes back as a message starting with "בחרתי".' );
	}

	private function record_insight( array $in ): array {
		$cur = json_decode( (string) $this->conv['insights'], true ) ?: array();
		foreach ( array( 'use_case', 'recipient', 'priority', 'competitor', 'buying_mode', 'job', 'closeness' ) as $k ) {
			if ( ! empty( $in[ $k ] ) ) {
				$cur[ $k ] = sanitize_text_field( (string) $in[ $k ] );
			}
		}
		foreach ( array( 'budget_max', 'competitor_price' ) as $k ) {
			if ( isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ) {
				$cur[ $k ] = (float) $in[ $k ];
			}
		}
		$this->conv['insights'] = wp_json_encode( $cur, JSON_UNESCAPED_UNICODE );
		AISR_DB::update_conversation( $this->conv['uuid'], array( 'insights' => $this->conv['insights'] ) );
		return array( 'saved' => true );
	}

	private function flag_unanswered( array $in ): array {
		$cur   = json_decode( (string) $this->conv['unanswered'], true ) ?: array();
		$cur[] = AISR_Privacy::mask( sanitize_text_field( (string) ( $in['question'] ?? '' ) ) );
		$this->conv['unanswered'] = wp_json_encode( array_slice( $cur, -10 ), JSON_UNESCAPED_UNICODE );
		AISR_DB::update_conversation( $this->conv['uuid'], array( 'unanswered' => $this->conv['unanswered'] ) );
		return array( 'saved' => true );
	}

	private function offer_coupon( array $in ): array {
		$s      = AISR_Settings::all();
		$reason = (string) ( $in['reason'] ?? '' );

		if ( empty( $s['coupon_enabled'] ) ) {
			return array( 'allowed' => false, 'why' => 'The store does not offer chat discounts. Do not mention discounts.' );
		}
		if ( '' !== $this->conv['coupon_code'] ) {
			return array( 'allowed' => true, 'code' => $this->conv['coupon_code'], 'note' => 'Already issued in this conversation. Remind them of this code.' );
		}
		$reasons = (array) $s['coupon_reasons'];
		if ( 'price_match' === $reason ) {
			if ( empty( $s['price_match'] ) ) {
				return array( 'allowed' => false, 'why' => 'The store does not price-match. Explain the value instead, or offer a human.' );
			}
		} elseif ( ! in_array( $reason, $reasons, true ) ) {
			return array( 'allowed' => false, 'why' => 'Not allowed in this situation. Do not mention discounts.' );
		}

		$subtotal = ( function_exists( 'WC' ) && WC()->cart ) ? (float) WC()->cart->get_subtotal() : 0.0;
		$base     = $subtotal;
		if ( $base <= 0 && ! empty( $this->page['product_id'] ) ) {
			$p    = wc_get_product( (int) $this->page['product_id'] );
			$base = $p ? (float) $p->get_price() : 0.0;
		}
		if ( (float) $s['coupon_min_cart'] > 0 && $base < (float) $s['coupon_min_cart'] ) {
			return array( 'allowed' => false, 'why' => 'Below the minimum order for a discount (' . $s['coupon_min_cart'] . '). You may say discounts apply from that amount.' );
		}

		$pct = (float) $s['coupon_max_pct'];
		if ( 'price_match' === $reason ) {
			$theirs = (float) ( $in['competitor_price'] ?? 0 );
			if ( $theirs <= 0 || $base <= 0 || $theirs >= $base ) {
				return array( 'allowed' => false, 'why' => 'Need the competitor total price, lower than ours, to price-match.' );
			}
			$needed = ceil( ( 1 - $theirs / $base ) * 100 );
			if ( $needed > $pct ) {
				return array( 'allowed' => false, 'why' => 'The gap is larger than the store can match. Offer a human to review it.' );
			}
			$pct = max( 1, $needed );
		}

		$code   = 'aisr-' . strtolower( wp_generate_password( 6, false, false ) );
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( $pct );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_date_expires( time() + (int) $s['coupon_valid_hours'] * HOUR_IN_SECONDS );
		if ( $s['coupon_excluded_cats'] ) {
			$coupon->set_excluded_product_categories( array_map( 'intval', (array) $s['coupon_excluded_cats'] ) );
		}
		if ( (float) $s['coupon_min_cart'] > 0 ) {
			$coupon->set_minimum_amount( (float) $s['coupon_min_cart'] );
		}
		$coupon->set_description( 'AI Sales Rep · ' . $reason . ' · ' . $this->conv['uuid'] );
		$coupon->save();

		$this->conv['coupon_code'] = $code;
		AISR_DB::update_conversation( $this->conv['uuid'], array( 'coupon_code' => $code ) );
		$this->actions[] = array( 'type' => 'coupon', 'code' => $code, 'pct' => $pct );

		return array(
			'allowed'     => true,
			'code'        => $code,
			'percent'     => $pct,
			'valid_hours' => (int) $s['coupon_valid_hours'],
			'note'        => 'A button to apply the code is shown under your message.',
		);
	}

	private function track_order( array $in ): array {
		$number = preg_replace( '/\D/', '', (string) ( $in['order_number'] ?? '' ) );
		$ident  = trim( (string) ( $in['email_or_phone'] ?? '' ) );
		$order  = $number ? wc_get_order( (int) $number ) : null;
		if ( ! $order instanceof WC_Order || '' === $ident ) {
			return array( 'found' => false );
		}
		$digits = static fn( string $v ): string => substr( preg_replace( '/\D/', '', $v ), -9 );
		$match  = ( is_email( $ident ) && 0 === strcasecmp( $ident, (string) $order->get_billing_email() ) )
			|| ( strlen( $digits( $ident ) ) >= 9 && $digits( $ident ) === $digits( (string) $order->get_billing_phone() ) );
		if ( ! $match ) {
			return array( 'found' => false );
		}
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name() . ' x' . $item->get_quantity();
		}
		return array(
			'found'      => true,
			'status'     => wc_get_order_status_name( $order->get_status() ),
			'date'       => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y' ) : '',
			'items'      => $items,
			'shipping'   => $order->get_shipping_method(),
			'tracking'   => (string) $order->get_meta( '_tracking_number' ),
		);
	}

	private function request_human( array $in ): array {
		$s       = AISR_Settings::all();
		$summary = sanitize_text_field( (string) ( $in['summary'] ?? '' ) );
		$open    = AISR_Settings::is_open_now();
		AISR_DB::update_conversation( $this->conv['uuid'], array( 'handoff' => 1 ) );

		if ( 'whatsapp' === $s['handoff_channel'] && $s['handoff_whatsapp'] ) {
			$url             = 'https://wa.me/' . preg_replace( '/\D/', '', $s['handoff_whatsapp'] ) . '?text=' . rawurlencode( $summary );
			$ws              = AISR_Knowledge::is_workshop( (int) ( $this->page['product_id'] ?? 0 ) ) || preg_match( '/סדנ/u', $summary );
			$this->actions[] = array( 'type' => 'whatsapp', 'url' => $url, 'label' => $ws ? 'לתאם מועד עם מאי בוואטסאפ' : 'המשך בוואטסאפ' );
			return array(
				'channel'    => 'whatsapp',
				'open_now'   => $open,
				'hours'      => $s['hours_start'] . '-' . $s['hours_end'],
				'note'       => 'Nothing was sent yet. A WhatsApp button with a ready message is shown; the shopper must tap it to send it. Do not say you forwarded or sent anything; tell them to tap the button. ' . ( $open ? '' : 'The team is offline now; say when they will reply.' ),
			);
		}

		if ( 'email' === $s['handoff_channel'] && $s['handoff_email'] ) {
			wp_mail( $s['handoff_email'], 'בקשה לנציג מהצ\'אט באתר', $summary . "\n\n" . admin_url( 'admin.php?page=aisr-dashboard' ) );
		}
		return array(
			'channel'  => 'lead',
			'open_now' => $open,
			'hours'    => $s['hours_start'] . '-' . $s['hours_end'],
			'note'     => 'Ask for name and phone or email (with consent) and call save_lead, so the team can get back to them.',
		);
	}

	private function save_lead( array $in ): array {
		$s     = AISR_Settings::all();
		$email = sanitize_email( (string) ( $in['email'] ?? '' ) );
		$phone = preg_replace( '/[^\d+]/', '', (string) ( $in['phone'] ?? '' ) );
		if ( '' === $email && strlen( (string) $phone ) < 9 ) {
			return array( 'saved' => false, 'why' => 'Need a valid phone or email.' );
		}
		$cart = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$cart[] = array( 'product_id' => (int) $item['product_id'], 'variation_id' => (int) $item['variation_id'], 'qty' => (int) $item['quantity'] );
			}
		}
		$data = array(
			'lead_name'      => sanitize_text_field( (string) ( $in['name'] ?? '' ) ),
			'lead_phone'     => (string) $phone,
			'lead_email'     => $email,
			'lead_marketing' => empty( $in['marketing_consent'] ) ? 0 : 1,
			'lead_cart'      => wp_json_encode( $cart ),
		);
		AISR_DB::update_conversation( $this->conv['uuid'], $data );

		if ( $s['handoff_email'] ) {
			$body = sprintf(
				"ליד חדש מהצ'אט:\nשם: %s\nטלפון: %s\nמייל: %s\nהערה: %s\nאישור דיוור: %s\n\n%s",
				$data['lead_name'],
				$data['lead_phone'],
				$data['lead_email'],
				sanitize_text_field( (string) ( $in['note'] ?? '' ) ),
				$data['lead_marketing'] ? 'כן' : 'לא',
				admin_url( 'admin.php?page=aisr-dashboard' )
			);
			wp_mail( $s['handoff_email'], 'ליד חדש מנציג ה-AI', $body );
		}
		return array( 'saved' => true );
	}
}
