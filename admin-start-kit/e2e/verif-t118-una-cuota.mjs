// T-118: crédito con una cuota por defecto y «Pagar en varias cuotas» (agencia-demo). No guarda nada:
// en registrar no se pulsa guardar; en editar (venta 35 simulada a crédito) el PATCH se intercepta.
// Uso: RECORRIDO_USUARIO=... RECORRIDO_CLAVE=... node e2e/verif-t118-una-cuota.mjs [carpeta-capturas]
import { chromium } from 'playwright-core';
import fs from 'node:fs'; import os from 'node:os'; import path from 'node:path';
const URL_BASE = 'http://agencia-demo.sistemafe.test:5173';
const salida = process.argv[2] ?? os.tmpdir();
const cache = path.join(os.homedir(), 'AppData', 'Local', 'ms-playwright');
const exe = fs.readdirSync(cache).filter((d) => /^chromium-\d+$/.test(d)).sort().reverse().map((b) => path.join(cache, b, 'chrome-win64', 'chrome.exe')).find((p) => fs.existsSync(p));
const browser = await chromium.launch({ executablePath: exe, headless: true });
const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
page.setDefaultTimeout(8000);
page.on('pageerror', (e) => console.log('ERROR JS:', e.message));
await page.goto(`${URL_BASE}/admin/login`);
await page.fill('#username', process.env.RECORRIDO_USUARIO); await page.fill('#userpassword', process.env.RECORRIDO_CLAVE);
await page.click('button[type=submit]'); await page.waitForFunction(() => !!localStorage.getItem('token')); await page.waitForTimeout(1500);
const txt = async (loc) => (await loc.innerText()).replace(/\s+/g, ' ').trim();
const escribirFecha = async (loc, valor) => { await loc.click(); await loc.fill(valor); await loc.press('Enter'); await page.mouse.click(10, 10); await page.waitForTimeout(300); };
const interruptor = () => page.locator('.cronograma-campos input[role=switch]').first();
const venceEl = () => page.locator('.cronograma-campos .col-6:has(label:has-text("Vence el")) input.form-control:visible').first();

// ── Registrar ─────────────────────────────────────────────────────
await page.goto(`${URL_BASE}/sale/register`);
const buscador = page.locator('input[placeholder^="Buscar producto"]');
await buscador.waitFor(); await page.waitForTimeout(1200);
await buscador.click(); await buscador.pressSequentially('PRUEBA T-109 Mouse', { delay: 80 }); await page.waitForTimeout(1500);
await buscador.press('Enter'); await page.waitForTimeout(1000);
if (await page.locator('.swal2-popup').isVisible()) { console.log('[registrar] ventana:', await txt(page.locator('.swal2-popup'))); await page.locator('.swal2-confirm').click(); }
await page.locator('.panel-cobro button:has-text("Crédito"), button:has-text("Crédito")').first().click(); await page.waitForTimeout(500);
console.log('[registrar] «Vence el» visible:', await venceEl().isVisible(), '| varias marcado:', await interruptor().isChecked());
await escribirFecha(venceEl(), '15/12/2026');
console.log('[registrar] resumen:', await txt(page.locator('text=Las cuotas suman').first()));
await page.locator('.cronograma-campos').first().screenshot({ path: path.join(salida, 't118-registrar-una.png') });
await interruptor().check(); await page.waitForTimeout(300);
console.log('[registrar] varias → filas:', await page.locator('.cronograma-campos table tbody tr').count(),
    '| N°:', await page.locator('.cronograma-campos input[type=number][min="1"]').inputValue());
await page.locator('.cronograma-campos input[type=number][min="1"]').fill('3'); await page.waitForTimeout(200);
await page.locator('.cronograma-campos button:has-text("Regenerar cronograma")').click(); await page.waitForTimeout(1200);
console.log('[registrar] regenerado → filas:', await page.locator('.cronograma-campos table tbody tr').count());
await page.locator('.cronograma-campos').first().screenshot({ path: path.join(salida, 't118-registrar-varias.png') });
await interruptor().uncheck(); await page.waitForTimeout(300);
console.log('[registrar] vuelve a una → fecha:', await venceEl().inputValue(), '|', await txt(page.locator('text=Las cuotas suman').first()));

// ── Editar (venta 35 simulada a crédito) ───────────────────────────
let cuotas = []; let payload = null;
await page.route((u) => /\/sales\/\d+$/.test(u.pathname), async (route) => {
    const r = route.request();
    if (r.method() === 'GET') {
        const res = await route.fetch(); const json = await res.json();
        json.sale.type_payment = 2; json.sale.condicion_pago = 'credito'; json.sale.sale_payments = [];
        const total = Number(json.sale.total);
        json.cronograma = cuotas.length === 2
            ? [{ numero_cuota: 1, monto_programado: total / 2, fecha_vencimiento: '2026-12-15' }, { numero_cuota: 2, monto_programado: total / 2, fecha_vencimiento: '2026-12-30' }]
            : [{ numero_cuota: 1, monto_programado: total, fecha_vencimiento: '2026-12-31' }];
        return route.fulfill({ response: res, json });
    }
    payload = JSON.parse(r.postData());
    return route.fulfill({ json: { code: 0, message: 'interceptado' } });
});
const abrirEdicion = async () => {
    await page.goto(`${URL_BASE}/sale/edit/35`);
    await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await page.waitForTimeout(1200);
};

cuotas = [1];
await abrirEdicion();
console.log('[editar 1 cuota] varias marcado:', await interruptor().isChecked(), '| Vence el:', await venceEl().inputValue());
await escribirFecha(venceEl(), '30/11/2026');
await page.locator('.panel-cobro').screenshot({ path: path.join(salida, 't118-editar-una.png') });
await page.locator('button:has-text("Guardar cambios")').click(); await page.waitForTimeout(800);
await page.locator('.swal2-confirm').click().catch(() => {});
console.log('[editar 1 cuota] PATCH cronograma:', JSON.stringify(payload?.cronograma));

cuotas = [1, 2]; payload = null;
await abrirEdicion();
console.log('[editar 2 cuotas] varias marcado:', await interruptor().isChecked(), '| filas:', await page.locator('.cronograma-campos table tbody tr').count(),
    '| N°:', await page.locator('.cronograma-campos input[type=number][min="1"]').inputValue());
await browser.close();
