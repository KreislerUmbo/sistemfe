// T-110: comprueba en la pantalla nueva que agregar y quitar un pago manda POST/DELETE sale_payments (sin tocar datos: respuestas simuladas).
import { chromium } from 'playwright-core';
import fs from 'node:fs'; import os from 'node:os'; import path from 'node:path';
const URL_BASE = 'http://agencia-demo.sistemafe.test:5173';
const cache = path.join(os.homedir(), 'AppData', 'Local', 'ms-playwright');
const exe = fs.readdirSync(cache).filter((d) => /^chromium-\d+$/.test(d)).sort().reverse().map((b) => path.join(cache, b, 'chrome-win64', 'chrome.exe')).find((p) => fs.existsSync(p));
const browser = await chromium.launch({ executablePath: exe, headless: true });
const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
await page.goto(`${URL_BASE}/admin/login`);
await page.fill('#username', process.env.RECORRIDO_USUARIO); await page.fill('#userpassword', process.env.RECORRIDO_CLAVE);
await page.click('button[type=submit]'); await page.waitForFunction(() => !!localStorage.getItem('token')); await page.waitForTimeout(1500);
const vistos = [];
await page.route((u) => /\/sale_payments(\/\d+)?$/.test(u.pathname), async (route) => {
    const r = route.request(); vistos.push({ metodo: r.method(), url: new URL(r.url()).pathname.split('/api')[1] ?? r.url(), cuerpo: r.postData() ? JSON.parse(r.postData()) : null });
    if (r.method() === 'POST') await route.fulfill({ json: { code: 200, payment: { id: 9100, sale_id: 27, method_payment: 'YAPE', amount: '50.00', date_payment: '', nro_operacion: 'OP-1' } } });
    else await route.fulfill({ json: { code: 200 } });
});
await page.goto(`${URL_BASE}/sale/edit/27`);
await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await page.waitForTimeout(800);
await page.selectOption('.panel-cobro select.form-select >> nth=0', 'YAPE').catch(() => {});
await page.locator('.panel-cobro input[type=number]').first().fill('50');
await page.locator('.panel-cobro input[placeholder="Opcional"]').fill('OP-1');
await page.click('text=Agregar pago'); await page.waitForTimeout(600);
console.log('filas de pagos:', await page.locator('.panel-cobro table tbody tr').count());
await page.screenshot({ path: 'resultados/edicion-nueva.png', fullPage: false });
await page.locator('.panel-cobro table tbody tr button').first().click(); await page.click('.swal2-confirm'); await page.waitForTimeout(600);
console.log(JSON.stringify(vistos, null, 1));
await browser.close();
