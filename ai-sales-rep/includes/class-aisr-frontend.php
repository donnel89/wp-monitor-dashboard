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
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$type = 'checkout';
		} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
			$type = 'cart';
		} elseif ( function_exists( 'is_product' ) && is_product() ) {
			$type = 'product';
			$pid  = (int) get_queried_object_id();
		} elseif ( function_exists( 'is_product_taxonomy' ) && ( is_product_taxonomy() || is_shop() ) ) {
			$type = 'category';
		} elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$type = 'account';
		} elseif ( is_front_page() ) {
			$type = 'home';
		} elseif ( is_singular( 'post' ) ) {
			$type = 'blog';
		}
		return array(
			'type'       => $type,
			'product_id' => $pid,
			'title'      => wp_strip_all_tags( wp_get_document_title() ),
		);
	}

	/** Templates per trigger. Neutral-gender Hebrew. {product}, {adv}, {shipping}, {returns}, {trust}. */
	public static function openers(): array {
		$o = array(
			'product_dwell'      => array( 'היי 🙂 רואה שמסתכלים על {product}. {hint} רוצים שאעזור?', array( 'כן, יש לי שאלה', 'משלוח והחזרות', 'להשוות לדגם אחר' ) ),
			'copy_name'          => array( 'רגע לפני שמחפשים במקום אחר 🙂 {adv_line} רוצים לשמוע מה עוד מקבלים אצלנו?', array( 'מה כלול במחיר?', 'משלוח והחזרות', 'ראיתי מחיר אחר' ) ),
			'comparison_visitor' => array( 'היי 🙂 משווים מחירים? {adv_line} אפשר לשאול אותי כל דבר.', array( 'מה כלול במחיר?', 'משלוח והחזרות', 'ראיתי מחיר אחר' ) ),
			'tab_return'         => array( 'ברוכים השבים 🙂 נשארה שאלה על {product}?', array( 'כן', 'להשוות לדגם אחר', 'משלוח והחזרות' ) ),
			'exit_scroll'        => array( 'לפני שהולכים, אפשר לעזור במשהו? {trust_line}', array( 'יש לי שאלה', 'משלוח והחזרות', 'לא תודה' ) ),
			'reread'             => array( 'רוצים שאסכם בקצרה מה חשוב לדעת על {product}?', array( 'כן, בקצרה', 'מה אומרים בביקורות?', 'לא תודה' ) ),
			'gallery'            => array( 'רוצים פרטים על החומר והמידות המדויקות של {product}?', array( 'כן', 'מה המידות?', 'ממה זה עשוי?' ) ),
			'size_switch'        => array( 'מתלבטים במידה? ספרו לי מה המידה הרגילה, ואמליץ מה לבחור.', array( 'עזרה במידה', 'איך מחליפים מידה?' ) ),
			'cart_idle'          => array( 'בחירה טובה! {shipping_line} נשארה שאלה לפני התשלום?', array( 'עלות משלוח', 'אמצעי תשלום', 'מדיניות החזרות' ) ),
			'social_visitor'     => array( 'היי 🙂 נעים להכיר! {trust_line} אפשר לשאול אותי כל דבר.', array( 'מי אתם?', 'משלוח והחזרות', 'מה הכי נמכר?' ) ),
			'idle'               => array( 'צריכים עזרה למצוא משהו?', array( 'כן', 'מה הכי נמכר?', 'רק מסתכלים' ) ),
			'returning'          => array( 'ברוכים השבים 🙂 ראיתי שבדקתם את {prev} בפעם הקודמת. רוצים לחזור אליו?', array( 'כן, תראו לי', 'מחפשים משהו אחר' ) ),
			'category'           => array( 'יש פה הרבה אפשרויות. לעזור לצמצם לפי תקציב או לפי שימוש?', array( 'לפי תקציב', 'לפי שימוש', 'מה הכי נמכר?' ) ),
			'home'               => array( 'היי 🙂 מחפשים משהו מסוים, או רק מסתכלים?', array( 'מחפשים משהו מסוים', 'מה המבצעים?', 'רק מסתכלים' ) ),
			'blog'               => array( 'מקווה שהמאמר עוזר 🙂 רוצים המלצה על מוצר שמתאים למה שכתוב כאן?', array( 'כן, תמליצו', 'לא תודה' ) ),
		);
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
				foreach ( $p->get_attributes() as $key => $attr ) {
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
		);
		$emoji = 'friendly' === $s['persona_template'];
		$out   = array();
		foreach ( self::openers() as $key => $o ) {
			$out[ $key ] = array(
				'text'    => self::fill( $o[0], $vars, $emoji ),
				'replies' => $o[1],
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
				// Test mode only (logged-in, uncached pages): lets REST see the logged-in manager.
				'restNonce'     => 'all' === $s['visibility'] ? '' : wp_create_nonce( 'wp_rest' ),
				'page'          => $page,
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
