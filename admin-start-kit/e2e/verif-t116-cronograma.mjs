// T-116: la edición de una venta a crédito abre con el cronograma guardado (agencia-demo).
// Venta 35 simulada a crédito con una cuota; el PATCH se intercepta (no se guarda nada).
// Uso: RECORRIDO_USUARIO=... RECORRIDO_CLAVE=... node e2e/verif-t116-cronograma.mjs
import { chromium } from 'playwright-core';
import fs from 'node:fs'; import os from 'node:os'; import path from 'node:path';
const URL_BASE = 'http://agencia-demo.sistemafe.test:5173';
const cache = path.join(os.homedir(), 'AppData', 'Local', 'ms-playwright');
const exe = fs.readdirSync(cache).filter((d) => /^chromium-\d+$/.test(d)).sort().reverse().map((b) => path.join(cache, b, 'chrome-win64', 'chrome.exe')).find((p) => fs.existsSync(p));
const browser = await chromium.launch({ executablePath: exe, headless: true });
const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
page.setDefaultTimeout(6000);
page.on('pageerror', (e) => console.log('ERROR JS:', e.message));
await page.goto(`${URL_BASE}/admin/login`);
await page.fill('#username', process.env.RECORRIDO_USUARIO); await page.fill('#userpassword', process.env.RECORRIDO_CLAVE);
await page.click('button[type=submit]'); await page.waitForFunction(() => !!localStorage.getItem('token')); await page.waitForTimeout(1500);
const swal = async () => { const t = await page.locator('.swal2-popup').innerText().catch(() => '(sin ventana)'); await page.locator('.swal2-confirm').click().catch(() => {}); await page.waitForTimeout(300); return t.replace(/\s+/g, ' '); };

let payload = null; let cuotas = [];
await page.route((u) => /\/sales\/\d+$/.test(u.pathname), async (route) => {
    const r = route.request();
    if (r.method() === 'GET') {
        const res = await route.fetch(); const json = await res.json();
        const total = Number(json.sale.total);
        json.sale.type_payment = 2; json.sale.condicion_pago = 'credito'; json.sale.sale_payments = [];
        json.cronograma = cuotas.length ? cuotas : [{ numero_cuota: 1, monto_programado: total, fecha_vencimiento: '2026-12-31' }];
        return route.fulfill({ response: res, json });
    }
    payload = JSON.parse(r.postData());
    return route.fulfill({ json: { code: 0, message: 'interceptado' } });
});

const revisar = async (nombre) => {
    payload = null;
    await page.goto(`${URL_BASE}/sale/edit/35`);
    await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await page.waitForTimeout(1000);
    const n = await page.locator('.cronograma-campos input[type=number][min="1"]').inputValue();
    const per = await page.locator('.cronograma-campos select').first().inputValue();
    const filas = await page.locator('.cronograma-campos table tbody tr').count();
    const suma = await page.locator('.cronograma-campos .small.text-success, .cronograma-campos .small.text-danger').first().innerText().catch(() => '(sin suma)');
    console.log(`[${nombre}] N° cuotas ${n} | periodicidad ${per} | filas ${filas} | ${suma.replace(/\s+/g, ' ')}`);
    await page.screenshot({ path: `e2e/resultados/t116-cronograma-${nombre}.png` });
    await page.click('.panel-cobro button:has-text("Guardar cambios")'); await page.waitForTimeout(800);
    console.log(`[${nombre}] ventana al guardar:`, await swal());
    console.log(`[${nombre}] cronograma en el payload:`, JSON.stringify(payload?.cronograma), '| debt', payload?.debt);
};

await revisar('una-cuota');
// dos cuotas quincenales que suman el total
cuotas = [{ numero_cuota: 1, monto_programado: 140, fecha_vencimiento: '2026-12-01' }, { numero_cuota: 2, monto_programado: 140, fecha_vencimiento: '2026-12-16' }];
await revisar('dos-quincenales');
await browser.close();
