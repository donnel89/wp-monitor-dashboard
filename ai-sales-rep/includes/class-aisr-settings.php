<?php
/**
 * Per-store settings: persona, trust card, triggers, coupons, privacy, billing.
 */

defined( 'ABSPATH' ) || exit;

class AISR_Settings {

	const OPTION = 'aisr_settings';

	/** Proactive triggers the store can switch on or off. */
	public static function trigger_labels(): array {
		return array(
			'product_dwell'      => 'שהייה בעמוד מוצר',
			'copy_name'          => 'העתקת שם המוצר (השוואת מחירים)',
			'tab_return'         => 'יציאה לאפליקציה אחרת וחזרה',
			'exit_scroll'        => 'גלילה מהירה למעלה (כוונת יציאה)',
			'reread'             => 'גלילה הלוך ושוב (קריאת ביקורות/מפרט)',
			'gallery'            => 'הגדלה או דפדוף בתמונות',
			'size_switch'        => 'החלפת מידה או צבע כמה פעמים',
			'cart_idle'          => 'הוספה לסל ועצירה',
			'social_visitor'     => 'הגעה מאינסטגרם/טיקטוק/פייסבוק',
			'comparison_visitor' => 'הגעה מאתר השוואת מחירים',
			'idle'               => 'שהייה ארוכה בלי גלילה',
			'returning'          => 'לקוח חוזר (רק במצב פרטיות מורחב)',
			'category'           => 'שהייה בעמוד קטגוריה',
			'home'               => 'שהייה בעמוד הבית',
			'blog'               => 'קריאת מאמר בבלוג',
			'freeship_gap'       => 'סל קרוב לסף המשלוח החינם',
			'holiday_made'       => 'מוצר בהזמנה מראש שלא יגיע עד החג',
			'made_lead'          => 'יציאה מעמוד של מוצר בהזמנה מראש (הצעה לעדכון)',
			'checkout_help'      => 'היסוס בעמוד התשלום (שורה עדינה, בלי חלון)',
			'shipping_cost'      => 'דמי משלוח שהופיעו בתשלום',
			'add_error'          => 'הוספה לסל שנכשלה (למשל בלי לבחור אפשרות)',
			'search_empty'       => 'חיפוש באתר בלי תוצאות',
			'rage_click'         => 'לחיצות חוזרות מהירות (משהו לא עובד)',
			'compare_many'       => 'צפייה ב-3 מוצרים ויותר בלי הוספה לסל',
			'cart_made'          => 'מוצר בהזמנה מראש בסל או בתשלום',
			'policy_return'      => 'חזרה מעמוד מדיניות לעמוד מוצר',
			'cart_waiting'       => 'סל מביקור קודם שמחכה',
			'footer_reached'     => 'גלילה עד סוף עמוד המוצר בלי פעולה',
		);
	}

	public static function defaults(): array {
		return array(
			'enabled'              => 1,
			// 'admins' = only logged-in shop managers see the chat (safe testing on a live site); 'all' = everyone.
			'visibility'           => 'admins',
			'api_key'              => '',
			// Persona.
			'persona_name'         => 'נועה',
			'persona_role'         => 'העוזרת הדיגיטלית',
			'persona_template'     => 'friendly',
			'avatar_url'           => '',
			'brand_color'          => '#2563eb',
			'position'             => 'left',
			'bottom_offset'        => 20,
			// Trust card + knowledge.
			'business_name'        => get_bloginfo( 'name' ),
			'business_address'     => '',
			'business_years'       => '',
			'business_phone'       => '',
			'advantages'           => '',
			'shipping_summary'     => '',
			'returns_summary'      => '',
			'warranty_summary'     => '',
			'payment_summary'      => '',
			'policy_page_ids'      => array(),
			'knowledge_extra'      => '',
			'diagnostic_questions' => '',
			// Handoff.
			'handoff_channel'      => 'whatsapp',
			'handoff_whatsapp'     => '',
			'handoff_email'        => get_option( 'admin_email' ),
			'hours_start'          => '09:00',
			'hours_end'            => '18:00',
			'hours_friday_end'     => '',
			'work_days'            => array( 0, 1, 2, 3, 4 ),
			// Triggers.
			'min_pages'            => 2,
			'product_dwell'        => 20,
			'idle_seconds'         => 25,
			'enabled_triggers'     => array_keys( self::trigger_labels() ),
			// Coupons.
			'coupon_enabled'       => 0,
			'coupon_max_pct'       => 10,
			'coupon_min_cart'      => 0,
			'coupon_reasons'       => array( 'exit_intent', 'cart_hesitation' ),
			'coupon_valid_hours'   => 48,
			'coupon_excluded_cats' => array(),
			'price_match'          => 0,
			// Billing / cost.
			'commission_pct'       => 5,
			'attribution_days'     => 7,
			'monthly_budget_usd'   => 30,
			'model_mode'           => 'balanced',
			// Sales features.
			'free_ship_gap'        => 100,
			'ready_days'           => 5,
			'made_days'            => 15,
			'holiday_name'         => '',
			'holiday_date'         => '',
			'brand_story'          => '',
			'opener_copy'          => '',
			'reminder_channel'     => 'email',
			'control_pct'          => 10,
			// Privacy.
			'privacy_mode'         => 'basic',
			'privacy_url'          => '',
			'retention_days'       => 90,
			// Credit.
			'show_credit'          => 1,
			'fresh_visits'         => 0,
			'credit_text'          => '',
			'credit_url'           => '',
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * A safe view of a key for the settings page: the prefix, the last 4 characters and the length.
	 * Never enough to use the key, enough to spot a password autofilled by the browser or a truncated paste.
	 */
	public static function key_fingerprint( string $key ): string {
		$len    = strlen( $key );
		$prefix = preg_match( '/^(sk-ant-[a-z]+\d*)-/', $key, $m ) ? $m[1] : '';
		$out    = $prefix ? $prefix . '…' . ( $len > 24 ? substr( $key, -4 ) : '' ) . ' (' . $len . ' תווים)' : '••• (' . $len . ' תווים)';
		if ( ! str_starts_with( $key, 'sk-ant-' ) ) {
			$out .= ' · לא נראה כמו מפתח של Anthropic';
		} elseif ( str_starts_with( $key, 'sk-ant-oat' ) ) {
			$out .= ' · זה טוקן של מנוי Claude, לא מפתח API';
		} elseif ( $len < 90 ) {
			$out .= ' · קצר מדי, אולי הודבק חלקית';
		}
		return $out;
	}

	/** The API key: a wp-config constant wins over the stored option. */
	public static function api_key(): string {
		if ( defined( 'AISR_ANTHROPIC_API_KEY' ) && AISR_ANTHROPIC_API_KEY ) {
			return (string) AISR_ANTHROPIC_API_KEY;
		}
		return (string) self::get( 'api_key' );
	}

	public static function advantages(): array {
		$lines = preg_split( '/\r\n|\r|\n/', (string) self::get( 'advantages' ) );
		return array_values( array_filter( array_map( 'trim', $lines ) ) );
	}

	/** Is the store open right now (site timezone)? */
	public static function is_open_now(): bool {
		$s    = self::all();
		$now  = current_datetime();
		$day  = (int) $now->format( 'w' );
		$time = $now->format( 'H:i' );
		$days = array_map( 'intval', (array) $s['work_days'] );
		$end  = ( 5 === $day && '' !== (string) $s['hours_friday_end'] ) ? $s['hours_friday_end'] : $s['hours_end'];
		return in_array( $day, $days, true ) && $time >= $s['hours_start'] && $time < $end;
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_aisr_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_aisr_import_settings', array( __CLASS__, 'import' ) );
		add_action( 'admin_post_aisr_test_connection', array( __CLASS__, 'test_connection' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'woocommerce',
			'נציג מכירות AI · הגדרות',
			'נציג AI · הגדרות',
			'manage_woocommerce',
			'aisr-settings',
			array( __CLASS__, 'render' )
		);
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'aisr_save_settings' );

		$in = wp_unslash( $_POST['aisr'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_option( self::OPTION, self::sanitize( is_array( $in ) ? $in : array() ), false );
		AISR_Knowledge::flush_cache();

		wp_safe_redirect( add_query_arg( array( 'page' => 'aisr-settings', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Import a JSON preset (e.g. prepared per store). The API key is never imported or exported. */
	public static function import(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'aisr_import_settings' );
		$json = json_decode( (string) wp_unslash( $_POST['aisr_json'] ?? '' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $json ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'aisr-settings', 'import_error' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}
		unset( $json['api_key'] );
		$merged = array_merge( self::all(), $json );
		// Checkbox semantics in sanitize(): a missing key means "off", so keep current values for booleans not in the preset.
		update_option( self::OPTION, self::sanitize( $merged ), false );
		AISR_Knowledge::flush_cache();
		wp_safe_redirect( add_query_arg( array( 'page' => 'aisr-settings', 'imported' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Export without the API key. */
	public static function export_json(): string {
		$s = self::all();
		unset( $s['api_key'] );
		return (string) wp_json_encode( $s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/** One tiny request to check the key and network from this server. */
	public static function test_connection(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'aisr_test_connection' );
		$result = AISR_Claude::ping();
		set_transient( 'aisr_ping_result_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'aisr-settings', 'tested' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Clean a raw settings array (from the form or an imported preset). */
	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$old = self::all();
		$out = array();

		$text = array( 'holiday_name', 'brand_story', 'opener_copy', 'persona_name', 'persona_role', 'business_name', 'business_address', 'business_years', 'business_phone', 'shipping_summary', 'returns_summary', 'warranty_summary', 'payment_summary', 'handoff_whatsapp', 'credit_text', 'hours_start', 'hours_end', 'hours_friday_end' );
		foreach ( $text as $k ) {
			$out[ $k ] = sanitize_text_field( $in[ $k ] ?? '' );
		}
		foreach ( array( 'advantages', 'knowledge_extra', 'diagnostic_questions' ) as $k ) {
			$out[ $k ] = sanitize_textarea_field( $in[ $k ] ?? '' );
		}
		foreach ( array( 'avatar_url', 'credit_url', 'privacy_url' ) as $k ) {
			$out[ $k ] = esc_url_raw( $in[ $k ] ?? '' );
		}
		$out['handoff_email'] = sanitize_email( $in['handoff_email'] ?? '' );
		$out['brand_color']   = sanitize_hex_color( $in['brand_color'] ?? '' ) ?: $d['brand_color'];

		$enums = array(
			'persona_template' => array( 'friendly', 'professional', 'luxury' ),
			'position'         => array( 'left', 'right' ),
			'handoff_channel'  => array( 'whatsapp', 'lead', 'email' ),
			'privacy_mode'     => array( 'basic', 'extended' ),
			'visibility'       => array( 'admins', 'all' ),
			'model_mode'       => array( 'balanced', 'economy' ),
			'reminder_channel' => array( 'email', 'whatsapp_digest' ),
		);
		foreach ( $enums as $k => $allowed ) {
			$v         = (string) ( $in[ $k ] ?? '' );
			$out[ $k ] = in_array( $v, $allowed, true ) ? $v : $d[ $k ];
		}

		foreach ( array( 'enabled', 'coupon_enabled', 'price_match', 'show_credit', 'fresh_visits' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}

		$ints = array(
			'bottom_offset'      => array( 0, 300 ),
			'min_pages'          => array( 1, 10 ),
			'product_dwell'      => array( 3, 300 ),
			'idle_seconds'       => array( 5, 300 ),
			'coupon_max_pct'     => array( 1, 50 ),
			'coupon_valid_hours' => array( 1, 720 ),
			'attribution_days'   => array( 1, 30 ),
			'control_pct'        => array( 0, 50 ),
			'retention_days'     => array( 1, 3650 ),
			'free_ship_gap'      => array( 0, 1000 ),
			'ready_days'         => array( 1, 60 ),
			'made_days'          => array( 1, 120 ),
		);
		foreach ( $ints as $k => $range ) {
			$out[ $k ] = max( $range[0], min( $range[1], (int) ( $in[ $k ] ?? $d[ $k ] ) ) );
		}
		foreach ( array( 'coupon_min_cart', 'commission_pct', 'monthly_budget_usd' ) as $k ) {
			$out[ $k ] = max( 0, (float) ( $in[ $k ] ?? $d[ $k ] ) );
		}

		$hd                  = (string) ( $in['holiday_date'] ?? '' );
		$out['holiday_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $hd ) ? $hd : '';
		$out['work_days']            = array_values( array_intersect( array_map( 'intval', (array) ( $in['work_days'] ?? array() ) ), range( 0, 6 ) ) );
		$out['enabled_triggers']     = array_values( array_intersect( (array) ( $in['enabled_triggers'] ?? array() ), array_keys( self::trigger_labels() ) ) );
		$out['coupon_reasons']       = array_values( array_intersect( (array) ( $in['coupon_reasons'] ?? array() ), array_keys( AISR_Tools::coupon_reason_labels() ) ) );
		$out['policy_page_ids']      = array_values( array_filter( array_map( 'absint', (array) ( $in['policy_page_ids'] ?? array() ) ) ) );
		$out['coupon_excluded_cats'] = array_values( array_filter( array_map( 'absint', (array) ( $in['coupon_excluded_cats'] ?? array() ) ) ) );

		// Keep the stored key when the field is left blank (it is never echoed back).
		$key            = trim( (string) ( $in['api_key'] ?? '' ) );
		$out['api_key'] = '' === $key ? $old['api_key'] : sanitize_text_field( $key );
		if ( ! empty( $in['api_key_clear'] ) ) {
			$out['api_key'] = '';
		}

		return $out;
	}

	/* ---------- rendering helpers ---------- */

	private static function field_text( string $key, string $label, string $help = '', string $type = 'text' ): void {
		$v = self::get( $key );
		printf(
			'<tr><th scope="row"><label for="aisr_%1$s">%2$s</label></th><td><input type="%5$s" class="regular-text" id="aisr_%1$s" name="aisr[%1$s]" value="%3$s">%4$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( (string) $v ),
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : '',
			esc_attr( $type )
		);
	}

	private static function field_textarea( string $key, string $label, string $help = '' ): void {
		printf(
			'<tr><th scope="row"><label for="aisr_%1$s">%2$s</label></th><td><textarea class="large-text" rows="5" id="aisr_%1$s" name="aisr[%1$s]">%3$s</textarea>%4$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_textarea( (string) self::get( $key ) ),
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private static function field_check( string $key, string $label, string $help = '' ): void {
		printf(
			'<tr><th scope="row">%2$s</th><td><label><input type="checkbox" name="aisr[%1$s]" value="1" %3$s> %4$s</label></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			checked( 1, (int) self::get( $key ), false ),
			esc_html( $help )
		);
	}

	private static function field_select( string $key, string $label, array $options, string $help = '' ): void {
		$v = (string) self::get( $key );
		echo '<tr><th scope="row"><label for="aisr_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="aisr_' . esc_attr( $key ) . '" name="aisr[' . esc_attr( $key ) . ']">';
		foreach ( $options as $ov => $ol ) {
			echo '<option value="' . esc_attr( $ov ) . '" ' . selected( $v, (string) $ov, false ) . '>' . esc_html( $ol ) . '</option>';
		}
		echo '</select>' . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>';
	}

	private static function field_multi( string $key, string $label, array $options, string $help = '' ): void {
		$v = array_map( 'strval', (array) self::get( $key ) );
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><fieldset>';
		foreach ( $options as $ov => $ol ) {
			echo '<label style="display:block;margin-bottom:4px"><input type="checkbox" name="aisr[' . esc_attr( $key ) . '][]" value="' . esc_attr( $ov ) . '" ' . checked( in_array( (string) $ov, $v, true ), true, false ) . '> ' . esc_html( $ol ) . '</label>';
		}
		echo '</fieldset>' . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s        = self::all();
		$pages    = get_pages( array( 'post_status' => 'publish' ) );
		$page_opt = array();
		foreach ( $pages as $p ) {
			$page_opt[ $p->ID ] = $p->post_title;
		}
		$cats     = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$cat_opt  = array();
		if ( ! is_wp_error( $cats ) ) {
			foreach ( $cats as $c ) {
				$cat_opt[ $c->term_id ] = $c->name;
			}
		}
		$days     = array( 'ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת' );
		$key_note = defined( 'AISR_ANTHROPIC_API_KEY' ) ? 'המפתח מוגדר בקובץ wp-config.php.' : ( $s['api_key'] ? 'מפתח שמור. השאירו ריק כדי לא לשנות.' : 'עדיין לא הוגדר מפתח.' );
		$key_print = self::api_key() ? self::key_fingerprint( self::api_key() ) : '';
		?>
		<div class="wrap" dir="rtl">
			<h1>נציג מכירות AI · הגדרות</h1>
			<?php if ( isset( $_GET['updated'] ) || isset( $_GET['imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php echo isset( $_GET['imported'] ) ? 'ההגדרות יובאו.' : 'ההגדרות נשמרו.'; // phpcs:ignore ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['import_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-error"><p>הקובץ שהודבק אינו JSON תקין.</p></div>
			<?php endif; ?>
			<?php
			$ping = isset( $_GET['tested'] ) ? get_transient( 'aisr_ping_result_' . get_current_user_id() ) : null; // phpcs:ignore WordPress.Security.NonceVerification
			if ( is_array( $ping ) ) :
				?>
				<div class="notice <?php echo $ping['ok'] ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( $ping['message'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'admins' === $s['visibility'] ) : ?>
				<div class="notice notice-info"><p>מצב בדיקה: רק מנהלי חנות מחוברים רואים את הצ'אט. גולשים לא רואים כלום.</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0">
				<input type="hidden" name="action" value="aisr_test_connection">
				<?php wp_nonce_field( 'aisr_test_connection' ); ?>
				<?php submit_button( 'בדיקת חיבור ל-Claude', 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aisr_save_settings">
				<?php wp_nonce_field( 'aisr_save_settings' ); ?>

				<h2>כללי</h2>
				<table class="form-table" role="presentation">
					<?php
					self::field_check( 'enabled', 'הפעלה', 'הצ\'אט פעיל' );
					self::field_check( 'fresh_visits', 'כל טעינת עמוד כמו גולש חדש', 'לתקופת בדיקות: אין מגבלה של פנייה אחת בביקור ואין קבוצת ביקורת, לכל הגולשים. שיחה שהתחילה נשמרת. לכבות בסיום הבדיקות, אחרת אין מדידה מול ביקורת.' );
					self::field_select(
						'visibility',
						'מי רואה את הצ\'אט',
						array(
							'admins' => 'רק מנהלי חנות מחוברים (מצב בדיקה באתר החי)',
							'all'    => 'כל הגולשים',
						),
						'אחרי מעבר ל"כל הגולשים" צריך לנקות את המטמון (Breeze / Cloudflare / Varnish) כדי שהעמודים יכללו את הצ\'אט.'
					);
					?>
					<tr><th scope="row"><label for="aisr_api_key">מפתח Claude API</label></th><td>
						<input type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore class="regular-text" id="aisr_api_key" name="aisr[api_key]" value="">
						<p class="description"><?php echo esc_html( $key_note ); ?><?php if ( $key_print ) : ?> <code dir="ltr"><?php echo esc_html( $key_print ); ?></code><?php endif; ?> אפשר גם להגדיר <code>AISR_ANTHROPIC_API_KEY</code> ב-wp-config.php.</p>
						<?php if ( $s['api_key'] ) : ?><label><input type="checkbox" name="aisr[api_key_clear]" value="1"> מחיקת המפתח השמור</label><?php endif; ?>
					</td></tr>
				</table>

				<h2>פרסונה ועיצוב</h2>
				<table class="form-table" role="presentation">
					<?php
					self::field_text( 'persona_name', 'שם הנציג/ה' );
					self::field_text( 'persona_role', 'תיאור', 'מוצג מתחת לשם. הבוט תמיד מציג את עצמו כעוזר דיגיטלי.' );
					self::field_select( 'persona_template', 'טון', array( 'friendly' => 'חברי וקליל', 'professional' => 'מקצועי', 'luxury' => 'יוקרתי' ) );
					self::field_text( 'avatar_url', 'כתובת תמונת פרופיל', '', 'url' );
					self::field_text( 'brand_color', 'צבע מותג', '', 'color' );
					self::field_select( 'position', 'מיקום האייקון', array( 'left' => 'שמאל', 'right' => 'ימין' ), 'בחרו צד שלא מתנגש עם כפתור הוואטסאפ של האתר.' );
					self::field_text( 'bottom_offset', 'מרחק מתחתית המסך (px)', 'הגדילו אם יש כפתור "הוסף לסל" צף בתחתית.', 'number' );
					?>
				</table>

				<h2>כרטיס אמון וידע</h2>
				<p>הבוט קורא אוטומטית את המוצרים, המלאי והמחירים. כאן משלימים מה שלא כתוב במוצרים.</p>
				<table class="form-table" role="presentation">
					<?php
					self::field_text( 'business_name', 'שם העסק' );
					self::field_text( 'business_address', 'כתובת / חנות פיזית' );
					self::field_text( 'business_years', 'ותק', 'לדוגמה: פועלים מאז 2012' );
					self::field_text( 'business_phone', 'טלפון' );
					self::field_textarea( 'advantages', 'יתרונות החנות', 'שורה לכל יתרון. משמש בתשובות על השוואה למתחרים. לדוגמה: אחריות יבואן רשמי לשנתיים' );
					self::field_text( 'shipping_summary', 'משלוחים בקצרה', 'לדוגמה: משלוח חינם מעל 300 ₪, 3–5 ימי עסקים' );
					self::field_text( 'opener_copy', 'הודעה למי שמעתיק את שם המוצר', 'מי שמעתיק את השם הולך לחפש אותו במקום אחר. אפשר להשתמש ב-{product}. ריק = נוסח ברירת המחדל.' );
					self::field_text( 'brand_story', 'הסיפור של המותג במשפט', 'נפתח איתו לגולשים שמגיעים מאינסטגרם, טיקטוק ופייסבוק.' );
					self::field_text( 'free_ship_gap', 'הצעה להשלים למשלוח חינם (₪)', 'כשחסר בסל עד הסכום הזה, הבוט מציע מוצר קטן שמשלים. 0 = כבוי. הסף עצמו נקרא מהגדרות המשלוח בווקומרס.', 'number' );
					self::field_text( 'ready_days', 'זמן אספקה למוצר במלאי (ימי עסקים)', '', 'number' );
					self::field_text( 'made_days', 'זמן הכנה למוצר בהזמנה מראש (ימי עסקים)', '', 'number' );
					self::field_text( 'holiday_name', 'חג קרוב', 'לדוגמה: חנוכה. הבוט בודק אם מוצר יגיע בזמן. ריק = כבוי.' );
					self::field_text( 'holiday_date', 'תאריך החג', 'היום שעד אליו המתנה צריכה להגיע.', 'date' );
					self::field_text( 'returns_summary', 'החזרות בקצרה', 'לדוגמה: החלפה או החזרה חינם תוך 14 יום' );
					self::field_text( 'warranty_summary', 'אחריות בקצרה' );
					self::field_text( 'payment_summary', 'תשלום בקצרה', 'לדוגמה: אשראי, ביט, עד 3 תשלומים' );
					self::field_multi( 'policy_page_ids', 'עמודי מדיניות לסריקה', $page_opt, 'הבוט קורא את תוכן העמודים האלה (משלוחים, החזרות, שאלות נפוצות, טבלת מידות).' );
					self::field_textarea( 'knowledge_extra', 'מידע משלים ותיקונים', 'כל מה שהבוט צריך לדעת ולא כתוב באתר, או תיקון למידע שגוי.' );
					self::field_textarea( 'diagnostic_questions', 'שאלות אבחון לפי קטגוריה', 'שורה לכל קטגוריה, לדוגמה: נעליים: לאיזו פעילות? מה המידה הרגילה? ריק = הבוט יבנה שאלות לפי מאפייני המוצרים.' );
					?>
				</table>

				<h2>העברה לנציג אנושי ושעות פעילות</h2>
				<table class="form-table" role="presentation">
					<?php
					self::field_select( 'handoff_channel', 'ערוץ', array( 'whatsapp' => 'וואטסאפ עם סיכום השיחה', 'lead' => 'השארת פרטים לחזרה', 'email' => 'מייל לבעל העסק' ) );
					self::field_text( 'handoff_whatsapp', 'מספר וואטסאפ', 'בפורמט בינלאומי, לדוגמה 972501234567' );
					self::field_text( 'handoff_email', 'מייל ללידים והתראות', '', 'email' );
					self::field_text( 'hours_start', 'שעת פתיחה', '', 'time' );
					self::field_text( 'hours_end', 'שעת סגירה', '', 'time' );
					self::field_text( 'hours_friday_end', 'שעת סגירה ביום שישי', 'ריק = כמו בשאר הימים', 'time' );
					self::field_multi( 'work_days', 'ימי פעילות', $days );
					?>
				</table>

				<h2>טריגרים לפנייה יזומה</h2>
				<p>פנייה יזומה אחת בכל ביקור. גולש שסגר את ההודעה לא מקבל פנייה נוספת עד הביקור הבא. בעמוד התשלום אין פניות יזומות.</p>
				<table class="form-table" role="presentation">
					<?php
					self::field_text( 'min_pages', 'מספר עמודים מינימלי', 'תנאי בסיס לפנייה (סימנים חזקים כמו העתקת שם מוצר עוקפים אותו).', 'number' );
					self::field_text( 'product_dwell', 'שניות שהייה בעמוד מוצר', '', 'number' );
					self::field_text( 'idle_seconds', 'שניות בלי גלילה עד "נתקע"', '', 'number' );
					self::field_multi( 'enabled_triggers', 'טריגרים פעילים', self::trigger_labels() );
					?>
				</table>

				<h2>קופונים</h2>
				<p>הבוט לא מחליט על הנחות. השרת בודק את הכללים ויוצר קופון חד-פעמי. הבוט רק מציע אותו בשיחה.</p>
				<table class="form-table" role="presentation">
					<?php
					self::field_check( 'coupon_enabled', 'הבוט רשאי להציע קופון' );
					self::field_text( 'coupon_max_pct', 'אחוז הנחה מקסימלי', '', 'number' );
					self::field_text( 'coupon_min_cart', 'סכום סל מינימלי (₪)', '', 'number' );
					self::field_multi( 'coupon_reasons', 'מתי מותר להציע', AISR_Tools::coupon_reason_labels() );
					self::field_text( 'coupon_valid_hours', 'תוקף הקופון (שעות)', '', 'number' );
					self::field_multi( 'coupon_excluded_cats', 'קטגוריות מוחרגות', $cat_opt );
					self::field_check( 'price_match', 'התאמת מחיר', 'כשהגולש מוסר מחיר נמוך יותר אצל מתחרה, עד התקרה שלמעלה' );
					?>
				</table>

				<h2>עמלה, תקציב ומדידה</h2>
				<table class="form-table" role="presentation">
					<?php
					self::field_text( 'commission_pct', 'אחוז עמלה ממכירות משויכות', '', 'number' );
					self::field_text( 'attribution_days', 'חלון שיוך (ימים)', 'מכירה נזקפת לבוט אם הגולש ענה או לחץ בשיחה ורכש בתוך החלון.', 'number' );
					self::field_select( 'model_mode', 'מודל AI', array( 'balanced' => 'מאוזן: Haiku לשאלות פשוטות, Sonnet להשוואות ולשיחות ארוכות', 'economy' => 'חסכוני: Haiku בלבד (בערך פי 20 זול יותר מ-Sonnet)' ), 'במצב חסכוני גם השוואות והתנגדויות עוברות ל-Haiku.' );
					self::field_text( 'monthly_budget_usd', 'תקרת עלות AI חודשית ($)', 'ב-80% נשלחת התראה. ב-100% הפניות היזומות נעצרות והבוט עונה רק למי שפונה אליו.', 'number' );
					self::field_text( 'control_pct', 'קבוצת ביקורת (%)', 'אחוז הגולשים שלא מקבלים פניות יזומות, כדי למדוד את התוספת האמיתית במכירות.', 'number' );
					?>
				</table>

				<h2>פרטיות</h2>
				<table class="form-table" role="presentation">
					<?php
					self::field_select(
						'privacy_mode',
						'רמת מעקב',
						array(
							'basic'    => 'בסיס: רק הביקור הנוכחי, בלי הסכמה',
							'extended' => 'מורחב: זוכר ביקורים קודמים, רק אחרי הסכמה לעוגיות',
						),
						'במצב מורחב נדרש תוסף עוגיות שתומך ב-WP Consent API. לפני הפעלה מומלץ לבדוק עם עורך דין.'
					);
					self::field_text( 'privacy_url', 'קישור למדיניות הפרטיות', '', 'url' );
					self::field_text( 'retention_days', 'שמירת שיחות (ימים)', '', 'number' );
					self::field_select( 'reminder_channel', 'תזכורת עגלה נטושה', array( 'email' => 'מייל ללקוח (רק למי שמסר מייל ואישר דיוור)', 'whatsapp_digest' => 'מייל ללקוח + סיכום יומי לחנות עם קישורי וואטסאפ מוכנים למי שמסר טלפון' ), 'וואטסאפ נשלח ידנית מהטלפון של החנות בלחיצה, כך שלא צריך WhatsApp Business API.' );
					self::field_check( 'show_credit', 'קרדיט קטן בתחתית הצ\'אט' );
					self::field_text( 'credit_text', 'טקסט הקרדיט' );
					self::field_text( 'credit_url', 'קישור הקרדיט', '', 'url' );
					?>
				</table>

				<?php submit_button( 'שמירה' ); ?>
			</form>

			<h2>ייבוא וייצוא הגדרות</h2>
			<p>מעבירים הגדרות בין אתרים או מתקינים הגדרות שהוכנו מראש. מפתח ה-API לא נכלל.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aisr_import_settings">
				<?php wp_nonce_field( 'aisr_import_settings' ); ?>
				<textarea name="aisr_json" class="large-text code" rows="10" dir="ltr" placeholder="{ ... }"></textarea>
				<?php submit_button( 'ייבוא', 'secondary' ); ?>
			</form>
			<details><summary>ההגדרות הנוכחיות (JSON)</summary>
				<textarea readonly class="large-text code" rows="12" dir="ltr"><?php echo esc_textarea( self::export_json() ); ?></textarea>
			</details>
		</div>
		<?php
	}
}
