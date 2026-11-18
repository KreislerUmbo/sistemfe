// Recorrido del cotizador (cotizador/editar.vue) — línea de base de T-094 y T-105.
//
// Crea una cotización nueva (siempre igual), recorre la pantalla como un vendedor y guarda,
// después de cada paso, una «huella» de lo que se ve: lienzo (con los valores de los inputs),
// panel de precio, pestañas, chips, drawer y errores de consola. Dos corridas sobre el mismo
// código y los mismos datos dan la misma huella; si un refactor cambia algo visible, la
// comparación lo muestra paso por paso.
//
// Uso (desde admin-start-kit/e2e, con `npm install` hecho una vez):
//   RECORRIDO_USUARIO=… RECORRIDO_CLAVE=… node recorrido-cotizador.mjs --salida resultados/base.json
//   RECORRIDO_USUARIO=… RECORRIDO_CLAVE=… node recorrido-cotizador.mjs --comparar resultados/base.json
// Opciones: --conservar (no borra la cotización creada), --visible (abre el navegador).
// Variables: RECORRIDO_URL (http://agencia-demo.sistemafe.test:5173), RECORRIDO_API_PUERTO (80),
// RECORRIDO_CLIENTE_ID (36), RECORRIDO_MODIFICACION_ID (89: cotización de modificación abierta),
// RECORRIDO_CHROME (ruta a chrome.exe; si falta, se busca el Chromium cacheado de Playwright),
// RECORRIDO_DETALLE=1 (imprime el detalle de Playwright cuando un paso falla).
// Depende de los datos de agencia-demo (biblioteca, mayoristas, guías): la huella se compara
// solo contra corridas sobre la misma base. La cotización de modificación se abre sin
// modificarla: el modal de penalidad solo se recorre si tiene un pasajero quitado.
// Los 5xx se informan como aviso y no cuentan como diferencia (ver vigilancia de respuestas).

import { chromium } from 'playwright-core';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const args = process.argv.slice(2);
const opcion = (nombre) => {
    const i = args.indexOf(nombre);
    return i >= 0 ? (args[i + 1] ?? true) : null;
};

const URL_BASE = process.env.RECORRIDO_URL ?? 'http://agencia-demo.sistemafe.test:5173';
const API_PUERTO = process.env.RECORRIDO_API_PUERTO ?? '80';
const USUARIO = process.env.RECORRIDO_USUARIO;
const CLAVE = process.env.RECORRIDO_CLAVE;
const CLIENTE_ID = Number(process.env.RECORRIDO_CLIENTE_ID ?? 36);
const MODIFICACION_ID = Number(process.env.RECORRIDO_MODIFICACION_ID ?? 89);
const SALIDA = opcion('--salida');
const COMPARAR = opcion('--comparar');
const CONSERVAR = args.includes('--conservar');
const VISIBLE = args.includes('--visible');

if (!USUARIO || !CLAVE) {
    console.error('Faltan RECORRIDO_USUARIO y RECORRIDO_CLAVE (las credenciales nunca van en el repo).');
    process.exit(2);
}

function buscarChrome() {
    if (process.env.RECORRIDO_CHROME) return process.env.RECORRIDO_CHROME;
    const cache = path.join(os.homedir(), 'AppData', 'Local', 'ms-playwright');
    const builds = fs.existsSync(cache) ? fs.readdirSync(cache).filter((d) => /^chromium-\d+$/.test(d)).sort().reverse() : [];
    for (const b of builds) {
        const exe = path.join(cache, b, 'chrome-win64', 'chrome.exe');
        if (fs.existsSync(exe)) return exe;
    }
    throw new Error('No se encontró Chromium: define RECORRIDO_CHROME.');
}

// ─── Huella ────────────────────────────────────────────────────────────────

// Quita lo que cambia entre corridas sin que cambie la pantalla: códigos e ids.
const normalizar = (s) => String(s ?? '')
    .replace(/[A-Z0-9]{4}-\d{4}-\d{7}(-M\d+)?/g, '<codigo>')
    .replace(/\/(cotizaciones|alternativas|items|cotizador|opciones-mayorista|opciones-hotel|reservas|reserva-modificaciones)\/\d+/g, '/$1/<id>')
    .replace(/[ \t]+/g, ' ')
    .replace(/\n\s*\n+/g, '\n')
    .trim();

async function huella(page) {
    const crudo = await page.evaluate(() => {
        const texto = (el) => (el ? el.innerText : null);
        // innerText no incluye el valor de los inputs: se agregan a mano.
        const conValores = (el) => {
            if (!el) return null;
            const valores = [...el.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select')]
                .filter((i) => i.offsetParent !== null)
                .map((i) => (i.tagName === 'SELECT' ? `[${i.selectedOptions[0]?.textContent?.trim() ?? ''}]` : `{${i.value}}`) + (i.disabled ? '(off)' : ''));
            return el.innerText + '\n' + valores.join(' ');
        };
        const panel = document.querySelector('.price-panel');
        const textoPanel = panel ? panel.innerText.split('Convertir a')[0] : null;
        const drawer = [...document.querySelectorAll('body > .modal.show .modal-dialog.modal-xl .modal-content')].find((d) => d.offsetParent !== null);
        return {
            cabecera: texto(document.querySelector('.mb-3 > .d-flex.align-items-start')?.parentElement?.querySelector('.d-flex.flex-wrap.align-items-center.gap-2')) ,
            titulo: texto(document.querySelector('h5.fw-bold')),
            alternativas: [...document.querySelectorAll('.alt-pill')].map((p) => (p.classList.contains('bg-primary') ? '* ' : '') + p.innerText),
            destinos: [...document.querySelectorAll('.destino-chip')].map((p) => (p.classList.contains('bg-primary') ? '* ' : '') + p.innerText),
            dias: [...document.querySelectorAll('.col-lg-8 ul.nav-tabs .nav-link')].map((a) => (a.classList.contains('active') ? '* ' : '') + a.innerText),
            lienzo: conValores(document.querySelector('.col-lg-8 > .card')),
            panel: textoPanel ? textoPanel + '\n' + [...panel.querySelectorAll('input')].map((i) => `{${i.value}}`).join(' ') : null,
            diferencia: texto([...document.querySelectorAll('h6')].find((h) => h.textContent.includes('Diferencia con lo vendido'))?.closest('.card')),
            avisos: [...document.querySelectorAll('.alert')].filter((a) => a.offsetParent !== null).map((a) => a.innerText),
            drawer: drawer ? conValores(drawer) : null,
        };
    });
    const r = {};
    for (const [k, v] of Object.entries(crudo)) r[k] = Array.isArray(v) ? v.map(normalizar) : (v == null ? null : normalizar(v));
    return r;
}

// ─── Espera: sin peticiones en vuelo, sin spinners, sin debounce pendiente ──

function vigilarRed(page) {
    const enVuelo = new Set();
    page.on('request', (r) => { if (['xhr', 'fetch'].includes(r.resourceType())) enVuelo.add(r); });
    const fin = (r) => enVuelo.delete(r);
    page.on('requestfinished', fin);
    page.on('requestfailed', fin);
    // Una petición cortada por una navegación no emite ningún evento: se olvida al navegar.
    page.on('framenavigated', (f) => { if (f === page.mainFrame()) enVuelo.clear(); });
    return enVuelo;
}

async function esperarQuieto(page, enVuelo, minimoMs = 900) {
    const limite = Date.now() + 30000;
    let quietoDesde = null;
    while (Date.now() < limite) {
        const spinners = await page.locator('.spinner-border:visible').count();
        if (enVuelo.size === 0 && spinners === 0) {
            quietoDesde ??= Date.now();
            if (Date.now() - quietoDesde >= minimoMs) return;
        } else {
            quietoDesde = null;
        }
        await page.waitForTimeout(100);
    }
    const spinners = await page.locator('.spinner-border:visible').count();
    throw new Error(`La pantalla no quedó quieta en 30 s (${spinners} spinner(s); en vuelo: ${[...enVuelo].map((r) => r.url()).join(', ') || 'nada'})`);
}

// ─── Recorrido ─────────────────────────────────────────────────────────────

async function main() {
    const browser = await chromium.launch({ executablePath: buscarChrome(), headless: !VISIBLE });
    const context = await browser.newContext({ acceptDownloads: true, viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    const enVuelo = vigilarRed(page);

    const resultado = { pasos: [] };
    let erroresPaso = [];
    let errores5xx = [];
    // Un 5xx se anota aparte con su URL y no entra en la comparación: en local hay un 500
    // intermitente de Apache + dotenv («fe_sendauth: no password supplied») que no depende
    // del código. Si un 500 cambia algo visible, la huella del paso igual lo muestra.
    page.on('response', (r) => { if (r.status() >= 500) errores5xx.push(`${r.status()} ${r.request().method()} ${normalizar(new URL(r.url()).pathname)}`); });
    page.on('console', (m) => {
        if (m.type() !== 'error') return;
        if (/status of 5\d\d/.test(m.text())) return;
        erroresPaso.push(normalizar(m.text()));
    });
    page.on('pageerror', (e) => erroresPaso.push('pageerror: ' + normalizar(e.message)));

    // Cada SweetAlert (modal o toast) que aparece queda anotado en window.__swals al
    // insertarse en el DOM: así no depende de cuánto dura un toast en pantalla.
    await context.addInitScript(() => {
        window.__swals = [];
        new MutationObserver((cambios) => {
            for (const c of cambios) for (const n of c.addedNodes) {
                if (!(n instanceof HTMLElement)) continue;
                const popup = n.matches('.swal2-container') ? n.querySelector('.swal2-popup') : null;
                if (popup) setTimeout(() => window.__swals.push(popup.innerText), 50);
            }
        }).observe(document, { childList: true, subtree: true });
    });
    const swalsVistos = async () => page.evaluate(() => window.__swals.splice(0)).catch(() => []);
    // Confirma el SweetAlert modal que esté abierto (los toasts se van solos).
    async function atenderSwal() {
        const confirmar = page.locator('.swal2-confirm:visible');
        if (await confirmar.count()) {
            await confirmar.click();
            await page.waitForTimeout(300);
        }
    }

    async function paso(nombre, accion) {
        erroresPaso = [];
        errores5xx = [];
        await swalsVistos();
        let fallo = null;
        try {
            await accion();
            await esperarQuieto(page, enVuelo);
            await atenderSwal();
            await esperarQuieto(page, enVuelo);
        } catch (e) {
            fallo = normalizar(e.message.split('\n')[0]);
            if (process.env.RECORRIDO_DETALLE) console.log(e.message.split('\n').slice(0, 16).join('\n'));
        }
        const h = await huella(page);
        const swal = (await swalsVistos()).map(normalizar);
        resultado.pasos.push({ nombre, fallo, ...h, swal, errores_consola: [...erroresPaso], servidor_5xx: [...errores5xx] });
        console.log(`${fallo ? '✗' : '✓'} ${nombre}${fallo ? ' — ' + fallo : ''}${erroresPaso.length ? ` (${erroresPaso.length} error(es) de consola)` : ''}${errores5xx.length ? ` [aviso: ${errores5xx.join(', ')}]` : ''}`);
    }

    const api = async (metodo, ruta, cuerpo) => page.evaluate(async ({ metodo, ruta, cuerpo, puerto }) => {
        const res = await fetch(`${location.protocol}//${location.hostname}:${puerto}/api${ruta}`, {
            method: metodo,
            headers: { Authorization: 'Bearer ' + localStorage.getItem('token'), Accept: 'application/json', 'Content-Type': 'application/json' },
            body: cuerpo ? JSON.stringify(cuerpo) : undefined,
        });
        return { status: res.status, body: await res.json().catch(() => null) };
    }, { metodo, ruta, cuerpo, puerto: API_PUERTO });

    const drawer = page.locator('body > .modal.show .modal-dialog.modal-xl .modal-content:visible');
    const abrirDrawer = async () => {
        if (!(await drawer.count())) await page.getByRole('button', { name: 'Agregar servicio' }).click();
        await esperarQuieto(page, enVuelo);
    };
    const cerrarDrawer = async () => {
        if (await drawer.count()) await drawer.locator('.modal-header .btn-close').click();
    };
    // Los buscadores (ProveedorBuscador, GuiaBuscador, AerolineaSelect, DestinoTreeSelect) eligen con mousedown.
    const elegirDeLista = async (contenedor, texto) => {
        const opcion = contenedor.locator('.list-group button', { hasText: texto }).first();
        await opcion.waitFor({ timeout: 10000 });
        await opcion.dispatchEvent('mousedown');
        await opcion.click({ timeout: 1000 }).catch(() => {});
    };
    const lienzoItem = (texto) => page.locator('.col-lg-8 .canvas-item', { hasText: texto }).first();

    console.log('Login…');
    await page.goto(`${URL_BASE}/admin/login`);
    await page.fill('#username', USUARIO);
    await page.fill('#userpassword', CLAVE);
    await page.click('button[type=submit]');
    await page.waitForFunction(() => !!localStorage.getItem('token'), null, { timeout: 20000 });
    await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 20000 });
    await page.waitForTimeout(1500);

    // Cotización nueva, siempre igual: PEN, 2 adultos + 1 niño, fechas fijas.
    const creada = await api('POST', '/cotizaciones', {
        cliente_id: CLIENTE_ID, destino: 'Tarapoto', fecha_viaje_desde: '2027-03-10', fecha_viaje_hasta: '2027-03-13',
        pasajeros: [{ edad: 30, residencia: 'nacional' }, { edad: 30, residencia: 'nacional' }, { edad: 8, residencia: 'nacional' }],
        alternativa_nombre: 'Base', moneda_cotizacion: 'PEN',
    });
    if (creada.status !== 200) throw new Error('No se pudo crear la cotización: ' + JSON.stringify(creada.body));
    const cotizacionId = creada.body.cotizacion.id;
    console.log(`Cotización de prueba #${cotizacionId}`);

    try {
        await paso('abrir cotización', async () => {
            await page.goto(`${URL_BASE}/agencia-viajes/cotizador/${cotizacionId}`);
            await page.getByRole('button', { name: 'Agregar servicio' }).waitFor();
        });

        await paso('nueva alternativa', async () => {
            await page.locator('.badge', { hasText: 'Nueva' }).click();
            await page.locator('.card', { hasText: 'Valor nuevo (opcional)' }).locator('input[type=text]').fill('Segunda');
            await page.getByRole('button', { name: 'Crear', exact: true }).click();
        });

        await paso('cambiar a la alternativa Base', async () => {
            await page.locator('.alt-pill', { hasText: 'Base' }).click();
        });

        await paso('biblioteca: tour', async () => {
            await abrirDrawer();
            await drawer.locator('.lib-item', { hasText: 'Tour Catarata de Ahuashiyacu' }).click();
        });

        await paso('biblioteca: hotel local con matriz', async () => {
            await abrirDrawer();
            await drawer.locator('.lib-item', { hasText: 'Cumbaza Hotel' }).click();
            await esperarQuieto(page, enVuelo);
            await drawer.locator('.habitacion-matrix tr', { hasText: /doble/i }).getByRole('button', { name: 'Elegir' }).click();
            await drawer.locator('.habitacion-matrix').locator('button', { hasText: /^\s*Agregar\s*$/ }).first().click();
        });

        await paso('biblioteca: proveedor por persona', async () => {
            await abrirDrawer();
            const quitarFiltro = drawer.locator('[data-test=biblioteca-filtro-destino] button');
            if (await quitarFiltro.count()) { await quitarFiltro.click(); await esperarQuieto(page, enVuelo); }
            await drawer.getByPlaceholder('Buscar...').fill('Jerusal');
            await drawer.getByPlaceholder('Buscar...').dispatchEvent('input');
            await esperarQuieto(page, enVuelo);
            await drawer.locator('.lib-item', { hasText: 'Segunda Jerusalén' }).click();
            await esperarQuieto(page, enVuelo);
            await drawer.getByRole('button', { name: /Por persona/ }).click();
        });

        await paso('cerrar drawer', cerrarDrawer);

        await paso('editar precio de un ítem', async () => {
            const input = lienzoItem('Entrada / Ticket de ingreso').locator('input[type=number]').first();
            await input.fill('50');
            await input.dispatchEvent('input');
        });

        await paso('editar precio del tour', async () => {
            const bloque = page.locator('.col-lg-8 .mb-3', { hasText: 'Tour Catarata de Ahuashiyacu' }).first();
            const input = bloque.locator('input[type=number]').first();
            await input.fill('200');
            await input.dispatchEvent('change');
        });

        await paso('descuento global', async () => {
            const input = page.locator('.price-panel input[type=number]').first();
            await input.fill('20');
            await input.dispatchEvent('change');
        });

        await paso('agregar otro día', async () => {
            await page.getByRole('button', { name: 'Agregar otro día' }).click();
        });

        await paso('volver al día 1', async () => {
            await page.locator('.col-lg-8 ul.nav-tabs .nav-link', { hasText: 'Día 1' }).click();
        });

        await paso('agregar destino', async () => {
            await page.locator('.badge', { hasText: 'Agregar destino' }).click();
            const card = page.locator('.card', { hasText: 'Fecha desde' }).filter({ has: page.getByPlaceholder('Buscar destino/atractivo...') }).first();
            const input = card.getByPlaceholder('Buscar destino/atractivo...');
            await input.click();
            await esperarQuieto(page, enVuelo, 400); // el árbol se carga al enfocar
            await input.pressSequentially('Lamas', { delay: 40 });
            await esperarQuieto(page, enVuelo, 400);
            const opcion = card.locator('.list-group button').filter({ hasText: 'Lamas' }).filter({ hasText: 'Lugar' }).first();
            try {
                await opcion.click({ timeout: 5000 });
            } catch (e) {
                throw new Error('No aparece «Lamas · Lugar». Lista: ' + (await card.locator('.list-group button').allInnerTexts()).join(' | ') + ' · input: ' + (await input.inputValue()));
            }
            await card.locator('button', { hasText: /^\s*Agregar\s*$/ }).first().click();
        });

        await paso('cambiar de destino', async () => {
            await page.locator('.destino-chip').first().click();
        });

        await paso('mayorista: crear opción', async () => {
            await abrirDrawer();
            await page.locator('label[for=modoIntl]').click();
            await esperarQuieto(page, enVuelo);
            await drawer.getByRole('button', { name: 'Agregar cotización de mayorista' }).click();
            await drawer.getByPlaceholder('Buscar proveedor mayorista…').click();
            await elegirDeLista(drawer, 'Costamar Peru');
            await drawer.locator('.card', { hasText: 'Proveedor mayorista' }).locator('select').first().selectOption('PEN');
            await drawer.getByPlaceholder(/Descripción para el cliente/).fill('Paquete recorrido');
            await drawer.locator('button', { hasText: /^\s*Agregar\s*$/ }).first().click();
        });

        await paso('mayorista: agregar hotel y elegir habitación', async () => {
            const op = drawer.locator('.mayorista-card', { hasText: 'Costamar Peru' });
            if (!(await op.locator('.habitacion-matrix').count())) await op.getByRole('button', { name: 'Hoteles' }).click();
            await op.getByRole('button', { name: 'Agregar hotel a esta opción' }).click();
            await op.getByPlaceholder('Nombre del hotel', { exact: true }).fill('Hotel Recorrido');
            await op.getByPlaceholder('Costo', { exact: true }).fill('800');
            await op.getByPlaceholder('Venta', { exact: true }).fill('1000');
            await op.getByRole('button', { name: 'Guardar hotel' }).click();
            await esperarQuieto(page, enVuelo);
            await op.locator('.habitacion-matrix tr', { hasText: /doble/i }).getByRole('button', { name: 'Elegir' }).click();
            await op.locator('.habitacion-matrix').locator('button', { hasText: /^\s*Agregar\s*$/ }).first().click();
        });

        await paso('mayorista: segunda opción', async () => {
            await drawer.getByRole('button', { name: 'Agregar cotización de mayorista' }).click();
            await drawer.getByPlaceholder('Buscar proveedor mayorista…').last().click();
            await elegirDeLista(drawer, 'Dkm Xplore');
            await drawer.locator('.card', { hasText: 'Proveedor mayorista' }).last().locator('select').first().selectOption('PEN');
            await drawer.locator('button', { hasText: /^\s*Agregar\s*$/ }).first().click();
        });

        await paso('mayorista: elegir la segunda opción', async () => {
            await drawer.locator('.mayorista-card', { hasText: 'Dkm Xplore' }).getByRole('button', { name: 'Elegir mayorista' }).click();
        });

        await paso('mayorista: volver a elegir la primera', async () => {
            await atenderSwal();
            await esperarQuieto(page, enVuelo);
            await drawer.locator('.mayorista-card', { hasText: 'Costamar Peru' }).getByRole('button', { name: 'Elegir mayorista' }).click();
        });

        await paso('mayorista: opcional al lienzo', async () => {
            await atenderSwal();
            await esperarQuieto(page, enVuelo);
            const op = drawer.locator('.mayorista-card', { hasText: 'Costamar Peru' });
            await op.getByRole('button', { name: /^Opcionales/ }).click();
            await op.getByRole('button', { name: 'Agregar opcional' }).click();
            await op.getByPlaceholder(/Nombre \(ej\. Isla/).fill('Opcional recorrido');
            await op.getByPlaceholder('Precio por persona').last().fill('40');
            await op.locator('.border.rounded.p-2.mt-2').getByRole('button', { name: 'Guardar' }).click();
            await esperarQuieto(page, enVuelo);
            await op.getByRole('button', { name: 'Agregar al lienzo' }).first().click();
        });

        await paso('guía', async () => {
            await abrirDrawer();
            await page.locator('label[for=modoGuia]').click();
            await drawer.getByPlaceholder('Buscar guía…').click();
            await elegirDeLista(drawer, 'ARCE LAZO');
            await esperarQuieto(page, enVuelo);
            await drawer.locator('.lib-item', { hasText: 'Alto Mayo' }).click();
            await drawer.getByRole('button', { name: 'Agregar guía' }).click();
        });

        await paso('cerrar drawer (2)', cerrarDrawer);

        await paso('pasaje aéreo', async () => {
            await page.getByRole('button', { name: 'Pasaje aéreo suelto' }).click();
            const f = page.locator('.pasaje-aereo-form');
            const aerolinea = f.getByPlaceholder('Ej. LATAM');
            await aerolinea.click();
            await aerolinea.pressSequentially('LAT', { delay: 60 });
            await elegirDeLista(f, 'LATAM');
            await f.locator('select').first().selectOption('PEN');
            await f.locator('.row', { hasText: 'Tarifa base adulto' }).locator('input').first().fill('300');
            await esperarQuieto(page, enVuelo);
            await f.getByRole('button', { name: 'Agregar pasaje aéreo' }).click();
        });

        await paso('editar pasajeros', async () => {
            await page.locator('i[title="Agregar o quitar pasajeros"]').click();
            await page.locator('button', { hasText: /^\s*Adulto\s*$/ }).click();
            await page.locator('.card', { hasText: 'Infante' }).getByRole('button', { name: 'Guardar' }).click();
        });

        await paso('editar cabecera', async () => {
            await page.locator('i[title="Corregir cliente/destino/fecha"]').click();
            const card = page.locator('.card', { hasText: 'Fecha hasta' }).filter({ hasText: 'Cliente' }).first();
            await card.locator('.col-md-4 input[type=text]').fill('Tarapoto y Lamas');
            await card.getByRole('button', { name: 'Guardar' }).click();
        });

        let pdf = null;
        await paso('descargar PDF', async () => {
            const [descarga] = await Promise.all([
                page.waitForEvent('download', { timeout: 60000 }),
                page.getByRole('button', { name: 'Ver PDF' }).click(),
            ]);
            const ruta = await descarga.path();
            const bytes = fs.readFileSync(ruta);
            pdf = { nombre: normalizar(descarga.suggestedFilename()), es_pdf: bytes.subarray(0, 4).toString() === '%PDF', tamano_mayor_a_1kb: bytes.length > 1024 };
        });
        resultado.pdf = pdf;

        await paso('modificación de reserva: abrir', async () => {
            await page.goto(`${URL_BASE}/agencia-viajes/cotizador/${MODIFICACION_ID}`);
            await page.locator('h5', { hasText: 'Modificación de la reserva' }).waitFor();
        });

        await paso('modificación de reserva: penalidad', async () => {
            const boton = page.getByRole('button', { name: /^(Cargar penalidad|Editar)$/ }).first();
            if (!(await boton.count())) return;
            await boton.click();
            await esperarQuieto(page, enVuelo);
            resultado.penalidad_modal = normalizar(await page.locator('.modal.show .modal-content').last().innerText());
            await page.locator('.modal.show').getByRole('button', { name: 'Cancelar' }).click();
        });
    } finally {
        if (!CONSERVAR) {
            const borrado = await api('DELETE', `/cotizaciones/${cotizacionId}`);
            console.log(borrado.status < 300 ? `Cotización #${cotizacionId} borrada` : `No se pudo borrar #${cotizacionId}: ${borrado.status}`);
        }
        await browser.close();
    }

    if (SALIDA) {
        fs.mkdirSync(path.dirname(path.resolve(SALIDA)), { recursive: true });
        fs.writeFileSync(SALIDA, JSON.stringify(resultado, null, 2));
        console.log(`Huella guardada en ${SALIDA}`);
    }

    const fallidos = resultado.pasos.filter((p) => p.fallo).length;
    const conErrores = resultado.pasos.filter((p) => p.errores_consola.length).length;
    console.log(`${resultado.pasos.length} pasos · ${fallidos} fallido(s) · ${conErrores} con errores de consola`);

    if (COMPARAR) {
        const base = JSON.parse(fs.readFileSync(COMPARAR, 'utf8'));
        const diferencias = comparar(base, resultado);
        if (diferencias.length) {
            console.log(`\n${diferencias.length} diferencia(s) contra ${COMPARAR}:`);
            for (const d of diferencias) console.log(d);
            process.exit(1);
        }
        console.log(`\nIgual a ${COMPARAR}.`);
    }
    process.exit(fallidos ? 1 : 0);
}

function comparar(base, actual) {
    const out = [];
    const plano = (o, pre = '') => Object.entries(o ?? {}).flatMap(([k, v]) =>
        v && typeof v === 'object' && !Array.isArray(v) ? plano(v, `${pre}${k}.`) : [[`${pre}${k}`, JSON.stringify(v)]]);
    const pasosBase = base.pasos ?? [];
    const max = Math.max(pasosBase.length, actual.pasos.length);
    for (let i = 0; i < max; i++) {
        const a = pasosBase[i] ?? {}, b = actual.pasos[i] ?? {};
        const ma = new Map(plano(a)), mb = new Map(plano(b));
        for (const k of new Set([...ma.keys(), ...mb.keys()])) {
            if (k === 'servidor_5xx') continue;
            if (ma.get(k) !== mb.get(k)) out.push(`· paso ${i + 1} «${b.nombre ?? a.nombre}» → ${k}\n    antes: ${ma.get(k)}\n    ahora: ${mb.get(k)}`);
        }
    }
    for (const k of ['pdf', 'penalidad_modal']) {
        if (JSON.stringify(base[k]) !== JSON.stringify(actual[k])) out.push(`· ${k}\n    antes: ${JSON.stringify(base[k])}\n    ahora: ${JSON.stringify(actual[k])}`);
    }
    return out;
}

main().catch((e) => { console.error(e); process.exit(1); });
