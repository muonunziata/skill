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

	/* ---------- Directorio: buscador, filtros y secciones ---------- */
	function directory(r) {
		q('[data-glh="directory"]', r).forEach(function (root) {
			if (!once(root, 'dr')) { return; }
			var data = json(root, 'data-glh-data'), cats = data.cats || [], items = data.items || [];
			var input = root.querySelector('.glh-search input'), clr = root.querySelector('.glh-clear');
			var dd = root.querySelector('.glh-dd'), ddBtn = root.querySelector('.glh-dd-btn'), ddList = root.querySelector('.glh-dd-list'), ddLabel = root.querySelector('.glh-dd-label'), opts = q('.glh-dd-opt', ddList), actI = 0, typed = '', typedT = 0;
			var status = root.querySelector('.glh-status'), recent = root.querySelector('.glh-recent'), found = root.querySelector('.glh-found');
			var active = null;
			items.forEach(function (l) { l.h = ((cats[l.k] ? cats[l.k].n : '') + ' ' + l.s + ' ' + l.n).toLowerCase(); });
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
						var name = l.u ? '<a href="' + esc(l.u) + '">' + hl(l.n, toks) + '</a>' : hl(l.n, toks);
						html += '<div class="glh-res"><strong>' + name + '</strong><em>' + hl(l.s, toks) + '</em><em>' + esc(l.a || '') + '</em>' + (l.x && data.sample ? '<span class="glh-smp">' + esc(data.sample) + '</span>' : '<span></span>') + '</div>';
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
		rise(r); header(r); stats(r); carousel(r); journey(r); parallax(r); directory(r); wall(r);
	}
	window.GLH = { init: init };
	if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', function () { init(); }); } else { init(); }
	d.addEventListener('vf:init', function (e) { init(e && e.target && e.target.querySelectorAll ? e.target : d); });
})();
