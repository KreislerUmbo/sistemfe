// T-101: las pantallas que el type-check encontró rotas (agencia-demo; no guarda nada: el POST de
// recursos se intercepta).
//  1. Recursos → Nuevo recurso: el selector «Sistema» trae la lista y el POST lleva sistema_id y tipo_recurso.
//  2. Portal → Favoritos: la página carga (antes fallaba por el script comentado).
//  3. Portal → Sistemas, plan anual: «Facturado S/ <precioAnual × 12> al año», no «undefined».
// Uso: RECORRIDO_USUARIO=... RECORRIDO_CLAVE=... node e2e/verif-t101-pantallas.mjs [carpeta-capturas]
import { chromium } from 'playwright-core';
import fs from 'node:fs'; import os from 'node:os'; import path from 'node:path';
const URL_BASE = 'http://agencia-demo.sistemafe.test:5173';
const salida = process.argv[2] ?? os.tmpdir();
const cache = path.join(os.homedir(), 'AppData', 'Local', 'ms-playwright');
const exe = fs.readdirSync(cache).filter((d) => /^chromium-\d+$/.test(d)).sort().reverse().map((b) => path.join(cache, b, 'chrome-win64', 'chrome.exe')).find((p) => fs.existsSync(p));
const browser = await chromium.launch({ executablePath: exe, headless: true });
const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
page.setDefaultTimeout(8000);
const erroresJs = [];
page.on('pageerror', (e) => { erroresJs.push(e.message); console.log('ERROR JS:', e.message); });
await page.goto(`${URL_BASE}/admin/login`);
await page.fill('#username', process.env.RECORRIDO_USUARIO); await page.fill('#userpassword', process.env.RECORRIDO_CLAVE);
await page.click('button[type=submit]'); await page.waitForFunction(() => !!localStorage.getItem('token')); await page.waitForTimeout(1500);

// ── 1. Nuevo recurso ─────────────────────────────────────────────
let cuerpo = null;
await page.route((u) => /\/recursos$/.test(u.pathname), async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    cuerpo = route.request().postData() ?? '';
    return route.fulfill({ json: { code: 200, message: 'interceptado' } });
});
await page.goto(`${URL_BASE}/recursos/register`);
await page.waitForFunction(() => document.querySelectorAll('#sistema_id option').length > 1, null, { timeout: 15000 }).catch(() => {});
const opciones = await page.locator('#sistema_id option').allInnerTexts();
console.log(`1. Selector «Sistema»: ${opciones.length - 1} sistema(s) — ${opciones.slice(1, 4).map((t) => t.trim()).join(' | ')}`);
if (opciones.length > 1) {
    await page.selectOption('#sistema_id', { index: 1 });
    await page.fill('#titulo', 'Prueba T-101 (interceptada)');
    await page.locator('form button[type=submit]').first().click();
    await page.waitForTimeout(1500);
    const campo = (n) => (new RegExp(`name="${n}"\\r?\\n\\r?\\n([^\\r\\n]*)`).exec(cuerpo ?? '') ?? [])[1];
    console.log(`   POST interceptado: sistema_id=${campo('sistema_id')} | tipo_recurso=${campo('tipo_recurso')} | tipo=${campo('tipo') ?? '(no va)'} | titulo=${campo('titulo')}`);
}
await page.screenshot({ path: path.join(salida, 't101-recurso.png') });

// ── 2. Favoritos ─────────────────────────────────────────────────
await page.goto(`${URL_BASE}/favoritos`); await page.waitForTimeout(2000);
const favoritos = await page.locator('h2:has-text("Mis favoritos")').count();
const vacio = await page.locator('text=No tienes productos favoritos').count();
console.log(`2. Favoritos: título ${favoritos ? 'sí' : 'NO'} | aviso de lista vacía ${vacio ? 'sí' : 'NO'}`);
await page.screenshot({ path: path.join(salida, 't101-favoritos.png') });

// ── 3. Sistemas, plan anual ──────────────────────────────────────
await page.goto(`${URL_BASE}/sistemas`); await page.waitForTimeout(2000);
await page.locator('button:has-text("Anual")').first().click(); await page.waitForTimeout(400);
const facturado = (await page.locator('text=/Facturado S\\//').first().innerText()).replace(/\s+/g, ' ');
console.log(`3. Sistemas, anual: «${facturado}»`);
await page.screenshot({ path: path.join(salida, 't101-sistemas.png') });

console.log(`Errores JS: ${erroresJs.length}`);
await browser.close();
