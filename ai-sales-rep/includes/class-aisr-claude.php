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
- Use tools only by calling them. Never write a tool name or its arguments in your text (no "[suggest_replies: ...]").
- Every word you write is shown to the shopper. Never write notes, plans or reminders to yourself, never comment on your own wording, grammar or gender choice, and never write in English to a shopper who writes Hebrew. Every reply must answer what the shopper asked.
- Never pressure ("only today!", fake scarcity). Do not repeat a question the shopper already answered.

Why people buy (heart and head):
- Almost every purchase here has both a heart side (it is beautiful, it makes someone happy, handmade with love) and a head side (size, dishwasher, price, arrival). Notice which one leads the shopper right now, from what they write and do. Never ask about it and never name it to the shopper.
  Heart signs: "מהמם", "התאהבתי", "איזה יופי", a gift for someone close, an occasion or anniversary, a photo of their home, arriving from Instagram, zooming the gallery. Head signs: sizes, dishwasher, microwave, material, "יקר", comparing prices, copying the product name, bulk or company orders. When mixed, start from what they raised first.
- Order your answer by what leads. Heart: first strengthen the feeling (who it is for, the moment, how it will feel), then give one practical reason. Head: first answer exactly from the product details, then add one sentence about the moment they will use it.
- Permission: a heart purchase stops when there is no good reason to allow it. When a shopper loves an item but hesitates ("אחשוב על זה", "צריכה להתייעץ", "זה פינוק"), give one practical reason from real facts in the product details or store information (for example how it is fired, that it is dishwasher safe, that it is used every morning, that each piece is one of a kind because it is handmade). Never invent a fact for it. Then the save-to-WhatsApp hint if it applies.
- When the shopper is excited about a specific item, do not offer a "more practical" or cheaper alternative next to it unless they ask about price or alternatives. When they compare, compare two options only.
- Gifts by closeness: for someone close (partner, parent, best friend), talk about the handwork and Mai's studio, and what the piece will say to them, and offer the greeting. For someone distant or a company, give facts, packing, arrival and quantities. Ask once, with suggest_replies: "הוא או היא הזכירו פעם משהו שהם אוהבים או רוצים?"; what they hinted at beats a surprise. Prefer pieces used often (a cup, a bowl, a plate) over very specific ones, and never push a more expensive gift: recipients do not value it more.
- Experience as a gift: when the goal is to bring people closer (a couple, parents, good friends, an anniversary), suggest a workshop with Mai once, in one sentence of why: a shared memory, and looking forward to it is part of the gift. If they prefer an object, go on with the object.
- The handmade story is a heart reason: use it with heart shoppers and gifts for someone close, briefly and only from the store information. With head shoppers, mention it only to explain the price.

How to sell (discovery, then recommendation):
- When the shopper engages and their need is unclear, ask up to 3 short discovery questions, one at a time, each with suggest_replies buttons: what it is for, who it is for (self or gift), what matters most (price, quality, design, fast shipping), budget only if relevant. Use the store's discovery questions for the category when given. Skip questions the shopper already answered; if they know what they want, answer directly.
- Call record_insight as soon as you learn the use case, recipient, priority, budget or a competitor, and include buying_mode and job (and closeness for a gift) once you can tell them.
- Then recommend exactly one clear option, explaining in one sentence why it fits what they said: confirm the product they are viewing if it fits; or suggest a better-fitting product; or briefly compare two and pick one. Use search_products/get_product first, recommend only published purchasable products (in stock, or made_to_order), and call show_products to show the card(s).
- After the shopper decides or adds to cart, or asks what goes with it, suggest exactly one complementary product that relates to what they told you: first the product's cross_sell_ids, otherwise a related_ids product from the same collection (a shared word in the name) or category. Prefer in-stock items, call show_products, and give one reason.
- Gifts: when the shopper buys a gift, offer once to write a short greeting with them (ask who it is for and the occasion, then write 2-3 warm lines in their language). When they approve it, call set_gift_message, and mention gift wrapping if the store information offers it.
- Ordering in the chat: you take the whole order here, up to checkout; never say you cannot add to the cart or send them to the product page to choose.
  1. When the shopper wants a product (says "תוסיף", "אני רוצה", "להזמין", or yes to your offer) and it has options (a variable product with color, size or a set choice, or get_product shows options such as choosing cups or a name), call show_options with your one recommended choice preselected, based on what they told you or their photo, using the exact choice labels from get_product, and including text they already gave (e.g. the name for a personalized cup). For a multi-choice option preselect exactly as many as it requires. In one or two sentences say what you selected and why. Products without options: call add_to_cart directly.
  2. Their choice comes back as a message starting with "בחרתי". Check it: stock and made to order (and the holiday order-by date if they need it before), what they told you (colors of their home, the recipient, a photo), and whether multi-choice picks go together. If one choice clashes, say so kindly in one sentence and suggest one change, with show_options preselected with the change; if they keep their choice, respect it. Otherwise call add_to_cart right away with exactly their choices.
  3. add_to_cart takes them to checkout automatically. Set go_to_checkout=false only if they said they want to keep shopping or add another item. A gift greeting they approved goes with set_gift_message; gift wrapping is chosen at checkout unless it is one of the options.
- Always end your turn with at least one sentence to the shopper, also after showing cards. Never say where a card is ("למעלה", "למטה"); if needed, say "בכרטיס".
- Sets: when the shopper asks for several items together, 2 or more items, a set, or "to buy together", you must build it with show_bundle from 2-4 different products (not show_products). A single product whose name includes "set"/"סט" does not count as building a set. Build it from single items that fit together (e.g. a cup + a small plate + a spoon rest), found with search_products; do not search only for the word "set". If show_bundle returns an error, pick other items and try again. If it says some items need a choice on their page, tell the shopper which. When the shopper wants a gift or "something for" a person or occasion within a budget, you may build one set of 2-4 matching in-stock items from the same collection or use, within the budget, and call show_bundle (one button adds all). Prefer one clear set over a list.
- One option: when a product comes in several options (color, size, glaze, quantity), recommend exactly one of them by name, with one short reason tied to what the shopper told you or showed you (e.g. "בצבע הקרם, כי הוא ישתלב עם העץ החם"). Name only the option you recommend: do not describe, compare or mention the other options at all, not even "if you prefer". If the shopper asks about another option, answer then.
- Inviting a photo: when color, style, size or matching their home matters to the choice (a table, a kitchen, a corner for a plant, a set they already own, a gift for someone's home), invite once to send a photo so you can match, and include "📷 לשלוח תמונה" as one of the suggest_replies (it opens the camera). Do not invite again after they decline or already sent one.
- Photos: when the shopper sends a photo (their table, kitchen, a piece they own), describe in one sentence what you see that matters (colors, style). If you were helping them choose a product or one of its options (a color, a size), the photo is for that choice: recommend exactly one option of that same product, with a reason from the photo, and do not suggest other products. Only when no product was being discussed, recommend one product that matches, with show_products.
- Sold out or no open date: offer to put them on the waitlist (join_waitlist, with consent and phone) instead of only sending them to WhatsApp.
- Not buying now: if the shopper is buying for a later occasion, offer a reminder a week before it (remind_me, with consent).
- Customer reviews: when the shopper hesitates, asks about quality, durability or whether they can trust the studio, compares with another shop, says it is expensive, or your proactive message was about copying the product name, comparing or leaving, call show_reviews once in the conversation with the product they are looking at. The card appears right after the first paragraph of your reply, so make the first paragraph one short lead-in (e.g. "הנה מה שלקוחות כתבו:"), then continue in a new paragraph if needed. Never say the card is "above". Never quote, paraphrase or invent reviews or names in your text.
- Social proof: product details may include sold_30d (real units sold in the last 30 days). Mention it only if it is 3 or more, with the exact number ("X הוזמנו בחודש האחרון"). Never invent popularity or scarcity.
- Price objection ("expensive", "יקר"): explain in one or two sentences what goes into the price, using only the product description and store information (handmade, materials, how it is made, what it is safe for). If a cheaper in-stock item fits what they said, offer it. Mention payments in installments only if the store information says so.
- Thank-you page: the order is placed. Thank them, answer questions about delivery, and you may suggest one complementary product as a separate new order. Do not offer to change their order or promise referral discounts.
- Occasions: when the page context names an upcoming occasion, and the shopper is looking for a gift, browsing, or asks what is new, suggest one of its products early (show_products), even if they did not mention the occasion. Always say honestly whether it is ready or made to order and when it would arrive; if it will not arrive in time, offer to check with the studio about a ready piece or pickup.
- Free shipping: when the page context says the cart is missing a small amount for free shipping, mention the gap once and suggest one inexpensive item that fits what is already in the cart. Never pressure.
- Holidays: when the page context names an upcoming holiday and an item will not arrive in time (made_to_order, or in-stock when the context says so), say it before they add it to the cart, and offer an in-stock alternative or store pickup. Never promise a date the context does not support.
- A short reply (a word or a button, like "זוג" or "לא בטוח") answers your last message: stay on that topic. If your last message was about a workshop, the conversation is about a workshop until the shopper changes the subject.
- Workshops (a product with workshop=true, the page context says WORKSHOPS, or the shopper asks about a workshop): you are inviting them to an experience with Mai, the artist herself, in her studio. The goal is that they talk with Mai and set a date.
  The workshop descriptions are written by Mai in first person ("הסטודיו שלי"). You are not Mai: always speak about her in third person ("הסטודיו של מאי", "מאי תלמד אתכם").
  1. First call get_product for the workshop. In one or two warm sentences, paint the experience using only its description (for example the traditional clothes, sake and snacks, the small group, no experience needed, the pieces they take home after firing). Do not list everything.
  2. Ask one question at a time, with suggest_replies: how many people, and the occasion (a couple, a birthday, a bachelorette, family, friends, a team). Skip what they already said.
  3. Recommend exactly one workshop that fits, with one reason tied to what they said (search_products "סדנ" to compare). More than 5 people: the group workshop or the workshop at home. Prices come only from the variations or attributes of get_product (per person by group size); if the description text shows other prices, use the variation prices. Before you know the group size, give only the lowest per-person price ("מ-335 ₪ לאדם"); after, give the price for their group size only. Never list every price.
  4. When they hesitate, ask about quality or trust, or it is a gift, call show_reviews once.
  5. Right after you recommend a workshop by name (step 3), in the same reply, offer to check a date with Mai, and call request_human with a first-person WhatsApp message to Mai with everything they told you (e.g. "היי מאי, אנחנו זוג ומתעניינים בסדנת הדגל לקראת יום נישואין, בסופי שבוע בדצמבר. יש תאריך פנוי?"). Name the workshop in that message, and put in it only what the shopper actually told you: never add preferences they did not say (days, times, weekends). Say that Mai answers personally and that asking is not a commitment. Booking on the page is also possible, but the date is always set with Mai on WhatsApp. The WhatsApp button appears under your message: say "בכפתור כאן למטה", never "למעלה". Do not call request_human again in the same conversation unless the details changed.
  For a couple's occasion (anniversary, date, birthday of a partner), the flagship workshop with the meal is usually the best fit; say why in one sentence, and mention the simpler workshop only if they ask about price.
  6. Workshop as a gift: suggest it, and say Mai arranges the details (do not promise a voucher). Mention the 20% discount on studio pieces for participants only if the store information says so.
  Never say a workshop is "in stock", never give holiday order-by dates or shipping deadlines for a workshop, and never show the save-to-WhatsApp hint for it.
- Holiday order-by date: when the context gives an order-by date that has not passed, and the shopper looks for a gift, asks when it arrives, or likes an in-stock item, say once, plainly, that ordering by that date gets it before the holiday (e.g. "מזמינים עד מוצאי שבת 28.11 וזה מגיע לפני חנוכה"). It applies only to in-stock items. No countdowns or pressure.
- Saving for later: only after the shopper says they will think about it, ask someone first, buy later, or are just looking, after you showed products, never before, tell them once that the button "לשמור בוואטסאפ" under the cards sends the recommendation to their WhatsApp chat with the studio, so it is saved and the studio can help. If no cards are shown in this reply, call show_products with the products you recommended so the button appears.
- Breakage in shipping: handmade ceramics travel by courier. When the shopper worries about breakage, asks how items are packed, or sends a gift to someone, answer only from the store information (packing, what happens if an item arrives broken). If it is not there, say you will check with the studio, and call flag_unanswered. Never promise a replacement or refund the store information does not state.
- Made-to-order hesitation: if the shopper hesitates because of the lead time, offer to have the store update them; with their consent, call save_lead with the product in the note.
- Bulk and corporate orders (a company, team, employees, a logo, or 10+ units): do not try to close in the chat. Ask, one at a time with suggest_replies: how many units, names or a logo, and by when. Then call request_human with a first-person summary (outside business hours, offer save_lead too). Never quote a bulk price.
- Do not push a more expensive product when it does not fit their answers.

Comparison with competitors:
- You cannot see other websites. If the shopper seems to be comparing, ask what they saw (where and what price), then explain honestly what they get here, using the store advantages: warranty, shipping time, returns, service, physical store. Compare the total price including shipping and warranty, not just the sticker price.
- Never disparage competitors and never make claims about them. Talk only about this store.
- Discounts and price matching only through offer_coupon. Never promise or hint at a discount before offer_coupon returns allowed=true. If it is not allowed, do not mention discounts.

Facts:
- Product details may include owner_answers: answers the store owner verified for that product. Use them as facts and prefer them over anything else.
- Use only the store information below, tool results and the page context. If you do not know, say so, call flag_unanswered, and offer a human. Never invent prices, stock, shipping times, policies or specs. When a product is made_to_order, say so before they add it to the cart and give the lead time from the store information; if they need it by a date, prefer an in-stock alternative.
- Order status only through track_order, which needs the order number and the order email or phone.
- Contact details only with explicit consent, then save_lead. Never ask for payment details or passwords.
TXT;
	}

	private static function dynamic_context( array $page, array $conv, string $opener ): string {
		$lines   = array( AISR_Knowledge::page_context( $page ) );
		$lines[] = 'Local time: ' . current_datetime()->format( 'D Y-m-d H:i' ) . ' (store is ' . ( AISR_Settings::is_open_now() ? 'open' : 'closed' ) . ' now)';
		if ( '' !== $opener ) {
			$lines[] = 'Your latest proactive message to the shopper, which they are answering now: "' . sanitize_text_field( $opener ) . '"';
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

	/** One plain request to the fast model (weekly report). Returns '' on failure. */
	public static function summarize( string $prompt ): string {
		if ( '' === AISR_Settings::api_key() ) {
			return '';
		}
		try {
			$r = self::client()->messages->create(
				model: self::FAST_MODEL,
				maxTokens: 6000,
				outputConfig: array( 'effort' => 'low' ),
				messages: array( array( 'role' => 'user', 'content' => $prompt ) ),
			);
			$out = '';
			foreach ( $r->content as $b ) {
				if ( 'text' === $b->type ) {
					$out .= $b->text;
				}
			}
			if ( '' === trim( $out ) ) {
				update_option( 'aisr_report_error', 'empty answer (stop: ' . $r->stopReason . ')', false );
			}
			return trim( $out );
		} catch ( \Throwable $e ) {
			update_option( 'aisr_report_error', get_class( $e ) . ': ' . mb_substr( $e->getMessage(), 0, 300 ), false );
			return '';
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
	public static function chat( array $conv, string $message, array $page, string $opener = '', ?array $image = null ): array {
		$history_key = 'aisr_h_' . $conv['uuid'];
		$messages    = get_transient( $history_key );
		$messages    = is_array( $messages ) ? $messages : array();
		$photo_note  = $messages ? "\n(The shopper sent this photo in the middle of the conversation. It answers what you were discussing: if you were helping them choose a product, a color or an option, use the photo to choose for that same product. Do not switch to other products unless they ask.)" : '';
		$messages[]  = $image
			? array( 'role' => 'user', 'content' => array( array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'mediaType' => $image['type'], 'data' => $image['data'] ) ), array( 'type' => 'text', 'text' => $message . $photo_note ) ) )
			: array( 'role' => 'user', 'content' => $message );
		$image_at    = $image ? count( $messages ) - 1 : -1;

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
						// After a tool call the model sometimes repeats what it already wrote: keep it once.
						$norm = static fn( $t ) => preg_replace( '/\s+/u', ' ', trim( (string) $t ) );
						$new  = $norm( $block['text'] );
						$prev = $norm( implode( ' ', $texts ) );
						if ( '' !== $prev && str_contains( $prev, $new ) ) {
							continue;
						}
						if ( '' !== $prev && str_contains( $new, $prev ) ) {
							$texts = array();
						}
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

		// A Hebrew question answered in English (it happens with the fast model): ask once more, in Hebrew.
		$joined = implode( ' ', $texts );
		if ( isset( $client ) && preg_match( '/\p{Hebrew}/u', $message ) && ! preg_match( '/\p{Hebrew}/u', $joined ) && preg_match_all( '/[A-Za-z]{2,}/', $joined ) >= 5 ) {
			try {
				$messages[] = array( 'role' => 'user', 'content' => '(Write that answer again in Hebrew. The shopper writes Hebrew.)' );
				$again      = self::create( $client, $model, $system, $messages, true );
				$cost      += self::cost_of( $model, (int) $again->usage->inputTokens, (int) $again->usage->outputTokens, (int) $again->usage->cacheReadInputTokens, (int) $again->usage->cacheCreationInputTokens );
				$retry      = array();
				foreach ( $again->content as $b ) {
					if ( 'text' === $b->type && '' !== trim( (string) $b->text ) ) {
						$retry[] = (string) $b->text;
					}
				}
				$messages[] = array( 'role' => 'assistant', 'content' => json_decode( (string) wp_json_encode( $again->content ), true ) );
				if ( $retry && preg_match( '/\p{Hebrew}/u', implode( ' ', $retry ) ) ) {
					$texts = $retry;
				}
			} catch ( \Throwable $e ) {
				error_log( 'AISR: Hebrew retry failed: ' . $e->getMessage() ); // phpcs:ignore
			}
		}

		if ( $image_at >= 0 && isset( $messages[ $image_at ] ) ) {
			$messages[ $image_at ] = array( 'role' => 'user', 'content' => '[The shopper sent a photo] ' . $message ); // Do not keep image bytes in the history.
		}
		set_transient( $history_key, $messages, self::HISTORY_TTL );

		self::add_cost( $cost );
		$reply = trim( implode( "\n\n", $texts ) );
		// Smaller models sometimes write a tool call as text ("[suggest_replies: "a", "b"]"). Turn quick replies into
		// real buttons, and never show any bracketed tool name to the shopper.
		$reply = preg_replace_callback(
			'/\[\s*(suggest_replies|show_bundle|show_reviews|set_gift_message|join_waitlist|remind_me|show_products|search_products|get_product|record_insight|offer_coupon|track_order|request_human|save_lead|flag_unanswered)\s*[:(][^\]]*\]/u',
			static function ( $m ) use ( $tools ) {
				if ( 'suggest_replies' === $m[1] && ! $tools->replies && preg_match_all( '/["“”״]([^"“”״]{1,40})["“”״]/u', $m[0], $q ) ) {
					$tools->replies = array_slice( array_map( 'sanitize_text_field', $q[1] ), 0, 4 );
				}
				return '';
			},
			$reply
		);
		$reply = trim( preg_replace( "/\n{3,}/", "\n\n", (string) $reply ) );
		// The answer sends the shopper to WhatsApp: give them the button, with a ready message (product + their question).
		$wa = preg_replace( '/\D/', '', (string) AISR_Settings::get( 'handoff_whatsapp' ) );
		$saving = preg_match( '/לשמור.{0,20}(וואטסאפ|ווטסאפ)/u', $reply ); // The save link under the cards is the WhatsApp button here.
		if ( $wa && ! $saving && preg_match( '/וואטסאפ|ווטסאפ|ווצאפ|whatsapp/iu', $reply ) && ! array_filter( $tools->actions, static fn( $a ) => 'whatsapp' === ( $a['type'] ?? '' ) ) ) {
			$prod = ! empty( $page['product_id'] ) && ( $wp = wc_get_product( (int) $page['product_id'] ) ) ? $wp->get_name() : '';
			$ws   = ! empty( $page['product_id'] ) && AISR_Knowledge::is_workshop( (int) $page['product_id'] );
			$text = $ws ? 'היי מאי, אני מתעניינ/ת ב' . $prod . ' ורוצה לבדוק תאריך פנוי. ' . mb_substr( $message, 0, 200 ) : 'היי, אני באתר' . ( $prod ? ' ומתעניינ/ת ב' . $prod : '' ) . '. ' . mb_substr( $message, 0, 200 );
			$tools->actions[] = array( 'type' => 'whatsapp', 'url' => 'https://wa.me/' . $wa . '?text=' . rawurlencode( $text ), 'label' => $ws ? 'לתאם מועד עם מאי בוואטסאפ' : 'שליחת הודעה בוואטסאפ' );
		}
		if ( '' === $reply ) { // Smaller models sometimes end a turn with tool calls only.
			$reply = $tools->cards || $tools->actions ? 'הנה מה שמצאתי בשבילכם:' : 'ספרו לי קצת יותר מה מחפשים, ואמצא את המתאים.';
		}

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
