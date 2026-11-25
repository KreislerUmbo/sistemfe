// T-110 (CA1): captura el payload de PATCH sales/{id} de la pantalla de edición, sin dejarlo llegar al servidor.
// Uso: RECORRIDO_USUARIO=… RECORRIDO_CLAVE=… node payload-edicion-venta.mjs --ui vieja|nueva --salida resultados/x.json
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const args = process.argv.slice(2);
const opcion = (n) => { const i = args.indexOf(n); return i >= 0 ? args[i + 1] : null; };
const UI = opcion('--ui') ?? 'vieja';
const SALIDA = opcion('--salida');
const URL_BASE = process.env.RECORRIDO_URL ?? 'http://agencia-demo.sistemafe.test:5173';
const cache = path.join(os.homedir(), 'AppData', 'Local', 'ms-playwright');
const exe = fs.readdirSync(cache).filter((d) => /^chromium-\d+$/.test(d)).sort().reverse()
    .map((b) => path.join(cache, b, 'chrome-win64', 'chrome.exe')).find((p) => fs.existsSync(p));

// Acciones por pantalla. Cada una recibe `page`.
const vieja = {
    esperar: async (p) => { await p.waitForSelector('#client-search'); await p.waitForFunction(() => document.querySelectorAll('tbody tr').length > 0 && !document.querySelector('tbody tr td[colspan="9"]')); await p.waitForTimeout(800); },
    cantidad: async (p, n) => { await p.locator('table tbody tr').first().locator('input[type=number]').first().fill(String(n)); },
    condicion: async (p, v) => { await p.locator('select.form-select', { has: p.locator('option[value="anticipo"]') }).selectOption(v); await p.waitForTimeout(300); },
    anticipo: async (p, v) => { await p.fill('input[placeholder="Ej: F001-00000123"]', v); },
    detraccion: async (p) => { const s = p.locator('select', { has: p.locator('option:text("Seleccionar código")') }); await s.selectOption({ index: 1 }); await p.waitForTimeout(300); },
    cronograma: async (p) => { await p.click('text=Generar cronograma sugerido'); await p.waitForTimeout(1500); },
    guardar: async (p) => { await p.locator('button:has-text("Guardar Venta")').first().click(); },
};
const driver = UI === 'vieja' ? vieja : (await import('./payload-edicion-venta-nueva.mjs')).default;

const escenarios = [
    { nombre: 'contado_sin_cambios', id: 35, pasos: async (d, p) => {} },
    { nombre: 'contado_cantidad_3', id: 35, pasos: async (d, p) => { await d.cantidad(p, 3); } },
    { nombre: 'credito_cronograma', id: 25, inyectarPago: 50, pasos: async (d, p) => { await d.cronograma(p); } },
    { nombre: 'anticipo', id: 26, inyectarPago: 5, pasos: async (d, p) => { await d.condicion(p, 'anticipo'); await d.anticipo(p, 'F001-00000123'); } },
    { nombre: 'detraccion', id: 26, inyectarPago: 5, pasos: async (d, p) => { await d.condicion(p, '2'); await d.detraccion(p); } },
];

const browser = await chromium.launch({ executablePath: exe, headless: true });
const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await ctx.newPage();
await page.goto(`${URL_BASE}/admin/login`);
await page.fill('#username', process.env.RECORRIDO_USUARIO);
await page.fill('#userpassword', process.env.RECORRIDO_CLAVE);
await page.click('button[type=submit]');
await page.waitForFunction(() => !!localStorage.getItem('token'), null, { timeout: 20000 });
await page.waitForTimeout(1500);

const resultado = {};
for (const e of escenarios) {
    let capturado = null;
    await page.unroute('**/*').catch(() => {});
    await page.route((u) => /\/sales\/\d+$/.test(u.pathname), async (route) => {
        const req = route.request();
        if (req.method() === 'PATCH') { capturado = JSON.parse(req.postData()); await route.abort(); return; }
        if (req.method() === 'GET' && e.inyectarPago) {
            const r = await route.fetch(); const j = await r.json();
            j.sale.sale_payments = [{ id: 9001, sale_id: e.id, method_payment: 'EFECTIVO', amount: String(e.inyectarPago), date_payment: '', nro_operacion: null }];
            await route.fulfill({ response: r, json: j }); return;
        }
        await route.continue();
    });
    await page.goto(`${URL_BASE}/sale/edit/${e.id}`);
    await driver.esperar(page);
    await e.pasos(driver, page);
    await driver.guardar(page);
    await page.waitForTimeout(1500);
    if (!capturado) console.log('  swal:', await page.locator('.swal2-popup').innerText().catch(() => '(sin swal)'));
    resultado[e.nombre] = capturado;
    console.log(e.nombre, capturado ? 'payload capturado' : 'SIN PAYLOAD (¿validación?)');
    await page.keyboard.press('Escape').catch(() => {});
}
await browser.close();
if (SALIDA) fs.writeFileSync(SALIDA, JSON.stringify(resultado, null, 2));
