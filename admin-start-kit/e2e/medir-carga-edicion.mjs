// T-116: mide las peticiones al abrir la edición de una venta (agencia-demo). No guarda nada.
// Uso: RECORRIDO_USUARIO=... RECORRIDO_CLAVE=... node e2e/medir-carga-edicion.mjs [id]
import { chromium } from 'playwright-core';
import fs from 'node:fs'; import os from 'node:os'; import path from 'node:path';
const URL_BASE = process.env.RECORRIDO_URL ?? 'http://agencia-demo.sistemafe.test:5173';
const id = process.argv[2] ?? '35';
const cache = path.join(os.homedir(), 'AppData', 'Local', 'ms-playwright');
const exe = fs.readdirSync(cache).filter((d) => /^chromium-\d+$/.test(d)).sort().reverse().map((b) => path.join(cache, b, 'chrome-win64', 'chrome.exe')).find((p) => fs.existsSync(p));
const browser = await chromium.launch({ executablePath: exe, headless: true });
const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
await page.goto(`${URL_BASE}/admin/login`);
await page.fill('#username', process.env.RECORRIDO_USUARIO); await page.fill('#userpassword', process.env.RECORRIDO_CLAVE);
await page.click('button[type=submit]'); await page.waitForFunction(() => !!localStorage.getItem('token')); await page.waitForTimeout(2500);

for (const vuelta of [1, 2]) {
    const t0 = Date.now(); const filas = [];
    const alPedir = (r) => { if (r.resourceType() === 'xhr' || r.resourceType() === 'fetch') r._t = Date.now(); };
    const alTerminar = (r) => { if (r._t) filas.push(`${String(r._t - t0).padStart(5)} → ${String(Date.now() - t0).padStart(5)} ms  ${r.method()} ${new URL(r.url()).pathname}${new URL(r.url()).search}`); };
    page.on('request', alPedir); page.on('requestfinished', alTerminar);
    await page.goto(`${URL_BASE}/sale/edit/${id}`);
    await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]', { timeout: 30000 });
    console.log(`\nVuelta ${vuelta}: líneas visibles a los ${Date.now() - t0} ms`);
    await page.waitForTimeout(1500);
    filas.sort((a, b) => parseInt(a) - parseInt(b)).forEach((f) => console.log(f));
    page.off('request', alPedir); page.off('requestfinished', alTerminar);
}
await browser.close();
