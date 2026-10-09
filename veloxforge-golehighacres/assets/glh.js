/* VeloxForge · GoLehighAcres — comportamiento de los bloques (sin dependencias). Se inicia solo y también al insertar contenido en el editor (evento vf:init). */
(function () {
	'use strict';
	var d = document;
	var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
	var fine = window.matchMedia && matchMedia('(pointer: fine)').matches;

	function q(s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); }
	function once(el, k) { if (el['__glh' + k]) { return false; } el['__glh' + k] = 1; return true; }
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function esc(t) { return String(t).replace(/[&<>"]/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); }
	function json(el, attr) { try { return JSON.parse(el.getAttribute(attr)) || {}; } catch (e) { return {}; } }
	function watch(els, cb, opts) {
		if (!('IntersectionObserver' in window)) { els.forEach(cb); return; }
		var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); cb(e.target); } }); }, opts || { threshold: 0.12 });
		els.forEach(function (el) { io.observe(el); });
	}

	/* ---------- Revelado suave ---------- */
	function rise(r) {
		var els = q('[data-glh-rise]', r).filter(function (e) { return once(e, 'rs'); });
		if (reduce) { els.forEach(function (e) { e.classList.add('in'); }); return; }
		watch(els, function (e) { e.classList.add('in'); });
	}

	/* ---------- Cabecera: menú en móvil ---------- */
	function header(r) {
		q('[data-glh="header"]', r).forEach(function (h) {
			if (!once(h, 'hd')) { return; }
			var b = h.querySelector('.glh-burger'), n = h.querySelector('.glh-nav');
			if (!b || !n) { return; }
			function set(o) { n.classList.toggle('open', o); b.setAttribute('aria-expanded', String(o)); b.setAttribute('aria-label', o ? 'Close menu' : 'Open menu'); }
			b.addEventListener('click', function () { set(!n.classList.contains('open')); });
			n.addEventListener('click', function (e) { if (e.target.closest('a')) { set(false); } });
			d.addEventListener('keydown', function (e) { if (e.key === 'Escape') { set(false); } });
		});
	}

	/* ---------- Cifras animadas ---------- */
	function stats(r) {
		var els = q('[data-glh-count]', r).filter(function (e) { return once(e, 'ct'); });
		watch(els, function (el) {
			var to = parseFloat(el.getAttribute('data-glh-count')) || 0;
			var fmt = function (n) { try { return Math.round(n).toLocaleString(d.documentElement.lang || undefined); } catch (e) { return String(Math.round(n)); } };
			if (reduce) { el.textContent = fmt(to); return; }
			var t0 = null;
			(function step(t) {
				if (t0 === null) { t0 = t; }
				var p = Math.min(1, (t - t0) / 1600);
				el.textContent = fmt(to * (1 - Math.pow(1 - p, 3)));
				if (p < 1) { requestAnimationFrame(step); }
			})(performance.now());
		});
	}

	/* ---------- Carrusel 3D ---------- */
	function carousel(r) {
		q('[data-glh="carousel"]', r).forEach(function (root) {
			if (!once(root, 'ca')) { return; }
			var car = root.querySelector('.glh-car'), ring = root.querySelector('.glh-ring'), cards = q('.glh-cd', ring);
			var n = cards.length; if (n < 2) { return; }
			var step = 360 / n, angle = 0, vel = 0, auto = root.getAttribute('data-auto') === '1' && !reduce, speed = (parseFloat(root.getAttribute('data-speed')) || 12) / 1000;
			var dragging = false, lastX = 0, hover = false, last = performance.now(), isStatic = root.hasAttribute('data-glh-static');
			var tog = root.querySelector('[data-act="toggle"]');
			var lblPause = root.getAttribute('data-pause') || 'Pause', lblPlay = root.getAttribute('data-play') || 'Rotate';
			function label() { if (tog) { tog.textContent = auto ? lblPause : lblPlay; tog.setAttribute('aria-pressed', String(!auto)); } }
			function layout() {
				var w = cards[0].offsetWidth, R = Math.round((w / 2) / Math.tan(Math.PI / n) + 24);
				cards.forEach(function (c, i) { c.dataset.a = i * step; c.dataset.r = R; });
			}
			function render() {
				ring.style.transform = 'translateZ(' + (-cards[0].dataset.r) + 'px) rotateY(' + angle.toFixed(2) + 'deg)';
				cards.forEach(function (c) {
					var a = (+c.dataset.a + angle) * Math.PI / 180;
					c.style.transform = 'rotateY(' + c.dataset.a + 'deg) translateZ(' + c.dataset.r + 'px)';
					c.style.opacity = (0.35 + 0.65 * (Math.cos(a) + 1) / 2).toFixed(2);
				});
			}
			function tick(t) {
				var dt = Math.min(50, t - last); last = t;
				if (!dragging) { angle += vel; vel *= 0.94; if (auto && !hover) { angle -= dt * speed; } }
				render();
				if (d.body.contains(root)) { requestAnimationFrame(tick); }
			}
			layout(); render(); label();
			window.addEventListener('resize', function () { layout(); render(); });
			if (isStatic) { return; }
			car.addEventListener('pointerdown', function (e) { dragging = true; lastX = e.clientX; vel = 0; car.classList.add('drag'); try { car.setPointerCapture(e.pointerId); } catch (er) { /* ok */ } });
			car.addEventListener('pointermove', function (e) { if (!dragging) { return; } var dx = e.clientX - lastX; lastX = e.clientX; angle += dx * 0.35; vel = dx * 0.35; });
			function end() { dragging = false; car.classList.remove('drag'); }
			car.addEventListener('pointerup', end); car.addEventListener('pointercancel', end);
			car.addEventListener('pointerenter', function () { hover = true; }); car.addEventListener('pointerleave', function () { hover = false; });
			function go(dir) {
				var target = Math.round(angle / step) * step + dir * step, from = angle, t0 = performance.now(); vel = 0; auto = false; label();
				(function anim(t) { var k = Math.min(1, (t - t0) / 600), e = 1 - Math.pow(1 - k, 3); angle = from + (target - from) * e; if (k < 1) { requestAnimationFrame(anim); } })(t0);
			}
			var pv = root.querySelector('[data-act="prev"]'), nx = root.querySelector('[data-act="next"]');
			if (pv) { pv.addEventListener('click', function () { go(1); }); }
			if (nx) { nx.addEventListener('click', function () { go(-1); }); }
			if (tog) { tog.addEventListener('click', function () { auto = !auto; label(); }); }
			requestAnimationFrame(tick);
		});
	}

	/* ---------- Recorrido: la tarjeta sigue a la etapa activa ---------- */
	function journey(r) {
		q('[data-glh="journey"]', r).forEach(function (root) {
			if (!once(root, 'jn') || !('IntersectionObserver' in window)) { return; }
			var steps = q('.glh-st', root), cards = q('.glh-jc', root), cnt = root.querySelector('[data-glh-cnt]'), total = pad(steps.length);
			var io = new IntersectionObserver(function (es) {
				es.forEach(function (e) {
					if (!e.isIntersecting) { return; }
					var i = +e.target.getAttribute('data-i');
					steps.forEach(function (s) { s.classList.toggle('on', s === e.target); });
					cards.forEach(function (c) { c.classList.toggle('on', +c.getAttribute('data-i') === i); });
					if (cnt) { cnt.textContent = pad(i + 1) + ' / ' + total; }
				});
			}, { rootMargin: '-40% 0px -40% 0px' });
			steps.forEach(function (s) { io.observe(s); });
		});
	}

	/* ---------- Columnas con movimiento suave ---------- */
	var pars = [], parTick = false;
	function parallax(r) {
		q('[data-glh-par]', r).forEach(function (el) { if (once(el, 'pa')) { pars.push(el); } });
		if (reduce || !pars.length) { return; }
		function update() {
			var vh = window.innerHeight;
			pars.forEach(function (el) {
				if (!d.body.contains(el)) { return; }
				var b = el.getBoundingClientRect();
				el.style.translate = '0 ' + ((b.top + b.height / 2 - vh / 2) * parseFloat(el.getAttribute('data-glh-par'))).toFixed(1) + 'px';
			});
			parTick = false;
		}
		if (!parallax.on) { parallax.on = true; window.addEventListener('scroll', function () { if (!parTick) { parTick = true; requestAnimationFrame(update); } }, { passive: true }); }
		update();
	}


	/* ---------- Perfil de negocio: galería, marcado y ventana emergente ---------- */
	function bindGallery(root) {
		var slides = q('.glh-pf-slide', root), th = q('.glh-pf-th', root), cnt = root.querySelector('.glh-pf-count'), i = 0;
		function show(n) {
			if (!slides.length) { return; }
			i = (n + slides.length) % slides.length;
			slides.forEach(function (s, k) { s.classList.toggle('on', k === i); });
			th.forEach(function (t, k) { t.setAttribute('aria-current', String(k === i)); });
			if (cnt) { cnt.textContent = (i + 1) + ' / ' + slides.length; }
		}
		root.addEventListener('click', function (e) {
			if (e.target.closest('.glh-pf-prev')) { show(i - 1); } else if (e.target.closest('.glh-pf-next')) { show(i + 1); }
			var t = e.target.closest('.glh-pf-th'); if (t) { show(th.indexOf(t)); }
		});
		return { show: show, step: function (d) { show(i + d); }, count: slides.length };
	}
	function mapsUrl(p) { return p.maps || 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(p.address || (p.name + ' Lehigh Acres FL')); }
	function dirUrl(p) { return 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(p.address || (p.name + ' Lehigh Acres FL')); }
	function profileHTML(p, L, ph) {
		var gal = p.gallery && p.gallery.length ? p.gallery : null, n = gal ? gal.length : Math.max(1, ph || 4), slides = '', thumbs = '', i, k;
		for (i = 0; i < n; i++) {
			k = (i % 6) + 1;
			var src = gal ? gal[i].src : '';
			slides += '<div class="glh-pf-slide glh-k' + k + (i === 0 ? ' on' : '') + '">' + (src ? '<img src="' + esc(src) + '" alt="' + esc((gal[i].alt) || p.name) + '">' : '<b>' + esc(L.photo.toUpperCase() + ' ' + (i + 1)) + '</b><small>' + esc(p.name) + '</small>') + '</div>';
			thumbs += '<button type="button" class="glh-pf-th glh-k' + k + '" aria-label="' + esc(L.photo + ' ' + (i + 1)) + '" aria-current="' + (i === 0) + '">' + (src ? '<img src="' + esc(src) + '" alt="">' : '') + '</button>';
		}
		var ini = p.name.replace(/^Sample\s+/i, '').split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('') || 'GO';
		var stars = '';
		if (p.rating > 0) { var r = Math.round(p.rating); stars = '<span class="glh-pf-stars" aria-label="' + p.rating + ' / 5">' + '★★★★★'.slice(0, r) + '☆☆☆☆☆'.slice(0, 5 - r) + '</span> <span>' + Number(p.rating).toFixed(1) + (p.reviews ? ' (' + p.reviews + ')' : '') + '</span>'; }
		var tel = (p.phone || '').replace(/[^+\d]/g, '');
		var rows = '<div><dt>' + esc(L.address) + '</dt><dd>' + esc(p.address || '') + '<br><a href="' + esc(mapsUrl(p)) + '" target="_blank" rel="noopener">' + esc(L.maps) + ' ↗</a></dd></div>';
		if (p.phone) { rows += '<div><dt>' + esc(L.phone) + '</dt><dd><a href="tel:' + esc(tel) + '">' + esc(p.phone) + '</a></dd></div>'; }
		if (p.email) { rows += '<div><dt>' + esc(L.email) + '</dt><dd>' + esc(p.email) + '</dd></div>'; }
		if (p.web) { rows += '<div><dt>' + esc(L.web) + '</dt><dd><a href="' + esc(p.web) + '" target="_blank" rel="noopener">' + esc(p.web.replace(/^https?:\/\//, '')) + '</a></dd></div>'; }
		if (p.hours && p.hours.length) { rows += '<div><dt>' + esc(L.hours) + '</dt><dd><div class="glh-pf-hours">' + p.hours.map(function (h) { return '<span>' + esc(h[0]) + '</span><span>' + esc(h[1]) + '</span>'; }).join('') + '</div></dd></div>'; }
		return '<div class="glh-pf-gal"><div class="glh-pf-main">' + slides + (n > 1 ? '<button type="button" class="glh-pf-arrow glh-pf-prev" aria-label="‹">‹</button><button type="button" class="glh-pf-arrow glh-pf-next" aria-label="›">›</button><span class="glh-pf-count">1 / ' + n + '</span>' : '') + '</div>' + (n > 1 ? '<div class="glh-pf-thumbs">' + thumbs + '</div>' : '') + '</div>'
			+ '<div class="glh-pf-info"><div class="glh-pf-head"><div class="glh-pf-logo" style="--dot:' + esc(p.color) + '">' + (p.logo ? '<img src="' + esc(p.logo) + '" alt="">' : esc(ini)) + '</div><div><h2 class="glh-pf-name">' + esc(p.name) + '</h2><div class="glh-pf-meta">' + (p.cat ? '<span class="glh-pf-cat"><i style="--dot:' + esc(p.color) + '"></i>' + esc(p.cat) + '</span>' : '') + stars + (p.founding ? '<span class="glh-pf-badge">' + esc(L.found) + '</span>' : '') + '</div></div></div>'
			+ (p.desc ? '<p class="glh-pf-desc">' + esc(p.desc) + '</p>' : '')
			+ '<div class="glh-pf-actions"><a class="glh-pf-btn main" href="' + esc(mapsUrl(p)) + '" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg> ' + esc(L.maps) + '</a><a class="glh-pf-btn" href="' + esc(dirUrl(p)) + '" target="_blank" rel="noopener">' + esc(L.dir) + '</a>' + (p.phone ? '<a class="glh-pf-btn" href="tel:' + esc(tel) + '">' + esc(L.call) + '</a>' : '') + (p.web ? '<a class="glh-pf-btn" href="' + esc(p.web) + '" target="_blank" rel="noopener">' + esc(L.web) + '</a>' : '') + '</div>'
			+ '<dl class="glh-pf-list">' + rows + '</dl>'
			+ (p.tags && p.tags.length ? '<div class="glh-pf-tags">' + p.tags.map(function (t) { return '<span>' + esc(t) + '</span>'; }).join('') + '</div>' : '')
			+ (p.social && p.social.length ? '<div class="glh-pf-social">' + p.social.map(function (x) { return '<a href="' + esc(x[1]) + '" target="_blank" rel="noopener">' + esc(x[0]) + '</a>'; }).join('') + '</div>' : '')
			+ (p.note ? '<p class="glh-pf-note">' + esc(p.note) + '</p>' : '') + '</div>';
	}
	var pmEl = null, pmGal = null, pmOpener = null;
	function closeProfile() {
		if (!pmEl) { return; }
		pmEl.remove(); pmEl = null; pmGal = null; d.documentElement.classList.remove('glh-lock');
		if (pmOpener && d.body.contains(pmOpener)) { pmOpener.focus({ preventScroll: true }); }
	}
	function openProfile(p, L, opener) {
		closeProfile(); pmOpener = opener || null;
		pmEl = d.createElement('div'); pmEl.className = 'glh-pm'; pmEl.setAttribute('role', 'dialog'); pmEl.setAttribute('aria-modal', 'true'); pmEl.setAttribute('aria-label', p.name);
		pmEl.innerHTML = '<div class="glh-pm__box"><button type="button" class="glh-pm__close" aria-label="' + esc(L.close) + '">&#10005;</button>' + profileHTML(p, L, 4) + '</div>';
		d.body.appendChild(pmEl); d.documentElement.classList.add('glh-lock');
		pmGal = bindGallery(pmEl);
		pmEl.addEventListener('click', function (e) { if (e.target === pmEl || e.target.closest('.glh-pm__close')) { closeProfile(); } });
		pmEl.querySelector('.glh-pm__close').focus({ preventScroll: true });
	}
	d.addEventListener('keydown', function (e) {
		if (!pmEl) { return; }
		if (e.key === 'Escape') { closeProfile(); }
		else if (e.key === 'ArrowLeft' && pmGal) { pmGal.step(-1); } else if (e.key === 'ArrowRight' && pmGal) { pmGal.step(1); }
		else if (e.key === 'Tab') {
			var f = q('button, a[href]', pmEl).filter(function (n) { return n.offsetParent !== null || n === d.activeElement; });
			if (!f.length) { return; }
			if (e.shiftKey && d.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); } else if (!e.shiftKey && d.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
		}
	});
	function business(r) {
		q('[data-glh="business"]', r).forEach(function (root) { if (once(root, 'bz') && !root.hasAttribute('data-glh-static')) { bindGallery(root); } });
	}

	/* ---------- Directorio: buscador, filtros y secciones ---------- */
	function directory(r) {
		q('[data-glh="directory"]', r).forEach(function (root) {
			if (!once(root, 'dr')) { return; }
			var data = json(root, 'data-glh-data'), cats = data.cats || [], items = data.items || [];
			var input = root.querySelector('.glh-search input'), clr = root.querySelector('.glh-clear');
			var dd = root.querySelector('.glh-dd'), ddBtn = root.querySelector('.glh-dd-btn'), ddList = root.querySelector('.glh-dd-list'), ddLabel = root.querySelector('.glh-dd-label'), opts = q('.glh-dd-opt', ddList), actI = 0, typed = '', typedT = 0;
			var status = root.querySelector('.glh-status'), recent = root.querySelector('.glh-recent'), found = root.querySelector('.glh-found');
			var active = null;
			items.forEach(function (l, i) { l.i = i; l.h = ((cats[l.k] ? cats[l.k].n : '') + ' ' + l.s + ' ' + l.n).toLowerCase(); });
			var L = data.L || {}, sp = data.sp || {};
			function profileFor(l) {
				if (l.p) { var p = l.p; p.note = ''; return p; }
				var c = cats[l.k] || { n: '', c: '#2E7D55' };
				return { name: l.n, cat: c.n, color: c.c, logo: '', desc: String(sp.desc || '').replace('{type}', l.s), address: sp.address || '', maps: '', phone: sp.phone || '', email: sp.email || '', web: sp.web || '', hours: sp.hours || [], tags: [l.s].concat(sp.tags || []), rating: 0, reviews: 0, founding: false, gallery: [], social: [], note: data.note || '' };
			}
			root.addEventListener('click', function (e) {
				var row = e.target.closest('.glh-res[data-i]');
				if (row && data.profile) { openProfile(profileFor(items[+row.getAttribute('data-i')]), L, row); return; }
				var tr = e.target.closest('tr[data-row]');
				if (tr && data.profile) {
					var nm = tr.querySelector('.glh-tn').textContent, ct = tr.children[2].textContent;
					openProfile({ name: nm, cat: ct, color: '#2E7D55', logo: '', desc: String(sp.desc || '').replace('{type}', ct.toLowerCase()), address: sp.address || '', maps: '', phone: sp.phone || '', email: sp.email || '', web: sp.web || '', hours: sp.hours || [], tags: [ct].concat(sp.tags || []), rating: 0, reviews: 0, founding: false, gallery: [], social: [], note: data.note || '' }, L, tr);
				}
			});
			root.addEventListener('keydown', function (e) {
				if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('.glh-res[data-i], tr[data-row]')) { e.preventDefault(); e.target.click(); }
			});
			function hl(text, toks) {
				var out = esc(text);
				toks.forEach(function (t) { if (t) { out = out.replace(new RegExp('(' + t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'), '<mark>$1</mark>'); } });
				return out;
			}
			function setDd() {
				var cur = active === null ? 'all' : String(active), c = active === null ? null : cats[active];
				opts.forEach(function (o) { o.setAttribute('aria-selected', String(o.dataset.id === cur)); });
				ddLabel.textContent = c ? c.n : ddLabel.getAttribute('data-all');
				ddBtn.querySelector('.glh-dd-dot').style.setProperty('--dot', c ? c.c : 'var(--glh-line)');
			}
			function setAct(i) {
				actI = Math.max(0, Math.min(opts.length - 1, i));
				opts.forEach(function (o, k) { o.classList.toggle('act', k === actI); });
				ddList.setAttribute('aria-activedescendant', opts[actI].id);
				opts[actI].scrollIntoView({ block: 'nearest' });
			}
			function openDd() {
				ddList.hidden = false; dd.dataset.open = 'true'; ddBtn.setAttribute('aria-expanded', 'true');
				var sel = opts.findIndex(function (o) { return o.getAttribute('aria-selected') === 'true'; });
				setAct(Math.max(0, sel)); ddList.focus({ preventScroll: true });
			}
			function closeDd(back) {
				ddList.hidden = true; dd.dataset.open = 'false'; ddBtn.setAttribute('aria-expanded', 'false');
				if (back) { ddBtn.focus({ preventScroll: true }); }
			}
			function choose(i) { var o = opts[i]; active = o.dataset.id === 'all' ? null : +o.dataset.id; closeDd(true); render(); }
			ddBtn.addEventListener('click', function () { if (ddList.hidden) { openDd(); } else { closeDd(true); } });
			ddBtn.addEventListener('keydown', function (e) { if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); openDd(); } });
			ddList.addEventListener('click', function (e) { var o = e.target.closest('.glh-dd-opt'); if (o) { choose(opts.indexOf(o)); } });
			ddList.addEventListener('pointermove', function (e) { var o = e.target.closest('.glh-dd-opt'); if (o) { var i = opts.indexOf(o); if (i !== actI) { setAct(i); } } });
			ddList.addEventListener('keydown', function (e) {
				if (e.key === 'ArrowDown') { e.preventDefault(); setAct(actI + 1); }
				else if (e.key === 'ArrowUp') { e.preventDefault(); setAct(actI - 1); }
				else if (e.key === 'Home') { e.preventDefault(); setAct(0); }
				else if (e.key === 'End') { e.preventDefault(); setAct(opts.length - 1); }
				else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(actI); }
				else if (e.key === 'Escape') { e.preventDefault(); closeDd(true); }
				else if (e.key === 'Tab') { closeDd(false); }
				else if (e.key.length === 1) {
					clearTimeout(typedT); typed += e.key.toLowerCase(); typedT = setTimeout(function () { typed = ''; }, 600);
					var i = opts.findIndex(function (o) { return o.textContent.toLowerCase().indexOf(typed) === 0; }); if (i > -1) { setAct(i); }
				}
			});
			d.addEventListener('pointerdown', function (e) { if (!ddList.hidden && !dd.contains(e.target)) { closeDd(false); } });
			function render() {
				var term = input.value.trim().toLowerCase(), toks = term.split(/\s+/).filter(Boolean);
				clr.hidden = !term;
				setDd();
				if (!toks.length && active === null) { recent.hidden = false; found.hidden = true; status.textContent = ''; return; }
				var hits = items.filter(function (l) { return (active === null || l.k === active) && toks.every(function (t) { return l.h.indexOf(t) > -1; }); });
				recent.hidden = true; found.hidden = false;
				if (!hits.length) {
					status.textContent = '0';
					found.innerHTML = '<div class="glh-none"><b>' + esc((data.none || [])[0] || '') + '</b>' + esc((data.none || [])[1] || '') + '</div>';
					return;
				}
				var by = {}; hits.forEach(function (l) { (by[l.k] = by[l.k] || []).push(l); });
				var html = '', groups = 0;
				cats.forEach(function (c, i) {
					var list = by[i]; if (!list) { return; }
					groups++;
					html += '<section class="glh-group"><h3><i style="background:' + esc(c.c) + '"></i>' + hl(c.n, toks) + '<span>' + list.length + '</span></h3>';
					list.forEach(function (l) {
						var name = (!data.profile && l.u) ? '<a href="' + esc(l.u) + '">' + hl(l.n, toks) + '</a>' : hl(l.n, toks);
						html += '<div class="glh-res"' + (data.profile ? ' role="button" tabindex="0" data-i="' + l.i + '" aria-label="' + esc(l.n) + '"' : '') + '><strong>' + name + '</strong><em>' + hl(l.s, toks) + '</em><em>' + esc(l.a || '') + '</em>' + (l.x && data.sample ? '<span class="glh-smp">' + esc(data.sample) + '</span>' : '<span></span>') + (data.profile ? '<span class="glh-go" aria-hidden="true">→</span>' : '<span></span>') + '</div>';
					});
					html += '</section>';
				});
				found.innerHTML = html;
				status.textContent = hits.length + ' / ' + groups;
			}
			input.addEventListener('input', render);
			clr.addEventListener('click', function () { input.value = ''; render(); input.focus(); });
			input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { input.value = ''; render(); } });
			root.querySelector('.glh-catgrid').addEventListener('click', function (e) {
				var b = e.target.closest('.glh-catcard'); if (!b) { return; }
				active = +b.dataset.id; render();
				root.querySelector('.glh-search').scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
			});
			render();
		});
	}

	/* ---------- Pared de videos infinita ---------- */
	function wrapP(v, P) { return v - P * Math.round(v / P); }

	function wall(r) {
		q('[data-glh="wall"]', r).forEach(function (root) {
			if (!once(root, 'wl') || root.hasAttribute('data-glh-static')) { return; }
			var cfg = json(root, 'data-glh-cfg'), stage = root.querySelector('.glh-wall__stage'), tiles = q('.glh-tile', stage);
			var ROWS = cfg.rows || 6, COLS = cfg.cols || 11, txt = cfg.txt || ['', '', 'Close'], cur = cfg.cur || ['Drag', 'GO!'];
			root.classList.add('is-on'); if (fine) { root.classList.add('fine'); } if (cfg.wheel) { root.classList.add('is-trap'); }
			var x = 0, y = 0, vx = 0, vy = 0, dvx = 0, dvy = 0, VW = 0, VH = 0, PITCH = 0, HS = 0, GAP = cfg.gap || 16;
			var cx = [], cy = [], tw = [], th = [], rowW = [], rowOf = [];
			var dragging = false, moved = false, sx = 0, sy = 0, lx = 0, ly = 0, lastAct = performance.now(), hoverTile = false, modalOpen = false;
			var ang = Math.random() * 6.28, tNext = 0, last = performance.now();
			tiles.forEach(function (t, i) { rowOf[i] = Math.floor(i / COLS); });

			function measure() {
				VW = root.clientWidth; VH = root.clientHeight;
				root.style.setProperty('--th', Math.round(VH * (cfg.tile || 36) / 100) + 'px');
				var rowH = 0, i, rr, c;
				for (i = 0; i < tiles.length; i++) { tw[i] = tiles[i].offsetWidth; th[i] = tiles[i].offsetHeight; if (th[i] > rowH) { rowH = th[i]; } }
				PITCH = rowH + GAP; HS = ROWS * PITCH;
				for (rr = 0; rr < ROWS; rr++) {
					var sum = 0; for (c = 0; c < COLS; c++) { sum += tw[rr * COLS + c] || 0; }
					rowW[rr] = sum + GAP * COLS;
					var left = -(sum + GAP * (COLS - 1)) / 2;
					for (c = 0; c < COLS; c++) { i = rr * COLS + c; cx[i] = left + (tw[i] || 0) / 2; left += (tw[i] || 0) + GAP; cy[i] = (rr - (ROWS - 1) / 2) * PITCH; }
				}
				apply();
			}
			function apply() {
				for (var i = 0; i < tiles.length; i++) {
					var px = wrapP(cx[i] + x, rowW[rowOf[i]]), py = wrapP(cy[i] + y, HS);
					var k = Math.min(1, Math.sqrt((px / VW) * (px / VW) + (py / VH) * (py / VH)) * 1.4), s = tiles[i].style;
					s.translate = (VW / 2 + px - tw[i] / 2).toFixed(1) + 'px ' + (VH / 2 + py - th[i] / 2).toFixed(1) + 'px';
					s.scale = (1 - 0.2 * k).toFixed(3); s.opacity = (1 - 0.5 * k).toFixed(3);
				}
			}
			function loop(now) {
				var f = Math.min(3, (now - last) / 16.67); last = now;
				var px = x, py = y;
				if (dragging) { lastAct = now; dvx = dvy = 0; }
				else {
					var idle = !reduce && cfg.drift && !modalOpen && !hoverTile && (now - lastAct > (cfg.idle || 0) * 1000), wx = 0, wy = 0;
					if (idle) {
						if (now > tNext) { ang += (Math.random() - 0.5) * 2.6; tNext = now + 3500 + Math.random() * 4500; }
						ang += (Math.random() - 0.5) * 0.03 * f;
						var sp = (cfg.driftSpeed || 36) / 60; wx = Math.cos(ang) * sp; wy = Math.sin(ang) * sp;
					}
					dvx += (wx - dvx) * 0.03 * f; dvy += (wy - dvy) * 0.03 * f;
					x += (vx + dvx) * f; y += (vy + dvy) * f;
					var dec = Math.pow(0.93, f); vx *= dec; vy *= dec;
				}
				if (x !== px || y !== py) { apply(); }
				if (d.body.contains(root)) { requestAnimationFrame(loop); }
			}
			function touched() { lastAct = performance.now(); }

			/* --- cursor propio --- */
			var cursor = null, ctext = null;
			if (fine) {
				cursor = d.createElement('div'); cursor.className = 'glh-cur'; cursor.setAttribute('aria-hidden', 'true'); cursor.innerHTML = '<div class="c"></div>';
				d.body.appendChild(cursor); ctext = cursor.firstChild;
			}
			var ARROWS = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v16M4 12h16"/><path d="m9 7 3-3 3 3M9 17l3 3 3-3M7 9l-3 3 3 3M17 9l3 3-3 3"/></svg>';
			function setCur(s) { if (!cursor) { return; } cursor.dataset.s = s; if (s === 'go') { ctext.textContent = cur[1]; } else { ctext.innerHTML = ARROWS; } }
			setCur('drag');
			function zone(e) { return e.target && e.target.closest && e.target.closest('.glh-tile') ? 'go' : 'drag'; }

			/* --- vista previa sin sonido --- */
			var pvI = null, pvT = 0;
			function stopPv() {
				clearTimeout(pvT); if (pvI === null) { return; }
				var t = tiles[pvI]; t.classList.remove('pv');
				var v = t.querySelector('video'); if (v) { v.pause(); v.remove(); }
				var l = t.querySelector('.glh-live'); if (l) { l.remove(); }
				pvI = null;
			}
			function startPv(i) {
				if (!cfg.preview || pvI === i) { return; }
				stopPv();
				var t = tiles[i], url = t.getAttribute('data-video');
				t.classList.add('pv');
				if (url) {
					var v = d.createElement('video'); v.muted = true; v.defaultMuted = true; v.playsInline = true; v.autoplay = true; v.src = url; t.appendChild(v);
					var pr = v.play(); if (pr && pr.catch) { pr.catch(function () { /* sin permiso */ }); }
				} else {
					var l = d.createElement('span'); l.className = 'glh-live'; l.style.setProperty('--pv', cfg.preview + 's'); l.innerHTML = '<span>' + esc(txt[0]) + '</span><i></i>'; t.appendChild(l);
				}
				pvI = i; pvT = setTimeout(stopPv, cfg.preview * 1000);
			}

			/* --- ventana emergente --- */
			var modal = null, opener = null;
			function closeModal() {
				if (!modal) { return; }
				var v = modal.querySelector('video'); if (v) { v.pause(); }
				modal.remove(); modal = null; modalOpen = false; stage.inert = false; touched();
				if (opener) { opener.focus({ preventScroll: true }); }
			}
			function openModal(i) {
				stopPv(); var t = tiles[i], url = t.getAttribute('data-video'), cat = t.getAttribute('data-cat'), orient = t.classList.contains('glh-tP') ? 'P' : 'L';
				opener = t; modalOpen = true; stage.inert = true; if (cursor) { cursor.classList.remove('on'); }
				modal = d.createElement('div'); modal.className = 'glh-modal'; modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-modal', 'true'); modal.setAttribute('aria-label', t.getAttribute('data-title') || 'Video');
				var media = url ? '<video src="' + esc(url) + '" controls playsinline autoplay></video>' : '<div class="glh-mph ' + esc(t.className.match(/glh-k\d/) ? t.className.match(/glh-k\d/)[0] : '') + '"><b>' + esc(t.querySelector('.glh-no') ? t.querySelector('.glh-no').textContent : '') + '</b><small>' + esc(txt[1]) + '</small></div>';
				modal.innerHTML = '<div class="glh-mbox ' + orient + '"><button type="button" class="glh-mclose" aria-label="' + esc(txt[2]) + '">&#10005;</button><div class="glh-mmedia ' + (url ? '' : (t.className.match(/glh-k\d/) || [''])[0]) + '">' + media + '</div><div class="glh-mmeta"><span>' + esc(t.getAttribute('data-title') || '') + '</span>' + (cat ? '<span class="t" style="background:' + esc(t.getAttribute('data-cc')) + '">' + esc(cat) + '</span>' : '') + '</div></div>';
				d.body.appendChild(modal);
				modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });
				modal.querySelector('.glh-mclose').addEventListener('click', closeModal);
				modal.querySelector('.glh-mclose').focus({ preventScroll: true });
				var v = modal.querySelector('video'); if (v) { var pr = v.play(); if (pr && pr.catch) { pr.catch(function () { /* el usuario pulsa play */ }); } }
			}
			d.addEventListener('keydown', function (e) {
				if (!d.body.contains(root)) { return; }
				if (e.key === 'Escape') { closeModal(); stopPv(); }
			});

			/* --- arrastre --- */
			stage.addEventListener('pointerdown', function (e) {
				if (e.button !== 0 || modalOpen) { return; }
				dragging = true; moved = false; sx = lx = e.clientX; sy = ly = e.clientY; vx = vy = 0; touched();
			});
			window.addEventListener('pointermove', function (e) {
				if (cursor && e.pointerType === 'mouse' && !modalOpen) {
					var inside = root.contains(e.target);
					cursor.classList.toggle('on', inside);
					if (inside) { cursor.style.transform = 'translate(' + e.clientX + 'px,' + e.clientY + 'px)'; if (!dragging || !moved) { var z = zone(e); if (cursor.dataset.s !== z) { setCur(z); } } }
				}
				if (!dragging) { return; }
				if (!moved && Math.hypot(e.clientX - sx, e.clientY - sy) > 6) { moved = true; stopPv(); setCur('grab'); }
				if (!moved) { return; }
				var dx = e.clientX - lx, dy = e.clientY - ly; lx = e.clientX; ly = e.clientY;
				x += dx; y += dy; vx = dx; vy = dy; apply();
			});
			function release(e) { if (!dragging) { return; } dragging = false; touched(); if (e && e.target) { setCur(zone(e)); } }
			window.addEventListener('pointerup', release); window.addEventListener('pointercancel', release);
			stage.addEventListener('click', function (e) {
				var t = e.target.closest('.glh-tile');
				if (!t || moved) { moved = false; return; }
				if (cfg.popup) { openModal(+t.getAttribute('data-n')); } else { startPv(+t.getAttribute('data-n')); }
			}, true);
			tiles.forEach(function (t) {
				t.addEventListener('pointerenter', function (e) { hoverTile = true; if (e.pointerType === 'mouse' && !dragging && !modalOpen) { startPv(+t.getAttribute('data-n')); } });
				t.addEventListener('pointerleave', function () { hoverTile = false; stopPv(); });
				t.addEventListener('focus', function () { if (!t.matches(':focus-visible')) { return; } var i = +t.getAttribute('data-n'); x = -cx[i]; y = -cy[i]; apply(); touched(); });
			});
			if (cfg.wheel) {
				stage.addEventListener('wheel', function (e) { e.preventDefault(); touched(); var h = e.shiftKey && !e.deltaX; x -= h ? e.deltaY : e.deltaX; y -= h ? 0 : e.deltaY; vx = vy = 0; apply(); }, { passive: false });
			}
			stage.addEventListener('scroll', function () { stage.scrollTop = 0; stage.scrollLeft = 0; });
			root.addEventListener('keydown', function (e) {
				if (modalOpen) { return; }
				var s = 15;
				if (e.key === 'ArrowLeft') { vx = s; } else if (e.key === 'ArrowRight') { vx = -s; } else if (e.key === 'ArrowUp') { vy = s; } else if (e.key === 'ArrowDown') { vy = -s; } else { return; }
				e.preventDefault(); touched();
			});
			var rz; window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(measure, 120); });
			measure(); tNext = performance.now() + 4000; requestAnimationFrame(loop);
		});
	}

	function init(r) {
		r = r || d;
		rise(r); header(r); business(r); stats(r); carousel(r); journey(r); parallax(r); directory(r); wall(r);
	}
	window.GLH = { init: init };
	if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', function () { init(); }); } else { init(); }
	d.addEventListener('vf:init', function (e) { init(e && e.target && e.target.querySelectorAll ? e.target : d); });
})();
