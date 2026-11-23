// T-120: prueba funcional en el panel real (agencia-demo).
// B) Editar la venta 35 con un descuento global mayor que el subtotal: el PATCH real responde 422 y la venta no cambia.
// C) Usuarios: la lista carga; sin rol la pantalla frena; con rol se crea (y se borra al final).
// Uso: RECORRIDO_USUARIO=... RECORRIDO_CLAVE=... node e2e/verif-t120-blindaje.mjs [carpeta-capturas]
import { chromium } from 'playwright-core';
import fs from 'node:fs'; import os from 'node:os'; import path from 'node:path';
const URL_BASE = 'http://agencia-demo.sistemafe.test:5173';
const VENTA = 35;
const salida = process.argv[2] ?? os.tmpdir();
const cache = path.join(os.homedir(), 'AppData', 'Local', 'ms-playwright');
const exe = fs.readdirSync(cache).filter((d) => /^chromium-\d+$/.test(d)).sort().reverse().map((b) => path.join(cache, b, 'chrome-win64', 'chrome.exe')).find((p) => fs.existsSync(p));
const browser = await chromium.launch({ executablePath: exe, headless: true });
const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
page.setDefaultTimeout(10000);
page.on('pageerror', (e) => console.log('ERROR JS:', e.message));
const txt = async (loc) => (await loc.innerText()).replace(/\s+/g, ' ').trim();
const popup = async () => txt(page.locator('.swal2-popup')).catch(() => '(sin ventana)');
const cerrarPopup = async () => { await page.locator('.swal2-confirm').click().catch(() => {}); await page.waitForTimeout(300); };

await page.goto(`${URL_BASE}/admin/login`);
await page.fill('#username', process.env.RECORRIDO_USUARIO); await page.fill('#userpassword', process.env.RECORRIDO_CLAVE);
await page.click('button[type=submit]'); await page.waitForFunction(() => !!localStorage.getItem('token')); await page.waitForTimeout(1500);
const api = (metodo, ruta) => page.evaluate(async ([m, r]) => {
    const base = `http://${location.hostname}/api/`;
    const res = await fetch(base + r, { method: m, headers: { Authorization: 'Bearer ' + localStorage.getItem('token'), Accept: 'application/json' } });
    return { status: res.status, body: await res.json().catch(() => null) };
}, [metodo, ruta]);

// ── B. Descuento global mayor que el subtotal ─────────────────────────
const antes = (await api('GET', `sales/${VENTA}`)).body;
const venta = antes?.sale ?? antes;
console.log(`[B] venta ${VENTA} antes → total: ${venta?.total} | discount_global: ${venta?.discount_global}`);
const respuestas = [];
page.on('response', async (r) => {
    if (r.request().method() === 'PATCH' && /\/sales\/\d+$/.test(new URL(r.url()).pathname)) {
        respuestas.push({ status: r.status(), body: await r.json().catch(() => null) });
    }
});
await page.goto(`${URL_BASE}/sale/edit/${VENTA}`);
await page.waitForSelector('.tabla-lineas tbody tr input[aria-label="Cantidad"]'); await page.waitForTimeout(1500);
await page.locator('button:has-text("descuento")').first().click().catch(() => {});
await page.fill('#tv-desc', '999999'); await page.locator('#tv-desc').press('Tab'); await page.waitForTimeout(500);
// Sin esto la pantalla frena antes («los pagos superan lo que queda por cobrar») y el PATCH no sale.
await page.locator('.panel-cobro table').last().locator('tbody tr').first().locator('button').click(); await page.waitForTimeout(400);
await page.locator('.panel-cobro input[type=number][placeholder="0.00"]:not(#tv-desc)').fill('0');
await page.locator('button:has-text("Guardar cambios")').click(); await page.waitForTimeout(800);
// Si la pantalla pide confirmar algo antes de enviar, se acepta para que llegue el PATCH.
if (!respuestas.length) {
    console.log('[B] la pantalla frenó antes de enviar:', await popup());
    await cerrarPopup(); await page.waitForTimeout(1200);
}
console.log('[B] PATCH →', JSON.stringify(respuestas.map((r) => ({ status: r.status, message: r.body?.message }))));
console.log('[B] aviso en pantalla:', await popup());
await page.screenshot({ path: path.join(salida, 't120-descuento-422.png') });
await cerrarPopup();
const despues = (await api('GET', `sales/${VENTA}`)).body;
const ventaDespues = despues?.sale ?? despues;
console.log(`[B] venta ${VENTA} después → total: ${ventaDespues?.total} | discount_global: ${ventaDespues?.discount_global}`,
    ventaDespues?.total == venta?.total && ventaDespues?.discount_global == venta?.discount_global ? '(sin cambios ✔)' : '(¡CAMBIÓ!)');

// ── C. Usuarios ───────────────────────────────────────────────────────
await page.goto(`${URL_BASE}/users`);
await page.waitForSelector('table tbody tr'); await page.waitForTimeout(800);
console.log('[C] filas en la lista de usuarios:', await page.locator('table tbody tr').count());
const email = `t120.${Date.now()}@agencia-demo.test`;
const abrirAlta = async () => {
    await page.locator('button:has-text("Registrar")').first().click(); await page.waitForSelector('#name-user');
    await page.fill('#name-user', 'Prueba'); await page.fill('#surname-user', 'T120'); await page.fill('#email-user', email);
    await page.fill('#phone-user', '900900900');
    await page.locator('#type_document_list').selectOption({ index: 1 }).catch(() => {});
    await page.fill('#n_document-user', '44556677'); await page.fill('#password-user', 'secreto123');
    await page.locator('#branch_id_list').selectOption({ index: 1 }).catch(() => {});
    await page.locator('label[for="gender-m"]').click().catch(() => {});
};
await abrirAlta();
await page.locator('.modal-footer button:has-text("Guardar"), .modal button.btn-primary').last().click(); await page.waitForTimeout(600);
console.log('[C] sin rol → aviso:', await popup());
await cerrarPopup();

const altas = [];
page.on('response', async (r) => {
    if (r.request().method() === 'POST' && /\/api\/users$/.test(new URL(r.url()).pathname)) altas.push({ status: r.status(), body: await r.json().catch(() => null) });
});
const opcionesRol = await page.locator('#role_list option').allInnerTexts();
const indiceRol = opcionesRol.findIndex((o) => /vendedor|cajero/i.test(o));
await page.locator('#role_list').selectOption({ index: indiceRol > 0 ? indiceRol : 1 });
await page.locator('.modal-footer button:has-text("Guardar"), .modal button.btn-primary').last().click(); await page.waitForTimeout(1500);
const alta = altas[0];
console.log('[C] con rol →', alta?.status, alta?.body?.message, '| role:', JSON.stringify(alta?.body?.user?.role), '| aviso:', await popup());
await cerrarPopup();

if (alta?.body?.user?.id) {
    const borrado = await api('DELETE', `users/${alta.body.user.id}`);
    console.log('[C] limpieza: usuario de prueba borrado →', borrado.status, borrado.body?.message ?? '');
}
await browser.close();
