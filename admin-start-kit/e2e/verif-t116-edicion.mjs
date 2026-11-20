// T-116: verificación en navegador de los arreglos de la edición de venta (agencia-demo).
// Ningún PATCH llega al servidor salvo el de la venta 19 (enviada a SUNAT, el backend la rechaza).
// Anticipo, sucursal y cliente mayorista se simulan en la respuesta de GET (no se toca la base).
// Uso: RECORRIDO_USUARIO=... RECORRIDO_CLAVE=... node e2e/verif-t116-edicion.mjs
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
const cabecera = async () => (await page.locator('.d-flex.flex-wrap.align-items-center.gap-2.mb-3').first().innerText()).replace(/\s+/g, ' ');
const peticiones = [];
page.on('request', (r) => { if (/\/sale_payments/.test(r.url())) peticiones.push(`${r.method()} ${new URL(r.url()).pathname}`); });

let payload = null; let interceptar = true; let simular = null;
await page.route((u) => /\/sales\/\d+$/.test(u.pathname), async (route) => {
    const r = route.request();
    if (r.method() === 'GET') {
        if (!simular) return route.continue();
        const res = await route.fetch(); const json = await res.json(); simular(json);
        return route.fulfill({ response: res, json });
    }
    payload = JSON.parse(r.postData());
    if (interceptar) return route.fulfill({ json: { code: 0, message: 'interceptado' } });
    return route.continue();
});
// Dos sucursales: la de la venta (1) no es la primera de la lista.
await page.route((u) => u.pathname.endsWith('/branches'), async (route) => {
    const res = await route.fetch(); const json = await res.json();
    json.branches = [{ id: 99, name: 'Sucursal Simulada', is_active: true }, ...json.branches];
    await route.fulfill({ response: res, json });
});

// ── CA3 + CA6 + CA2: venta 35, cliente mayorista y anticipo simulados ──
simular = (json) => {
    json.sale.client.es_mayorista = true;
    json.sale.n_comprobante_anticipo = 'F001-00000123';
    json.sale.amount_anticipo = 50;
    // Línea guardada a 60 sin IGV: catálogo general 70.80 (= 60 + IGV) y mayorista 59 → debe decir «General».
    const d = json.sale.sale_details.find((x) => Number(x.price_base) === 60);
    if (d) { d.product.price_general = 70.8; d.product.price_company = 59; }
};
await page.goto(`${URL_BASE}/sale/edit/35`);
await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await page.waitForTimeout(1000);
console.log('[CA3] cabecera:', await cabecera());
const etiquetas = await page.locator('.tabla-lineas tbody tr .small.text-muted, .tabla-lineas tbody tr .small.text-warning').allInnerTexts();
console.log('[CA6] etiquetas de precio (cliente mayorista simulado):', JSON.stringify(etiquetas.filter((t) => /General|Mayorista|Precio/.test(t))));
await page.locator('button:has-text("Datos de la venta")').click(); await page.waitForTimeout(400);
await page.screenshot({ path: 'e2e/resultados/t116-venta-35.png' });
// La condición «Con anticipo» se elige en Datos de la venta; el campo vive en el cobro.
const anticipo = await page.locator('input[placeholder="Ej: F001-00000123"]').inputValue().catch(() => '(campo no visible)');
console.log('[CA2] comprobante de anticipo al abrir:', anticipo);
await page.click('.panel-cobro button:has-text("Guardar cambios")'); await page.waitForTimeout(800);
console.log('[CA2/CA3] ventana:', await swal());
console.log('[CA2/CA3] payload: branch_id', payload?.branch_id, '| n_comprobante_anticipo', payload?.n_comprobante_anticipo, '| amount_anticipo', payload?.amount_anticipo,
    '| precio_guardado en payload:', payload?.sale_details?.some((d) => 'precio_guardado' in d));

// ── CA1: misma venta pasada a crédito, pago inicial en memoria ──
simular = null; payload = null; peticiones.length = 0;
await page.goto(`${URL_BASE}/sale/edit/35`);
await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await page.waitForTimeout(1000);
await page.click('.panel-cobro button:has-text("Crédito")'); await page.waitForTimeout(300);
// quitar el pago existente (en crédito, sin DELETE)
const filasPago = page.locator('.panel-cobro table tbody tr button.btn-outline-danger');
while (await filasPago.count()) { await filasPago.first().click(); await page.waitForTimeout(200); await swal(); }
await page.click('.panel-cobro button:has-text("Guardar cambios")'); await page.waitForTimeout(600);
console.log('[CA1] sin pago inicial y sin cronograma, ventana:', await swal());
// pago inicial
await page.locator('.panel-cobro label:has-text("Método (pago inicial)") + select').selectOption({ index: 0 });
await page.fill('.panel-cobro input[placeholder="0.00"]', '30');
const fecha = page.locator('.panel-cobro input[type="date"], .panel-cobro input[placeholder*="dd"]').first();
if (await fecha.count()) { await fecha.click(); await page.locator('.flatpickr-calendar.open .flatpickr-day.today').first().click().catch((e) => console.log('sin día de hoy', e.message)); }
await page.keyboard.press('Escape'); await page.locator('h5:has-text("Editar venta")').click(); await page.waitForTimeout(300);
await page.click('.panel-cobro button:has-text("Agregar pago inicial")'); await page.waitForTimeout(500);
const v = await swal(); if (v !== '(sin ventana)') console.log('[CA1] ventana al agregar:', v);
console.log('[CA1] filas de pago inicial:', await page.locator('.panel-cobro table tbody tr button.btn-outline-danger').count());
const generar = page.locator('.panel-cobro button:has-text("Generar")');
await page.keyboard.press('Escape'); await page.locator('h5:has-text("Editar venta")').click(); await page.waitForTimeout(300);
if (await generar.count()) { await generar.first().click(); await page.waitForTimeout(1500); }
console.log('[CA1] cuotas generadas en pantalla:', await page.locator('.cronograma-campos table tbody tr').count());
await page.click('.panel-cobro button:has-text("Guardar cambios")'); await page.waitForTimeout(800);
console.log('[CA1] ventana al guardar:', await swal());
console.log('[CA1] payload: type_payment', payload?.type_payment, '| payments', JSON.stringify(payload?.payments?.map((p) => ({ m: p.method_payment, a: p.amount, f: p.date_payment, id: p.id ?? null }))), '| cuotas', payload?.cronograma?.length);
console.log('[CA1] peticiones a sale_payments:', peticiones.join(', ') || '(ninguna)');
await page.screenshot({ path: 'e2e/resultados/t116-credito.png' });

// ── CA4: venta 27 nacional con cliente de Amazonía ──
simular = (json) => { json.sale.destino = 'nacional'; console.log('[CA4] cliente es_amazonia:', json.sale.client.es_amazonia); };
await page.goto(`${URL_BASE}/sale/edit/35`);
await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await page.waitForTimeout(1000);
console.log('\n[CA4] cabecera venta 35 (destino nacional simulado):', await cabecera());
payload = null;
await page.click('.panel-cobro button:has-text("Guardar cambios")'); await page.waitForTimeout(800); await swal();
console.log('[CA4] destino en el payload:', payload?.destino); simular = null;

// ── CA5: venta 19 enviada a SUNAT, el PATCH llega al servidor ──
interceptar = false; payload = null;
await page.goto(`${URL_BASE}/sale/edit/19`); await page.waitForTimeout(2500);
await swal();
await page.click('.panel-cobro button:has-text("Guardar cambios")'); await page.waitForTimeout(1500);
console.log('[CA5] venta 19 ventana al guardar:', await swal());
await browser.close();
