/* Editor de bloques de GoLehighAcres: lista de bloques, formulario de ajustes generado del esquema y vista previa en vivo. */
(function () {
	'use strict';
	var D = window.GLH_ED, root = document.getElementById('glh-editor');
	if (!D || !root) { return; }

	var S = { blocks: clone(D.blocks || []), on: !!D.on, canvas: !!D.canvas, sel: -1, tab: 'content', dirty: false, device: 'desktop' };
	var TABS = { content: 'Contenido', style: 'Estilo', wrap: 'Bloque' };
	var DEV = { desktop: ['🖥', 'Escritorio'], tablet: ['💻', 'Tablet'], mobile: ['📱', 'Celular'] };

	function clone(o) { return JSON.parse(JSON.stringify(o)); }
	function uid() { return Math.random().toString(36).slice(2, 10); }
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (k === 'text') { e.textContent = v; }
			else if (k.slice(0, 2) === 'on') { e.addEventListener(k.slice(2), v); }
			else if (v !== false && v != null) { e.setAttribute(k, v === true ? '' : v); }
		});
		(kids || []).forEach(function (c) { if (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return e;
	}
	function def(type) { return D.schema[type] || { title: type, icon: '▫️', controls: [] }; }
	function getv(s, c) { return Object.prototype.hasOwnProperty.call(s, c.id) ? s[c.id] : (c.default !== undefined ? clone(c.default) : ''); }

	/* ---------- Estado ---------- */

	var timer = 0;
	function changed(soft) {
		S.dirty = true;
		status('Cambios sin guardar', 'warn');
		clearTimeout(timer);
		timer = setTimeout(preview, soft ? 700 : 250);
		if (!soft) { draw(); }
	}
	window.addEventListener('beforeunload', function (e) { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });

	/* ---------- Vista previa: el borrador se envía por POST a la propia página, sin guardarlo ---------- */

	var frame = el('iframe', { name: 'glh-preview', class: 'glh-ed__frame', title: 'Vista previa' });
	var form = el('form', { method: 'post', action: D.link, target: 'glh-preview', class: 'glh-ed__pf' });
	['glh_preview', 'post_id', '_glh', 'blocks', 'canvas'].forEach(function (n) { form.appendChild(el('input', { type: 'hidden', name: n })); });
	var scrollY = 0;
	frame.addEventListener('load', function () {
		try { frame.contentWindow.scrollTo(0, scrollY); } catch (e) { /* otra dirección */ }
	});
	function preview() {
		try {
			scrollY = frame.contentWindow.scrollY || 0;
			frame.contentWindow.name = 'glh-preview'; // la vista previa de WordPress renombra la ventana («wp-preview-ID»)
		} catch (e) { scrollY = 0; }
		form.elements.glh_preview.value = '1';
		form.elements.post_id.value = D.post;
		form.elements._glh.value = D.nonce;
		form.elements.blocks.value = JSON.stringify(S.blocks);
		form.elements.canvas.value = S.canvas ? '1' : '';
		form.submit();
	}

	/* ---------- Guardar ---------- */

	var stEl;
	function status(t, k) { if (stEl) { stEl.textContent = t; stEl.className = 'glh-ed__st is-' + (k || 'ok'); } }
	function save(publish) {
		status('Guardando…', 'warn');
		var body = new URLSearchParams();
		body.set('action', 'glh_save'); body.set('post_id', D.post); body.set('_glh', D.nonce);
		body.set('blocks', JSON.stringify(S.blocks)); body.set('on', S.on ? '1' : ''); body.set('canvas', S.canvas ? '1' : '');
		if (publish) { body.set('publish', '1'); }
		fetch(D.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r || !r.success) { throw new Error(r && r.data && r.data.msg ? r.data.msg : 'Error al guardar'); }
			S.blocks = r.data.blocks; S.dirty = false; D.status = r.data.status;
			draw();
			status(D.status === 'publish' ? 'Guardado y publicado' : 'Guardado (borrador)', 'ok');
		}).catch(function (e) { status(e.message || 'Error al guardar', 'err'); });
	}

	/* ---------- Campos ---------- */

	function field(c, s, onch) {
		var v = getv(s, c), w = el('div', { class: 'glh-f glh-f--' + c.type });
		var set = function (nv, soft) { s[c.id] = nv; onch(soft); };
		var lab = el('label', { class: 'glh-f__l', text: c.label });
		if (c.responsive) {
			var row = el('div', { class: 'glh-f__resp' });
			v = v && typeof v === 'object' && !Array.isArray(v) ? v : (v !== '' ? { desktop: v } : {});
			Object.keys(DEV).forEach(function (d) {
				var inp = el('input', { type: 'number', min: c.min, max: c.max, step: 'any', value: v[d] != null ? v[d] : '', placeholder: d === 'desktop' && c.default != null && typeof c.default !== 'object' ? c.default : '', title: DEV[d][1] });
				inp.addEventListener('input', function () {
					var o = clone(v); if (inp.value === '') { delete o[d]; } else { o[d] = inp.value; } v = o; set(o, true);
				});
				row.appendChild(el('span', { class: 'glh-f__dev' }, [DEV[d][0], inp]));
			});
			w.appendChild(lab); w.appendChild(row); return w;
		}
		switch (c.type) {
			case 'textarea': {
				var ta = el('textarea', { rows: c.css_only ? 6 : 3, spellcheck: c.css_only ? 'false' : 'true' });
				ta.value = v || '';
				ta.addEventListener('input', function () { set(ta.value, true); });
				w.appendChild(lab); w.appendChild(ta); break;
			}
			case 'number': {
				var n = el('input', { type: 'number', min: c.min, max: c.max, step: 'any', value: v });
				n.addEventListener('input', function () { set(n.value, true); });
				w.appendChild(lab); w.appendChild(n); break;
			}
			case 'select': {
				var sel = el('select');
				Object.keys(c.options || {}).forEach(function (k) { sel.appendChild(el('option', { value: k, selected: String(v) === k, text: c.options[k] })); });
				sel.addEventListener('change', function () { set(sel.value); });
				w.appendChild(lab); w.appendChild(sel); break;
			}
			case 'switch': {
				var cb = el('input', { type: 'checkbox', checked: !!(+v) });
				cb.addEventListener('change', function () { set(cb.checked ? 1 : 0); });
				w.appendChild(el('label', { class: 'glh-f__sw' }, [cb, el('span', { text: c.label })])); break;
			}
			case 'color': {
				var pick = el('input', { type: 'color', value: /^#[0-9a-f]{6}$/i.test(v) ? v : '#ffffff' });
				var txt = el('input', { type: 'text', value: v || '', placeholder: 'Predeterminado (#hex, rgba(), transparent)' });
				var clr = el('button', { type: 'button', class: 'button-link', text: 'Quitar', onclick: function () { txt.value = ''; set(''); } });
				pick.addEventListener('input', function () { txt.value = pick.value; set(pick.value, true); });
				txt.addEventListener('input', function () { if (/^#[0-9a-f]{6}$/i.test(txt.value)) { pick.value = txt.value; } set(txt.value.trim(), true); });
				w.appendChild(lab); w.appendChild(el('div', { class: 'glh-f__color' }, [pick, txt, clr])); break;
			}
			case 'image': {
				v = v && typeof v === 'object' ? v : { id: 0, url: '' };
				var th = el('div', { class: 'glh-f__thumb' });
				var url = el('input', { type: 'url', value: v.url || '', placeholder: 'https://… (o elige de la biblioteca)' });
				var paint = function () { th.innerHTML = ''; if (v.url) { th.appendChild(el('img', { src: v.url, alt: '' })); } else { th.textContent = 'Sin imagen'; } };
				var put = function (o) { v = o; url.value = o.url || ''; paint(); set(o); };
				url.addEventListener('change', function () { put({ id: 0, url: url.value.trim() }); });
				var choose = el('button', { type: 'button', class: 'button', text: 'Elegir imagen', onclick: function () {
					if (!window.wp || !wp.media) { return; }
					var f = wp.media({ title: c.label, library: { type: 'image' }, multiple: false, button: { text: 'Usar esta imagen' } });
					f.on('select', function () { var a = f.state().get('selection').first().toJSON(); put({ id: a.id, url: (a.sizes && a.sizes.large ? a.sizes.large.url : a.url) }); });
					f.open();
				} });
				var rm = el('button', { type: 'button', class: 'button-link', text: 'Quitar', onclick: function () { put({ id: 0, url: '' }); } });
				paint();
				w.appendChild(lab); w.appendChild(el('div', { class: 'glh-f__img' }, [th, el('div', {}, [choose, ' ', rm, url])])); break;
			}
			case 'url': {
				var u = el('input', { type: 'text', value: v || '', placeholder: 'https://…, #seccion, tel:, mailto:' });
				u.addEventListener('input', function () { set(u.value.trim(), true); });
				w.appendChild(lab); w.appendChild(u);
				if (/video/i.test(c.label) && window.wp && wp.media) {
					w.appendChild(el('button', { type: 'button', class: 'button', text: 'Elegir video', onclick: function () {
						var f = wp.media({ title: c.label, library: { type: 'video' }, multiple: false });
						f.on('select', function () { u.value = f.state().get('selection').first().toJSON().url; set(u.value); });
						f.open();
					} }));
				}
				break;
			}
			case 'repeater': {
				var items = Array.isArray(v) ? v : [];
				var list = el('div', { class: 'glh-rep' });
				var commit = function (soft) { set(items, soft); };
				items.forEach(function (it, i) {
					var tf = c.title_field && it[c.title_field] ? String(it[c.title_field]) : '';
					var body = el('div', { class: 'glh-rep__body' });
					(c.fields || []).forEach(function (f) { body.appendChild(field(f, it, function (soft) { commit(soft); })); });
					var mv = function (dlt) { return function (e) { e.preventDefault(); var j = i + dlt; if (j < 0 || j >= items.length) { return; } var t = items[i]; items[i] = items[j]; items[j] = t; commit(); }; };
					list.appendChild(el('details', { class: 'glh-rep__it' }, [
						el('summary', {}, [el('span', { text: (i + 1) + '. ' + (tf || 'Elemento') }),
							el('span', { class: 'glh-rep__tools' }, [
								el('button', { type: 'button', title: 'Subir', text: '↑', onclick: mv(-1) }),
								el('button', { type: 'button', title: 'Bajar', text: '↓', onclick: mv(1) }),
								el('button', { type: 'button', title: 'Duplicar', text: '⧉', onclick: function (e) { e.preventDefault(); items.splice(i + 1, 0, clone(it)); commit(); } }),
								el('button', { type: 'button', title: 'Quitar', text: '✕', onclick: function (e) { e.preventDefault(); items.splice(i, 1); commit(); } })
							])]),
						body
					]));
				});
				var max = c.max || 30;
				w.appendChild(lab); w.appendChild(list);
				w.appendChild(el('button', { type: 'button', class: 'button', disabled: items.length >= max, text: '+ Añadir', onclick: function () { items.push({}); commit(); } }));
				break;
			}
			default: {
				var t = el('input', { type: 'text', value: v || '' });
				t.addEventListener('input', function () { set(t.value, true); });
				w.appendChild(lab); w.appendChild(t);
			}
		}
		return w;
	}

	/* ---------- Panel ---------- */

	var side = el('div', { class: 'glh-ed__side' });
	var main = el('div', { class: 'glh-ed__main' }, [el('div', { class: 'glh-ed__view is-desktop' }, [frame])]);

	function toolbar() {
		stEl = el('span', { class: 'glh-ed__st' });
		var devs = el('span', { class: 'glh-ed__devs' });
		Object.keys(DEV).forEach(function (d) {
			devs.appendChild(el('button', { type: 'button', class: S.device === d ? 'is-on' : '', title: DEV[d][1], text: DEV[d][0], onclick: function () {
				S.device = d; main.firstChild.className = 'glh-ed__view is-' + d; draw();
			} }));
		});
		return el('div', { class: 'glh-ed__bar' }, [
			el('a', { href: D.back, class: 'glh-ed__back', text: '← GoLehighAcres' }),
			el('b', { class: 'glh-ed__title', text: (D.title || '(sin título)') + ' · v' + D.ver }),
			devs, stEl,
			el('a', { href: D.link, target: '_blank', class: 'button', text: 'Ver página' }),
			el('button', { type: 'button', class: 'button', text: 'Guardar', onclick: function () { save(false); } }),
			D.status !== 'publish' ? el('button', { type: 'button', class: 'button button-primary', text: 'Publicar', onclick: function () { save(true); } }) : el('button', { type: 'button', class: 'button button-primary', text: 'Actualizar', onclick: function () { save(false); } })
		]);
	}

	function listView() {
		var box = el('div', { class: 'glh-ed__list' });
		box.appendChild(el('label', { class: 'glh-f__sw' }, [el('input', { type: 'checkbox', checked: S.on, onchange: function (e) { S.on = e.target.checked; changed(); } }), el('span', { text: 'Mostrar esta página con los bloques' })]));
		box.appendChild(el('label', { class: 'glh-f__sw' }, [el('input', { type: 'checkbox', checked: S.canvas, onchange: function (e) { S.canvas = e.target.checked; changed(); } }), el('span', { text: 'Pantalla completa (sin la cabecera ni el pie del tema)' })]));
		var ul = el('ol', { class: 'glh-ed__blocks' });
		if (!S.blocks.length) { ul.appendChild(el('li', { class: 'glh-ed__empty', text: 'Página vacía: añade un bloque o carga una plantilla.' })); }
		S.blocks.forEach(function (b, i) {
			var d = def(b.type), mv = function (dl) { return function (e) { e.stopPropagation(); var j = i + dl; if (j < 0 || j >= S.blocks.length) { return; } var t = S.blocks[i]; S.blocks[i] = S.blocks[j]; S.blocks[j] = t; changed(); }; };
			var tint = b.settings && b.settings.w_tint ? ' ' + b.settings.w_tint : '';
			ul.appendChild(el('li', { class: 'glh-ed__b' + tint, onclick: function () { S.sel = i; S.tab = 'content'; draw(); } }, [
				el('span', { class: 'glh-ed__bi', text: d.icon }), el('span', { class: 'glh-ed__bt', text: d.title }),
				el('span', { class: 'glh-ed__tools' }, [
					el('button', { type: 'button', title: 'Subir', text: '↑', onclick: mv(-1) }),
					el('button', { type: 'button', title: 'Bajar', text: '↓', onclick: mv(1) }),
					el('button', { type: 'button', title: 'Duplicar', text: '⧉', onclick: function (e) { e.stopPropagation(); var c = clone(b); c.id = uid(); S.blocks.splice(i + 1, 0, c); changed(); } }),
					el('button', { type: 'button', title: 'Eliminar', text: '✕', onclick: function (e) { e.stopPropagation(); if (confirm('¿Eliminar el bloque «' + d.title + '»?')) { S.blocks.splice(i, 1); changed(); } } })
				])
			]));
		});
		box.appendChild(ul);
		var add = el('select', { class: 'glh-ed__add' }, [el('option', { value: '', text: '+ Añadir bloque…' })]);
		Object.keys(D.schema).forEach(function (k) { add.appendChild(el('option', { value: k, text: D.schema[k].icon + ' ' + D.schema[k].title })); });
		add.addEventListener('change', function () { if (!add.value) { return; } S.blocks.push({ id: uid(), type: add.value, settings: {} }); S.sel = S.blocks.length - 1; S.tab = 'content'; changed(); });
		var tpl = el('select', { class: 'glh-ed__add' }, [el('option', { value: '', text: '📄 Cargar una plantilla…' })]);
		Object.keys(D.pages).forEach(function (k) { tpl.appendChild(el('option', { value: k, text: D.pages[k].icon + ' ' + D.pages[k].title })); });
		tpl.addEventListener('change', function () {
			var p = D.pages[tpl.value]; if (!p) { return; }
			var add = clone(p.blocks).map(function (b) { b.id = uid(); return b; });
			if (S.blocks.length && confirm('¿Reemplazar los bloques actuales por la plantilla?\nAceptar = reemplazar · Cancelar = añadirla al final')) { S.blocks = add; } else { S.blocks = S.blocks.concat(add); }
			changed();
		});
		box.appendChild(add); box.appendChild(tpl);
		box.appendChild(el('p', { class: 'glh-ed__hint' }, ['Insertar en otra página: ', el('code', { text: '[glh_page id="' + D.post + '"]' })]));
		if (D.wpedit) { box.appendChild(el('p', { class: 'glh-ed__hint' }, [el('a', { href: D.wpedit, text: 'Título, dirección y SEO de la página (editor de WordPress)' })])); }
		return box;
	}

	function blockView() {
		var b = S.blocks[S.sel], d = def(b.type);
		b.settings = b.settings && !Array.isArray(b.settings) ? b.settings : {};
		var box = el('div', { class: 'glh-ed__form' });
		box.appendChild(el('div', { class: 'glh-ed__fh' }, [
			el('button', { type: 'button', class: 'button-link', text: '← Bloques', onclick: function () { S.sel = -1; draw(); } }),
			el('b', { text: d.icon + ' ' + d.title })
		]));
		var tabs = el('div', { class: 'glh-ed__tabs' });
		Object.keys(TABS).forEach(function (t) {
			if (!d.controls.some(function (c) { return c.tab === t; })) { return; }
			tabs.appendChild(el('button', { type: 'button', class: S.tab === t ? 'is-on' : '', text: TABS[t], onclick: function () { S.tab = t; draw(); } }));
		});
		box.appendChild(tabs);
		var groups = {};
		d.controls.filter(function (c) { return c.tab === S.tab; }).forEach(function (c) {
			var g = c.group || 'General';
			if (!groups[g]) { groups[g] = el('details', { class: 'glh-ed__g', open: Object.keys(groups).length === 0 }, [el('summary', { text: g })]); box.appendChild(groups[g]); }
			groups[g].appendChild(field(c, b.settings, function (soft) { changed(soft); }));
		});
		box.appendChild(el('button', { type: 'button', class: 'button-link glh-ed__reset', text: 'Restablecer este bloque a sus valores por defecto', onclick: function () {
			if (confirm('¿Borrar todos los cambios de este bloque?')) { b.settings = {}; changed(); }
		} }));
		return box;
	}

	var openState = {}, lastKey = '';
	function draw() {
		var key = S.sel + ':' + S.tab, same = key === lastKey;
		var top = same ? side.scrollTop : 0;
		if (same) { side.querySelectorAll('details').forEach(function (x, i) { openState[i] = x.open; }); } else { openState = {}; }
		lastKey = key;
		side.innerHTML = '';
		side.appendChild(S.sel >= 0 && S.blocks[S.sel] ? blockView() : listView());
		side.querySelectorAll('details').forEach(function (x, i) { if (openState[i] !== undefined) { x.open = openState[i]; } });
		side.scrollTop = top;
		var nb = toolbar(); bar.replaceWith(nb); bar = nb;
		status(S.dirty ? 'Cambios sin guardar' : (D.status === 'publish' ? 'Publicada' : 'Borrador'), S.dirty ? 'warn' : 'ok');
	}

	var bar = el('div');
	root.innerHTML = '';
	root.appendChild(bar);
	root.appendChild(el('div', { class: 'glh-ed__body' }, [side, main]));
	root.appendChild(form);
	draw();
	preview();
})();
