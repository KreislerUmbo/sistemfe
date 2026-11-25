// T-110 (revisión): las tres pruebas pendientes de la edición de venta en agencia-demo.
// 1) agregar un producto, 2) cambiar a un cliente mayorista con una línea nueva (cliente simulado en la respuesta),
// 3) venta de reserva y venta enviada a SUNAT. El PATCH de 1-2 se intercepta (no guarda); el de 3 llega al
// servidor, que lo rechaza antes de la transacción.
import { chromium } from 'playwright-core';
import fs from 'node:fs'; import os from 'node:os'; import path from 'node:path';
const URL_BASE = 'http://agencia-demo.sistemafe.test:5173';
const cache = path.join(os.homedir(), 'AppData', 'Local', 'ms-playwright');
const exe = fs.readdirSync(cache).filter((d) => /^chromium-\d+$/.test(d)).sort().reverse().map((b) => path.join(cache, b, 'chrome-win64', 'chrome.exe')).find((p) => fs.existsSync(p));
const browser = await chromium.launch({ executablePath: exe, headless: true });
const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
page.setDefaultTimeout(5000);
page.on('pageerror', (e) => console.log('ERROR JS:', e.message));
await page.goto(`${URL_BASE}/admin/login`);
await page.fill('#username', process.env.RECORRIDO_USUARIO); await page.fill('#userpassword', process.env.RECORRIDO_CLAVE);
await page.click('button[type=submit]'); await page.waitForFunction(() => !!localStorage.getItem('token')); await page.waitForTimeout(1500);

const textoAviso = async () => (await page.locator('body').innerText()).match(/Se actualizó el precio[^\n]*/)?.[0] ?? '(sin aviso)';
const swal = async () => { const t = await page.locator('.swal2-popup').innerText().catch(() => '(sin ventana)'); await page.locator('.swal2-confirm').click().catch(() => {}); return t.replace(/\s+/g, ' '); };

// ── 1 y 2: venta 35 ─────────────────────────────────────────────
let payload = null; let interceptar = true;
await page.route((u) => /\/sales\/\d+$/.test(u.pathname), async (route) => {
    const r = route.request();
    if (r.method() !== 'PATCH' && r.method() !== 'PUT') return route.continue();
    payload = JSON.parse(r.postData());
    if (interceptar) return route.fulfill({ json: { code: 0, message: 'interceptado' } });
    return route.continue();
});
// Cliente simulado como mayorista en la búsqueda (no se toca la base).
await page.route((u) => u.pathname.endsWith('/clients') && u.searchParams.has('search'), async (route) => {
    const res = await route.fetch(); const json = await res.json();
    const lista = json.clients?.data ?? json.clients ?? json.data ?? [];
    lista.forEach((c) => { c.es_mayorista = true; });
    console.log('clientes encontrados:', lista.length);
    await route.fulfill({ response: res, json });
});

await page.goto(`${URL_BASE}/sale/edit/35`);
await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await page.waitForTimeout(800);
const lineasAntes = await page.locator('.tabla-lineas tbody tr input[aria-label="Cantidad"]').count();
console.log('\n[1] líneas guardadas al abrir:', lineasAntes);

await page.fill('input[placeholder^="Buscar producto"]', 'PRUEBA T-109 Mouse');
await page.waitForSelector(".sugerencias .list-group-item-action.justify-content-between"); await page.locator(".sugerencias .list-group-item-action.justify-content-between").first().click();
await page.waitForTimeout(500);
console.log('[1] líneas tras agregar:', await page.locator('.tabla-lineas tbody tr input[aria-label="Cantidad"]').count());

await page.click('.panel-cobro button:has-text("Guardar cambios")'); await page.waitForTimeout(800);
const v1 = await swal(); if (v1 !== '(sin ventana)') console.log('[1] ventana al guardar:', v1);
const linea = (p) => p?.sale_details?.map((d) => ({ id: d.id ?? null, prod: d.product?.id, qty: d.quantity, price_base: d.price_base, price_final: d.price_final }));
console.log('[1] payload sale_details:', JSON.stringify(linea(payload)));
const guardadasAntes = linea(payload)?.filter((d) => d.id);

// 2: cambiar de cliente a uno "mayorista"
payload = null;
await page.click('.panel-cobro button:has-text("Cambiar")'); await page.waitForTimeout(300);
await page.fill('input[placeholder="DNI, RUC o nombre del cliente"]', 'a');
await page.fill('input[placeholder="DNI, RUC o nombre del cliente"]', 'ag');
await page.waitForSelector('.sugerencias .list-group-item-action', { timeout: 8000 }); await page.locator('.sugerencias .list-group-item-action').first().click();
await page.waitForTimeout(500);
console.log('\n[2] aviso:', await textoAviso());
await page.click('.panel-cobro button:has-text("Guardar cambios")'); await page.waitForTimeout(800);
const v2 = await swal(); if (v2 !== '(sin ventana)') console.log('[2] ventana al guardar:', v2);
console.log('[2] payload sale_details:', JSON.stringify(linea(payload)), 'client_id:', payload?.client_id);
const guardadasDespues = linea(payload)?.filter((d) => d.id);
console.log('[2] guardadas sin cambio de precio:', JSON.stringify(guardadasAntes?.map((d) => d.price_base)) === JSON.stringify(guardadasDespues?.map((d) => d.price_base)));
await page.screenshot({ path: 'resultados/t110-cambio-cliente.png' });

// ── 3: venta de reserva (22) y enviada a SUNAT (19): el servidor decide ──
interceptar = false;
for (const id of [22, 19]) {
    payload = null;
    const respuestas = [];
    const oir = async (res) => { if (/\/sales\/\d+$/.test(new URL(res.url()).pathname) && res.request().method() === 'PATCH') respuestas.push(`${res.status()} ${(await res.text()).slice(0, 220)}`); };
    page.on('response', oir);
    await page.goto(`${URL_BASE}/sale/edit/${id}`); await page.waitForTimeout(2500); await page.screenshot({ path: `resultados/t110-venta-${id}.png` });
    const alAbrir = await swal();
    console.log(`\n[3] venta ${id} al abrir:`, alAbrir);
    if (await page.locator('.panel-cobro button:has-text("Guardar cambios")').count()) {
        await page.click('.panel-cobro button:has-text("Guardar cambios")'); await page.waitForTimeout(1500);
        console.log(`[3] venta ${id} ventana al guardar:`, await swal());
    }
    console.log(`[3] venta ${id} respuesta del servidor:`, respuestas.join(' | ') || '(no se envió PATCH)');
    page.off('response', oir);
}
await browser.close();
