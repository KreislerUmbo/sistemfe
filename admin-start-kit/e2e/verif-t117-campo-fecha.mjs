// T-117: el campo de fecha rechaza una fecha escrita que no existe (agencia-demo, no guarda nada:
// el PATCH de la edición se intercepta). Venta 35 simulada a crédito con 1 cuota al 31-12.
// Uso: RECORRIDO_USUARIO=... RECORRIDO_CLAVE=... node e2e/verif-t117-campo-fecha.mjs [carpeta-capturas]
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

const escribir = async (campo, valor) => { await campo.click(); await campo.fill(valor); await campo.press('Enter'); await page.mouse.click(5, 5); await page.waitForTimeout(300); };
const estado = async (grupo) => {
    const campo = grupo.locator('input.form-control:visible').first();
    const aviso = await grupo.locator('.invalid-feedback').innerText().catch(() => '');
    const rojo = await campo.evaluate((el) => el.classList.contains('is-invalid'));
    return `valor «${await campo.inputValue()}» | rojo ${rojo} | aviso «${aviso}»`;
};

// ── 1. Cuota de la edición (el caso de la venta 41) ───────────────
let payload = null;
await page.route((u) => /\/sales\/\d+$/.test(u.pathname), async (route) => {
    const r = route.request();
    if (r.method() === 'GET') {
        const res = await route.fetch(); const json = await res.json();
        json.sale.type_payment = 2; json.sale.condicion_pago = 'credito'; json.sale.sale_payments = [];
        json.cronograma = [{ numero_cuota: 1, monto_programado: Number(json.sale.total), fecha_vencimiento: '2026-12-31' }];
        return route.fulfill({ response: res, json });
    }
    payload = JSON.parse(r.postData());
    return route.fulfill({ json: { code: 0, message: 'interceptado' } });
});
await page.goto(`${URL_BASE}/sale/edit/35`);
await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await page.waitForTimeout(1500);
const grupoCuota = page.locator('.cronograma-campos .col-6:has(label:has-text("Vence el")) .campo-fecha');
const campoCuota = grupoCuota.locator('input.form-control:visible').first();
await escribir(campoCuota, '31/11/2026');
console.log('[cuota] 31/11/2026 →', await estado(grupoCuota));
await page.locator('.panel-cobro').screenshot({ path: path.join(salida, 't117-cuota-rechazada.png') });
await page.locator('button:has-text("Guardar cambios")').click(); await page.waitForTimeout(800);
await page.locator('.swal2-confirm').click().catch(() => {});
console.log('[cuota] PATCH tras rechazo:', JSON.stringify(payload?.cronograma));
await escribir(campoCuota, '30/11/2026');
console.log('[cuota] 30/11/2026 →', await estado(grupoCuota));
payload = null;
await page.locator('button:has-text("Guardar cambios")').click(); await page.waitForTimeout(800);
await page.locator('.swal2-confirm').click().catch(() => {});
console.log('[cuota] PATCH tras corregir:', JSON.stringify(payload?.cronograma));

// Elegir con el calendario sigue igual: abrir y pulsar el día 25 (el 15 está antes de la emisión, deshabilitado).
await escribir(campoCuota, '31/11/2026');
await grupoCuota.locator('button[aria-label^="Abrir calendario"]').click(); await page.waitForTimeout(300);
await page.locator('.flatpickr-calendar.open .flatpickr-day:not(.prevMonthDay):not(.nextMonthDay)', { hasText: /^25$/ }).first().click();
await page.waitForTimeout(300);
console.log('[cuota] calendario día 25 →', await estado(grupoCuota));
// Borrar el campo lo deja vacío (como antes).
await campoCuota.click(); await campoCuota.fill(''); await campoCuota.press('Tab'); await page.mouse.click(5, 5); await page.waitForTimeout(300);
console.log('[cuota] borrado →', await estado(grupoCuota), '| suma:', (await page.locator('.cronograma-campos').innerText()).includes('Las cuotas suman') ? 'con cuota' : 'sin cuota');

// ── 2. Filtro de fechas (página) ─────────────────────────────────
await page.goto(`${URL_BASE}/agencia-viajes/reportes-comerciales`); await page.waitForTimeout(2000);
const grupoDesde = page.locator('.campo-fecha').first();
const antes = await grupoDesde.locator('input.form-control:visible').first().inputValue();
await escribir(grupoDesde.locator('input.form-control:visible').first(), '30/02/2026');
console.log(`[reportes] antes «${antes}», 30/02/2026 →`, await estado(grupoDesde));

// ── 3. Dentro de un modal (calendario estático) ──────────────────
await page.goto(`${URL_BASE}/agencia-viajes/cotizador`); await page.waitForTimeout(2000);
await page.locator('button:has-text("consulta")').first().click(); await page.waitForTimeout(800);
const grupoModal = page.locator('.modal .col-6:has(label:has-text("Desde")) .campo-fecha');
await escribir(grupoModal.locator('input.form-control:visible').first(), '31/04/2026');
console.log('[modal] 31/04/2026 (vacío antes) →', await estado(grupoModal));
await escribir(grupoModal.locator('input.form-control:visible').first(), 'mañana');
console.log('[modal] «mañana» →', await estado(grupoModal));
await page.locator('.modal-content').screenshot({ path: path.join(salida, 't117-modal.png') });
await browser.close();
