// Prueba de punta a punta en un WordPress real: node tests/wp-e2e.mjs http://127.0.0.1:8890 admin contraseña
import { chromium } from 'playwright';
const [base, user, pass] = process.argv.slice(2);
const out = process.env.SHOTS || '.';
const b = await chromium.launch();
const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
const errs = [];
p.on('pageerror', e => errs.push('pageerror: ' + e.message));
p.on('console', m => { if (m.type() === 'error' && !/ERR_NAME_NOT_RESOLVED|ERR_TUNNEL|ERR_CONNECTION/.test(m.text())) errs.push('console: ' + m.text()); });
let fail = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fail++; };

await p.goto(base + '/wp-login.php');
await p.fill('#user_login', user); await p.fill('#user_pass', pass);
await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);

// 1. Activar con el módulo antiguo activo: debe desactivarlo.
await p.goto(base + '/wp-admin/plugins.php');
const act = async slug => {
	const a = p.locator(`tr[data-plugin="${slug}"] .activate a`);
	if (await a.count()) { await Promise.all([p.waitForNavigation(), a.click()]); }
};
await act('veloxforge-golehighacres/veloxforge-golehighacres.php');
await act('golehighacres-blocks/golehighacres-blocks.php');
await p.goto(base + '/wp-admin/plugins.php');
ok(await p.locator('tr[data-plugin="golehighacres-blocks/golehighacres-blocks.php"].active').count() === 1, 'GoLehighAcres Blocks activo');
ok(await p.locator('tr[data-plugin="veloxforge-golehighacres/veloxforge-golehighacres.php"].active').count() === 0, 'módulo antiguo de VeloxForge desactivado al activar');

// 2. Crear una página desde la plantilla de inicio.
await p.goto(base + '/wp-admin/admin.php?page=glh');
ok(await p.locator('text=GoLehighAcres · v1.2.0').count() > 0, 'menú GoLehighAcres con versión 1.2.0');
await p.fill('.glh-new input[name=title]', 'Explore');
await p.selectOption('.glh-new select[name=tpl]', 'page-home');
await Promise.all([p.waitForNavigation(), p.click('.glh-new button')]);
await p.waitForSelector('.glh-ed__b');
ok(await p.locator('.glh-ed__b').count() === 9, 'editor abierto con 9 bloques de la plantilla');
const frame = p.frameLocator('iframe[name=glh-preview]');
await frame.locator('.glh-b-glh-hero').waitFor({ timeout: 15000 });
ok(true, 'vista previa muestra la portada');
await p.screenshot({ path: out + '/ed-list.png' });

// 3. Editar el titular de la portada y ver el cambio en la vista previa sin guardar.
await p.locator('.glh-ed__b', { hasText: 'Portada' }).click();
const ta = p.locator('.glh-f', { hasText: 'Titular' }).locator('textarea');
await ta.fill('Hola Lehigh Acres');
await p.waitForTimeout(1500);
await frame.locator('.glh-hero__title', { hasText: 'Hola Lehigh Acres' }).waitFor({ timeout: 15000 });
ok(true, 'la vista previa refleja el titular sin guardar');
// Color y número responsive.
await p.click('.glh-ed__tabs button:has-text("Estilo")');
await p.click('summary:has-text("Tipografía")');
await p.locator('.glh-f', { hasText: 'Tamaño del titular' }).locator('input').nth(2).fill('30');
await p.click('.glh-ed__tabs button:has-text("Bloque")');
await p.locator('.glh-f', { hasText: 'Capa de color' }).locator('select').selectOption('glh-tint-6');
await p.screenshot({ path: out + '/ed-form.png' });

// 4. Repetidor: añadir una cifra en «Ciudad».
await p.click('text=← Bloques');
await p.locator('.glh-ed__b', { hasText: 'cifras' }).first().click();
await p.click('summary:has-text("Cifras")');
const rep = p.locator('.glh-f--repeater').first();
const before = await rep.locator('.glh-rep__it').count();
await rep.locator('button:has-text("+ Añadir")').click();
ok(await p.locator('.glh-f--repeater').first().locator('.glh-rep__it').count() === before + 1, `repetidor: ${before} → ${before + 1} elementos`);
await p.click('text=← Bloques');

// 5. Guardar y publicar.
await p.click('.glh-ed__bar button:has-text("Publicar")');
await p.locator('.glh-ed__st', { hasText: 'publicado' }).waitFor({ timeout: 15000 });
ok(true, 'guardado y publicado');
const link = await p.locator('.glh-ed__bar a:has-text("Ver página")').getAttribute('href');

// 6. Página publicada, a pantalla completa, sin VeloxForge.
for (const [w, h, n] of [[1440, 900, 'desk'], [390, 844, 'mob']]) {
	const v = await b.newPage({ viewport: { width: w, height: h } });
	v.on('pageerror', e => errs.push('front pageerror: ' + e.message));
	await v.goto(link); await v.waitForTimeout(1500);
	const r = await v.evaluate(() => ({
		blocks: document.querySelectorAll('section.glh-block').length,
		title: document.querySelector('.glh-hero__title')?.textContent,
		canvas: document.body.classList.contains('glh-canvas'),
		css: !!document.querySelector('link#glh-css'), js: !!document.querySelector('script#glh-js'),
		video: document.querySelector('.glh-bgv video source')?.getAttribute('src') || '',
		tint: document.querySelector('.glh-b-glh-hero').classList.contains('glh-tint-6'),
		fs: getComputedStyle(document.querySelector('.glh-hero__title')).fontSize,
		scrollX: document.documentElement.scrollWidth - innerWidth,
		vf: /vfb-|veloxforge/i.test(document.documentElement.outerHTML.replace(/golehighacres-blocks/g, '')),
	}));
	ok(r.blocks === 9 && r.title === 'Hola Lehigh Acres' && r.canvas && r.css && r.js, `${n}: 9 bloques, titular guardado, pantalla completa, CSS y JS cargados`);
	ok(/fondo-azul\.mp4/.test(r.video), `${n}: video de fondo incluido en el plugin`);
	ok(r.tint, `${n}: capa de color guardada`);
	if (n === 'mob') { ok(r.fs === '30px', `mob: tamaño de titular de celular = ${r.fs}`); }
	ok(r.scrollX <= 0, `${n}: sin scroll horizontal (${r.scrollX})`);
	ok(!r.vf, `${n}: sin rastro de VeloxForge en el HTML`);
	await v.screenshot({ path: `${out}/front-${n}.png` });
	await v.close();
}

// 7. Código corto en una página normal con el tema.
await p.goto(base + '/wp-admin/admin.php?page=glh');
console.log(errs.length ? 'Errores JS:\n' + errs.join('\n') : 'Sin errores de JavaScript');
await b.close();
console.log(fail ? `\n${fail} fallos` : '\nTodo bien');
process.exit(fail || errs.length ? 1 : 0);
