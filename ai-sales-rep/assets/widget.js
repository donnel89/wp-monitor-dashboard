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
	// Remembering a visitor between visits needs consent: the WP Consent API in extended mode, or the site's cookie banner (Cookie Notice).
	var hasConsent = (C.privacyMode === 'extended' && typeof window.wp_has_consent === 'function' && window.wp_has_consent('preferences'))
		|| /(?:^|; )cookie_notice_accepted=true/.test(document.cookie);
	var LS = hasConsent ? store('localStorage') : null;
	// Test mode (only shop managers see the chat): forget the visit on every page load, so each
	// trigger can be tried again without opening a new tab. Live visitors keep the normal rules.
	if (C.testMode || /[?&]aisr_debug=1/.test(location.search)) { // Also when checking with ?aisr_debug=1: every load is a fresh visit.
		[SS, LS].forEach(function (s) {
			try { if (s) Object.keys(s).filter(function (k) { return k.indexOf('aisr_') === 0 && ['aisr_pages', 'aisr_viewed', 'aisr_policy_seen', 'aisr_added'].indexOf(k) === -1; }).forEach(function (k) { s.removeItem(k); }); } catch (e) { /* ignore */ }
		});
	}
	// 'Every page load is a new visitor' (a store setting for a testing period): forget the proactive message and its
	// dismissal on each load, but keep a conversation that already started.
	if (C.freshVisits && SS) { try { SS.removeItem('aisr_proactive'); SS.removeItem('aisr_dismissed'); } catch (e) { /* ignore */ } }
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
	// Managers, and anyone checking the bot with ?aisr_debug=1, are never put in the control group.
	if (C.testMode || C.isManager || C.freshVisits || /[?&]aisr_debug=1/.test(location.search)) grp = 'test';
	if (grp !== 'test' && grp !== 'control') {
		grp = Math.random() * 100 < (C.controlPct || 0) ? 'control' : 'test';
	}
	setCookie('aisr_grp', grp, hasConsent ? 30 : 0);

	var page = C.page || { type: 'other', product_id: 0 };

	// Diagnostics: add ?aisr_debug=1 to any page to see why a trigger did or did not show (works on phones).
	var DEBUG = /[?&]aisr_debug=1/.test(location.search);
	var dbgBox = null;
	function dbg(msg) {
		if (!DEBUG) return;
		if (!dbgBox) {
			dbgBox = document.createElement('div');
			dbgBox.style.cssText = 'position:fixed;top:8px;left:8px;z-index:2147483647;max-width:300px;max-height:45vh;overflow:auto;background:rgba(0,0,0,.82);color:#fff;font:12px/1.4 monospace;padding:8px;border-radius:8px;direction:ltr;text-align:left;white-space:pre-wrap';
			document.body.appendChild(dbgBox);
		}
		var t = new Date(); dbgBox.textContent += ('0' + t.getSeconds()).slice(-2) + 's ' + msg + '\n';
		dbgBox.scrollTop = dbgBox.scrollHeight;
	}
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
		return { type: page.type, product_id: page.product_id, title: page.title, order_items: page.order_items || '', category: page.category || '', workshop: !!page.workshop, viewed: viewed.filter(function (id) { return id !== page.product_id; }) };
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
	// Adds through WooCommerce's own add-to-cart, with the product page's option fields, so required choices pass validation.
	function wcAjaxAdd(a) {
		var fd = new URLSearchParams();
		fd.append('product_id', a.id); fd.append('quantity', a.qty || 1);
		if (a.groups) fd.append('wapf_field_groups', a.groups);
		Object.keys(a.fields || {}).forEach(function (k) {
			var v = a.fields[k];
			if (Array.isArray(v)) v.forEach(function (x) { fd.append('wapf[field_' + k + '][]', x); });
			else fd.append('wapf[field_' + k + ']', v);
		});
		return fetch(C.wcAjax.replace('%%endpoint%%', 'add_to_cart'), {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString()
		}).then(function (r) { return r.json(); }).then(function (j) {
			if (!j || j.error) throw j;
			try { if (window.jQuery && j.fragments) window.jQuery(document.body).trigger('added_to_cart', [j.fragments, j.cart_hash]); } catch (e) { /* ignore */ }
			return j;
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
	// A photo of the shopper's table or kitchen: resized here, then the bot recommends matching pieces.
	var attach = el('button', 'aisr-attach'); attach.type = 'button'; attach.setAttribute('aria-label', 'שליחת תמונה');
	attach.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M9 3 7.2 5H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3.2L15 3H9zm3 5a4.5 4.5 0 1 1 0 9 4.5 4.5 0 0 1 0-9z"/></svg>';
	var fileIn = el('input'); fileIn.type = 'file'; fileIn.accept = 'image/*'; fileIn.hidden = true;
	attach.addEventListener('click', function () { fileIn.click(); });
	fileIn.addEventListener('change', function () {
		var f = fileIn.files && fileIn.files[0]; fileIn.value = '';
		if (!f || busy) return;
		var img = new Image(), url = URL.createObjectURL(f);
		img.onload = function () {
			var max = 1024, w = img.width, h = img.height, k = Math.min(1, max / Math.max(w, h));
			var cv = document.createElement('canvas'); cv.width = Math.round(w * k); cv.height = Math.round(h * k);
			cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height); URL.revokeObjectURL(url);
			sendMessage(input.value.trim() || (history.length ? 'הנה תמונה' : 'מה יתאים לזה?'), cv.toDataURL('image/jpeg', 0.8)); // Mid-conversation the photo answers the open question.
		};
		img.src = url;
	});
	form.appendChild(attach); form.appendChild(fileIn);
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
			c.addEventListener('click', function () { if (/^📷/.test(txt)) { fileIn.click(); return; } sendMessage(txt); }); // The photo chip opens the camera.
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
		if (holidayOn() && p.in_stock && !p.made_to_order && !p.workshop) body.appendChild(el('div', 'aisr-card-note', 'מגיע לפני ' + C.holiday.name + ' · בהזמנה עד ' + C.holiday.deadlineText));
		var btn = el('button', 'aisr-card-btn'); btn.type = 'button';
		if (p.simple && p.in_stock) {
			btn.textContent = 'הוספה לסל';
			btn.addEventListener('click', function () {
				btn.disabled = true; btn.textContent = 'מוסיף…';
				storeApi('/cart/add-item', { id: p.id, quantity: 1 }).then(function () {
					btn.textContent = 'נוסף לסל ✓'; refreshMiniCart(); track('add_to_cart', { trigger: 'card' }); setChips(['מה מתאים לזה?']);
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
	// Days from today (the shopper's clock) to the holiday's order-by date; cached pages may be a day old.
	function todayStr() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
	function holidayOn() { var h = C.holiday; return !!(h && h.deadline && todayStr() <= h.deadline); }
	function deadlineSoon() { return holidayOn() && (Date.parse(C.holiday.deadline) - Date.parse(todayStr())) / 864e5 <= 4; }
	// One tap sends the recommendation to the studio's WhatsApp: the shopper keeps it in their chat, the studio sees the lead.
	function saveWa(items) {
		var lines = (items || []).filter(function (p) { return p && p.url; }).map(function (p) { var u = p.url; try { u = decodeURI(u); } catch (e) { /* keep encoded */ } return '• ' + p.name + ' – ' + u; });
		if (!C.whatsapp || !lines.length) return null;
		var a = el('a', 'aisr-save-wa', 'לשמור בוואטסאפ');
		a.href = 'https://wa.me/' + C.whatsapp + '?text=' + encodeURIComponent('היי, שמרתי מהצ׳אט באתר:\n' + lines.join('\n'));
		a.target = '_blank'; a.rel = 'noopener';
		a.addEventListener('click', function () { track('clicked', { trigger: 'save_whatsapp' }); });
		return a;
	}
	function action(a) {
		var wrap = el('div', 'aisr-action');
		if (a.type === 'bundle') {
			var box = el('div', 'aisr-bundle');
			box.appendChild(el('div', 'aisr-bundle-title', a.title));
			(a.items || []).forEach(function (p) { var c = card(p); if (p.simple) c.classList.add('aisr-in-set'); box.appendChild(c); });
			var addable = (a.items || []).filter(function (p) { return p.simple; });
			var choose = (a.items || []).filter(function (p) { return !p.simple; });
			var all = el('button', 'aisr-btn', choose.length ? (addable.length === 1 ? 'הוספת הפריט המוכן לסל' : 'הוספת ' + addable.length + ' הפריטים המוכנים לסל') : 'הוספת כל הסט לסל · ' + a.total); all.type = 'button';
			all.addEventListener('click', function () {
				all.disabled = true; all.textContent = 'מוסיף…';
				var chain = Promise.resolve();
				addable.forEach(function (p) { chain = chain.then(function () { return storeApi('/cart/add-item', { id: p.id, quantity: 1 }); }); });
				chain.then(function () {
					all.textContent = choose.length ? 'נוסף לסל ✓ · את ' + choose.map(function (p) { return p.name; }).join(', ') + ' בוחרים בעמוד המוצר' : 'הסט נוסף לסל ✓'; refreshMiniCart(); track('add_to_cart', { trigger: 'bundle' }); set(SS, 'added', true);
				}).catch(function () { all.disabled = false; all.textContent = 'לא הצלחנו להוסיף. נסו שוב'; });
			});
			box.appendChild(all);
			var sw = saveWa(a.items); if (sw) box.appendChild(sw);
			wrap.appendChild(box);
			return wrap;
		}
		if (a.type === 'reviews') {
			var rv = el('div', 'aisr-reviews');
			(a.items || []).forEach(function (r) {
				var q = el('div', 'aisr-review');
				var st = el('div', 'aisr-stars', '★★★★★'.slice(0, Math.max(1, Math.min(5, r.rating || 5))));
				st.setAttribute('aria-label', (r.rating || 5) + ' מתוך 5 כוכבים');
				q.appendChild(st);
				q.appendChild(el('div', 'aisr-review-text', '"' + r.text + '"'));
				q.appendChild(el('div', 'aisr-review-who', (r.name ? r.name + ' · ' : '') + 'על ' + r.product));
				rv.appendChild(q);
			});
			if (a.google) {
				var gl = el(a.google.url ? 'a' : 'div', 'aisr-google', '★ ' + a.google.rating + ' בגוגל' + (a.google.count ? ' · ' + a.google.count + ' ביקורות' : '') + (a.google.url ? ' ←' : ''));
				if (a.google.url) { gl.href = a.google.url; gl.target = '_blank'; gl.rel = 'noopener'; gl.addEventListener('click', function () { track('clicked', { trigger: 'google_reviews' }); }); }
				rv.appendChild(gl);
			}
			wrap.appendChild(rv);
			return wrap;
		}
		if (a.type === 'add_to_cart') {
			var box2 = el('div', 'aisr-added', 'מוסיף לסל…');
			wrap.appendChild(box2);
			wcAjaxAdd(a).then(function () {
				box2.textContent = '✓ נוסף לסל: ' + a.name + (a.summary ? ' · ' + a.summary : '');
				if (C.cartUrl) { var go = el('a', 'aisr-btn', 'למעבר לתשלום'); go.href = C.cartUrl; wrap.appendChild(go); }
				refreshMiniCart(); track('add_to_cart', { trigger: 'chat' }); set(SS, 'added', true);
			}).catch(function () {
				box2.textContent = 'לא הצלחתי להוסיף מכאן. אפשר להוסיף בעמוד המוצר.';
				var pg = el('a', 'aisr-btn', 'לעמוד המוצר'); pg.href = a.url; wrap.appendChild(pg);
			});
			return wrap;
		}
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
	function sendMessage(text, image) {
		text = String(text || '').trim();
		if (!text || busy) return;
		busy = true; setChips([]); input.value = '';
		// Keep the opening message in the visit history, so the chat reads the same on the next page.
		if (!history.length) {
			var first = log.querySelector('.aisr-bot');
			if (first) { history.push({ r: 'bot', t: first.textContent }); }
		}
		bubble('user', text);
		if (image) { var th = el('img', 'aisr-photo'); th.src = image; th.alt = ''; log.appendChild(th); }
		var t = typing();
		var body = { message: text, conversation_id: convId, visitor: visitor, grp: grp, page: pageCtx() };
		if (image) body.image = image;
		if (pendingOpener) { body.opener = pendingOpener.text; body.trigger = pendingOpener.trigger; }
		post('/chat', body).then(function (res) {
			convId = res.conversation_id || convId; set(SS, 'conv', convId);
			pendingOpener = null;
			var seq = Promise.resolve();
			(res.bubbles || []).forEach(function (txt, i) {
				seq = seq.then(function () {
					if (i > 0) { log.appendChild(t); scrollDown(); return wait(Math.min(1600, 450 + txt.length * 12)); }
				}).then(function () {
					if (t.parentNode) t.remove(); bubble('bot', txt);
					// Reviews come right after the lead-in sentence, before the rest of the reply.
					if (i === 0) { (res.actions || []).forEach(function (a) { if (a.type === 'reviews') { log.appendChild(action(a)); scrollDown(); } }); }
				});
			});
			return seq.then(function () {
				if (t.parentNode) t.remove();
				if (!(res.bubbles || []).length) { (res.actions || []).forEach(function (a) { if (a.type === 'reviews') log.appendChild(action(a)); }); }
				(res.cards || []).forEach(function (p) { log.appendChild(card(p)); });
				// One WhatsApp button at a time: when the reply already offers one, the save link is left out.
				var hasWa = (res.actions || []).some(function (a) { return a.type === 'whatsapp'; });
				var shop = (res.cards || []).filter(function (p) { return !p.workshop; });
				var sw = shop.length && !hasWa ? saveWa(shop) : null; if (sw) log.appendChild(sw);
				(res.actions || []).forEach(function (a) { if (a.type !== 'reviews') log.appendChild(action(a)); });
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
		if (pendingOpener && history.length) {
			bubble('bot', pendingOpener.text, true); // A new proactive message joins the existing conversation.
			setChips(pendingOpener.replies);
		} else if (pendingOpener && !history.length) {
			bubble('bot', pendingOpener.text, false);
			setChips(pendingOpener.replies);
		} else if (!history.length) {
			// Opened by the shopper: a short hello with the three things people come for most.
			var o = (C.openers && (C.openers[page.workshop ? 'welcome_workshop' : (page.type === 'product' ? 'welcome_product' : 'welcome')] || C.openers[page.type === 'product' ? 'product_dwell' : (page.type === 'category' ? 'category' : 'home')])) || { text: 'היי, איך אפשר לעזור?', replies: [] };
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
	var CHECKOUT_TRIGGERS = ['checkout_help', 'shipping_cost', 'cart_made', 'rage_click'];
	function proactiveAllowed(trigger) {
		var on = page.type === 'checkout' ? (C.checkoutProactive && CHECKOUT_TRIGGERS.indexOf(trigger) !== -1) : C.proactive;
		if (C.proactiveMode === 'all') {
			// Every trigger may speak: once per page load each, with a pause between messages, never over an open chat.
			// Urgent signals (copying the product name, an error) skip the pause: the moment matters.
			return on && grp !== 'control' && panel.hidden && peek.hidden && !firedSet[trigger] && (prio(trigger) >= 3 || Date.now() - lastShownAt >= (C.proactiveCooldown || 0) * 1000);
		}
		return on && grp !== 'control' && !fired && !get(SS, 'proactive', false) && !get(SS, 'dismissed', false) && panel.hidden && !history.length;
	}
	var firedSet = {}, lastShownAt = 0;
	var PRIORITY = { copy_name: 3, add_error: 3, shipping_cost: 3, checkout_help: 3, rage_click: 2, search_empty: 3,
		cart_made: 2, holiday_made: 2, holiday_deadline: 2, freeship_gap: 2, gallery: 2, tab_return: 2, exit_scroll: 2, made_lead: 2, compare_many: 2, policy_return: 2 };
	var currentTrigger = '';
	function prio(t) { return PRIORITY[t] || 1; }
	function upgradeable(trigger) {
		return currentTrigger && trigger !== currentTrigger && prio(trigger) > prio(currentTrigger) && pendingOpener && panel.hidden
			&& (C.proactiveMode === 'all' || (!history.length && !get(SS, 'dismissed', false))) && (page.type !== 'checkout' || CHECKOUT_TRIGGERS.indexOf(trigger) !== -1);
	}
	function enabled(trigger) { return (C.triggers || []).indexOf(trigger) !== -1; }
	function eligible() { return pages >= (C.minPages || 1) || (page.type === 'product' && (Date.now() - startedAt) / 1000 >= (C.productDwell || 8)); }

	function fire(trigger, strong, vars) {
		// A made-to-order product changes what is worth saying: the lead time before a holiday, or an offer to be updated.
		if (page.made_to_order && trigger === 'product_dwell' && C.holiday && !C.holiday.madeOk && enabled('holiday_made')) trigger = 'holiday_made';
		if (page.made_to_order && trigger === 'exit_scroll' && enabled('made_lead')) trigger = 'made_lead';
		// A workshop page sells an experience with the artist: its own words for dwelling, looking and leaving.
		if (page.workshop) {
			if (['product_dwell', 'gallery', 'reread', 'footer_reached', 'size_switch', 'category', 'idle'].indexOf(trigger) !== -1 && enabled(trigger)) trigger = 'workshop';
			else if ((trigger === 'exit_scroll' || trigger === 'tab_return') && enabled(trigger)) trigger = 'workshop_exit';
		}
		// The last days to order before the holiday: a browsing or dwelling shopper hears the order-by date first.
		if (!page.workshop && ((trigger === 'product_dwell' && page.in_stock && !page.made_to_order) || trigger === 'home' || trigger === 'category') && deadlineSoon() && enabled('holiday_deadline') && !firedSet.holiday_deadline) trigger = 'holiday_deadline';
		// On checkout, leaving or stalling becomes one gentle offer of help, and only after 40 seconds there.
		if (page.type === 'checkout' && (trigger === 'exit_scroll' || trigger === 'tab_return' || trigger === 'idle')) {
			if ((Date.now() - startedAt) / 1000 < 20) return;
			trigger = 'checkout_help';
		}
		dbg('trigger: ' + trigger);
		if (!enabled(trigger) && trigger !== 'workshop' && trigger !== 'workshop_exit') { dbg('  blocked: not enabled in settings'); return; }
		// One proactive message per visit, but a stronger signal may replace the text of a bubble
		// the shopper has not reacted to yet (e.g. copying the product name after a 'your cart is waiting').
		if (upgradeable(trigger)) { dbg('  replaces the bubble of ' + currentTrigger); var u = C.openers && C.openers[trigger]; if (u) { var ut = u.text; for (var uv in vars || {}) ut = ut.split('{' + uv + '}').join(vars[uv]); currentTrigger = trigger; pendingOpener = { trigger: trigger, text: ut, replies: u.replies }; if (!peek.hidden && !peekText.querySelector('.aisr-typing')) { peekText.textContent = ut; track('shown', { trigger: trigger }); } } return; }
		if (!proactiveAllowed(trigger)) {
			dbg('  blocked: ' + (C.proactiveMode === 'all' && grp !== 'control' ? (!panel.hidden ? 'chat is open' : !peek.hidden ? 'a bubble is showing' : firedSet[trigger] ? 'this trigger already spoke on this page' : 'pause between messages (' + (C.proactiveCooldown || 0) + 's)') : grp === 'control' ? 'control group (10% see no proactive messages)' : fired || get(SS, 'proactive', false) ? 'already had one proactive message this visit' : get(SS, 'dismissed', false) ? 'a bubble was closed this visit' : !panel.hidden ? 'chat is open' : history.length ? 'already chatted this visit' : 'proactive off (checkout or budget)'));
			return;
		}
		if (!strong && !eligible()) { dbg('  waiting: needs 2 pages or the dwell time'); return; }
		var o = C.openers && C.openers[trigger === 'holiday_deadline' && page.type !== 'product' ? 'holiday_deadline_browse' : trigger];
		if (!o) return;
		var text = o.text;
		for (var v in vars || {}) text = text.split('{' + v + '}').join(vars[v]);
		if (trigger === 'returning') {
			if (!prevVisitProduct || !prevVisitProduct.name) return;
			text = text.replace('{prev}', prevVisitProduct.name);
		}
		fired = true; set(SS, 'proactive', true); currentTrigger = trigger; firedSet[trigger] = true; lastShownAt = Date.now(); dbg('  SHOWN');
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
		var urgent = prio(trigger) >= 3; // The shopper is about to look elsewhere: answer almost at once.
		setTimeout(function () { if (pendingOpener) showPeek(pendingOpener.text, pendingOpener.trigger, urgent); }, urgent ? 250 : 700 + Math.random() * 500);
	}
	function showPeek(text, trigger, urgent) {
		if (!panel.hidden) return;
		peekText.innerHTML = '<span class="aisr-typing aisr-inline"><i></i><i></i><i></i></span>';
		peek.hidden = false; root.classList.add('aisr-peeking');
		setTimeout(function () {
			var latest = pendingOpener || { text: text, trigger: trigger }; // A stronger trigger may have replaced it meanwhile.
			peekText.textContent = latest.text;
			dot.hidden = false;
			track('shown', { trigger: latest.trigger });
		}, urgent ? 600 : 800);
	}
	function hidePeek(dismissed) {
		if (peek.hidden) return;
		peek.hidden = true; root.classList.remove('aisr-peeking');
		if (dismissed) { lastShownAt = Date.now(); set(SS, 'dismissed', true); track('dismissed', { trigger: pendingOpener ? pendingOpener.trigger : '' }); pendingOpener = null; dot.hidden = true; }
	}
	peekClose.addEventListener('click', function (e) { e.stopPropagation(); hidePeek(true); });

	/* ---------- behavior sensors (phone-first) ---------- */
	var startedAt = Date.now();
	var lastActivity = Date.now();
	function activity() { lastActivity = Date.now(); }
	['touchstart', 'scroll', 'keydown', 'mousemove'].forEach(function (ev) { window.addEventListener(ev, activity, { passive: true }); });

	// Dwell on product / category / home / blog.
	var dwellMap = { product: ['product_dwell', C.productDwell || 8], category: ['category', 8], home: ['home', 8], blog: ['blog', 20] };
	if (dwellMap[page.type]) {
		setTimeout(function () { fire(dwellMap[page.type][0], page.type === 'product'); }, dwellMap[page.type][1] * 1000);
	}

	// Copying text on a product page: probably comparing prices elsewhere.
	setTimeout(function () { dbg('v' + (C.version || '') + ' page=' + page.type + ' grp=' + grp + ' proactive=' + !!C.proactive + ' test=' + !!C.testMode + ' triggers=' + (C.triggers || []).length + ' mobile=' + isMobile + ' history=' + history.length + ' hadProactive=' + !!get(SS, 'proactive', false) + ' dismissed=' + !!get(SS, 'dismissed', false)); }, 300);
	document.addEventListener('copy', function () { dbg('event: copy'); if (page.type === 'product') fire('copy_name', true); });
	// Phones: a long press selects the text and the system menu copies it. Some browsers (notably in-app
	// browsers of Instagram and Facebook) never send the copy event to the page, so selecting part of the
	// product name counts as copying it.
	if (page.type === 'product') {
		var selTimer = null;
		document.addEventListener('selectionchange', function () {
			clearTimeout(selTimer);
			selTimer = setTimeout(function () {
				var sel = (window.getSelection ? String(window.getSelection()) : '').replace(/\s+/g, ' ').trim();
				if (sel) dbg('event: selection "' + sel.slice(0, 30) + '"');
				if (sel.length < 3 || sel.length > 120) return;
				var h = document.querySelector('h1.product_title, .product_title, h1');
				var name = ((h && h.textContent) || page.title || '').replace(/\s+/g, ' ').trim();
				if (name && (name.indexOf(sel) !== -1 || sel.indexOf(name) !== -1)) fire('copy_name', true);
			}, 350);
		});
	}

	// Left to another app/tab and came back.
	var hiddenAt = 0;
	document.addEventListener('visibilitychange', function () {
		if (document.hidden) { hiddenAt = Date.now(); return; }
		var away = (Date.now() - hiddenAt) / 1000;
		if (hiddenAt && away >= 4 && away <= 1800 && (page.type === 'product' || page.type === 'checkout')) fire('tab_return', true);
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
		if (flips.length >= 4 && page.type === 'product') {
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
		if (galleryHits >= 2) fire('gallery', true);
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
			if (++switches >= 2) fire('size_switch', true);
		}
	}, true);

	// Added to cart and stopped.
	/* ---------- saving the visit before the shopper leaves ---------- */

	// Shipping cost that appears at checkout (classic checkout refresh, or the blocks totals).
	function shippingCharged() {
		// Classic checkout, CheckoutWC (tr.shipping in its totals table), and the blocks checkout.
		var cfw = document.querySelector('tr.shipping td');
		if (cfw) { var a = cfw.querySelector('.woocommerce-Price-amount, .amount'); var mm = a ? a.textContent.replace(/,/g, '').match(/(\d+(?:\.\d+)?)/) : null; return !!(mm && parseFloat(mm[1]) > 0); }
		var el1 = document.querySelector('.woocommerce-shipping-totals input:checked') || document.querySelector('.woocommerce-shipping-totals li, .wc-block-components-totals-shipping .wc-block-components-totals-item__value');
		var row = el1 && (el1.closest ? el1.closest('li, .wc-block-components-totals-item') : null) || el1;
		// Read only the price element: method names can hold digits (an address, a street number).
		var price = row && row.querySelector ? row.querySelector('.woocommerce-Price-amount, .amount') : null;
		if (!price && row && /wc-block-components-totals-item__value/.test(row.className || '')) price = row;
		var m = price ? price.textContent.replace(/,/g, '').match(/(\d+(?:\.\d+)?)/) : null;
		return !!(m && parseFloat(m[1]) > 0);
	}
	if (page.type === 'checkout') {
		var shipCheck = function () { if (shippingCharged()) fire('shipping_cost', true); };
		if (window.jQuery) window.jQuery(document.body).on('updated_checkout', function () { setTimeout(shipCheck, 500); });
		setTimeout(shipCheck, 5000);
		// Opened the coupon field and did not apply a coupon within 20 seconds.
		document.addEventListener('click', function (e) {
			if (!e.target.closest || !e.target.closest('.showcoupon, .wc-block-components-totals-coupon-link, .wc-block-components-panel__button')) return;
			setTimeout(function () { if (!document.querySelector('.cart-discount, .wc-block-components-totals-discount')) fire('checkout_help', true); }, 10000);
		}, true);
	}

	// Add to cart that did not go through: a disabled button (no option chosen) or a WooCommerce error notice.
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('.single_add_to_cart_button');
		if (b && (b.disabled || /disabled|wc-variation-selection-needed/.test(b.className))) fire('add_error', true);
	}, true);
	if (window.MutationObserver && page.type !== 'checkout') { // Checkout errors are payment errors, not add-to-cart ones.
		new MutationObserver(function (list) {
			list.forEach(function (m) { m.addedNodes.forEach(function (n) {
				if (n.nodeType === 1 && (n.matches && n.matches('.woocommerce-error, .wc-block-components-notice-banner.is-error') || (n.querySelector && n.querySelector('.woocommerce-error, .wc-block-components-notice-banner.is-error')))) fire('add_error', true);
			}); });
		}).observe(document.body, { childList: true, subtree: true });
	}
	if (document.querySelector('.woocommerce-error, .wc-block-components-notice-banner.is-error') && page.type !== 'checkout') setTimeout(function () { fire('add_error', true); }, 600);

	// Right after an order: thanks, delivery questions, one idea that completes it.
	if (page.type === 'thankyou') setTimeout(function () { fire('thankyou', true); }, 2000);

	// A site search that found nothing.
	if (page.type === 'search' && page.search_empty) setTimeout(function () { fire('search_empty', true); }, 1000);

	// Rage clicks: three fast clicks in the same spot usually mean something on the page does not work.
	var clicks = [];
	document.addEventListener('click', function (e) {
		if (root.contains(e.target)) return;
		// Selecting text on a phone (long press, adjusting the handles) produces taps that are not frustration.
		if (window.getSelection && String(window.getSelection()).trim()) { clicks = []; return; }
		var now = Date.now();
		clicks = clicks.filter(function (c) { return now - c.t < 900; });
		clicks.push({ t: now, x: e.clientX, y: e.clientY });
		var near = clicks.filter(function (c) { return Math.abs(c.x - e.clientX) < 30 && Math.abs(c.y - e.clientY) < 30; });
		if (near.length >= (isMobile ? 4 : 3)) { clicks = []; fire('rage_click', true); }
	}, true);

	// Several products viewed in this visit and nothing added yet.
	if (page.type === 'product' && viewed.length >= 3 && !get(SS, 'added', false)) setTimeout(function () { fire('compare_many', true); }, 2500);
	if (window.jQuery) window.jQuery(document.body).on('added_to_cart', function () { set(SS, 'added', true); });

	// A made-to-order item in the cart: say the lead time before paying, not after.
	if ((page.type === 'cart' || page.type === 'checkout') && page.cart_made) setTimeout(function () { fire('cart_made', true); }, 2000);

	// Came back from a policy page (returns, shipping) to a product: something worries them.
	if (page.type === 'policy') set(SS, 'policy_seen', true);
	else if (page.type === 'product' && get(SS, 'policy_seen', false)) { set(SS, 'policy_seen', false); setTimeout(function () { fire('policy_return', true); }, 1500); }

	// First page of a visit, and a cart from an earlier visit is still there.
	if (pages === 1 && page.type !== 'cart' && page.type !== 'checkout') {
		setTimeout(function () {
			fetch(C.storeApi + '/cart', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (cart) {
				if (cart && cart.items && cart.items.length) fire('cart_waiting', true, { item: cart.items[0].name });
			}).catch(function () { /* ignore */ });
		}, 2000);
	}

	// Scrolled to the bottom of a product page without doing anything.
	if (page.type === 'product') {
		window.addEventListener('scroll', function () {
			if (window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 150) fire('footer_reached', false);
		}, { passive: true });
	}

	// Close to the free-shipping threshold: offer one small item that completes the cart.
	function checkFreeShip() {
		if (!C.freeShipMin || !C.freeShipGap) return;
		fetch(C.storeApi + '/cart', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (cart) {
			var t = cart && cart.totals; if (!t) return;
			var unit = Math.pow(10, t.currency_minor_unit || 0);
			var sub = (parseInt(t.total_items, 10) + parseInt(t.total_items_tax || 0, 10)) / unit;
			var gap = Math.ceil(C.freeShipMin - sub);
			if (sub > 0 && gap > 0 && gap <= C.freeShipGap) fire('freeship_gap', true, { gap: gap });
		}).catch(function () { /* ignore */ });
	}
	if (window.jQuery) window.jQuery(document.body).on('added_to_cart', function () { setTimeout(checkFreeShip, 1500); });
	if (page.type === 'cart') setTimeout(checkFreeShip, 2500);

	function cartIdle() { setTimeout(function () { if (Date.now() - lastActivity > 6000 || page.type === 'cart') fire('cart_idle', true); }, 10000); }
	if (window.jQuery) window.jQuery(document.body).on('added_to_cart', cartIdle);
	document.body.addEventListener('wc-blocks_added_to_cart', cartIdle);
	if (page.type === 'cart') cartIdle();

	// Arrived from social apps (in-app browsers) or price-comparison sites.
	var ua = navigator.userAgent || '', ref = document.referrer || '';
	if (pages === 1) {
		if (/Instagram|FBAN|FBAV|TikTok|musical_ly|BytedanceWebview/i.test(ua) || /instagram\.|facebook\.|tiktok\./i.test(ref)) {
			setTimeout(function () { fire('social_visitor', true); }, 3000);
		} else if (/zap\.co\.il|wisebuy|google\.[^/]+\/shopping|shopping\.google/i.test(ref)) {
			setTimeout(function () { fire('comparison_visitor', true); }, 3000);
		}
	}

	// Stuck: no scrolling or touching for a while.
	setInterval(function () {
		if ((Date.now() - lastActivity) / 1000 >= (C.idleSeconds || 12) && !document.hidden) fire('idle', false);
	}, 2000);

	// Returning visitor (extended privacy mode with consent only).
	if (LS && prevVisitProduct && pages === 1 && prevVisitProduct.id !== page.product_id && Date.now() - prevVisitProduct.t > 3600000) {
		setTimeout(function () { fire('returning', true); }, 2500);
	}

	dot.hidden = true;
})();
