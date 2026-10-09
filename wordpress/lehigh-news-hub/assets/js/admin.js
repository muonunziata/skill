/* Lehigh News Hub – admin behaviour. No dependencies. */
(function () {
	'use strict';
	var L = window.LNH || { i18n: {} };

	function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
	function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

	/* ---------- copy buttons ---------- */
	function copy(text, btn) {
		var done = function () {
			if (!btn) { return; }
			var old = btn.textContent;
			btn.textContent = L.i18n.copied || 'Copied!';
			setTimeout(function () { btn.textContent = old; }, 1500);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, done);
		} else {
			var ta = document.createElement('textarea');
			ta.value = text; document.body.appendChild(ta); ta.select();
			try { document.execCommand('copy'); } catch (e) { /* ignore */ }
			document.body.removeChild(ta); done();
		}
	}
	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-lnh-copy],[data-lnh-copy-target],[data-lnh-copy-text]');
		if (!b) { return; }
		var text = b.getAttribute('data-lnh-copy-text');
		if (text == null) {
			var target = b.getAttribute('data-lnh-copy-target');
			var el = target ? qs(target) : b.parentNode.querySelector('pre');
			text = el ? el.textContent : '';
		}
		copy(text, b);
	});

	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-lnh-download]');
		if (!b) { return; }
		var el = qs(b.getAttribute('data-lnh-download'));
		if (!el) { return; }
		var blob = new Blob([el.textContent], { type: 'text/plain' });
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = b.getAttribute('data-filename') || 'download.txt';
		document.body.appendChild(a); a.click(); document.body.removeChild(a);
		setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
	});

	/* ---------- confirmations ---------- */
	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-confirm]');
		if (!b) { return; }
		var msg = b.getAttribute('data-confirm') === 'delete' ? L.i18n.confirmDelete : L.i18n.confirmReject;
		if (msg && !window.confirm(msg)) { e.preventDefault(); }
	});
	qsa('[data-lnh-bulk]').forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			var sel = btn.form.querySelector('[name=bulk_action]');
			var opt = sel.options[sel.selectedIndex];
			if (!sel.value) { e.preventDefault(); return; }
			var c = opt.getAttribute('data-confirm');
			if (c && !window.confirm(c === 'delete' ? L.i18n.confirmDelete : L.i18n.confirmReject)) { e.preventDefault(); }
		});
	});

	/* ---------- select all ---------- */
	var all = qs('[data-lnh-all]');
	if (all) {
		all.addEventListener('change', function () {
			qsa('input[name="post[]"]', all.form).forEach(function (c) { c.checked = all.checked; });
		});
	}

	/* ---------- start / pause control: keep the status card live ---------- */
	var ctl = qs('[data-lnh-control]');
	if (ctl && L.controlUrl) {
		var refreshControl = function () {
			if (document.hidden) { return; }
			fetch(L.controlUrl, { headers: { 'X-WP-Nonce': L.restNonce }, credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (d) {
					if (!d) { return; }
					var connected = d.worker && d.worker.connected ? '1' : '0';
					// Buttons and the help block depend on these: re-render the page instead of patching them.
					var upd = d.agents ? (d.agents.pending ? 'pending' : (connected === '1' && d.agents.outdated ? (d.agents.can_update ? 'available' : 'old') : 'none')) : 'none';
					if (d.state !== ctl.getAttribute('data-state') || connected !== ctl.getAttribute('data-connected') || upd !== ctl.getAttribute('data-update')) { window.location.reload(); return; }
					ctl.setAttribute('data-phase', d.phase);
					ctl.className = ctl.className.replace(/lnh-control--\w+/, 'lnh-control--' + d.phase);
					var ph = qs('[data-lnh-control-phase]', ctl), dt = qs('[data-lnh-control-detail]', ctl), wk = qs('[data-lnh-control-worker]', ctl);
					if (ph) { ph.textContent = d.label; }
					// working animation: highlight the busy agent, tick the ones already done, flow packets into the busy one
					var order = ['rastreador', 'redactor', 'auditor', 'social'];
					var act = (d.phase === 'working' || d.phase === 'pausing') ? order.indexOf(d.worker.agent || '') : -1;
					ctl.setAttribute('data-active', act);
					qsa('.lnh-node', ctl).forEach(function (n) {
						var i = +n.getAttribute('data-i');
						n.classList.toggle('is-active', i === act);
						n.classList.toggle('is-done', act > -1 && i < act);
					});
					qsa('.lnh-anim__link', ctl).forEach(function (l) {
						var i = +l.getAttribute('data-i');
						l.classList.toggle('is-flow', i === act);
						l.classList.toggle('is-done', act > -1 && i < act);
					});
					var detail = d.phase === 'working' ? (d.worker.message || '') : (d.phase === 'waiting' && d.next_in ? L.i18n.nextIn.replace('%s', d.next_in) : '');
					if (dt) { dt.textContent = detail ? '· ' + detail : ''; }
					if (wk && d.worker && d.worker.connected) { wk.textContent = L.i18n.connectedAgo.replace('%s', d.worker.seen_ago) + (d.worker.host ? ' · ' + d.worker.host : ''); }
				}).catch(function () { /* offline: keep what is shown */ });
		};
		setInterval(refreshControl, 8000);
	}

	/* ---------- live feed ---------- */
	var feed = qs('[data-lnh-feed]');
	if (feed) {
		var agentSel = qs('[data-lnh-feed-agent]');
		var liveBox = qs('[data-lnh-live]');
		// Agent names, icons and event labels come from PHP so the refreshed feed stays translated like the first render.
		var agents = L.agents || {};
		var labelOf = function (a) { return (agents[a] && agents[a].label) || a; };
		var iconOf = function (a) { return (agents[a] && agents[a].icon) || ''; };
		var typeLabel = function (t) { return (L.events && L.events[t]) || String(t).replace(/_/g, ' '); };
		var ago = function (ts) {
			var s = Math.max(0, Math.round(Date.now() / 1000 - ts));
			if (s < 60) { return s + 's'; } if (s < 3600) { return Math.round(s / 60) + 'm'; }
			if (s < 86400) { return Math.round(s / 3600) + 'h'; } return Math.round(s / 86400) + 'd';
		};
		var agoText = function (ts) { return (L.i18n.ago || '%s ago').replace('%s', ago(ts)); };
		var render = function (events) {
			if (!events.length) { feed.innerHTML = '<li class="lnh-empty">' + esc(L.i18n.noEvents) + '</li>'; return; }
			feed.innerHTML = events.map(function (ev) {
				return '<li class="lnh-tl lnh-tl--' + esc(ev.level) + '" data-agent="' + esc(ev.agent) + '" data-ts="' + esc(ev.ts) + '">' +
					'<span class="lnh-tl__ico" title="' + esc(labelOf(ev.agent)) + '">' + esc(iconOf(ev.agent)) + '</span>' +
					'<div><span class="lnh-tl__type">' + esc(typeLabel(ev.type)) + '</span> <span class="lnh-tl__msg">' + esc(ev.message) + '</span>' +
					' <span class="lnh-muted lnh-tl__time">' + esc(agoText(ev.ts)) + '</span></div></li>';
			}).join('');
		};
		var poll = function () {
			if (liveBox && !liveBox.checked) { return; }
			var url = L.feedUrl + (L.feedUrl.indexOf('?') > -1 ? '&' : '?') + 'limit=60&agent=' + encodeURIComponent(agentSel ? agentSel.value : '');
			fetch(url, { headers: { 'X-WP-Nonce': L.restNonce }, credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
				.then(render).catch(function () { /* keep the last render */ });
		};
		if (agentSel) { agentSel.addEventListener('change', poll); }
		setInterval(poll, 15000);
	}

	/* ---------- shortcode builder ---------- */
	var form = qs('#lnh-builder-form');
	if (form) {
		var out = qs('[data-lnh-shortcode]');
		var preview = qs('[data-lnh-preview]');
		var state = qs('[data-lnh-preview-state]');
		var timer = null, seq = 0;
		var refresh = function () {
			var data = new FormData(form);
			data.set('action', 'lnh_preview');
			data.set('nonce', L.nonce);
			data.delete('name'); data.delete('op'); data.delete('_wpnonce');
			// multi-select categories arrive as repeated a_category[] values
			var cats = data.getAll('a_category[]');
			data.delete('a_category[]');
			if (cats.length) { data.set('a_category', cats.join(',')); }
			var my = ++seq;
			if (state) { state.textContent = '…'; }
			fetch(L.ajax, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (my !== seq) { return; }
					if (!res || !res.success) { throw new Error('fail'); }
					preview.innerHTML = res.data.html;
					out.textContent = res.data.shortcode;
					if (state) { state.textContent = ''; }
				})
				.catch(function () { if (state) { state.textContent = L.i18n.previewFail; } });
		};
		var schedule = function () { clearTimeout(timer); timer = setTimeout(refresh, 250); };
		form.addEventListener('input', schedule);
		form.addEventListener('change', schedule);
		// preset save: send the multi-select as a CSV too
		form.addEventListener('submit', function () {
			var sel = form.querySelector('select[name="a_category[]"]');
			if (sel) {
				var vals = Array.prototype.filter.call(sel.options, function (o) { return o.selected; }).map(function (o) { return o.value; });
				var h = document.createElement('input'); h.type = 'hidden'; h.name = 'a_category'; h.value = vals.join(',');
				form.appendChild(h);
			}
		});
		refresh();
	}
})();
