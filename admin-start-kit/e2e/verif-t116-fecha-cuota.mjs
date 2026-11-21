// T-116 ampl. 4: corregir la fecha de una cuota guardada y las ayudas del cobro de la edición (venta 35 de
// agencia-demo simulada a crédito con 1 cuota al 31-12, como la venta 41 de umbo). El PATCH se intercepta.
// Uso: RECORRIDO_USUARIO=... RECORRIDO_CLAVE=... node e2e/verif-t116-fecha-cuota.mjs [carpeta-capturas]
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
page.on('console', (m) => { if (m.type() === 'error') console.log('CONSOLA:', m.text().slice(0, 200)); });
await page.goto(`${URL_BASE}/admin/login`);
await page.fill('#username', process.env.RECORRIDO_USUARIO); await page.fill('#userpassword', process.env.RECORRIDO_CLAVE);
await page.click('button[type=submit]'); await page.waitForFunction(() => !!localStorage.getItem('token')); await page.waitForTimeout(1500);
const txt = async (loc) => (await loc.innerText()).replace(/\s+/g, ' ').trim();

let payload = null;
await page.route((u) => /\/sales\/\d+$/.test(u.pathname), async (route) => {
    const r = route.request();
    if (r.method() === 'GET') {
        const res = await route.fetch(); const json = await res.json();
        const total = Number(json.sale.total);
        json.sale.type_payment = 2; json.sale.condicion_pago = 'credito'; json.sale.sale_payments = [];
        json.cronograma = [{ numero_cuota: 1, monto_programado: total, fecha_vencimiento: '2026-12-31' }];
        return route.fulfill({ response: res, json });
    }
    payload = JSON.parse(r.postData());
    return route.fulfill({ json: { code: 0, message: 'interceptado' } });
});

await page.goto(`${URL_BASE}/sale/edit/35`);
await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await page.waitForTimeout(1200);
const panel = page.locator('.panel-cobro');
await panel.screenshot({ path: path.join(salida, 'cuota-antes.png') });
console.log('al abrir:', await txt(page.locator('text=Las cuotas suman')));

// 1. La fecha de la cuota se corrige escribiéndola.
const fecha = page.locator('.cronograma-campos table tbody tr').first().locator('input.form-control:visible').last();
await fecha.click(); await fecha.fill('30/11/2026'); await fecha.press('Enter'); await page.mouse.click(10, 10); await page.waitForTimeout(300);
console.log('fecha corregida:', await fecha.inputValue());

// 2. (C) Cambiar el N° de cuotas deja la tabla «desactualizada»; volver al mismo número la deja al día.
const nCuotas = page.locator('.cronograma-campos input[type=number][min="1"]');
await nCuotas.fill('2'); await page.waitForTimeout(300);
console.log('N° 2 → aviso desactualizado:', await page.locator('text=Cambiaste el N° de cuotas').isVisible());
await nCuotas.fill('1'); await page.waitForTimeout(300);
console.log('N° 1 → aviso desactualizado:', await page.locator('text=Cambiaste el N° de cuotas').isVisible());

// 3. (A + E) Pago inicial: aviso de cuándo se guarda, «Todo el saldo», fila con marca «Sin guardar».
console.log('bajo el botón:', await txt(page.locator('text=El pago se guarda al pulsar')));
const metodo = page.locator('.panel-cobro .col-6:has(label:has-text("Método (pago inicial)")) select');
await metodo.selectOption({ index: 0 });
await page.locator('button:has-text("Todo el saldo")').click();
const monto = page.locator('.panel-cobro input[type=number][placeholder="0.00"]');
console.log('monto con «Todo el saldo»:', await monto.inputValue());
await monto.fill('100');
const fechaPago = page.locator('.panel-cobro .col-6:has(label:has-text("Fecha de pago")) input.form-control:visible').first();
await fechaPago.click(); await fechaPago.fill('21/11/2026'); await fechaPago.press('Enter'); await page.mouse.click(10, 10);
await page.locator('button:has-text("Agregar pago inicial")').click(); await page.waitForTimeout(500);
if (await page.locator('.swal2-popup').isVisible()) { console.log('ventana al agregar:', await txt(page.locator('.swal2-popup')), '| método:', await metodo.inputValue(), '| fecha:', await fechaPago.inputValue()); await page.locator('.swal2-confirm').click(); }
console.log('fila del pago:', await txt(page.locator('.panel-cobro table').last().locator('tbody tr').first()));

// 4. (B) La suma ya no calza: dice cuánto sobra y ofrece arreglarlo.
console.log('aviso de suma:', await txt(page.locator('.bg-danger-subtle').first()));
await panel.screenshot({ path: path.join(salida, 'cuota-descuadre.png') });
await page.locator('button:has-text("Ajustar última cuota")').click(); await page.waitForTimeout(300);
console.log('tras ajustar:', await txt(page.locator('text=Las cuotas suman')));
await panel.screenshot({ path: path.join(salida, 'cuota-final.png') });

await page.locator('button:has-text("Guardar cambios")').click(); await page.waitForTimeout(800);
console.log('aviso al guardar:', await txt(page.locator('.swal2-popup')).catch(() => '(sin ventana)'));
console.log('PATCH → cronograma:', JSON.stringify(payload?.cronograma), '| pagos:', JSON.stringify(payload?.payments), '| debt:', payload?.debt);
await page.locator('.swal2-confirm').click().catch(() => {}); await page.waitForTimeout(300);

// 5. Quitar la cuota: la tabla queda vacía y el botón vuelve a «Generar».
await page.locator('[aria-label="Quitar la cuota 1"]').click(); await page.waitForTimeout(300);
console.log('filas tras quitar:', await page.locator('.cronograma-campos table tbody tr').count(),
    '| botón:', await txt(page.locator('.cronograma-campos button.fw-semibold').first()));

// 6. Crédito → Contado: aviso, pago del saldo (en memoria) y el PATCH va como contado.
await page.locator('.panel-cobro .btn-group button:has-text("Contado")').click(); await page.waitForTimeout(400);
console.log('aviso contado:', await txt(page.locator('text=Al guardar, las cuotas de esta venta se anulan')));
await page.locator('.panel-cobro .col-6:has(label:has-text("Método")) select').selectOption({ index: 1 });
await page.locator('button:has-text("Todo el saldo")').click();
await page.locator('button:has-text("Agregar pago")').click(); await page.waitForTimeout(500);
await page.locator('button:has-text("Guardar cambios")').click(); await page.waitForTimeout(800);
await page.locator('.swal2-confirm').click().catch(() => {});
console.log('PATCH contado → type_payment:', payload?.type_payment, '| pagos:', JSON.stringify(payload?.payments), '| debt:', payload?.debt, '| state:', payload?.state_payment);
await browser.close();
