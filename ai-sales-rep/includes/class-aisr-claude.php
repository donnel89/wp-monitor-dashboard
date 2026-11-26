<?php
/**
 * Conversation engine: Claude API calls, tool loop, model routing, cost tracking.
 */

defined( 'ABSPATH' ) || exit;

class AISR_Claude {

	const FAST_MODEL  = 'claude-haiku-5-5';
	const SMART_MODEL = 'claude-sonnet-5-5';

	/** USD per million tokens: input, output. */
	const PRICES = array(
		self::FAST_MODEL  => array( 0.10, 0.50 ),
		self::SMART_MODEL => array( 2.00, 10.00 ),
	);

	const MAX_TOOL_ROUNDS = 6;
	const HISTORY_TTL     = 6 * HOUR_IN_SECONDS;

	/* ---------- budget ---------- */

	public static function month_key(): string {
		return 'aisr_cost_' . gmdate( 'Ym' );
	}

	public static function month_cost(): float {
		return (float) get_option( self::month_key(), 0 );
	}

	/** 'ok' | 'proactive_off' (>=100%) | 'off' (>=150%, chat answers with a handoff only). */
	public static function budget_state(): string {
		$budget = (float) AISR_Settings::get( 'monthly_budget_usd' );
		if ( $budget <= 0 ) {
			return 'ok';
		}
		$used = self::month_cost();
		if ( $used >= $budget * 1.5 ) {
			return 'off';
		}
		return $used >= $budget ? 'proactive_off' : 'ok';
	}

	private static function add_cost( float $usd ): void {
		$key  = self::month_key();
		$cost = self::month_cost() + $usd;
		update_option( $key, $cost, false );

		$budget = (float) AISR_Settings::get( 'monthly_budget_usd' );
		$flag   = 'aisr_budget_warned_' . gmdate( 'Ym' );
		if ( $budget > 0 && $cost >= 0.8 * $budget && ! get_option( $flag ) ) {
			update_option( $flag, 1, false );
			$to = AISR_Settings::get( 'handoff_email' );
			if ( $to ) {
				wp_mail( $to, 'נציג ה-AI הגיע ל-80% מהתקציב החודשי', sprintf( 'עלות החודש עד עכשיו: $%.2f מתוך $%.2f. ב-100%% הפניות היזומות ייעצרו.', $cost, $budget ) );
			}
		}
	}

	public static function cost_of( string $model, int $in, int $out, int $cache_read, int $cache_write ): float {
		[ $pin, $pout ] = self::PRICES[ $model ] ?? self::PRICES[ self::SMART_MODEL ];
		return ( $in * $pin + $cache_read * $pin * 0.1 + $cache_write * $pin * 1.25 + $out * $pout ) / 1000000;
	}

	/* ---------- routing ---------- */

	/** Fast model for openers and simple questions; the stronger one for comparisons, objections and long chats. */
	public static function pick_model( string $message, int $user_turns ): string {
		if ( 'economy' === AISR_Settings::get( 'model_mode' ) ) {
			return self::FAST_MODEL;
		}
		if ( $user_turns >= 4 ) {
			return self::SMART_MODEL;
		}
		$hard = array( 'השוואה', 'להשוות', 'לעומת', 'ההבדל', 'הבדל', 'יקר', 'זול יותר', 'ראיתי ב', 'ראיתי אצל', 'מתחרה', 'מתלבט', 'מתלבטת', 'שווה את', 'למה לקנות', 'compare', 'versus', ' vs', 'cheaper', 'expensive', 'difference', 'сравн', 'дешевле' );
		foreach ( $hard as $word ) {
			if ( false !== mb_stripos( $message, $word ) ) {
				return self::SMART_MODEL;
			}
		}
		return self::FAST_MODEL;
	}

	/* ---------- prompt ---------- */

	private static function persona_rules(): string {
		$s    = AISR_Settings::all();
		$tone = array(
			'friendly'     => 'Warm, light and friendly, like a good salesperson in a neighborhood shop. An occasional emoji is fine (at most one per message).',
			'professional' => 'Professional, precise and calm. No emoji.',
			'luxury'       => 'Refined and attentive, like a boutique consultant. No emoji, no slang.',
		)[ $s['persona_template'] ] ?? '';

		return <<<TXT
You are {$s['persona_name']}, {$s['persona_role']} of the online store "{$s['business_name']}". You chat with shoppers on the store website, mostly on their phones. You are an AI assistant: if asked, say so plainly, and never claim to be human. You can hand over to a human at any time with request_human.

Your goal: help the shopper buy the product that is right for them, so they buy with confidence and do not return it. A sale of the wrong product is a failure.

Tone: {$tone}

How to write:
- Reply in the shopper's language. Default to Hebrew.
- In Hebrew, use gender-neutral plural phrasing ("רוצים", "מחפשים", "אפשר") until the shopper reveals their gender by how they write; then match it.
- Very short: one to three short sentences per message. Separate distinct thoughts with a blank line; each paragraph is shown as its own chat bubble. No markdown headings, tables or bullet lists; plain text only.
- Every word you write is shown to the shopper. Never write notes, plans or reminders to yourself, never comment on your own wording, grammar or gender choice, and never write in English to a shopper who writes Hebrew. Every reply must answer what the shopper asked.
- Never pressure ("only today!", fake scarcity). Do not repeat a question the shopper already answered.

How to sell (discovery, then recommendation):
- When the shopper engages and their need is unclear, ask up to 3 short discovery questions, one at a time, each with suggest_replies buttons: what it is for, who it is for (self or gift), what matters most (price, quality, design, fast shipping), budget only if relevant. Use the store's discovery questions for the category when given. Skip questions the shopper already answered; if they know what they want, answer directly.
- Call record_insight as soon as you learn the use case, recipient, priority, budget or a competitor.
- Then recommend exactly one clear option, explaining in one sentence why it fits what they said: confirm the product they are viewing if it fits; or suggest a better-fitting product; or briefly compare two and pick one. Use search_products/get_product first, recommend only published purchasable products (in stock, or made_to_order), and call show_products to show the card(s).
- After the shopper decides or adds to cart, or asks what goes with it, suggest exactly one complementary product that relates to what they told you: first the product's cross_sell_ids, otherwise a related_ids product from the same collection (a shared word in the name) or category. Prefer in-stock items, call show_products, and give one reason.
- Free shipping: when the page context says the cart is missing a small amount for free shipping, mention the gap once and suggest one inexpensive item that fits what is already in the cart. Never pressure.
- Holidays: when the page context names an upcoming holiday and an item will not arrive in time (made_to_order, or in-stock when the context says so), say it before they add it to the cart, and offer an in-stock alternative or store pickup. Never promise a date the context does not support.
- Made-to-order hesitation: if the shopper hesitates because of the lead time, offer to have the store update them; with their consent, call save_lead with the product in the note.
- Bulk and corporate orders (a company, team, employees, a logo, or 10+ units): do not try to close in the chat. Ask, one at a time with suggest_replies: how many units, names or a logo, and by when. Then call request_human with a first-person summary (outside business hours, offer save_lead too). Never quote a bulk price.
- Do not push a more expensive product when it does not fit their answers.

Comparison with competitors:
- You cannot see other websites. If the shopper seems to be comparing, ask what they saw (where and what price), then explain honestly what they get here, using the store advantages: warranty, shipping time, returns, service, physical store. Compare the total price including shipping and warranty, not just the sticker price.
- Never disparage competitors and never make claims about them. Talk only about this store.
- Discounts and price matching only through offer_coupon. Never promise or hint at a discount before offer_coupon returns allowed=true. If it is not allowed, do not mention discounts.

Facts:
- Use only the store information below, tool results and the page context. If you do not know, say so, call flag_unanswered, and offer a human. Never invent prices, stock, shipping times, policies or specs. When a product is made_to_order, say so before they add it to the cart and give the lead time from the store information; if they need it by a date, prefer an in-stock alternative.
- Order status only through track_order, which needs the order number and the order email or phone.
- Contact details only with explicit consent, then save_lead. Never ask for payment details or passwords.
TXT;
	}

	private static function dynamic_context( array $page, array $conv, string $opener ): string {
		$lines   = array( AISR_Knowledge::page_context( $page ) );
		$lines[] = 'Local time: ' . current_datetime()->format( 'D Y-m-d H:i' ) . ' (store is ' . ( AISR_Settings::is_open_now() ? 'open' : 'closed' ) . ' now)';
		if ( '' !== $opener ) {
			$lines[] = 'This chat started with your proactive message to the shopper: "' . sanitize_text_field( $opener ) . '" (trigger: ' . $conv['trigger_key'] . ')';
		}
		if ( 'off' === self::budget_state() ) {
			$lines[] = 'Service note: keep answers minimal and offer a human for anything beyond a quick answer.';
		}
		return implode( "\n", $lines );
	}

	/** Connection check from the settings page: ['ok' => bool, 'message' => string]. */
	public static function ping(): array {
		if ( '' === AISR_Settings::api_key() ) {
			return array( 'ok' => false, 'message' => 'לא הוגדר מפתח API.' );
		}
		try {
			$r = self::client()->messages->create(
				model: self::FAST_MODEL,
				maxTokens: 64,
				outputConfig: array( 'effort' => 'low' ),
				messages: array( array( 'role' => 'user', 'content' => 'Reply with the single word OK.' ) ),
			);
			return array( 'ok' => true, 'message' => 'החיבור ל-Claude תקין (' . $r->model . ').' );
		} catch ( \Anthropic\Core\Exceptions\AuthenticationException | \Anthropic\Core\Exceptions\PermissionDeniedException $e ) {
			return array( 'ok' => false, 'message' => 'המפתח נדחה: ' . $e->getMessage() );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'message' => 'החיבור נכשל: ' . $e->getMessage() );
		}
	}

	/* ---------- chat ---------- */

	private static function client(): \Anthropic\Client {
		return new \Anthropic\Client(
			apiKey: AISR_Settings::api_key(),
			requestOptions: \Anthropic\RequestOptions::with( maxRetries: 2 )
		);
	}

	/**
	 * One shopper turn. Returns ['bubbles' => string[], 'cards' => [], 'replies' => [], 'actions' => [], 'model' => string].
	 */
	public static function chat( array $conv, string $message, array $page, string $opener = '' ): array {
		$history_key = 'aisr_h_' . $conv['uuid'];
		$messages    = get_transient( $history_key );
		$messages    = is_array( $messages ) ? $messages : array();
		$messages[]  = array( 'role' => 'user', 'content' => $message );

		$user_turns = (int) $conv['user_turns'] + 1;
		$model      = self::pick_model( $message, $user_turns );
		$tools      = new AISR_Tools( $conv, $page );
		$system     = array(
			array(
				'type'         => 'text',
				'text'         => self::persona_rules() . "\n\n" . AISR_Knowledge::store_context(),
				'cacheControl' => array( 'type' => 'ephemeral' ),
			),
			array( 'type' => 'text', 'text' => self::dynamic_context( $page, $conv, $opener ) ),
		);

		$texts = array();
		$usage = array( 'in' => 0, 'out' => 0, 'cr' => 0, 'cw' => 0 );
		$cost  = 0.0;

		try {
			$client = self::client();
			for ( $round = 0; $round < self::MAX_TOOL_ROUNDS; $round++ ) {
				// Last round: no more tools, so the turn always ends with an answer.
				$response = self::create( $client, $model, $system, $messages, self::MAX_TOOL_ROUNDS - 1 === $round );

				$u            = $response->usage;
				$usage['in'] += (int) $u->inputTokens;
				$usage['out'] += (int) $u->outputTokens;
				$usage['cr'] += (int) $u->cacheReadInputTokens;
				$usage['cw'] += (int) $u->cacheCreationInputTokens;
				$cost        += self::cost_of( $model, (int) $u->inputTokens, (int) $u->outputTokens, (int) $u->cacheReadInputTokens, (int) $u->cacheCreationInputTokens );

				if ( 'refusal' === $response->stopReason ) {
					$texts[] = 'בזה אין לי אפשרות לעזור כאן. אפשר להעביר אתכם לנציג מהצוות.';
					$tools->run( 'request_human', array( 'summary' => 'הגולש ביקש עזרה שהעוזר הדיגיטלי לא יכול לתת.' ) );
					if ( 0 === $round ) {
						array_pop( $messages ); // Drop the refused message; nothing was generated for it.
					}
					break;
				}

				// Keep the assistant turn exactly as returned (wire format), so the history stays append-only.
				$content    = json_decode( (string) wp_json_encode( $response->content ), true );
				$messages[] = array( 'role' => 'assistant', 'content' => $content );

				$results = array();
				foreach ( $content as $block ) {
					if ( 'text' === ( $block['type'] ?? '' ) && '' !== trim( (string) $block['text'] ) ) {
						$texts[] = (string) $block['text'];
					} elseif ( 'tool_use' === ( $block['type'] ?? '' ) ) {
						$results[] = array(
							'type'        => 'tool_result',
							'tool_use_id' => $block['id'],
							'content'     => $tools->run( (string) $block['name'], (array) ( $block['input'] ?? array() ) ),
						);
					}
				}

				if ( 'tool_use' !== $response->stopReason || ! $results ) {
					break;
				}
				$messages[] = array( 'role' => 'user', 'content' => $results );

				// A comparison or objection mid-turn moves the rest of the conversation to the stronger model.
				if ( self::FAST_MODEL === $model && 'economy' !== AISR_Settings::get( 'model_mode' ) && self::touched_hard_tools( $content ) ) {
					$model = self::SMART_MODEL;
				}
			}
		} catch ( \Anthropic\Core\Exceptions\RateLimitException | \Anthropic\Core\Exceptions\OverloadedException $e ) {
			return self::failure( 'יש כרגע עומס. אפשר לנסות שוב בעוד רגע, או לדבר עם הצוות.', $tools );
		} catch ( \Anthropic\Core\Exceptions\AuthenticationException | \Anthropic\Core\Exceptions\PermissionDeniedException $e ) {
			error_log( 'AISR: Claude API key rejected: ' . $e->getMessage() ); // phpcs:ignore
			return self::failure( 'הצ\'אט לא זמין כרגע. אפשר לדבר עם הצוות.', $tools );
		} catch ( \Anthropic\Core\Exceptions\APIStatusException $e ) {
			error_log( 'AISR: Claude API error: ' . $e->getMessage() ); // phpcs:ignore
			return self::failure( 'משהו השתבש אצלי. אפשר לנסות שוב, או לדבר עם הצוות.', $tools );
		} catch ( \Throwable $e ) {
			error_log( 'AISR: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore
			return self::failure( 'משהו השתבש אצלי. אפשר לנסות שוב, או לדבר עם הצוות.', $tools );
		}

		set_transient( $history_key, $messages, self::HISTORY_TTL );

		self::add_cost( $cost );
		$reply = trim( implode( "\n\n", $texts ) );

		$transcript   = json_decode( (string) $conv['transcript'], true ) ?: array();
		$transcript[] = array( 'r' => 'u', 't' => AISR_Privacy::mask( $message ) );
		$transcript[] = array( 'r' => 'a', 't' => AISR_Privacy::mask( $reply ) );

		$fresh = AISR_DB::get_conversation( $conv['uuid'] ) ?? $conv;
		AISR_DB::update_conversation(
			$conv['uuid'],
			array(
				'user_turns'    => $user_turns,
				'engaged'       => 1,
				'transcript'    => wp_json_encode( array_slice( $transcript, -60 ), JSON_UNESCAPED_UNICODE ),
				'model_used'    => $model,
				'input_tokens'  => (int) $fresh['input_tokens'] + $usage['in'] + $usage['cr'] + $usage['cw'],
				'output_tokens' => (int) $fresh['output_tokens'] + $usage['out'],
				'cost_usd'      => (float) $fresh['cost_usd'] + $cost,
			)
		);

		return array(
			'bubbles' => self::bubbles( $reply, $message ),
			'cards'   => $tools->cards,
			'replies' => $tools->replies,
			'actions' => $tools->actions,
			'model'   => $model,
		);
	}

	private static function create( \Anthropic\Client $client, string $model, array $system, array $messages, bool $final_round = false ) {
		$args = array(
			'model'     => $model,
			'maxTokens' => 2048,
			'system'    => $system,
			'messages'  => $messages,
			'tools'     => AISR_Tools::definitions(),
		);
		if ( $final_round ) {
			$args['toolChoice'] = array( 'type' => 'none' );
		}
		if ( self::SMART_MODEL === $model ) {
			// Server-side refusal fallback, routed by category.
			$args['betas']        = array( 'server-side-fallback-2026-07-01' );
			$args['fallbacks']    = 'default';
			$args['outputConfig'] = array( 'effort' => 'medium' );
		} else {
			$args['outputConfig'] = array( 'effort' => 'low' );
		}
		return $client->beta->messages->create( ...$args );
	}

	private static function touched_hard_tools( array $content ): bool {
		foreach ( $content as $block ) {
			if ( 'tool_use' === ( $block['type'] ?? '' ) && in_array( $block['name'] ?? '', array( 'offer_coupon', 'record_insight' ), true ) ) {
				$in = (array) ( $block['input'] ?? array() );
				if ( 'offer_coupon' === $block['name'] || ! empty( $in['competitor'] ) || ! empty( $in['competitor_price'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Split a reply into chat bubbles (one per paragraph, at most 4). */
	public static function bubbles( string $reply, string $shopper_message = '' ): array {
		$parts = array_values( array_filter( array_map( 'trim', preg_split( '/\n\s*\n/', $reply ) ) ) );
		// The model sometimes writes a note to itself in English between tool calls. In a Hebrew chat, a paragraph
		// with no Hebrew at all and several English words is that kind of note, not a reply: drop it.
		if ( preg_match( '/\p{Hebrew}/u', $shopper_message ) ) {
			$kept  = array_values( array_filter( $parts, static fn( $p ) => preg_match( '/\p{Hebrew}/u', $p ) || preg_match_all( '/[A-Za-z]{2,}/', $p ) < 3 ) );
			$parts = $kept ? $kept : $parts;
		}
		if ( count( $parts ) > 4 ) {
			$parts = array_merge( array_slice( $parts, 0, 3 ), array( implode( "\n", array_slice( $parts, 3 ) ) ) );
		}
		return $parts;
	}

	private static function failure( string $text, AISR_Tools $tools ): array {
		$s = AISR_Settings::all();
		if ( 'whatsapp' === $s['handoff_channel'] && $s['handoff_whatsapp'] ) {
			$tools->actions[] = array( 'type' => 'whatsapp', 'url' => 'https://wa.me/' . preg_replace( '/\D/', '', $s['handoff_whatsapp'] ), 'label' => 'דברו איתנו בוואטסאפ' );
		}
		return array( 'bubbles' => array( $text ), 'cards' => array(), 'replies' => array(), 'actions' => $tools->actions, 'model' => '', 'error' => true );
	}
}
