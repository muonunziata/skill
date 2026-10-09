// Prueba de humo de las páginas HTML. Uso: PAGES=../paginas node smoke-pages.mjs
import { chromium } from 'playwright';
import path from 'node:path';
const PAGES = path.resolve(process.env.PAGES || '../paginas');
const url = f => 'file://' + path.join(PAGES, f);
const b = await chromium.launch(); const errs = []; const ok = []; const bad = [];
const check = (name, cond) => (cond ? ok : bad).push(name);

// ---- Home: directorio, menú desplegable y perfil de negocio
const h = await b.newPage({ viewport: { width: 1366, height: 860 } });
h.on('pageerror', e => errs.push('home: ' + e));
await h.goto(url('golehighacres-home.html')); await h.waitForTimeout(800);
await h.locator('#searchForm').scrollIntoViewIfNeeded();
check('18 categorías + «todas» en el menú', (await h.locator('.dd-opt').count()) === 19);
await h.click('#ddBtn'); check('el menú se abre', await h.locator('#ddList').isVisible());
await h.click('.dd-opt[data-id="5"]'); check('filtrar por categoría', (await h.locator('.res').count()) === 8);
await h.click('#ddBtn'); await h.click('.dd-opt[data-id="all"]');
await h.fill('#q', 'cafe'); await h.locator('.res').first().click(); await h.waitForTimeout(400);
check('click en un negocio abre el perfil', await h.locator('.pf-modal').isVisible());
check('enlace a Google Maps con la dirección', /google\.com\/maps\/search\/\?api=1&query=/.test(await h.getAttribute('.pf-btn.main', 'href')));
check('galería navegable', (await h.locator('.pf-slide').count()) > 1);
await h.keyboard.press('Escape'); check('Esc cierra el perfil', await h.locator('.pf-modal').isHidden());
await h.setViewportSize({ width: 390, height: 844 }); await h.waitForTimeout(200);
check('sin scroll horizontal en celular', !(await h.evaluate(() => document.documentElement.scrollWidth > innerWidth)));

// ---- News & Events: pared de videos
const w = await b.newPage({ viewport: { width: 1366, height: 800 } });
w.on('pageerror', e => errs.push('wall: ' + e));
await w.goto(url('golehighacres-menu.html')); await w.waitForTimeout(900);
await w.mouse.move(683, 120); await w.waitForTimeout(250);
check('cursor de flechas sobre la pared', (await w.locator('#curText svg').count()) === 1);
const p = await w.evaluate(() => { const r = [...document.querySelectorAll('.tile')].map(e => e.getBoundingClientRect()).filter(r => r.left > 300 && r.right < 1000 && r.top > 200 && r.bottom < 700)[0]; return [r.x + r.width / 2, r.y + r.height / 2]; });
await w.mouse.move(p[0], p[1]); await w.waitForTimeout(250);
check('cursor GO! sobre un video', (await w.textContent('#curText')).trim() === 'GO!');
await w.mouse.down(); await w.mouse.up(); await w.waitForTimeout(300);
check('click real abre el reproductor', (await w.locator('.modal:not([hidden])').count()) === 1);

console.log('OK  :', ok.length); ok.forEach(x => console.log('  ✓', x));
console.log('FALLA:', bad.length); bad.forEach(x => console.log('  ✗', x));
if (errs.length) console.log('Errores de página:', errs);
await b.close(); process.exit(bad.length || errs.length ? 1 : 0);
