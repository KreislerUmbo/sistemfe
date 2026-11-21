// T-116: pagos contra total − anticipo, y venta guardada a crédito pasada a Contado (agencia-demo).
// Ningún PATCH llega al servidor; anticipo y crédito se simulan en la respuesta de GET.
// Uso: RECORRIDO_USUARIO=... RECORRIDO_CLAVE=... node e2e/verif-t116-anticipo-pagos.mjs
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
const peticiones = [];
page.on('request', (r) => { if (/\/sale_payments/.test(r.url())) peticiones.push(`${r.method()} ${new URL(r.url()).pathname}`); });

let payload = null; let simular = null;
await page.route((u) => /\/sales\/\d+$/.test(u.pathname), async (route) => {
    const r = route.request();
    if (r.method() === 'GET') {
        if (!simular) return route.continue();
        const res = await route.fetch(); const json = await res.json(); simular(json);
        return route.fulfill({ response: res, json });
    }
    payload = JSON.parse(r.postData());
    return route.fulfill({ json: { code: 0, message: 'interceptado' } });
});
const abrir = async () => {
    await page.goto(`${URL_BASE}/sale/edit/35`);
    await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await page.waitForTimeout(1000);
};

// ── 1: anticipo que deja 1.00 por cobrar; los pagos guardados cubren el total ──
let total = 0;
simular = (json) => {
    total = Number(json.sale.total);
    json.sale.retencion_igv = 0; json.sale.codigo_detraccion = null;
    json.sale.n_comprobante_anticipo = 'F001-00000123';
    json.sale.amount_anticipo = Number((total - 1).toFixed(2));
    console.log('[1] total', total, '| anticipo', json.sale.amount_anticipo, '| pagos', JSON.stringify(json.sale.payments?.map((p) => p.amount)));
};
await abrir();
const filas = await page.locator('.panel-cobro').innerText();
console.log('[1] fila de anticipo en el resumen:', /Anticipo \(ya pagado\)[^\n]*/.exec(filas)?.[0] ?? '(no está)');
await page.click('.panel-cobro button:has-text("Guardar cambios")'); await page.waitForTimeout(800);
console.log('[1] ventana al guardar:', await swal());
console.log('[1] PATCH enviado:', payload ? 'sí' : 'no');

// ── 2: venta guardada a crédito (simulada), en pantalla se pasa a Contado ──
simular = (json) => { json.sale.type_payment = 2; json.sale.condicion_pago = 'credito'; json.sale.n_comprobante_anticipo = null; json.sale.amount_anticipo = 0; };
payload = null; peticiones.length = 0;
await abrir();
await page.click('.panel-cobro button:has-text("Contado")'); await page.waitForTimeout(300);
const quitar = page.locator('.panel-cobro table tbody tr button.btn-outline-danger');
const antes = await quitar.count();
while (await quitar.count()) { await quitar.first().click(); await page.waitForTimeout(200); await swal(); }
await page.locator('.panel-cobro label:has-text("Método") + select').selectOption({ index: 0 });
await page.fill('.panel-cobro input[placeholder="0.00"]', String(total));
await page.click('.panel-cobro button:has-text("Agregar pago")'); await page.waitForTimeout(500);
const v = await swal(); if (v !== '(sin ventana)') console.log('[2] ventana al agregar:', v);
console.log('[2] pagos quitados', antes, '| filas ahora:', await quitar.count());
await page.click('.panel-cobro button:has-text("Guardar cambios")'); await page.waitForTimeout(800);
console.log('[2] ventana al guardar:', await swal());
console.log('[2] payload: type_payment', payload?.type_payment, '| payments', JSON.stringify(payload?.payments?.map((p) => ({ a: p.amount, id: p.id ?? null }))), '| debt', payload?.debt);
console.log('[2] peticiones a sale_payments:', peticiones.join(', ') || '(ninguna)');
await browser.close();
