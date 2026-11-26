/* AI Sales Rep widget: behavior sensors, proactive peek, chat panel. No dependencies. */
(function () {
	'use strict';

	var C = window.AISR_CONFIG;
	if (!C || window.__aisrLoaded) return;
	window.__aisrLoaded = true;

	/* ---------- storage (always guarded: private mode, blocked storage) ---------- */
	function store(kind) {
		try { var s = window[kind]; var k = '__aisr_t'; s.setItem(k, '1'); s.removeItem(k); return s; } catch (e) { return null; }
	}
	var SS = store('sessionStorage');
	var hasConsent = C.privacyMode === 'extended' && typeof window.wp_has_consent === 'function' && window.wp_has_consent('preferences');
	var LS = hasConsent ? store('localStorage') : null;
	// Test mode (only shop managers see the chat): forget the visit on every page load, so each
	// trigger can be tried again without opening a new tab. Live visitors keep the normal rules.
	if (C.testMode) {
		[SS, LS].forEach(function (s) {
			try { if (s) Object.keys(s).filter(function (k) { return k.indexOf('aisr_') === 0 && k !== 'aisr_pages' && k !== 'aisr_viewed'; }).forEach(function (k) { s.removeItem(k); }); } catch (e) { /* ignore */ }
		});
	}
	function get(s, k, d) { try { var v = s && s.getItem('aisr_' + k); return v === null || v === undefined ? d : JSON.parse(v); } catch (e) { return d; } }
	function set(s, k, v) { try { s && s.setItem('aisr_' + k, JSON.stringify(v)); } catch (e) { /* ignore */ } }

	function cookie(name) {
		var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : '';
	}
	function setCookie(name, value, days) {
		var c = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
		if (days) c += '; max-age=' + days * 86400;
		document.cookie = c;
	}
	function rid() { return Math.random().toString(36).slice(2) + Date.now().toString(36); }

	/* ---------- visit state ---------- */
	var visitor = get(LS, 'visitor') || get(SS, 'visitor');
	if (!visitor) { visitor = rid(); set(SS, 'visitor', visitor); set(LS, 'visitor', visitor); }

	var grp = cookie('aisr_grp');
	if (C.testMode) grp = 'test'; // Managers are never put in the control group.
	if (grp !== 'test' && grp !== 'control') {
		grp = Math.random() * 100 < (C.controlPct || 0) ? 'control' : 'test';
	}
	setCookie('aisr_grp', grp, hasConsent ? 30 : 0);

	var page = C.page || { type: 'other', product_id: 0 };
	var pages = (get(SS, 'pages', 0) || 0) + 1;
	set(SS, 'pages', pages);
	var viewed = get(SS, 'viewed', []);
	if (page.product_id && viewed.indexOf(page.product_id) === -1) { viewed.push(page.product_id); set(SS, 'viewed', viewed.slice(-6)); }

	var prevVisitProduct = get(LS, 'last_product', null);
	if (page.product_id && LS) set(LS, 'last_product', { id: page.product_id, name: C.productName, t: Date.now() });

	var convId = get(SS, 'conv', '');
	var history = get(SS, 'hist', []);
	var pendingOpener = null;

	/* ---------- API ---------- */
	function pageCtx() {
		return { type: page.type, product_id: page.product_id, title: page.title, viewed: viewed.filter(function (id) { return id !== page.product_id; }) };
	}
	function post(path, body) {
		var h = { 'Content-Type': 'application/json' };
		if (C.restNonce) h['X-WP-Nonce'] = C.restNonce;
		return fetch(C.rest + path, {
			method: 'POST', credentials: 'same-origin',
			headers: h,
			body: JSON.stringify(body)
		}).then(function (r) { if (!r.ok) throw r; return r.json(); });
	}
	function track(type, extra) {
		var body = { type: type, visitor: visitor, grp: grp, page: pageCtx(), conversation_id: convId };
		for (var k in extra || {}) body[k] = extra[k];
		post('/event', body).catch(function () { /* analytics only */ });
	}
	if (!get(SS, 'visit_logged', false)) { set(SS, 'visit_logged', true); track('visit'); }

	// Pages are often served from a full-page cache, so the nonce printed in the HTML can be stale.
	// Get a fresh one from the cart endpoint before the first cart action.
	var storeNonce = C.storeNonce, nonceReady = null;
	function freshNonce() {
		if (!nonceReady) {
			nonceReady = fetch(C.storeApi + '/cart', { credentials: 'same-origin' }).then(function (r) {
				var n = r.headers.get('Nonce'); if (n) storeNonce = n;
			}).catch(function () { /* keep the printed nonce */ });
		}
		return nonceReady;
	}
	function storeApi(path, body) {
		return freshNonce().then(function () { return fetch(C.storeApi + path, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Nonce': storeNonce },
			body: JSON.stringify(body)
		}); }).then(function (r) {
			var n = r.headers.get('Nonce'); if (n) storeNonce = n;
			return r.json().then(function (j) { if (!r.ok) throw j; return j; });
		});
	}
	function refreshMiniCart() {
		try { if (window.jQuery) window.jQuery(document.body).trigger('wc_fragment_refresh'); } catch (e) { /* ignore */ }
		try { document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart')); } catch (e) { /* ignore */ }
	}

	/* ---------- DOM ---------- */
	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined && text !== null) e.textContent = text;
		return e;
	}
	var isMobile = window.matchMedia('(max-width: 640px)').matches;
	var root = el('div', 'aisr-root aisr-' + (C.position === 'right' ? 'right' : 'left'));
	root.setAttribute('dir', document.documentElement.getAttribute('dir') || 'rtl');
	root.style.setProperty('--aisr-color', C.color || '#2563eb');
	root.style.setProperty('--aisr-bottom', (C.bottomOffset || 20) + 'px');

	function avatar() {
		var a = el('span', 'aisr-avatar');
		if (C.persona.avatar) { var img = el('img'); img.src = C.persona.avatar; img.alt = ''; a.appendChild(img); }
		else a.textContent = (C.persona.name || '?').charAt(0);
		return a;
	}

	var launcher = el('button', 'aisr-launcher');
	launcher.type = 'button';
	launcher.setAttribute('aria-label', 'פתיחת צ\'אט עם ' + C.persona.name);
	launcher.appendChild(avatar());
	var dot = el('span', 'aisr-dot'); launcher.appendChild(dot);

	var peek = el('div', 'aisr-peek'); peek.hidden = true;
	var peekText = el('div', 'aisr-peek-text');
	var peekClose = el('button', 'aisr-peek-close', '×'); peekClose.type = 'button'; peekClose.setAttribute('aria-label', 'סגירה');
	peek.appendChild(peekClose); peek.appendChild(peekText);

	var panel = el('div', 'aisr-panel'); panel.hidden = true;
	panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'צ\'אט עם ' + C.persona.name);
	var head = el('div', 'aisr-head');
	var who = el('div', 'aisr-who');
	who.appendChild(el('strong', null, C.persona.name));
	who.appendChild(el('span', null, (C.persona.role || 'עוזר דיגיטלי') + ' · מבוסס AI'));
	var close = el('button', 'aisr-close', '×'); close.type = 'button'; close.setAttribute('aria-label', 'סגירת הצ\'אט');
	head.appendChild(avatar()); head.appendChild(who); head.appendChild(close);
	var log = el('div', 'aisr-log'); log.setAttribute('aria-live', 'polite');
	var chips = el('div', 'aisr-chips');
	var form = el('form', 'aisr-form');
	var input = el('input', 'aisr-input');
	input.type = 'text'; input.maxLength = 600; input.placeholder = 'כתבו הודעה…'; input.setAttribute('aria-label', 'הודעה');
	input.setAttribute('enterkeyhint', 'send');
	var send = el('button', 'aisr-send'); send.type = 'submit'; send.setAttribute('aria-label', 'שליחה');
	send.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M3.4 20.4 21 12 3.4 3.6 3 10l12 2-12 2z"/></svg>';
	form.appendChild(input); form.appendChild(send);
	var foot = el('div', 'aisr-foot');
	if (C.privacyUrl) { var pl = el('a', null, 'פרטיות'); pl.href = C.privacyUrl; pl.target = '_blank'; pl.rel = 'noopener'; foot.appendChild(pl); }
	if (C.credit) {
		var cr = C.creditUrl ? el('a', null, C.credit) : el('span', null, C.credit);
		if (C.creditUrl) { cr.href = C.creditUrl; cr.target = '_blank'; cr.rel = 'noopener'; }
		foot.appendChild(cr);
	}
	panel.appendChild(head); panel.appendChild(log); panel.appendChild(chips); panel.appendChild(form); panel.appendChild(foot);

	root.appendChild(panel); root.appendChild(peek); root.appendChild(launcher);
	document.body.appendChild(root);

	/* ---------- rendering ---------- */
	function cleanText(t) { return String(t || '').replace(/\*\*(.+?)\*\*/g, '$1').replace(/^#+\s*/gm, ''); }
	function renderText(node, text) {
		var parts = cleanText(text).split(/(https?:\/\/[^\s]+)/g);
		parts.forEach(function (p) {
			if (/^https?:\/\//.test(p)) { var a = el('a', null, p); a.href = p; a.target = '_blank'; a.rel = 'noopener nofollow'; node.appendChild(a); }
			else node.appendChild(document.createTextNode(p));
		});
	}
	function scrollDown() { log.scrollTop = log.scrollHeight; }
	function bubble(role, text, save) {
		var b = el('div', 'aisr-msg aisr-' + role);
		renderText(b, text);
		log.appendChild(b); scrollDown();
		if (save !== false) { history.push({ r: role, t: text }); set(SS, 'hist', history.slice(-40)); }
		return b;
	}
	function typing() { var t = el('div', 'aisr-msg aisr-bot aisr-typing'); t.innerHTML = '<i></i><i></i><i></i>'; t.setAttribute('aria-label', C.persona.name + ' מקלידה'); log.appendChild(t); scrollDown(); return t; }
	function setChips(list) {
		chips.innerHTML = '';
		(list || []).forEach(function (txt) {
			var c = el('button', 'aisr-chip', txt); c.type = 'button';
			c.addEventListener('click', function () { sendMessage(txt); });
			chips.appendChild(c);
		});
	}
	function card(p) {
		var c = el('div', 'aisr-card');
		var img = el('img'); img.src = p.image; img.alt = ''; img.loading = 'lazy'; c.appendChild(img);
		var body = el('div', 'aisr-card-body');
		var name = el('a', 'aisr-card-name', p.name); name.href = p.url;
		name.addEventListener('click', function () { track('clicked', { trigger: 'card' }); });
		body.appendChild(name);
		body.appendChild(el('div', 'aisr-card-price', p.made_to_order ? p.price + ' · בהזמנה מראש' : p.price));
		var btn = el('button', 'aisr-card-btn'); btn.type = 'button';
		if (p.simple && p.in_stock) {
			btn.textContent = 'הוספה לסל';
			btn.addEventListener('click', function () {
				btn.disabled = true; btn.textContent = 'מוסיף…';
				storeApi('/cart/add-item', { id: p.id, quantity: 1 }).then(function () {
					btn.textContent = 'נוסף לסל ✓'; refreshMiniCart(); track('add_to_cart', { trigger: 'card' });
				}).catch(function () { btn.disabled = false; btn.textContent = 'לעמוד המוצר'; btn.onclick = function () { location.href = p.url; }; });
			});
		} else {
			btn.textContent = p.in_stock ? 'לבחירת אפשרויות' : 'לעמוד המוצר';
			btn.addEventListener('click', function () { track('clicked', { trigger: 'card' }); location.href = p.url; });
		}
		body.appendChild(btn);
		c.appendChild(body);
		return c;
	}
	function action(a) {
		var wrap = el('div', 'aisr-action');
		if (a.type === 'whatsapp') {
			var w = el('a', 'aisr-btn', a.label || 'המשך בוואטסאפ'); w.href = a.url; w.target = '_blank'; w.rel = 'noopener';
			w.addEventListener('click', function () { track('clicked', { trigger: 'whatsapp' }); });
			wrap.appendChild(w);
		} else if (a.type === 'coupon') {
			wrap.appendChild(el('div', 'aisr-coupon', a.code));
			var b = el('button', 'aisr-btn', 'החלת הקופון על הסל'); b.type = 'button';
			b.addEventListener('click', function () {
				b.disabled = true;
				storeApi('/cart/apply-coupon', { code: a.code }).then(function () {
					b.textContent = 'הקופון הוחל ✓'; refreshMiniCart(); track('coupon_applied');
				}).catch(function (err) {
					b.disabled = false;
					b.textContent = (err && err.message) ? 'אפשר להזין את הקוד בתשלום' : 'נסו שוב';
				});
			});
			wrap.appendChild(b);
		}
		return wrap;
	}
	function renderHistory() {
		log.innerHTML = '';
		history.forEach(function (m) { bubble(m.r, m.t, false); });
	}

	/* ---------- chat ---------- */
	var busy = false;
	function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
	function sendMessage(text) {
		text = String(text || '').trim();
		if (!text || busy) return;
		busy = true; setChips([]); input.value = '';
		// Keep the opening message in the visit history, so the chat reads the same on the next page.
		if (!history.length) {
			var first = log.querySelector('.aisr-bot');
			if (first) { history.push({ r: 'bot', t: first.textContent }); }
		}
		bubble('user', text);
		var t = typing();
		var body = { message: text, conversation_id: convId, visitor: visitor, grp: grp, page: pageCtx() };
		if (pendingOpener) { body.opener = pendingOpener.text; body.trigger = pendingOpener.trigger; }
		post('/chat', body).then(function (res) {
			convId = res.conversation_id || convId; set(SS, 'conv', convId);
			pendingOpener = null;
			var seq = Promise.resolve();
			(res.bubbles || []).forEach(function (txt, i) {
				seq = seq.then(function () {
					if (i > 0) { log.appendChild(t); scrollDown(); return wait(Math.min(1600, 450 + txt.length * 12)); }
				}).then(function () { if (t.parentNode) t.remove(); bubble('bot', txt); });
			});
			return seq.then(function () {
				if (t.parentNode) t.remove();
				(res.cards || []).forEach(function (p) { log.appendChild(card(p)); });
				(res.actions || []).forEach(function (a) { log.appendChild(action(a)); });
				setChips(res.replies);
				scrollDown();
			});
		}).catch(function () {
			if (t.parentNode) t.remove();
			bubble('bot', 'משהו השתבש בחיבור. אפשר לנסות שוב.', false);
		}).then(function () { busy = false; });
	}
	form.addEventListener('submit', function (e) { e.preventDefault(); sendMessage(input.value); });

	function openPanel(fromPeek, auto) {
		hidePeek(false);
		fired = true; // No proactive message once the shopper opened the chat.
		panel.hidden = false; root.classList.add('aisr-open');
		dot.hidden = true;

	/* ---------- stay above sticky bottom bars (e.g. a floating add-to-cart bar on phones) ---------- */
	var baseBottom = C.bottomOffset || 20;
	function liftAboveBars() {
		if (root.classList.contains('aisr-open')) return;
		var lift = 0, w = window.innerWidth, h = window.innerHeight;
		var probes = [h - 2, h - 20];
		probes.forEach(function (y) {
			var els = document.elementsFromPoint ? document.elementsFromPoint(w / 2, y) : [];
			for (var i = 0; i < els.length; i++) {
				var e = els[i];
				if (root.contains(e) || e === document.body || e === document.documentElement) continue;
				var cs = getComputedStyle(e);
				if ((cs.position === 'fixed' || cs.position === 'sticky') && cs.visibility !== 'hidden') {
					var r = e.getBoundingClientRect();
					if (r.width >= w * 0.5 && r.bottom >= h - 4 && r.height < h * 0.4) lift = Math.max(lift, h - r.top);
				}
			}
		});
		root.style.setProperty('--aisr-bottom', (lift ? lift + 12 : baseBottom) + 'px');
	}
	var liftTimer = null;
	function scheduleLift() { if (!liftTimer) liftTimer = setTimeout(function () { liftTimer = null; liftAboveBars(); }, 250); }
	window.addEventListener('scroll', scheduleLift, { passive: true });
	window.addEventListener('resize', scheduleLift);
	setTimeout(liftAboveBars, 800);
		renderHistory();
		if (pendingOpener && !history.length) {
			bubble('bot', pendingOpener.text, false);
			setChips(pendingOpener.replies);
		} else if (!history.length) {
			var o = (C.openers && C.openers[page.type === 'product' ? 'product_dwell' : (page.type === 'category' ? 'category' : 'home')]) || { text: 'היי, איך אפשר לעזור?', replies: [] };
			bubble('bot', o.text, false); setChips(o.replies);
		}
		if (!isMobile && !auto) input.focus(); // Opened by itself: do not take the keyboard from the page.
		if (!auto) track('opened', { trigger: pendingOpener ? pendingOpener.trigger : 'manual' });
		fitViewport();
	}
	function closePanel() { panel.hidden = true; root.classList.remove('aisr-open'); }
	launcher.addEventListener('click', function () { panel.hidden ? openPanel(false) : closePanel(); });
	close.addEventListener('click', closePanel);
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) closePanel(); });
	peekText.addEventListener('click', function () { openPanel(true); });

	// Keep the last message visible above the phone keyboard.
	function fitViewport() {
		if (!isMobile || !window.visualViewport || panel.hidden) return;
		var vv = window.visualViewport;
		panel.style.height = Math.round(vv.height * 0.85) + 'px';
		panel.style.bottom = Math.max(0, window.innerHeight - vv.height - vv.offsetTop) + 'px';
		scrollDown();
	}
	if (window.visualViewport) window.visualViewport.addEventListener('resize', fitViewport);

	/* ---------- proactive ---------- */
	var fired = false;
	function proactiveAllowed() {
		return C.proactive && grp !== 'control' && !fired && !get(SS, 'proactive', false) && !get(SS, 'dismissed', false) && panel.hidden && !history.length;
	}
	function enabled(trigger) { return (C.triggers || []).indexOf(trigger) !== -1; }
	function eligible() { return pages >= (C.minPages || 2) || (page.type === 'product' && (Date.now() - startedAt) / 1000 >= (C.productDwell || 20)); }

	function fire(trigger, strong) {
		if (!enabled(trigger) || !proactiveAllowed()) return;
		if (!strong && !eligible()) return;
		var o = C.openers && C.openers[trigger];
		if (!o) return;
		var text = o.text;
		if (trigger === 'returning') {
			if (!prevVisitProduct || !prevVisitProduct.name) return;
			text = text.replace('{prev}', prevVisitProduct.name);
		}
		fired = true; set(SS, 'proactive', true);
		pendingOpener = { trigger: trigger, text: text, replies: o.replies };
		if (trigger === 'gallery') {
			// The shopper is looking at a large image: open the full chat next to it, not a small bubble.
			setTimeout(function () {
				if (!panel.hidden) return;
				track('shown', { trigger: trigger });
				openPanel(true, true);
			}, 1500);
			return;
		}
		setTimeout(function () { showPeek(text, trigger); }, 2000 + Math.random() * 2000);
	}
	function showPeek(text, trigger) {
		if (!panel.hidden) return;
		peekText.innerHTML = '<span class="aisr-typing aisr-inline"><i></i><i></i><i></i></span>';
		peek.hidden = false; root.classList.add('aisr-peeking');
		setTimeout(function () {
			peekText.textContent = text;
			dot.hidden = false;
			track('shown', { trigger: trigger });
		}, 1200);
	}
	function hidePeek(dismissed) {
		if (peek.hidden) return;
		peek.hidden = true; root.classList.remove('aisr-peeking');
		if (dismissed) { set(SS, 'dismissed', true); track('dismissed', { trigger: pendingOpener ? pendingOpener.trigger : '' }); pendingOpener = null; dot.hidden = true; }
	}
	peekClose.addEventListener('click', function (e) { e.stopPropagation(); hidePeek(true); });

	/* ---------- behavior sensors (phone-first) ---------- */
	var startedAt = Date.now();
	var lastActivity = Date.now();
	function activity() { lastActivity = Date.now(); }
	['touchstart', 'scroll', 'keydown', 'mousemove'].forEach(function (ev) { window.addEventListener(ev, activity, { passive: true }); });

	// Dwell on product / category / home / blog.
	var dwellMap = { product: ['product_dwell', C.productDwell || 20], category: ['category', 20], home: ['home', 20], blog: ['blog', 40] };
	if (dwellMap[page.type]) {
		setTimeout(function () { fire(dwellMap[page.type][0], page.type === 'product'); }, dwellMap[page.type][1] * 1000);
	}

	// Copying text on a product page: probably comparing prices elsewhere.
	document.addEventListener('copy', function () { if (page.type === 'product') fire('copy_name', true); });

	// Left to another app/tab and came back.
	var hiddenAt = 0;
	document.addEventListener('visibilitychange', function () {
		if (document.hidden) { hiddenAt = Date.now(); return; }
		var away = (Date.now() - hiddenAt) / 1000;
		if (hiddenAt && away >= 8 && away <= 1800 && page.type === 'product') fire('tab_return', true);
	});

	// Scroll: fast upward flick near the top (mobile exit intent) and back-and-forth re-reading.
	var lastY = window.scrollY, lastT = Date.now(), maxY = 0, dir = 0, flips = [];
	window.addEventListener('scroll', function () {
		var y = window.scrollY, now = Date.now(), dy = y - lastY, dt = Math.max(1, now - lastT);
		maxY = Math.max(maxY, y);
		if (dy < 0 && -dy / dt > 2.5 && y < 300 && maxY > 800) fire('exit_scroll', true);
		var d = dy > 0 ? 1 : (dy < 0 ? -1 : 0);
		if (d && d !== dir && Math.abs(dy) > 30) { flips.push({ t: now, y: y }); dir = d; }
		flips = flips.filter(function (f) { return now - f.t < 10000; });
		if (flips.length >= 5 && page.type === 'product') {
			var ys = flips.map(function (f) { return f.y; });
			if (Math.max.apply(null, ys) - Math.min.apply(null, ys) < 900) fire('reread', false);
		}
		lastY = y; lastT = now;
	}, { passive: true });

	// Gallery: opening an image in large view, paging through images, or pinch zoom.
	// Covers WooCommerce's gallery, CommerceKit (Shoptimizer) and the Elementor lightbox.
	var GALLERY = '.woocommerce-product-gallery, .flex-control-nav, .wc-block-product-gallery, #commercegurus-pdp-gallery, .cg-psp-gallery, .dialog-lightbox-widget, .elementor-lightbox, .pswp';
	var ZOOM = '.woocommerce-product-gallery__trigger, .woocommerce-product-gallery__image a, a.swiper-slide-imglink, .wc-block-product-gallery-large-image, .zoomImg';
	var galleryHits = 0;
	function galleryHit(n) {
		if (page.type !== 'product') return;
		galleryHits += n;
		if (galleryHits >= 3) fire('gallery', true);
	}
	document.addEventListener('click', function (e) {
		if (!e.target.closest || !e.target.closest(GALLERY)) return;
		galleryHit(e.target.closest(ZOOM) ? 3 : 1); // Opening the large view is enough on its own.
	}, true);
	var swipeX = null;
	document.addEventListener('touchstart', function (e) {
		if (e.touches && e.touches.length > 1 && page.type === 'product') { fire('gallery', true); return; }
		swipeX = e.touches && e.touches.length === 1 && e.target.closest && e.target.closest(GALLERY) ? e.touches[0].clientX : null;
	}, { passive: true });
	document.addEventListener('touchend', function (e) {
		if (swipeX === null || !e.changedTouches || !e.changedTouches.length) return;
		if (Math.abs(e.changedTouches[0].clientX - swipeX) > 40) galleryHit(1);
		swipeX = null;
	}, { passive: true });

	// Switching size/color several times.
	var switches = 0;
	document.addEventListener('change', function (e) {
		if (e.target.closest && e.target.closest('form.variations_form, .wc-block-add-to-cart-form')) {
			if (++switches >= 3) fire('size_switch', true);
		}
	}, true);

	// Added to cart and stopped.
	function cartIdle() { setTimeout(function () { if (Date.now() - lastActivity > 15000 || page.type === 'cart') fire('cart_idle', true); }, 25000); }
	if (window.jQuery) window.jQuery(document.body).on('added_to_cart', cartIdle);
	document.body.addEventListener('wc-blocks_added_to_cart', cartIdle);
	if (page.type === 'cart') cartIdle();

	// Arrived from social apps (in-app browsers) or price-comparison sites.
	var ua = navigator.userAgent || '', ref = document.referrer || '';
	if (pages === 1) {
		if (/Instagram|FBAN|FBAV|TikTok|musical_ly|BytedanceWebview/i.test(ua) || /instagram\.|facebook\.|tiktok\./i.test(ref)) {
			setTimeout(function () { fire('social_visitor', true); }, 8000);
		} else if (/zap\.co\.il|wisebuy|google\.[^/]+\/shopping|shopping\.google/i.test(ref)) {
			setTimeout(function () { fire('comparison_visitor', true); }, 8000);
		}
	}

	// Stuck: no scrolling or touching for a while.
	setInterval(function () {
		if ((Date.now() - lastActivity) / 1000 >= (C.idleSeconds || 25) && !document.hidden) fire('idle', false);
	}, 5000);

	// Returning visitor (extended privacy mode with consent only).
	if (LS && prevVisitProduct && pages === 1 && prevVisitProduct.id !== page.product_id && Date.now() - prevVisitProduct.t > 3600000) {
		setTimeout(function () { fire('returning', true); }, 6000);
	}

	dot.hidden = true;
})();
