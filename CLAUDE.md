# Sistema POS / E-commerce / verticales — Contexto del proyecto

> **Proceso de trabajo:** `docs/proceso.md`. Cada dato vive en un solo lugar: el **estado** de las
> tareas solo en `docs/backlog.md`; tareas en `docs/tareas/` con `docs/plantillas/tarea.md`;
> historial en `git log`. Este archivo tiene contexto, reglas y trampas — **no** avance de módulos.
> El estado e historial que vivían acá (hasta 29-oct-2026) están en
> `docs/archivo/CLAUDE-hasta-2026-10-29.md`: consultarlo solo si hace falta el detalle de un
> módulo; sus pendientes que no estén en el backlog están sin verificar.

## Estructura
```
sistemfe/
├── CLAUDE.md
├── api-sistema-fe/    → Backend Laravel (API REST, negocio, SUNAT/Greenter), multi-tenant (stancl/tenancy)
├── admin-start-kit/   → Frontend Vue 3 (panel admin/POS, template Rizz/Bootstrap 5)
└── central-panel/     → Panel Vue del Super-Admin (gestión de tenants)
```
El portal e-commerce (Vue) consume la misma API pero vive fuera de este workspace.
Referencia del template completo: `admin-version-full/` (gitignored).

## Stack
Laravel + PostgreSQL · Vue 3 (Rizz) · Greenter (SUNAT) · DomPDF · stancl/tenancy (un tenant por
subdominio, una base Postgres por tenant + `db_tenant_central`).

## Módulos y dónde leer antes de tocarlos
| Módulo | Leer primero |
|---|---|
| Ventas / SUNAT / NC-ND / Series | `docs/planning/retail-facturacion-core/` (historial-archivo.md) |
| Caja | `docs/planning/retail-facturacion-core/plan-modulo-caja.md` |
| Amortizaciones (ventas a crédito) | `docs/planning/retail-facturacion-core/plan-modulo-amortizaciones.md` |
| Agencia de Viajes (giro `agencia_viajes`) | `docs/planning/agencia-de-viajes/00-mapa-de-modulos.md`; tours: `plan-tours-venta-simple-costo-real.md` §6 |
| Créditos (giro `creditos`, prestamista) | `docs/planning/creditos/00-reglas-y-modelo.md` (fuente de verdad) |
| Panel Superadmin | `docs/planning/panel-superadmin/` |
| Compras (diseño cerrado, sin construir) | `plan-tours-venta-simple-costo-real.md` §3.7-§3.8 |
| Manual de código para el usuario | `docs/aprendizaje/` |

## Contexto de negocio
- Mercado peruano, facturación electrónica SUNAT.
- **Exoneración Amazonía (Ley 27037):** según naturaleza del producto + destino + exportación.
  `resolverTipAfeIgv()` es la función central; un cambio aislado puede romper otro caso: revisar
  el flujo producto → destino → tipo de operación completo. **En Agencia, exonerado (20) =
  Amazonía** (trait `ExoneradoEsAmazonia`); retail no cambia.
- Guardar una venta **no** la envía a SUNAT (envío manual, a propósito). Nota de Venta = documento
  interno, nunca pasa por `enviarSunat()`. Reportes SUNAT/PLE: `Sale::scopeSoloDocumentosFiscales()`,
  nunca inferir por prefijo de serie.
- El Super-Admin es soporte de UmboSystem; los dueños usan el rol Administrador.

## Convenciones
- Comentarios, mensajes y respuestas en **español**.
- **Frontend-first** cuando alcance; **explicar la causa raíz antes de corregir**.
- API desde Vue: `httpClient`/`publicHttpClient` (Axios). En dev la URL sale del hostname
  (`src/helpers/apiBaseUrl.ts::resolveApiBaseUrl()`, `VITE_API_DEV_PORT`), una pestaña por tenant
  (`http://<tenant>.sistemafe.test:5173`); en producción `VITE_API_BASE_URL`.
- Variables CSS que no se aplican en `<style scoped>`: moverlas a `main.css`.
- Nada de `document.getElementById()` al montar: `ref` + `onMounted`.
- UI: clases de Agencia/Ventas (inputs `-sm`, cards con `card-header`, fechas con `CampoFecha.vue`).

## Reglas que no se negocian

**Fechas y horas.** Todo instante se guarda en **UTC** y se muestra en hora de Perú (UTC−5).
1. Fecha de negocio "hoy": `HoraPeru::hoyTexto()` / `HoraPeru::hoy()` (Créditos: `Creditos\Reloj`).
   **Nunca** `now()`/`today()`/`now()->toDateString()` (de 19:00 a 24:00 ya es mañana en UTC).
2. Filtrar un instante por día: `HoraPeru::inicioDiaUtc($dia)` / `finDiaUtc($dia)`; nunca `whereDate`.
3. Formatear en servidor (PDF, Excel, texto): `HoraPeru::deUtc($col)->format(...)`.
Frontend: `formatFechaHora()`, `hoyPeru()`, `sumarDiasISO()` de `helpers/fecha.ts`; nunca
`new Date().toISOString().slice(0,10)`. **Prohibido `date_default_timezone_set`.**
`tests/Unit/ReglaFechasNegocioTest.php` falla si reaparecen. Excepción: `credito_pagos.fecha_pago`
es fecha-hora de negocio en hora Lima.

**Archivos por tenant.** Nunca `env('APP_URL')` ni host + `/storage/` + path: usar
`StorageUrl::resolve()`/`resolveMuchas()` (`tenant_asset()`); dentro de un PDF,
`StorageUrl::resolveParaPdf()` (DomPDF no carga URLs remotas). `public/storage` apunta siempre a
la carpeta central → 403 para cualquier tenant salvo `umbo`. `SystemCategory`/`ManualRecurso` son
centrales y quedan fuera de esta regla.

**Dinero y caja.** Ningún movimiento de dinero es silencioso: corregir con `cash_movement` tipo
`correction` (`CashCorrectionService`), nunca editar/borrar. Correlativos con lock real
(`SerieComprobanteService`, `CodigoGeneradorService`), nunca `MAX()`.

**Ventas.** `SaleController::update()` sincroniza venta/detalles/comprobante en **una**
transacción (crear/actualizar/eliminar): no romper la atomicidad.

**Agencia.**
- Fecha de una reserva: `reserva.fecha_viaje_desde/hasta`, **nunca** la de la cotización.
  `reserva_items.fecha_origen='manual'` no se recalcula sin decisión explícita; mover fechas de una
  reserva = `reservas/{id}/reprogramar`.
- Toda acción sobre una reserva pasa por `ReservaPolicy`. "Qué está facturado":
  `ReservaVenta::itemIdsFacturados()`/`pasajeroIdsFacturados()`/`itemIdsDe()`/`pasajeroIdsDe()`/
  `liberarPorNotaDeCredito()` — no reescribirlo a mano. Quitar pasajero: `QuitarPasajeroReservaService`.
- Listados comerciales: `Cotizacion::delCotizador()` (excluye `origen='venta_directa'`).
- Compras: **no** construir sobre `cronograma_pago_proveedor`/`pago_proveedor` ni ampliar
  `suppliers`: se reemplazan.

**Créditos.** No fijar `tasa_maxima` en credishirley hasta corregir la validación por unidades
(TEA), ver `00-reglas-y-modelo.md` §5.

## Multi-tenancy
- **Central** (`db_tenant_central`): `tenants`, `domains`, catálogo SaaS (`systems*`, `plans`,
  `manual_recursos`) y catálogos SUNAT (`tax_configs`, `detraction_codes`, `note_motivos`). Sus
  modelos usan el trait `CentralConnection`.
- **Tenant:** todo lo del negocio (productos, clientes, ventas, notas, caja, usuarios, roles
  Spatie, verticales…). Referencias tenant → central sin FK (p. ej. `codigo_detraccion`): validar en PHP.
- `sv_facturacion` solo guarda datos históricos pre-multitenant.
- Migraciones nuevas en `database/migrations/tenant/` (`core/` o `verticals/*`): aplicar con
  **`php artisan tenants:migrate-verticales`**, nunca `tenants:migrate` a secas (solo cubre `core/`).
  Nunca editar una migración existente.
- Config SUNAT por tenant en `SunatConfig`; certificado en el disco `private`.

## Entorno local (trampas)
- **No correr `composer install`/`update`**: el lock es para PHP 8.5 (prod); local es 8.2.
- Tests contra Postgres real (`sistemafe_test_migrations`); no correr dos suites completas a la vez.
  Intermitente conocido: `Fila13Fase1cFacturacionTest::descuento_del_combo…`.
- Dev del backend: Apache (puerto 80), no `php artisan serve` (un solo hilo).
- PDFs: verificar a la vista con Ghostscript (`gswin64c.exe`).
- Producción: `deploy.sh` sigue aunque falle `tenants:migrate-verticales` — revisar su salida.
