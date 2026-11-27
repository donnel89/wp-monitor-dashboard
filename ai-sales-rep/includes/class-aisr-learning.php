<?php
/**
 * Learning queue: questions the bot could not answer, plus suggestions from the weekly report, wait for the
 * store owner. Only an answer the owner saves reaches the bot, as a verified fact: general answers go into the
 * store information, product answers into that product's details. Nothing a shopper writes changes the bot by itself.
 */

defined( 'ABSPATH' ) || exit;

class AISR_Learning {

	const OPT       = 'aisr_learned';
	const DISMISSED = 'aisr_learn_dismissed';
	const MAX       = 200;
	/** Questions shoppers of handmade ceramics ask before buying, shown until the owner answers or dismisses them. */
	const SEED      = array( 'מה קורה אם כלי מגיע שבור במשלוח?', 'איך הכלים נארזים למשלוח?' );

	public static function init(): void {
		add_action( 'admin_post_aisr_learn_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_aisr_learn_dismiss', array( __CLASS__, 'dismiss' ) );
		add_action( 'admin_post_aisr_learn_delete', array( __CLASS__, 'delete' ) );
	}

	public static function key( string $q ): string {
		$q = preg_replace( '/[\s\?\!\.,״"\']+/u', ' ', mb_strtolower( trim( $q ) ) );
		return md5( trim( (string) $q ) );
	}

	/** @return array<int, array{id:string,q:string,a:string,pid:int,t:int,src:string,key:string}> */
	public static function all(): array {
		return array_values( array_filter( (array) get_option( self::OPT, array() ), 'is_array' ) );
	}

	/** Owner answers that apply to the whole store. */
	public static function general(): array {
		return array_values( array_filter( self::all(), static fn( $e ) => 0 === (int) $e['pid'] ) );
	}

	/** Owner answers for one product (or its parent, for a variation). */
	public static function for_product( int $pid ): array {
		$parent = wp_get_post_parent_id( $pid );
		$ids    = array_filter( array( $pid, $parent ) );
		$out    = array();
		foreach ( self::all() as $e ) {
			if ( in_array( (int) $e['pid'], $ids, true ) ) {
				$out[] = array( 'q' => $e['q'], 'a' => $e['a'] );
			}
		}
		return $out;
	}

	/**
	 * What waits for the owner: unanswered questions from the last 60 days (with how many chats asked and on
	 * which product), then the weekly report's suggestions. Answered and dismissed questions are left out.
	 */
	public static function queue(): array {
		global $wpdb;
		$done  = array_column( self::all(), 'key' );
		$done  = array_merge( $done, (array) get_option( self::DISMISSED, array() ) );
		$items = array();
		$table = AISR_DB::conversations();
		$since = gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, unanswered, started_at FROM $table WHERE started_at >= %s AND unanswered IS NOT NULL AND unanswered NOT IN ('', '[]') ORDER BY id DESC LIMIT 500", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $r ) {
			foreach ( json_decode( (string) $r['unanswered'], true ) ?: array() as $q ) {
				$q = trim( (string) $q );
				$k = self::key( $q );
				if ( '' === $q || in_array( $k, $done, true ) ) {
					continue;
				}
				if ( isset( $items[ $k ] ) ) {
					$items[ $k ]['n']++;
					continue;
				}
				$items[ $k ] = array( 'key' => $k, 'q' => $q, 'a' => '', 'pid' => (int) $r['product_id'], 'n' => 1, 'src' => 'chat', 't' => $r['started_at'] );
			}
		}
		$rep = get_option( 'aisr_weekly_report' );
		foreach ( (array) ( $rep['suggestions'] ?? array() ) as $s ) {
			$k = self::key( (string) $s['q'] );
			if ( ! in_array( $k, $done, true ) && ! isset( $items[ $k ] ) ) {
				$items[ $k ] = array( 'key' => $k, 'q' => $s['q'], 'a' => (string) $s['a'], 'pid' => 0, 'n' => 0, 'src' => 'report', 't' => '' );
			}
		}
		uasort( $items, static fn( $a, $b ) => $b['n'] <=> $a['n'] );
		$seed = array();
		foreach ( self::SEED as $q ) {
			$k = self::key( $q );
			if ( ! in_array( $k, $done, true ) ) {
				unset( $items[ $k ] );
				$seed[] = array( 'key' => $k, 'q' => $q, 'a' => '', 'pid' => 0, 'n' => 0, 'src' => 'seed', 't' => '' );
			}
		}
		return array_slice( array_merge( $seed, array_values( $items ) ), 0, 30 );
	}

	/** Splits the weekly report's machine part ("---" then "question || draft answer" lines) from the text. */
	public static function split_report( string $text ): array {
		$parts = preg_split( '/^\s*-{3,}\s*$/m', $text, 2 );
		$sugg  = array();
		foreach ( preg_split( '/\R/u', (string) ( $parts[1] ?? '' ) ) as $line ) {
			$line = trim( preg_replace( '/^[\-\*\d\.\)\s]+/u', '', $line ) );
			if ( false === strpos( $line, '||' ) ) {
				continue;
			}
			[ $q, $a ] = array_map( 'trim', explode( '||', $line, 2 ) );
			if ( '' !== $q ) {
				$sugg[] = array( 'q' => mb_substr( sanitize_text_field( $q ), 0, 300 ), 'a' => mb_substr( sanitize_textarea_field( $a ), 0, 1000 ) );
			}
		}
		return array( trim( $parts[0] ), array_slice( $sugg, 0, 8 ) );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( $action );
	}

	private static function back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=aisr-dashboard' ) . '#aisr-learn' );
		exit;
	}

	private static function changed(): void {
		AISR_Knowledge::flush_cache(); // General answers live in the cached store information.
	}

	public static function save(): void {
		self::guard( 'aisr_learn_save' );
		// phpcs:disable WordPress.Security.NonceVerification
		$q   = mb_substr( sanitize_text_field( wp_unslash( (string) ( $_POST['q'] ?? '' ) ) ), 0, 300 );
		$a   = mb_substr( sanitize_textarea_field( wp_unslash( (string) ( $_POST['a'] ?? '' ) ) ), 0, 1000 );
		$pid = absint( $_POST['pid'] ?? 0 );
		$src = in_array( $_POST['src'] ?? '', array( 'report', 'seed' ), true ) ? sanitize_key( $_POST['src'] ) : 'chat';
		$old = sanitize_key( (string) ( $_POST['key'] ?? '' ) );
		// phpcs:enable
		if ( '' === $q || '' === trim( $a ) ) {
			self::back();
		}
		if ( $pid && 'product' !== get_post_type( $pid ) ) {
			$pid = 0;
		}
		$list = array_filter( self::all(), static fn( $e ) => $e['key'] !== $old && $e['key'] !== self::key( $q ) );
		$list[] = array(
			'id'  => wp_generate_password( 8, false ),
			'key' => $old ?: self::key( $q ),
			'q'   => $q,
			'a'   => $a,
			'pid' => $pid,
			't'   => time(),
			'src' => $src,
		);
		update_option( self::OPT, array_slice( array_values( $list ), -self::MAX ), false );
		self::changed();
		self::back();
	}

	public static function dismiss(): void {
		self::guard( 'aisr_learn_dismiss' );
		$k = sanitize_key( (string) ( $_POST['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $k ) {
			$d   = (array) get_option( self::DISMISSED, array() );
			$d[] = $k;
			update_option( self::DISMISSED, array_slice( array_unique( $d ), -500 ), false );
		}
		self::back();
	}

	public static function delete(): void {
		self::guard( 'aisr_learn_delete' );
		$id = sanitize_key( (string) ( $_POST['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		update_option( self::OPT, array_values( array_filter( self::all(), static fn( $e ) => strtolower( $e['id'] ) !== $id ) ), false );
		self::changed();
		self::back();
	}

	/* ---------- dashboard section ---------- */

	public static function render(): void {
		$queue   = self::queue();
		$learned = array_reverse( self::all() );
		$post    = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<h2 id="aisr-learn">תור למידה: שאלות שמחכות לתשובה</h2>
		<p class="description">שאלות שהבוט לא ידע לענות בשיחות, והצעות מהדוח השבועי. כותבים תשובה פעם אחת ושומרים, ומהשיחה הבאה הבוט עונה לבד. אפשר לערוך גם את נוסח השאלה. תשובה לשאלה על מוצר מסוים אפשר לשמור רק למוצר הזה.</p>
		<?php if ( ! $queue ) : ?>
			<p>אין כרגע שאלות פתוחות.</p>
		<?php endif; ?>
		<?php foreach ( $queue as $it ) : ?>
			<?php $p = $it['pid'] ? wc_get_product( $it['pid'] ) : null; ?>
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;margin:10px 0;max-width:900px">
				<div style="color:#50575e;font-size:12px;margin-bottom:6px">
					<?php
					echo esc_html(
						'seed' === $it['src']
							? 'שאלה שקונים של קרמיקה שואלים לפני קנייה, ועדיין אין עליה תשובה באתר'
							: ( 'report' === $it['src']
							? 'הצעה מהדוח השבועי: לבדוק ולתקן את הטיוטה לפני שמירה'
							: ( $it['n'] > 1 ? 'נשאל ב-' . $it['n'] . ' שיחות' : 'נשאל בשיחה אחת' ) . ( $p ? ' · בעמוד: ' . $p->get_name() : '' ) )
					);
					?>
				</div>
				<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="margin:0">
					<input type="hidden" name="action" value="aisr_learn_save">
					<input type="hidden" name="key" value="<?php echo esc_attr( $it['key'] ); ?>">
					<input type="hidden" name="src" value="<?php echo esc_attr( $it['src'] ); ?>">
					<?php wp_nonce_field( 'aisr_learn_save' ); ?>
					<p style="margin:0 0 6px"><label>שאלה<br><input type="text" name="q" value="<?php echo esc_attr( $it['q'] ); ?>" class="large-text" maxlength="300"></label></p>
					<p style="margin:0 0 6px"><label>תשובה<br><textarea name="a" rows="2" class="large-text" maxlength="1000" placeholder="מה הבוט צריך לענות"><?php echo esc_textarea( $it['a'] ); ?></textarea></label></p>
					<?php if ( $p ) : ?>
						<p style="margin:0 0 6px">
							<label><input type="radio" name="pid" value="<?php echo esc_attr( $p->get_id() ); ?>" checked> רק למוצר הזה</label>
							&nbsp; <label><input type="radio" name="pid" value="0"> לכל החנות</label>
						</p>
					<?php else : ?>
						<input type="hidden" name="pid" value="0">
					<?php endif; ?>
					<button class="button button-primary" type="submit">לשמור, הבוט יענה כך</button>
					<button class="button-link" type="submit" form="aisr-dis-<?php echo esc_attr( $it['key'] ); ?>" style="margin-right:12px">להתעלם</button>
				</form>
				<form id="aisr-dis-<?php echo esc_attr( $it['key'] ); ?>" method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="display:none">
					<input type="hidden" name="action" value="aisr_learn_dismiss">
					<input type="hidden" name="key" value="<?php echo esc_attr( $it['key'] ); ?>">
					<?php wp_nonce_field( 'aisr_learn_dismiss' ); ?>
				</form>
			</div>
		<?php endforeach; ?>

		<h3>מה הבוט למד (<?php echo esc_html( count( $learned ) ); ?>)</h3>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th>שאלה</th><th>תשובה</th><th>חל על</th><th>מתי</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $learned as $e ) : ?>
				<?php $lp = $e['pid'] ? wc_get_product( (int) $e['pid'] ) : null; ?>
				<tr>
					<td><?php echo esc_html( $e['q'] ); ?></td>
					<td style="white-space:pre-wrap"><?php echo esc_html( $e['a'] ); ?></td>
					<td><?php echo esc_html( $lp ? $lp->get_name() : 'כל החנות' ); ?></td>
					<td><?php echo esc_html( wp_date( 'd/m/Y', (int) $e['t'] ) ); ?></td>
					<td><form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="margin:0"><input type="hidden" name="action" value="aisr_learn_delete"><input type="hidden" name="id" value="<?php echo esc_attr( $e['id'] ); ?>"><?php wp_nonce_field( 'aisr_learn_delete' ); ?><button class="button-link-delete" type="submit">מחיקה</button></form></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $learned ) : ?><tr><td colspan="5">עדיין לא נשמרו תשובות.</td></tr><?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
