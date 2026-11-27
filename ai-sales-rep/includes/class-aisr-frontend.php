<?php
/**
 * Loads the chat widget and hands it the page context and the opening messages.
 * Opening messages are templates (no AI call), so a proactive message costs nothing.
 */

defined( 'ABSPATH' ) || exit;

class AISR_Frontend {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function page_info(): array {
		$type = 'other';
		$pid  = 0;
		$order_items = '';
		$category    = '';
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			$type = 'thankyou';
			$oid  = absint( get_query_var( 'order-received' ) );
			$o    = $oid ? wc_get_order( $oid ) : null;
			// Only with the order key from the URL, so the page never reveals someone else's order.
			if ( $o && isset( $_GET['key'] ) && hash_equals( $o->get_order_key(), sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$order_items = implode( ', ', array_map( static fn( $i ) => $i->get_name(), array_values( $o->get_items() ) ) );
			}
		} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$type = 'checkout';
		} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
			$type = 'cart';
		} elseif ( function_exists( 'is_product' ) && is_product() ) {
			$type = 'product';
			$pid  = (int) get_queried_object_id();
		} elseif ( function_exists( 'is_product_taxonomy' ) && ( is_product_taxonomy() || is_shop() ) ) {
			$type = 'category';
			$term = get_queried_object();
			$category = $term instanceof WP_Term ? $term->name : '';
		} elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$type = 'account';
		} elseif ( is_search() ) {
			$type = 'search';
		} elseif ( is_page() && in_array( (int) get_queried_object_id(), array_map( 'intval', (array) AISR_Settings::get( 'policy_page_ids' ) ), true )
			&& preg_match( '/החזר|החלפ|משלוח|תקנון|מדיניות|פרטיות|אחריות|ביטול|return|refund|shipping|terms|privacy|warranty/ui', get_the_title( get_queried_object_id() ) ) ) {
			// Only returns, shipping, terms and similar pages: an About page is not a sign of worry.
			$type = 'policy';
		} elseif ( is_front_page() ) {
			$type = 'home';
		} elseif ( is_singular( 'post' ) ) {
			$type = 'blog';
		}
		return array(
			'type'       => $type,
			'product_id' => $pid,
			'made_to_order' => $pid && ( $p = wc_get_product( $pid ) ) && 'onbackorder' === $p->get_stock_status(),
			'in_stock'      => $pid && ( $p = wc_get_product( $pid ) ) && 'instock' === $p->get_stock_status(),
			'workshop'      => $pid ? AISR_Knowledge::is_workshop( $pid ) : (bool) preg_match( '/סדנ|workshop/ui', (string) $category . ' ' . ( is_singular() || is_page() ? get_the_title( get_queried_object_id() ) : '' ) ),
			'title'      => wp_strip_all_tags( wp_get_document_title() ),
			'query'      => 'search' === $type ? mb_substr( get_search_query(), 0, 60 ) : '',
			'search_empty' => 'search' === $type && ! have_posts(),
			'cart_made'  => in_array( $type, array( 'cart', 'checkout' ), true ) ? self::cart_made_to_order() : '',
			'order_items' => mb_substr( $order_items, 0, 200 ),
			'category'   => $category,
		);
	}

	/** Names of made-to-order items in the cart, for a heads-up before paying. */
	public static function cart_made_to_order(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}
		$names = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$p = $item['data'] ?? null;
			if ( $p && 'onbackorder' === $p->get_stock_status() ) {
				$names[] = $p->get_name();
			}
		}
		return implode( ', ', array_slice( array_unique( $names ), 0, 3 ) );
	}

	/** Keeps the last 100 searches that found nothing, for the dashboard (what shoppers want and do not find). */
	private static function log_failed_search( string $q ): void {
		$q    = sanitize_text_field( $q );
		$list = (array) get_option( 'aisr_failed_searches', array() );
		$key  = mb_strtolower( $q );
		$list[ $key ] = array( 'q' => $q, 'n' => (int) ( $list[ $key ]['n'] ?? 0 ) + 1, 't' => time() );
		uasort( $list, static fn( $a, $b ) => $b['t'] <=> $a['t'] );
		update_option( 'aisr_failed_searches', array_slice( $list, 0, 100, true ), false );
	}

	/** Whether the store offers local pickup in any shipping zone. */
	private static function has_pickup(): bool {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return false;
		}
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			foreach ( $zone['shipping_methods'] as $m ) {
				if ( 'yes' === $m->enabled && in_array( $m->id, array( 'local_pickup', 'pickup_location' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Templates per trigger. Neutral-gender Hebrew. {product}, {adv}, {shipping}, {returns}, {trust}. */
	public static function openers(): array {
		$o = array(
			'product_dwell'      => array( 'היי 🙂 רואה שמסתכלים על {product}. {hint} רוצים שאעזור?', array( 'כן, יש לי שאלה', 'משלוח והחזרות', 'להשוות לדגם אחר' ) ),
			'copy_name'          => array( 'רגע לפני שמשווים 🙂 {adv_line} ראיתם משהו דומה במקום אחר? ספרו לי ואגיד בכנות מה ההבדל.', array( 'ראיתי מחיר אחר', 'מה מיוחד בו?', 'משלוח והחזרות' ) ),
			'comparison_visitor' => array( 'היי 🙂 משווים מחירים? {adv_line} אפשר לשאול אותי כל דבר.', array( 'מה כלול במחיר?', 'משלוח והחזרות', 'ראיתי מחיר אחר' ) ),
			'tab_return'         => array( 'ברוכים השבים 🙂 נשארה שאלה על {product}?', array( 'כן', 'להשוות לדגם אחר', 'משלוח והחזרות' ) ),
			'exit_scroll'        => array( 'לפני שהולכים, אפשר לעזור במשהו? {trust_line}', array( 'יש לי שאלה', 'משלוח והחזרות', 'לא תודה' ) ),
			'reread'             => array( 'רוצים שאסכם בקצרה מה חשוב לדעת על {product}?', array( 'כן, בקצרה', 'מה אומרים בביקורות?', 'לא תודה' ) ),
			'gallery'            => array( 'רוצים פרטים על החומר והמידות המדויקות של {product}?', array( 'כן', 'מה המידות?', 'ממה זה עשוי?' ) ),
			'size_switch'        => array( 'מתלבטים במידה? ספרו לי מה המידה הרגילה, ואמליץ מה לבחור.', array( 'עזרה במידה', 'איך מחליפים מידה?' ) ),
			'cart_idle'          => array( 'בחירה טובה! {shipping_line} נשארה שאלה לפני התשלום?', array( 'עלות משלוח', 'אמצעי תשלום', 'מדיניות החזרות' ) ),
			'social_visitor'     => array( 'היי 🙂 נעים להכיר! {story_line} אפשר לשאול אותי כל דבר.', array( 'מי אתם?', 'מה הכי נמכר?', 'מחפשים מתנה' ) ),
			'freeship_gap'       => array( 'חסרים לכם {gap} ₪ למשלוח חינם 🙂 רוצים שאציע משהו קטן שמתאים למה שבסל?', array( 'כן, תציעו', 'לא תודה' ) ),
			'holiday_made'       => array( 'רק שתדעו 🙂 את {product} מכינים בהזמנה (כ-{made_days} ימי עסקים), ולכן כנראה זה לא יגיע עד {holiday}. רוצים שאציע משהו דומה שמוכן במלאי?', array( 'כן, מה יש במלאי?', 'לא דחוף לי' ) ),
			'checkout_help'      => array( 'שאלה על משלוח או תשלום? אני כאן 🙂 {payment_line}', array( 'אמצעי תשלום', 'זמן משלוח', 'איסוף עצמי' ) ),
			'shipping_cost'      => array( 'שמתם לב לעלות המשלוח? {freeship_hint}{pickup_hint}רוצים שאבדוק מה הכי משתלם?', array( 'מה האפשרויות?', 'מה חסר למשלוח חינם?' ) ),
			'add_error'          => array( 'משהו לא הסתדר בהוספה לסל? לפעמים צריך לבחור קודם אפשרות, למשל כמות או צבע. רוצים שאעזור?', array( 'כן, תעזרו לי', 'איזה אפשרויות יש?' ) ),
			'search_empty'       => array( 'לא מצאנו "{query}" 🙂 ספרו לי מה מחפשים, ואמצא משהו דומה.', array( 'מתנה', 'כוסות וספלים', 'כלי הגשה' ) ),
			'rage_click'         => array( 'משהו לא עובד? אפשר להזמין דרכי או לדבר עם הצוות.', array( 'להזמין דרך הצ׳אט', 'לדבר עם הצוות' ) ),
			'compare_many'       => array( 'מתלבטים בין כמה מוצרים? 🙂 אשווה לכם בקצרה ואגיד מה מתאים למה.', array( 'כן, תשוו לי', 'למה ההבדל במחיר?' ) ),
			'cart_made'          => array( 'רק שתדעו 🙂 את {cart_made} מכינים בהזמנה (כ-{made_days} ימי עסקים). {holiday_part}רוצים חלופה מהמלאי?', array( 'כן, מה יש במלאי?', 'זה בסדר' ) ),
			'policy_return'      => array( 'ראיתי שבדקתם את המדיניות 🙂 {trust_line} מה מטריד אתכם?', array( 'משלוח', 'החזרות', 'זמן הכנה' ) ),
			'cart_waiting'       => array( 'ברוכים השבים 🙂 {item} עדיין מחכה לכם בסל. נשארה שאלה?', array( 'יש לי שאלה', 'לסל', 'לא תודה' ) ),
			'footer_reached'     => array( 'הגעתם עד הסוף 🙂 משהו חסר לכם בעמוד?', array( 'מידות', 'זמן הכנה', 'משלוח' ) ),
			'made_lead'          => array( 'את {product} מכינים במיוחד אחרי ההזמנה. רוצים שהסטודיו יעדכן אתכם מתי זה יכול להיות מוכן?', array( 'כן, תעדכנו אותי', 'כמה זמן זה לוקח?', 'לא תודה' ) ),
			'idle'               => array( 'צריכים עזרה למצוא משהו?', array( 'כן', 'מה הכי נמכר?', 'רק מסתכלים' ) ),
			'holiday_deadline'   => array( '{product} במלאי 🙂 מזמינים עד {deadline} וזה מגיע לפני {holiday}. נשארה שאלה?', array( 'יש לי שאלה', 'מה משלים את זה?', 'רק מסתכלים' ) ),
			'holiday_deadline_browse' => array( 'מחפשים מתנה ל{holiday}? 🙂 מה שבמלאי, מזמינים עד {deadline} ומגיע לפני החג. אעזור לבחור?', array( 'כן, עזרה במתנה', 'מתנה עד 200 ₪', 'רק מסתכלים' ) ),
			'workshop'           => array( 'רוצים לשמוע איך נראית סדנה אצל מאי? 🙂 ספרו לי לכמה אנשים ולאיזו הזדמנות, ואעזור לבחור ולתאם איתה מועד.', array( 'סדנה לזוג', 'סדנה למשפחה או חברים', 'סדנה ליום הולדת או אירוע', 'סדנה כמתנה' ) ),
			'workshop_exit'      => array( 'רגע לפני שהולכים 🙂 רוצים לבדוק עם מאי אם יש תאריך פנוי? זה לא מחייב.', array( 'כן, לבדוק תאריך לסדנה', 'יש לי שאלה על הסדנה', 'לא תודה' ) ),
			'welcome_workshop'   => array( 'היי, אני {persona} מהסטודיו 🙂 אשמח לעזור לבחור סדנה ולתאם מועד עם מאי.', array( 'מה עושים בסדנה?', 'לכמה אנשים זה מתאים?', 'לבדוק תאריך עם מאי' ) ),
			'welcome'            => array( 'היי, אני {persona} מהסטודיו 🙂 אפשר לשאול אותי כל דבר, או לבחור כאן:', array( '{gift_chip}', 'התאמת צבע לפי תמונה', 'מתי זה יגיע?' ) ),
			'welcome_product'    => array( 'היי, אני {persona} מהסטודיו 🙂 אפשר לשאול על {product}, או לבחור כאן:', array( '{gift_chip}', 'התאמת צבע לפי תמונה', 'מתי זה יגיע?' ) ),
			'returning'          => array( 'ברוכים השבים 🙂 ראיתי שבדקתם את {prev} בפעם הקודמת. רוצים לחזור אליו?', array( 'כן, תראו לי', 'מחפשים משהו אחר' ) ),
			'category'           => array( 'יש פה הרבה אפשרויות. לעזור לצמצם לפי תקציב או לפי שימוש?', array( 'לפי תקציב', 'לפי שימוש', 'מה הכי נמכר?' ) ),
			'thankyou'           => array( 'תודה על ההזמנה 🙂 נשארה שאלה, או רוצים רעיון למשהו שמשלים אותה?', array( 'מתי זה יגיע?', 'מה משלים את מה שקניתי?', 'לא תודה' ) ),
			'home'               => array( 'היי 🙂 מחפשים משהו מסוים, או רק מסתכלים?', array( 'מחפשים משהו מסוים', 'מה המבצעים?', 'רק מסתכלים' ) ),
			'blog'               => array( 'מקווה שהמאמר עוזר 🙂 רוצים המלצה על מוצר שמתאים למה שכתוב כאן?', array( 'כן, תמליצו', 'לא תודה' ) ),
		);
		$custom = trim( (string) AISR_Settings::get( 'opener_copy' ) );
		if ( '' !== $custom ) {
			$o['copy_name'][0] = $custom; // The store's own words for someone about to compare elsewhere.
		}
		return apply_filters( 'aisr_openers', $o );
	}

	private static function fill( string $text, array $vars, bool $emoji ): string {
		foreach ( $vars as $k => $v ) {
			$text = str_replace( '{' . $k . '}', $v, $text );
		}
		$text = preg_replace( '/\s{2,}/u', ' ', trim( $text ) );
		if ( ! $emoji ) {
			$text = trim( preg_replace( '/\s*[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]\s*/u', ' ', $text ) );
		}
		return $text;
	}

	public static function rendered_openers( array $page ): array {
		$s     = AISR_Settings::all();
		$adv   = AISR_Settings::advantages();
		$name  = '';
		$hint  = $s['warranty_summary'] ? 'אפשר לשאול אותי על התאמה, משלוח או אחריות.' : 'אפשר לשאול אותי על התאמה, משלוח או מתנה.';
		if ( $page['product_id'] ) {
			$p = wc_get_product( $page['product_id'] );
			if ( $p ) {
				$name = $p->get_name();
				$topic = AISR_Knowledge::top_topic( $p->get_id() );
				if ( $topic ) {
					$hint = 'שואלים עליו הרבה על ' . $topic . '.';
				}
				foreach ( $topic ? array() : $p->get_attributes() as $key => $attr ) { // A learned topic wins over the size guess.
					$label = $attr instanceof WC_Product_Attribute ? wc_attribute_label( $attr->get_name(), $p ) : (string) $key;
					if ( preg_match( '/size|מידה|מידות/ui', urldecode( $label . ' ' . $key ) ) ) {
						$hint = 'הרבה שואלים פה על המידות.';
						break;
					}
				}
			}
		}
		// Only the headline of the returns policy ("החלפה או החזרה תוך 14 יום"), not its conditions.
		$trust = $s['returns_summary'] ? trim( preg_split( '/[,.;]/u', $s['returns_summary'] )[0] ) : ( $adv[0] ?? '' );
		$vars  = array(
			'product'       => $name ?: 'המוצר',
			'hint'          => $hint,
			'adv_line'      => $adv ? 'אצלנו ' . $adv[0] . '.' : '',
			'trust_line'    => $trust ? $trust . '.' : '',
			'shipping_line' => $s['shipping_summary'] ? $s['shipping_summary'] . '.' : '',
			'prev'          => '{prev}', // Filled in the browser from the previous visit.
			'gap'           => '{gap}', // Filled in the browser from the live cart.
			'story_line'    => $s['brand_story'] ? rtrim( $s['brand_story'], '.' ) . '.' : ( $trust ? $trust . '.' : '' ),
			'made_days'     => (string) $s['made_days'],
			'holiday'       => $s['holiday_name'],
			'query'         => $page['query'] ?? '',
			'item'          => '{item}', // Filled in the browser from the saved cart.
			'cart_made'     => $page['cart_made'] ?? '',
			'holiday_part'  => ( $h = AISR_Knowledge::holiday() ) && ! $h['madeOk'] ? 'זה לא יגיע עד ' . $h['name'] . '. ' : '',
			'deadline'      => ( $hd = AISR_Knowledge::holiday() ) ? $hd['deadlineText'] : '',
			'persona'       => $s['persona_name'] ?: 'הנציגה',
			'payment_line'  => $s['payment_summary'] ? 'אפשר לשלם ב' . $s['payment_summary'] . '.' : '',
			'freeship_hint' => ( $fm = AISR_Knowledge::free_shipping_min() ) ? 'מעל ' . (int) $fm . ' ₪ המשלוח חינם. ' : '',
			'pickup_hint'   => self::has_pickup() ? 'יש גם איסוף עצמי בלי עלות. ' : '',
		);
		$emoji = 'friendly' === $s['persona_template'];
		$cat_opener = null;
		if ( 'category' === $page['type'] && ! empty( $page['category'] ) ) {
			// "כוסות וספלים: למה זה מיועד (קפה, אספרסו, תה, מאצ'ה)? ..." -> one question with its options as buttons.
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $s['diagnostic_questions'] ) as $line ) {
				$parts = explode( ':', $line, 2 );
				$words = static fn( $t ) => array_filter( preg_split( '/[\s,\-–\/]+/u', mb_strtolower( (string) $t ) ), static fn( $w ) => mb_strlen( $w ) >= 3 && 'וגם' !== $w );
				if ( count( $parts ) < 2 || ! array_intersect( $words( $parts[0] ), $words( $page['category'] ) ) ) { // One shared word is enough: 'כוסות קרמיקה' ~ 'כוסות וספלים'.
					continue;
				}
				$q = trim( preg_split( '/\?/u', $parts[1] )[0] );
				if ( preg_match( '/\(([^)]+)\)/u', $q, $m ) ) {
					$opts       = array_slice( array_map( 'trim', explode( ',', $m[1] ) ), 0, 4 );
					$q          = trim( preg_replace( '/\s*\([^)]*\)/u', '', $q ) );
					$cat_opener = array( 'מחפשים ' . $page['category'] . '? 🙂 ' . $q . '? אציג 3 שמתאימים.', $opts );
				}
				break;
			}
		}
		$out   = array();
		$all = self::openers();
		// The nearest occasion opens the conversation on the home page and the blog.
		$occ = AISR_Knowledge::upcoming_occasion();
		if ( $occ && $occ['products'] ) {
			$names = implode( ', ', array_slice( array_column( $occ['products'], 'name' ), 0, 2 ) );
			$when  = $occ['days'] > 1 ? $occ['name'] . ' בעוד ' . $occ['days'] . ' ימים' : ( $occ['days'] >= 0 ? $occ['name'] . ' כבר כאן' : $occ['name'] . ' עכשיו' );
			$all['home'] = array( $when . ' 🙂 מחפשים משהו לחג או מתנה? יש לנו למשל ' . $names . '.', array( 'תראו לי', 'מתנה עד 200 ₪', 'רק מסתכלים' ) );
			$all['blog'] = $all['home'];
		}
		if ( $cat_opener ) {
			$all['category'] = $cat_opener;
		}
		if ( 'category' === $page['type'] && preg_match( '/סדנ/u', (string) ( $page['category'] ?? '' ) ) ) {
			$all['category'] = $all['workshop']; // The workshops page sells an experience, not items.
		}
		// Where the home decides the choice, the first message already offers to match from a photo.
		if ( 'category' === $page['type'] && preg_match( '/עיצוב|בית|עציץ|צמח|הגשה|שולחן|decor|home|plant/iu', (string) ( $page['category'] ?? '' ) ) ) {
			$all['category'][1] = array_merge( array_slice( (array) $all['category'][1], 0, 3 ), array( '📷 לשלוח תמונה' ) );
			$all['category'][0] = rtrim( $all['category'][0] ) . ' אפשר גם לשלוח לי תמונה של הפינה, ואמליץ מה יתאים.';
		}
		$hol      = AISR_Knowledge::holiday();
		$gift     = $hol && $hol['deadline'] ? 'מתנה ל' . $hol['name'] : 'מחפשים מתנה';
		foreach ( $all as $key => $o ) {
			$out[ $key ] = array(
				'text'    => self::fill( $o[0], $vars, $emoji ),
				'replies' => array_map( static fn( $r ) => str_replace( '{gift_chip}', $gift, (string) $r ), (array) $o[1] ),
			);
		}
		return $out;
	}

	public static function enqueue(): void {
		$s = AISR_Settings::all();
		if ( empty( $s['enabled'] ) || '' === AISR_Settings::api_key() || is_admin() ) {
			return;
		}
		if ( 'all' !== $s['visibility'] && ! current_user_can( 'manage_woocommerce' ) ) {
			return; // Test mode: shop managers only. Logged-in pages are not served from page cache.
		}
		$page   = self::page_info();
		$budget = AISR_Claude::budget_state();
		if ( ! empty( $page['search_empty'] ) && '' !== $page['query'] && 'all' === $s['visibility'] ) {
			self::log_failed_search( $page['query'] );
		}

		wp_enqueue_style( 'aisr-widget', AISR_URL . 'assets/widget.css', array(), AISR_VERSION );
		wp_enqueue_script( 'aisr-widget', AISR_URL . 'assets/widget.js', array(), AISR_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );

		$credit = '';
		if ( ! empty( $s['show_credit'] ) && $s['credit_text'] ) {
			$credit = $s['credit_text'];
		}
		wp_localize_script(
			'aisr-widget',
			'AISR_CONFIG',
			array(
				'rest'          => esc_url_raw( rest_url( AISR_REST::NS ) ),
				'storeApi'      => esc_url_raw( rest_url( 'wc/store/v1' ) ),
				'storeNonce'    => wp_create_nonce( 'wc_store_api' ),
				'wcAjax'        => class_exists( 'WC_AJAX' ) ? esc_url_raw( WC_AJAX::get_endpoint( '%%endpoint%%' ) ) : '',
				'cartUrl'       => function_exists( 'wc_get_checkout_url' ) ? esc_url_raw( wc_get_checkout_url() ) : '',
				// Test mode only (logged-in, uncached pages): lets REST see the logged-in manager.
				'restNonce'     => 'all' === $s['visibility'] && ! current_user_can( 'manage_woocommerce' ) ? '' : wp_create_nonce( 'wp_rest' ), // Managers' pages are not cached.
				'page'          => $page,
				'freeShipMin'   => (float) AISR_Knowledge::free_shipping_min(),
				'freeShipGap'   => (int) $s['free_ship_gap'],
				'holiday'       => AISR_Knowledge::holiday(),
				'whatsapp'      => preg_replace( '/\D/', '', (string) $s['handoff_whatsapp'] ),
				'checkoutProactive' => 'ok' === $budget,
				'version'       => AISR_VERSION,
				'freshVisits'   => ! empty( $s['fresh_visits'] ),
				'proactiveMode' => $s['proactive_mode'],
				'proactiveCooldown' => (int) $s['proactive_cooldown'],
				'isManager'     => current_user_can( 'manage_woocommerce' ), // Logged-in pages are not served from page cache.
				'testMode'      => 'all' !== $s['visibility'], // Shop managers testing: every page load starts a fresh visit.
				'persona'       => array(
					'name'   => $s['persona_name'],
					'role'   => $s['persona_role'],
					'avatar' => $s['avatar_url'],
				),
				'color'         => $s['brand_color'],
				'position'      => $s['position'],
				'bottomOffset'  => (int) $s['bottom_offset'],
				'minPages'      => (int) $s['min_pages'],
				'productDwell'  => (int) $s['product_dwell'],
				'idleSeconds'   => (int) $s['idle_seconds'],
				'triggers'      => array_values( (array) $s['enabled_triggers'] ),
				'proactive'     => 'ok' === $budget && 'checkout' !== $page['type'],
				'controlPct'    => (int) $s['control_pct'],
				'privacyMode'   => $s['privacy_mode'],
				'privacyUrl'    => $s['privacy_url'] ?: get_privacy_policy_url(),
				'credit'        => $credit,
				'creditUrl'     => $s['credit_url'],
				'openers'       => self::rendered_openers( $page ),
				'productName'   => $page['product_id'] ? get_the_title( $page['product_id'] ) : '',
			)
		);
	}
}
