// T-119: en la edición, los pagos al contado se guardan con «Guardar cambios» (nunca POST/DELETE sale_payments)
// y, al contado sin pagos, el formulario trae Efectivo y el saldo. Venta 35 de agencia-demo tal como está;
// el PATCH se intercepta (no se guarda nada).
// Uso: RECORRIDO_USUARIO=... RECORRIDO_CLAVE=... node e2e/verif-t119-cobro-edicion.mjs [carpeta-capturas]
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
const aSalePayments = [];
page.on('request', (r) => { if (/\/sale_payments/.test(r.url())) aSalePayments.push(`${r.method()} ${r.url()}`); });
await page.goto(`${URL_BASE}/admin/login`);
await page.fill('#username', process.env.RECORRIDO_USUARIO); await page.fill('#userpassword', process.env.RECORRIDO_CLAVE);
await page.click('button[type=submit]'); await page.waitForFunction(() => !!localStorage.getItem('token')); await page.waitForTimeout(1500);
const txt = async (loc) => (await loc.innerText()).replace(/\s+/g, ' ').trim();

let payload = null;
await page.route((u) => /\/sales\/\d+$/.test(u.pathname), async (route) => {
    if (route.request().method() === 'GET') return route.continue();
    payload = JSON.parse(route.request().postData());
    return route.fulfill({ json: { code: 0, message: 'interceptado' } });
});

await page.goto(`${URL_BASE}/sale/edit/35`);
await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await page.waitForTimeout(1500);
const tablaPagos = page.locator('.panel-cobro table').last();
const metodo = page.locator('.panel-cobro .col-6:has(label:has-text("Método")) select');
const monto = page.locator('.panel-cobro input[type=number][placeholder="0.00"]');
console.log('al abrir → pagos:', await txt(tablaPagos.locator('tbody')), '| formulario:', await metodo.inputValue(), await monto.inputValue());

// Quitar el pago guardado: sin ventana de confirmación ni DELETE; aviso y sugerencia del saldo en efectivo.
await tablaPagos.locator('tbody tr').first().locator('button').click(); await page.waitForTimeout(500);
console.log('tras quitar → aviso:', await txt(page.locator('text=el cambio se aplica al pulsar')),
    '| formulario:', await metodo.inputValue(), await monto.inputValue());
await page.locator('.panel-cobro').screenshot({ path: path.join(salida, 't119-sugerido.png') });

// Agregar el pago sugerido: queda «Sin guardar», sin POST.
await page.locator('button:has-text("Agregar pago")').click(); await page.waitForTimeout(400);
console.log('tras agregar → fila:', await txt(tablaPagos.locator('tbody tr').first()));

await page.locator('button:has-text("Guardar cambios")').click(); await page.waitForTimeout(800);
console.log('aviso al guardar:', await txt(page.locator('.swal2-popup')).catch(() => '(sin ventana)'));
console.log('PATCH → type_payment:', payload?.type_payment, '| pagos:', JSON.stringify(payload?.payments), '| debt:', payload?.debt, '| state:', payload?.state_payment);
console.log('peticiones a sale_payments:', aSalePayments.length ? aSalePayments : 'ninguna');
await browser.close();
