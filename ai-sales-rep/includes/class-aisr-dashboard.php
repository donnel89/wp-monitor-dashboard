<?php
/**
 * Store owner dashboard: uplift vs control group, attributed revenue, commission, AI cost,
 * trigger performance, shopper insights, unanswered questions and leads.
 */

defined( 'ABSPATH' ) || exit;

class AISR_Dashboard {

	const PAID = array( 'processing', 'completed', 'on-hold' );

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', 'נציג מכירות AI · דשבורד', 'נציג AI · דשבורד', 'manage_woocommerce', 'aisr-dashboard', array( __CLASS__, 'render' ) );
	}

	/** All numbers for a period. Public so a central monitoring dashboard can read it later. */
	public static function stats( int $days ): array {
		global $wpdb;
		$since    = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$events   = AISR_DB::events();
		$convs    = AISR_DB::conversations();
		$s        = AISR_Settings::all();

		$visits = array( 'test' => 0, 'control' => 0 );
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT grp, COUNT(*) n FROM $events WHERE type='visit' AND created_at >= %s GROUP BY grp", $since ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$visits[ $r['grp'] ] = (int) $r['n'];
		}

		$orders     = array( 'test' => 0, 'control' => 0 );
		$attributed = array( 'count' => 0, 'revenue' => 0.0, 'by_conv' => array() );
		$ids        = wc_get_orders(
			array(
				'limit'        => -1,
				'return'       => 'ids',
				'status'       => self::PAID,
				'date_created' => '>=' . ( time() - $days * DAY_IN_SECONDS ),
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array( 'key' => '_aisr_group', 'compare' => 'EXISTS' ),
					array( 'key' => '_aisr_attributed', 'compare' => 'EXISTS' ),
				),
			)
		);
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				continue;
			}
			$g = $o->get_meta( '_aisr_group' );
			if ( isset( $orders[ $g ] ) ) {
				$orders[ $g ]++;
			}
			if ( $o->get_meta( '_aisr_attributed' ) ) {
				$net = (float) $o->get_total() - (float) $o->get_total_refunded();
				$attributed['count']++;
				$attributed['revenue'] += $net;
				$cu = (string) $o->get_meta( '_aisr_conversation' );
				if ( $cu ) {
					$attributed['by_conv'][ $cu ] = ( $attributed['by_conv'][ $cu ] ?? 0 ) + $net;
				}
			}
		}

		$conv_rows = $wpdb->get_results( $wpdb->prepare( "SELECT uuid, engaged, trigger_key, cost_usd, insights, unanswered, handoff FROM $convs WHERE started_at >= %s", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$cost      = 0.0;
		$engaged   = 0;
		$handoffs  = 0;
		$triggers  = array();
		$insight   = array( 'gift' => 0, 'self' => 0, 'priority' => array(), 'competitor' => 0, 'with_need' => 0, 'mode' => array() );
		$questions = array();
		foreach ( $conv_rows as $r ) {
			$cost     += (float) $r['cost_usd'];
			$engaged  += (int) $r['engaged'];
			$handoffs += (int) $r['handoff'];
			$t         = $r['trigger_key'] ?: 'manual';
			$triggers[ $t ]            = $triggers[ $t ] ?? array( 'shown' => 0, 'dismissed' => 0, 'replied' => 0, 'revenue' => 0.0 );
			$triggers[ $t ]['replied'] += (int) $r['engaged'];
			$triggers[ $t ]['revenue'] += $attributed['by_conv'][ $r['uuid'] ] ?? 0;

			$in = json_decode( (string) $r['insights'], true ) ?: array();
			if ( $in ) {
				$insight['with_need']++;
			}
			if ( ! empty( $in['recipient'] ) && isset( $insight[ $in['recipient'] ] ) ) {
				$insight[ $in['recipient'] ]++;
			}
			if ( ! empty( $in['priority'] ) ) {
				$insight['priority'][ $in['priority'] ] = ( $insight['priority'][ $in['priority'] ] ?? 0 ) + 1;
			}
			if ( ! empty( $in['competitor'] ) || ! empty( $in['competitor_price'] ) ) {
				$insight['competitor']++;
			}
			if ( ! empty( $in['buying_mode'] ) && in_array( $in['buying_mode'], array( 'heart', 'head', 'mixed' ), true ) ) {
				$m = $in['buying_mode'];
				$insight['mode'][ $m ] = $insight['mode'][ $m ] ?? array( 'n' => 0, 'orders' => 0, 'revenue' => 0.0 );
				$insight['mode'][ $m ]['n']++;
				if ( isset( $attributed['by_conv'][ $r['uuid'] ] ) ) {
					$insight['mode'][ $m ]['orders']++;
					$insight['mode'][ $m ]['revenue'] += $attributed['by_conv'][ $r['uuid'] ];
				}
			}
			foreach ( json_decode( (string) $r['unanswered'], true ) ?: array() as $q ) {
				$questions[] = $q;
			}
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT trigger_key, type, COUNT(*) n FROM $events WHERE type IN ('shown','dismissed') AND trigger_key <> 'addon' AND created_at >= %s GROUP BY trigger_key, type", $since ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$t                         = $r['trigger_key'] ?: 'manual';
			$triggers[ $t ]            = $triggers[ $t ] ?? array( 'shown' => 0, 'dismissed' => 0, 'replied' => 0, 'revenue' => 0.0 );
			$triggers[ $t ][ $r['type'] ] = (int) $r['n'];
		}

		$rate_test    = $visits['test'] ? $orders['test'] / $visits['test'] : 0;
		$rate_control = $visits['control'] ? $orders['control'] / $visits['control'] : 0;
		$commission   = $attributed['revenue'] * (float) $s['commission_pct'] / 100;

		$leads = $wpdb->get_results( "SELECT started_at, lead_name, lead_phone, lead_email, lead_marketing, handoff FROM $convs WHERE lead_phone <> '' OR lead_email <> '' ORDER BY id DESC LIMIT 20", ARRAY_A ); // phpcs:ignore WordPress.DB

		return array(
			'days'          => $days,
			'visits'        => $visits,
			'orders'        => $orders,
			'rate_test'     => $rate_test,
			'rate_control'  => $rate_control,
			'uplift'        => $rate_control > 0 ? ( $rate_test - $rate_control ) / $rate_control : null,
			'attributed'    => $attributed,
			'commission'    => $commission,
			'ai_cost_usd'   => $cost,
			'month_cost'    => AISR_Claude::month_cost(),
			'budget'        => (float) $s['monthly_budget_usd'],
			'conversations' => count( $conv_rows ),
			'engaged'       => $engaged,
			'handoffs'      => $handoffs,
			'triggers'      => $triggers,
			'insight'       => $insight,
			'addon'         => array_column( $wpdb->get_results( $wpdb->prepare( "SELECT type, COUNT(*) n FROM $events WHERE trigger_key = 'addon' AND created_at >= %s GROUP BY type", $since ), ARRAY_A ), 'n', 'type' ), // phpcs:ignore WordPress.DB
			'questions'     => array_slice( array_reverse( $questions ), 0, 20 ),
			'leads'         => $leads,
		);
	}

	private static function money( float $v ): string {
		return wp_strip_all_tags( wc_price( $v ) );
	}

	private static function pct( ?float $v ): string {
		return null === $v ? '—' : number_format_i18n( $v * 100, 1 ) . '%';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$days   = (int) ( $_GET['days'] ?? 30 ); // phpcs:ignore WordPress.Security.NonceVerification
		$days   = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
		$st     = self::stats( $days );
		$labels = AISR_Settings::trigger_labels() + array( 'manual' => 'הגולש פתח בעצמו', 'workshop' => 'עמוד סדנה: הזמנה לחוויה', 'workshop_exit' => 'עמוד סדנה: לפני שעוזבים' );
		$pri    = array( 'price' => 'מחיר', 'quality' => 'איכות', 'design' => 'עיצוב', 'fast_shipping' => 'משלוח מהיר', 'other' => 'אחר' );
		$usd    = static fn( float $v ): string => '$' . number_format( $v, 2 );
		$state  = AISR_Claude::budget_state();
		?>
		<div class="wrap aisr-dash" dir="rtl">
			<style>
				.aisr-dash .aisr-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin:16px 0}
				.aisr-dash .aisr-kpi{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px}
				.aisr-dash .aisr-kpi b{display:block;font-size:22px;line-height:1.3;margin-top:4px}
				.aisr-dash .aisr-kpi span{color:#50575e;font-size:12px}
				.aisr-dash .aisr-kpi.main{border-color:#2563eb;box-shadow:inset 0 0 0 1px #2563eb}
				.aisr-dash table.widefat td,.aisr-dash table.widefat th{text-align:right}
				.aisr-dash h2{margin-top:28px}
			</style>
			<h1>נציג מכירות AI · דשבורד</h1>
			<p>
				<?php foreach ( array( 7, 30, 90 ) as $d ) : ?>
					<a class="button <?php echo $d === $days ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'days', $d ) ); ?>"><?php echo esc_html( $d ); ?> ימים</a>
				<?php endforeach; ?>
			</p>
			<?php if ( 'ok' !== $state ) : ?>
				<div class="notice notice-warning"><p>התקציב החודשי נוצל. הפניות היזומות עצורות עד תחילת החודש או עד הגדלת התקציב.</p></div>
			<?php endif; ?>

			<div class="aisr-kpis">
				<div class="aisr-kpi main"><span>תוספת בהמרה מול קבוצת ביקורת</span><b dir="ltr" style="text-align:right"><?php echo esc_html( min( $st['visits']['test'], $st['visits']['control'] ) < 100 ? '—' : self::pct( $st['uplift'] ) ); ?></b><?php if ( min( $st['visits']['test'], $st['visits']['control'] ) < 100 ) : ?><span>צריך לפחות 100 ביקורים בכל קבוצה כדי למדוד. </span><?php endif; ?><span><?php echo esc_html( self::pct( $st['rate_test'] ) . ' מול ' . self::pct( $st['rate_control'] ) ); ?></span></div>
				<div class="aisr-kpi"><span>מכירות משויכות לבוט</span><b><?php echo esc_html( self::money( $st['attributed']['revenue'] ) ); ?></b><span><?php echo esc_html( $st['attributed']['count'] ); ?> הזמנות, אחרי החזרים</span></div>
				<div class="aisr-kpi"><span>עמלה</span><b><?php echo esc_html( self::money( $st['commission'] ) ); ?></b></div>
				<div class="aisr-kpi"><span>עלות AI בתקופה</span><b><?php echo esc_html( $usd( $st['ai_cost_usd'] ) ); ?></b><span>החודש: <?php echo esc_html( $usd( $st['month_cost'] ) . ' מתוך ' . $usd( $st['budget'] ) ); ?></span></div>
				<div class="aisr-kpi"><span>שיחות</span><b><?php echo esc_html( number_format_i18n( $st['conversations'] ) ); ?></b><span><?php echo esc_html( $st['engaged'] ); ?> עם תגובה · <?php echo esc_html( $st['handoffs'] ); ?> הועברו לצוות</span></div>
				<div class="aisr-kpi"><span>ביקורים (בדיקה / ביקורת)</span><b><?php echo esc_html( number_format_i18n( $st['visits']['test'] ) . ' / ' . number_format_i18n( $st['visits']['control'] ) ); ?></b><span>הזמנות: <?php echo esc_html( $st['orders']['test'] . ' / ' . $st['orders']['control'] ); ?></span></div>
			</div>
			<p class="description">רווח לחנות = מכירות משויכות × עמלה − עלות AI. התוספת בהמרה מחושבת מול גולשים שלא קיבלו פניות יזומות, וזו ההוכחה שהבוט מוסיף מכירות ולא רק נזקף להן.</p>

			<h2>ביצועי טריגרים</h2>
			<table class="widefat striped">
				<thead><tr><th>טריגר</th><th>הוצג</th><th>נסגר</th><th>שיחות עם תגובה</th><th>שיעור תגובה</th><th>מכירות משויכות</th></tr></thead>
				<tbody>
				<?php
				uasort( $st['triggers'], static fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] ?: $b['replied'] <=> $a['replied'] );
				foreach ( $st['triggers'] as $key => $t ) :
					$rate = $t['shown'] ? $t['replied'] / $t['shown'] : null;
					?>
					<tr>
						<td><?php echo esc_html( $labels[ $key ] ?? $key ); ?></td>
						<td><?php echo esc_html( $t['shown'] ); ?></td>
						<td><?php echo esc_html( $t['dismissed'] ); ?></td>
						<td><?php echo esc_html( $t['replied'] ); ?></td>
						<td><?php echo esc_html( 'manual' === $key ? '—' : self::pct( $rate ) ); ?></td>
						<td><?php echo esc_html( self::money( $t['revenue'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $st['triggers'] ) : ?><tr><td colspan="6">עדיין אין נתונים.</td></tr><?php endif; ?>
				</tbody>
			</table>

			<h2>מה הגולשים סיפרו</h2>
			<?php $in = $st['insight']; ?>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<tr><td>שיחות שבהן הגולש סיפר על הצורך</td><td><?php echo esc_html( $in['with_need'] ); ?></td></tr>
					<tr><td>קונים כמתנה</td><td><?php echo esc_html( ( $in['gift'] + $in['self'] ) ? self::pct( $in['gift'] / ( $in['gift'] + $in['self'] ) ) : '—' ); ?></td></tr>
					<?php foreach ( $in['priority'] as $k => $n ) : ?>
						<tr><td>הכי חשוב: <?php echo esc_html( $pri[ $k ] ?? $k ); ?></td><td><?php echo esc_html( $n ); ?></td></tr>
					<?php endforeach; ?>
					<tr><td>הזכירו מתחרה או מחיר אחר</td><td><?php echo esc_html( $in['competitor'] ); ?></td></tr>
					<?php $ad = $st['addon'] ?? array(); if ( ! empty( $ad['shown'] ) ) : ?>
						<tr><td>תוספת חכמה אחרי הוספה לסל</td><td><?php echo esc_html( (int) $ad['shown'] . ' הוצגו · ' . (int) ( $ad['add_to_cart'] ?? 0 ) . ' נוספו (' . self::pct( (int) ( $ad['add_to_cart'] ?? 0 ) / (int) $ad['shown'] ) . ')' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( array( 'heart' => 'קונים מהלב', 'head' => 'קונים מהראש', 'mixed' => 'מעורב' ) as $mk => $ml ) : ?>
						<?php if ( ! empty( $in['mode'][ $mk ] ) ) : $mv = $in['mode'][ $mk ]; ?>
							<tr><td><?php echo esc_html( $ml ); ?></td><td><?php echo esc_html( $mv['n'] . ' שיחות · ' . $mv['orders'] . ' הזמנות (' . self::pct( $mv['orders'] / $mv['n'] ) . ') · ' . self::money( $mv['revenue'] ) ); ?></td></tr>
						<?php endif; ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php AISR_Followup::render( $days ); ?>

			<?php AISR_Learning::render(); ?>

			<h2 id="aisr-weekly">דוח שבועי: למה לא קנו ומה לתקן</h2>
			<?php $aisr_rep = get_option( 'aisr_weekly_report' ); ?>
			<?php if ( $aisr_rep ) : ?>
				<p class="description"><?php echo esc_html( 'נוצר ' . wp_date( 'd/m/Y H:i', (int) $aisr_rep['t'] ) . ' מתוך ' . (int) $aisr_rep['chats'] . ' שיחות. נשלח גם במייל פעם בשבוע.' ); ?></p>
				<div style="white-space:pre-wrap;background:#fff;border:1px solid #dcdcde;padding:12px;max-width:900px"><?php echo esc_html( $aisr_rep['text'] ); ?></div>
			<?php else : ?>
				<p class="description">עדיין אין דוח. הדוח נוצר פעם בשבוע מהשיחות ונשלח במייל.<?php $aisr_err = get_option( 'aisr_report_error' ); echo $aisr_err ? ' ' . esc_html( 'הניסיון האחרון נכשל: ' . $aisr_err ) : ''; ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aisr_weekly_report_now">
				<?php wp_nonce_field( 'aisr_weekly_report_now' ); ?>
				<?php submit_button( 'ליצור דוח עכשיו (בלי מייל)', 'secondary', 'aisr_report_btn', false ); ?>
			</form>

			<h2 id="aisr-limits">מגבלת הודעות</h2>
			<p class="description">כל גולש מוגבל ל-40 הודעות בשעה ול-8 תמונות בשעה, נגד שימוש לרעה. מנהל מחובר לא מוגבל.<?php $aisr_rs = (int) get_option( 'aisr_rl_reset_at', 0 ); echo $aisr_rs ? ' ' . esc_html( 'אופס לאחרונה: ' . wp_date( 'd/m/Y H:i', $aisr_rs ) . '.' ) : ''; ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aisr_reset_limits">
				<?php wp_nonce_field( 'aisr_reset_limits' ); ?>
				<?php submit_button( 'איפוס מגבלת ההודעות לכולם', 'secondary', 'aisr_reset_btn', false ); ?>
			</form>

			<h2 id="aisr-lists">מחכים למוצר ותזכורות</h2>
			<p class="description">גולשים שביקשו בצ'אט עדכון כשמוצר חוזר, או תזכורת לפני אירוע. ההודעות מגיעות כמייל יומי עם קישורי וואטסאפ מוכנים.</p>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>סוג</th><th>מוצר / אירוע</th><th>שם</th><th>טלפון / מייל</th><th>מתי</th><th></th></tr></thead>
				<tbody>
				<?php
				$aisr_rows = array();
				foreach ( array( 'waitlist' => 'מחכה למלאי', 'waitlist_ready' => 'חזר למלאי, יישלח מחר' ) as $aisr_kind => $aisr_label ) {
					foreach ( (array) get_option( 'waitlist' === $aisr_kind ? 'aisr_waitlist' : 'aisr_waitlist_ready', array() ) as $aisr_pid => $aisr_people ) {
						$aisr_p = wc_get_product( $aisr_pid );
						foreach ( $aisr_people as $aisr_i => $aisr_w ) {
							$aisr_rows[] = array( $aisr_kind, $aisr_pid . ':' . $aisr_i, $aisr_label, $aisr_p ? $aisr_p->get_name() : '#' . $aisr_pid, $aisr_w['name'], $aisr_w['phone'], wp_date( 'd/m/Y', (int) $aisr_w['t'] ) );
						}
					}
				}
				foreach ( (array) get_option( 'aisr_reminders', array() ) as $aisr_i => $aisr_r ) {
					$aisr_rows[] = array( 'reminder', (string) $aisr_i, 'תזכורת', $aisr_r['occasion'], $aisr_r['name'], $aisr_r['phone'] ?: $aisr_r['email'], $aisr_r['date'] );
				}
				foreach ( $aisr_rows as $aisr_row ) :
					?>
					<tr>
						<td><?php echo esc_html( $aisr_row[2] ); ?></td><td><?php echo esc_html( $aisr_row[3] ); ?></td><td><?php echo esc_html( $aisr_row[4] ); ?></td><td><?php echo esc_html( $aisr_row[5] ); ?></td><td><?php echo esc_html( $aisr_row[6] ); ?></td>
						<td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0"><input type="hidden" name="action" value="aisr_growth_remove"><input type="hidden" name="kind" value="<?php echo esc_attr( $aisr_row[0] ); ?>"><input type="hidden" name="key" value="<?php echo esc_attr( $aisr_row[1] ); ?>"><?php wp_nonce_field( 'aisr_growth_remove' ); ?><button class="button-link-delete" type="submit">מחיקה</button></form></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $aisr_rows ) : ?><tr><td colspan="6">אין כרגע.</td></tr><?php endif; ?>
				</tbody>
			</table>

			<h2>חיפושים באתר שלא מצאו כלום</h2>
			<p class="description">מה גולשים חיפשו ולא מצאו. אפשר להוסיף מוצר, מילת חיפוש בתיאור, או הפניה.</p>
			<?php $aisr_fs = (array) get_option( 'aisr_failed_searches', array() ); uasort( $aisr_fs, static fn( $a, $b ) => $b['n'] <=> $a['n'] ); ?>
			<ul style="list-style:disc;padding-right:20px">
				<?php foreach ( array_slice( $aisr_fs, 0, 20 ) as $f ) : ?><li><?php echo esc_html( $f['q'] . ' (' . (int) $f['n'] . ')' ); ?></li><?php endforeach; ?>
				<?php if ( ! $aisr_fs ) : ?><li>עדיין אין. נאסף רק אחרי פתיחה לכל הגולשים.</li><?php endif; ?>
			</ul>

			<h2>לידים אחרונים</h2>
			<table class="widefat striped">
				<thead><tr><th>תאריך</th><th>שם</th><th>טלפון</th><th>מייל</th><th>אישור דיוור</th></tr></thead>
				<tbody>
				<?php foreach ( $st['leads'] as $l ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $l['started_at'], 'd/m/Y H:i' ) ); ?></td>
						<td><?php echo esc_html( $l['lead_name'] ); ?></td>
						<td><?php echo esc_html( $l['lead_phone'] ); ?></td>
						<td><?php echo esc_html( $l['lead_email'] ); ?></td>
						<td><?php echo $l['lead_marketing'] ? 'כן' : 'לא'; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $st['leads'] ) : ?><tr><td colspan="5">אין לידים עדיין.</td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
