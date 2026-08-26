# Sistema POS / E-commerce — Contexto del proyecto

## Estructura del proyecto
```
sistemafe/
├── CLAUDE.md
├── api-sistema-fe/       → Backend Laravel (API REST, lógica de negocio, SUNAT/Greenter)
└── admin-start-kit/      → Frontend Vue 3 (panel admin/POS, template Rizz/Riz)
```
El portal e-commerce (Vue.js) consume la misma API de `api-sistema-fe/` pero vive en un
repositorio/carpeta separado (no incluido en este workspace).

## Estado actual del proyecto (avance)

> **Nota de mantenimiento (25-ago-2026):** esta sección se comprimió — cada módulo cerrado
> quedó con un resumen corto + un pointer al documento que tiene el detalle completo
> (fase por fase, bugs reales encontrados, evidencia de verificación). Nada se perdió: el
> detalle sigue existiendo en `docs/planning/*/historial-archivo.md`, en los planes de
> módulo activos (`plan-modulo-amortizaciones.md`/`plan-modulo-caja.md`/
> `plan-hoja-de-ruta-ejecucion.md`), o en memoria de proyecto (`[[nombre]]`). Antes de
> asumir que un pendiente ya no aplica, seguir el pointer y confirmar contra el documento
> vigente, no solo contra este resumen.

**Completado (CRUD):**
- Roles y permisos
- Usuarios
- Categorías
- Productos
- Clientes

**Completado — Ventas: matriz de pruebas tributarias (cerrado 2026-07-19):**
Registrar/actualizar/enviar a SUNAT implementado. Matriz de pruebas tributarias
(Bloques A-E) resuelta y automatizada: `resolverTipAfeIgv()` extraída y testeada
(15/15 — la matriz original tenía mal el modelo de variables, `destino_venta` solo
tiene 2 valores reales); `validarRegimenEspecial()` 14/14; FormaPago contado/crédito
5/5 contra Postgres real; Bloques C/E resueltos como no-aplicables al modelo de datos
actual. 3 bugs reales de infraestructura de envío SUNAT corregidos en el camino.
Primera infraestructura de testing real del proyecto (`sistemafe_test_migrations`,
`vitest` en frontend).
Detalle completo: `docs/planning/retail-facturacion-core/historial-archivo.md`.
**Pendiente real:** comunicación de baja SUNAT sin conectar a ningún flujo; producto
real en `umbo` (id=37) con `tip_afe_igv_default` sospechoso, a confirmar con el
contador; migración central que sigue alterando `products` (tabla de tenant),
pregunta arquitectónica abierta.

**Completado — Notas de Crédito/Débito (cerrado 2026-07-14/15):**
Módulo completo: emisión, envío a SUNAT, PDF (A4/ticket 80mm), listado con filtros,
reposición de stock atada a aceptación real de SUNAT. Los 13 motivos del catálogo
SUNAT (09 NC/10 ND) habilitados y validados contra SUNAT BETA real. 3 bugs reales
corregidos (IGV no se reducía con descuento global, `$d->subtotal` como objeto rompía
notas clonadas, tope de cantidad acreditable contaba mal los motivos de valor).
Detalle completo: `docs/planning/retail-facturacion-core/historial-archivo.md`.
**Pendiente real:** ajuste automático de `debt`/`paid_out`/`state_payment` al aceptar
una NC/ND; tabla `client_credit_movements` (saldo a favor del cliente) diseñada, sin
construir.

**Completo — Módulo de Amortizaciones / ventas a crédito (Fases 1-9 de 9, 2026-07-15
a 2026-07-21):**
Cronograma de cuotas, pagos con algoritmo FIFO, anulaciones, devolución con retención
parcial, reemplazo de comprobante con traspaso de pagos, mora on-the-fly. Cerrado con
historial de recibos + PDF, edición de venta a crédito pre-SUNAT, UI de anular pago +
permisos configurables desde Roles, y fix de `sales.state_payment` desincronizado del
módulo (una venta a crédito ya cobrada 100% seguía apareciendo "Pendiente" en Ventas).
Detalle completo, fase por fase: `docs/planning/retail-facturacion-core/
plan-modulo-amortizaciones.md` (documento activo, no archivado — sigue siendo
referencia técnica viva).
**Pendiente real, documentado sin resolver:** `sales.debt`/`paid_out` quedan
congelados al cobrar vía Cuentas por Cobrar (`CreditPaymentController` nunca los
toca) — sin bug visible hoy (ninguna pantalla los lee), pero cualquier reporte futuro
que los use como "saldo actual" leerá un dato viejo. `credit_type='libre'` sigue sin
soportarse (solo `cuotas_fijas`); UI de anular cuota/refund/replace sigue siendo solo
por API directa.

**Completo — Módulo de Caja, Fases 0-6 de 7 (2026-07-18/19):**
Catálogos base (`payment_methods`/`suppliers`/`cash_concepts`), apertura/cierre con
lock + índice único parcial de Postgres, integración con ventas (guard de sesión
abierta + `cash_movement` tipo `correction` en vez de editar/borrar — "ningún
movimiento de dinero debe ser silencioso"), movimientos manuales con aprobación
condicional, reportes (PDF/Excel/dashboard), integración con Adelantos y
Amortizaciones (`CashCorrectionService` compartido, patrón de corrección unificado).
Detalle completo, fase por fase: `docs/planning/retail-facturacion-core/
plan-modulo-caja.md` (documento activo — checklist de activación de Fase 7 en su
sección propia).
**Pendiente real:** Fase 7 (multi-caja simultánea), sin fecha, espera que el negocio
abra una segunda caja real. Deuda técnica sin bloquear ninguna fase: CRUD completo de
`branches`/`cash_registers` (hoy solo listado de solo lectura); bug de
`AuthController::respondWithToken()` (permisos asignados directamente a un usuario no
llegan al frontend — Super-Admin no afectado, ver `project_auth_permissions_bug` en
memoria); filtro de cajero en `history.vue` derivado de sesiones cargadas, no de un
catálogo real de usuarios.

**Completo — Módulo de series de comprobantes, con Nota de Venta interna (cerrado
2026-07-19):**
Resuelve de raíz el bug de concurrencia de `reservarCorrelativo()` con series nuevas
(`serie_comprobantes` con fila semilla `correlativo_actual=0` + `lockForUpdate()` real
sobre esa fila, no `MAX(sales.correlativo)`). Nota de Venta interna: documento no
fiscal y terminal, nunca pasa por `enviarSunat()`, reserva su correlativo de inmediato
en `store()`. Permisos de emisión por tipo de documento, validados en backend, nunca
solo confiados al frontend. `AdvanceController` conectado al mecanismo nuevo el mismo
día (antes generaba su propia serie con un string hardcodeado).
Detalle completo: `docs/planning/retail-facturacion-core/historial-archivo.md`.
**Pendiente real:** completar el Catálogo 01 SUNAT del código 15 en adelante; migrar
NC/ND (07/08) del mecanismo viejo (`note_series`) a este módulo; CRUD completo de
`branches`; reporte SUNAT/PLE (usar siempre `Sale::scopeSoloDocumentosFiscales()`
cuando se construya, nunca inferir por prefijo de `serie`).

**Panel Superadmin (gestión central de tenants) — todas las fases cerradas en su
alcance actual (Fases 0/A/B/B.0.5/B.2/C/D/E, 2026-07-20/21):**
Provisioning (`tenants:provision` + `TenantAdminController`), `Company`/`SunatConfig`
+ certificado por tenant, backups con restauración de fricción intencional (preview →
backup de seguridad obligatorio → restore in-place, nunca `DROP DATABASE`), panel Vue
propio (`central-panel/`) con listado/detalle/audit logs/alta de tenant/
archivar-restaurar/eliminar, y verificación de emisión (`test-emission`) que valida
Company/SunatConfig/certificado sin quemar ningún correlativo SUNAT real antes de
habilitar producción.
Detalle completo, fase por fase: `docs/planning/panel-superadmin/historial-archivo.md`
y `docs/planning/panel-superadmin/plan-panel-superadmin.md` (stub con los pendientes
reales).
**Pendiente real:** decisión de negocio sobre si `test-emission` se vuelve gate
obligatorio antes de `modo=produccion`, o sigue informativo; confirmar/corregir el
`giro` real de `market.umbosystem.com`; mismatch no bloqueante de manejo de errores en
2 vistas (`sale/index.vue`/`advances/show.vue`) que leen el shape anidado de error
distinto al esperado en fallos de red de `enviarSunat()`.

**Completo — URL de API dinámica en dev + URLs de storage tenant-aware (2026-07-31):**
Dev permite tener abierta una pestaña por tenant simultáneamente sin editar `.env` ni
reiniciar Vite (`resolveApiBaseUrl()`, calcula la URL desde el hostname actual). Bug
real de storage multi-tenant corregido: `public/storage` es un symlink ESTÁTICO que
Apache sirve directo, siempre apuntando a la carpeta CENTRAL — cualquier tenant nuevo
daba 403 en sus fotos/avatares sin importar el host de la URL ("umbo" "funcionaba"
solo por archivos duplicados a mano desde antes del split). Resuelto con
`tenant_asset()` + `StorageUrl::resolve()`/`resolveMuchas()` — ver regla completa en
"Cómo trabajar en este proyecto", abajo.
Detalle completo: `docs/planning/historial-archivo.md`.
**Pendiente real:** `SystemCategory`/`ManualRecurso` (modelos centrales) siguen
subiendo sus archivos al disco particionado por tenant, inconsistente con ser datos
centrales que deberían verse igual desde cualquier tenant.

**Completo — spinners de carga, editor de texto enriquecido y cache de preflight CORS
(2026-08-10/11):**
Spinners en toda acción async de `paquetes/detalle.vue`/`cotizador/editar.vue` que no
los tenía. `RichTextEditor.vue` (Quill) reemplaza `<textarea>` en descripciones de
paquetes/destinos. `config/cors.php` publicado con `max_age=3600` — antes el
navegador repetía el preflight OPTIONS en cada request real, duplicando round-trips
en TODAS las pantallas.
Detalle completo: `docs/planning/historial-archivo.md`.
**Hallazgo de rendimiento en dev, diagnosticado y no resuelto (no bloquea nada,
dev-only):** abrir un paquete/tour tarda 2.8-3s — causa dominante es `php artisan
serve` sin workers atendiendo una sola request a la vez, no el N+1 conocido de
`ComboExplosionService` (secundario, ~180ms).

**Backfill comprimido + ítem manual flexible + mover/fusionar servicio entre destinos
(30-jul a 12-ago-2026):**
~23 commits del vertical Agencia de Viajes (cambio arquitectónico de hoteles a
`proveedor_tarifa`, precio por pasajero real, primer `CotizacionController::destroy()`,
ítem manual con costo/cantidad reales + "promover a proveedor", corrección de
asociación destino↔servicio mal hecha sin perder tarifas ya enganchadas).
Detalle completo: `docs/planning/agencia-de-viajes/historial-archivo.md` y
`plan-hoja-de-ruta-ejecucion.md` §3.

**Completo — Facturación de reservas, de punta a punta (filas 11u/11v/11w de la hoja
de ruta de Agencia de Viajes, 2026-08-19/20):**
Cierra el gap de que una reserva aceptada no tenía forma de generar una venta real:
`ReservaFacturacionController::store()` arma Sale/SaleDetail/SaleDetailItem/
ReservaVenta reales (líneas agrupadas por categoría, solo contado, guard
anti-doble-facturación por ítem/pasajero). Guardia tributario (`GET reservas/{id}/
preparar-factura` + validación real en `store()`) bloquea facturar ítems con
tratamiento tributario mezclado en un mismo comprobante. Facturación múltiple: N
`Sale` por reserva, uno por subgrupo de pasajeros/cliente. Split en dos botones,
"Facturar" (simple) vs. "Facturación especial" (selección manual, mismo backend).
Facturación externa por tenant (11w): `tenants.facturacion_habilitada` +
`reserva.facturacion_externa` para tenants que facturan con otro sistema aparte.
Quitar ítems/pasajeros de una reserva ya aceptada (bloqueado si ya facturado).
Detalle completo, fase por fase: `docs/planning/agencia-de-viajes/
plan-hoja-de-ruta-ejecucion.md` §3 (entradas 19/20-ago-2026) y
`docs/planning/agencia-de-viajes/historial-archivo.md`.
**Pendientes reales, documentados sin resolver:** reglas tributarias de la venta
generada son una simplificación conocida (IGV 18%/`destino='nacional'` fijo por
producto placeholder, no por proveedor/destino real de cada ítem); reparto de un ítem
`tarifa_fija` compartido entre pasajeros que terminan en Sales distintos no tiene
ningún mecanismo; bloqueos inconsistentes entre `ReservaItemController`/
`ReservaPasajeroController::destroy()`, sin guard "mínimo 1 ítem", condición de
carrera sin `lockForUpdate()`, `SalidaOperativa` huérfana tras quitar su último ítem —
todo documentado, queda para una sesión futura dedicada a esos dos controllers.

**Completo — Módulo Adelantos: gaps de integridad, conexión con Reservas, selector
fiscal y corrección post-SUNAT (2026-08-24):**
Auditoría propia sobre el módulo construido 2026-07-11/12 — Tier 0 conecta
`reserva_anticipos` (schema puente inerte desde antes) con un controller real y con
`ReservaFacturacionController`; Tier 1 agrega integridad (moneda, locks, permisos) y
un selector de tratamiento tributario que ya no fuerza gravado 18% siempre (resuelve
el hallazgo Amazonía que había quedado pendiente desde la construcción original);
Tier 2 agrega corrección post-SUNAT vía NC motivo 01, preservando el mismo
`Advance.id`; Tier 3 rediseña las 3 pantallas. 226/226 tests backend en verde.
Detalle completo: `docs/planning/retail-facturacion-core/historial-archivo.md` y
`project_advances_module` en memoria de proyecto (mecánica SUNAT no obvia del bloque
`PrepaidPayment`).
**Pendiente real:** validar contra SUNAT Beta un comprobante de adelanto exonerado/
inafecto y una corrección real vía NC motivo 01 (requiere credenciales de tenant
real). Retail (fuera de Agencia de Viajes) sigue sin un mecanismo estructurado de
"adelanto → venta futura" como `reserva_anticipos` — descartado de alcance, no
reconfirmado con el usuario si hace falta.

**Completo — Cotizaciones Comerciales, módulo nuevo (2026-08-25):**
Retira `state_sale` de `Sale` (la cotización comercial dejó de vivir como un estado de
venta) y construye `commercial_quotes`/`commercial_quote_items`/
`commercial_quote_anticipos` como módulo propio, con PDF A4 dedicado. Verificado en
los 5 tenants de producción.
Detalle completo: `docs/planning/retail-facturacion-core/historial-archivo.md` y
`project_cotizaciones_comerciales_modulo` en memoria de proyecto.

**Próximos módulos (en orden de prioridad):**

1. **Representación impresa (PDF) con impresión automática**
   - Generación de PDF con **dos formatos** (A4 y ticket térmico 80mm) **ya implementada**,
     tanto para ventas (`sales-pdf-url/{id}`) como para notas (`notas-pdf-url/{id}`), con
     selector manual de formato al momento de imprimir
     (`usePrintComprobante.ts::imprimirComprobante()`/`imprimirNota()`).
   - **Sin confirmar:** si ya existe el "formato por defecto configurable por usuario/caja"
     persistido en algún lado, o si hoy el default es fijo — verificar antes de cerrar este
     ítem.
   - Método de impresión automática **silenciosa** (sin diálogo del navegador): **pendiente
     de decidir** entre modo kiosco de Chrome (`--kiosk-printing`), servicio local de
     impresión en la PC del cajero, o SDK/ESC-POS directo a la impresora térmica. Lo que hay
     hoy (`window.open()` + `.print()`) abre el diálogo de impresión del navegador, no es
     automático/silencioso.

2. **Compras** — módulo futuro, reglas de negocio aún sin definir.

(Anticipos/Adelantos, Amortizaciones y Caja ya no están en esta lista — están construidos o
en progreso, ver sus secciones propias arriba. Este ítem de "Anticipos" describía un diseño
propuesto que quedó obsoleto en cuanto se construyó el módulo real de Adelantos —
`AdvanceController`, tabla `advances`, integrado con Caja Fase 6 y Panel Superadmin Fase E.)

## Stack técnico
- **Frontend (POS/admin):** Vue 3, template Rizz/Riz basado en Bootstrap 5
- **Frontend (e-commerce):** Portal Vue.js separado, conectado al mismo backend Laravel
- **Backend:** Laravel
- **Base de datos:** PostgreSQL
- **Facturación electrónica:** Integración con Greenter (SUNAT), generación y envío de XML

## Contexto de negocio
- Sistema orientado al mercado peruano, con cumplimiento de facturación electrónica SUNAT
  y reglas tributarias regionales.
- **Exoneración de Amazonía (Ley 27037):** aplica según combinación de naturaleza del
  producto, destino de la venta y si es exportación.
- **`resolverTipAfeIgv()`:** función central que determina el tipo de afectación del IGV
  combinando naturaleza de producto + destino + exportación. Cualquier cambio en reglas
  tributarias regionales probablemente pasa por aquí.
- **`SaleController::update()`:** usa una estrategia de sincronización con transacción DB
  de tres casos. Al modificar ventas existentes, mantener esta estructura transaccional
  para evitar inconsistencias entre la venta, sus detalles y el comprobante SUNAT asociado.

## Módulos principales
- Gestión de clientes y productos
- Registro y edición de ventas
- Generación de comprobantes electrónicos (boleta/factura) vía Greenter
- Portal e-commerce: catálogo, categorías, carrito — consume la misma API Laravel

## Convenciones y preferencias de trabajo
- **Frontend-first cuando sea suficiente:** si un problema se puede resolver de forma
  simple en el frontend (Vue), preferir esa solución antes que modificar el backend.
- **Explicar antes de corregir:** al debuggear, explicar la causa raíz del problema antes
  de aplicar la solución.
- **Comunicación entre frontend y backend:** usar el helper `httpClient` (Axios) para
  llamadas a la API Laravel.
- **Estilos:** cuidado con variables CSS personalizadas en estilos "scoped" de Vue — han
  dado problemas antes; si una variable CSS no se aplica en un componente, considerar
  moverla a `main.css` (global) en vez de depurar el scoping.
- **Timing del DOM en Vue:** evitar `document.getElementById()` directo en el montaje de
  componentes; usar `ref` de Vue + hooks de ciclo de vida (`onMounted`, etc.) para evitar
  errores de timing.
- **URL de la API en desarrollo (`admin-start-kit`):** en dev, `httpClient`/
  `publicHttpClient` calculan la URL de la API en el momento a partir del hostname actual
  (`src/helpers/apiBaseUrl.ts::resolveApiBaseUrl()`, puerto configurable vía
  `VITE_API_DEV_PORT`, default 8000) — así se puede tener abierta una pestaña por tenant
  (`http://umbo.sistemafe.test:5173`, `http://agencia-demo.sistemafe.test:5173`, etc.)
  simultáneamente contra el mismo `npm run dev`, sin editar `.env` ni reiniciar Vite para
  cambiar de tenant. En producción sigue usando `VITE_API_BASE_URL` fijo desde `.env`, sin
  cambios.

## Notas de arquitectura recientes
- Sync de ventas en edición: enfoque de tres casos dentro de una transacción DB en
  `SaleController::update()` (crear/actualizar/eliminar detalles de venta de forma atómica).
- Bugs resueltos recientemente: cálculo de impuestos, manejo de tipos de datos, renderizado
  de modales, alineación de íconos de navegación (flexbox + `<span>` dentro de
  `<router-link>`).

## Cómo trabajar en este proyecto
- **Nunca usar `env('APP_URL')` (ni concatenar host+"/storage/"+path a mano) para construir
  URLs de archivos servidos por tenant (avatar, imagen, foto).** Usar
  `App\Services\StorageUrl::resolve()`/`resolveMuchas()`, que arma la URL con
  `tenant_asset()` (helper de `stancl/tenancy`, ruta `/tenancy/assets/{path}`) — refleja
  siempre el tenant de la petición actual, tanto en el HOST como en el archivo físico que
  sirve. **Motivo, no solo estilo:** `public/storage` es un symlink ESTÁTICO (Apache lo
  sirve directo, sin pasar por Laravel) que apunta siempre a la carpeta CENTRAL
  (`storage/app/public`) — nunca a `storage/tenant{slug}/app/public/...`, que es donde vive
  de verdad el archivo de cualquier tenant creado después del split a multi-tenancy. `/storage/`
  directo da **403 para cualquier tenant salvo "umbo"** (el tenant original, con archivos
  duplicados a mano desde antes del split — por eso ahí "funcionaba", tapando el bug).
  `tenant_asset()` resuelve bien porque la ruta que genera corre DENTRO de una petición
  Laravel bootstrapeada, donde `FilesystemTenancyBootstrapper` ya reescribió `storage_path()`
  al tenant correcto. Requirió un fix adicional en
  `App\Providers\TenancyServiceProvider::configureTenantAssetsMiddleware()`: el paquete
  registra esa ruta con `InitializeTenancyByDomain` hardcodeado, pero este proyecto
  identifica tenants por SUBDOMINIO (`InitializeTenancyBySubdomain`) — sin el override, la
  ruta tira 500 (`TenantCouldNotBeIdentifiedOnDomainException`) para cualquier tenant.
  **`SystemCategoryController`/`ManualRecursoController` quedan fuera de esta regla a
  propósito**: `SystemCategory`/`ManualRecurso` son modelos CENTRALES
  (`CentralConnection`, catálogo/marketplace del SaaS) — no correspondería resolverlos por
  tenant. **Pendiente, no resuelto:** sus archivos SÍ se suben hoy al disco `public`
  suffijado por tenant (`Storage::putFile()` sin disco central dedicado), así que un
  archivo subido mientras se navega bajo un tenant queda físicamente aislado en la
  partición de ESE tenant — inconsistente con que el dato sea central y debería verse
  igual desde cualquier tenant. No es el mismo bug que el de arriba, pero es de la misma
  familia (storage y tenancy no siempre coinciden) — evaluar en una sesión dedicada.
- **Agencia de Viajes — fecha de una `Reserva`:** leer siempre de
  `reserva.fecha_viaje_desde`/`fecha_viaje_hasta` (columnas propias, Fase 1 del fix
  Cotización↔Reserva, 2026-08-18) — **nunca** de
  `reserva.alternativa.cotizacion.fecha_viaje_desde`/`fecha_viaje_hasta`. **Motivo:**
  `Cotizacion.fecha_viaje_desde/hasta` sigue siendo editable sin ningún guard incluso
  después de que la cotización ya generó una reserva
  (`CotizacionController::update()`, a propósito) — antes de esta fase,
  `reserva_items.fecha` se calculaba en vivo contra ese valor, así que cualquier
  corrección posterior de la cotización desincronizaba en silencio la fecha operativa
  ya congelada de la reserva (confirmado con datos reales de prueba en `agencia-demo`:
  reservas con ítems calculados contra 3-4 fechas base distintas entre sí). La relación
  `alternativa.cotizacion` se sigue cargando y su fecha sigue viajando en el JSON
  completo de `ReservaController::show()` — es intencional (refleja la propuesta
  comercial vigente), no un bug; el punto es no leerla como si fuera la fecha de la
  reserva. `reserva_items.fecha_origen` (`'auto'`/`'manual'`) distingue una fecha
  calculada por la fórmula de una editada a mano
  (`ReservaItemController::update()`) — ningún recálculo automático futuro debe pisar
  un ítem `'manual'` sin decisión explícita. Diagnóstico de datos existentes:
  `php artisan agencia-viajes:diagnosticar-fechas-reserva` (solo lectura). Ver
  `docs/planning/agencia-de-viajes/plan-modulo-cotizaciones-reservas.md` y el brief
  "Fix fechas Cotización↔Reserva, FASE 1". **Fase 2 CERRADA (2026-08-19):**
  `POST reservas/{id}/reprogramar` (`ReservaController::reprogramar()`) mueve
  `reserva.fecha_viaje_desde/hasta` y recalcula `reserva_items.fecha` SOLO para los
  ítems `fecha_origen='auto'` — los `'manual'` quedan intactos y vuelven en
  `items_no_tocados` de la respuesta. Re-engancha `SalidaOperativa` de los ítems
  recalculados que cambiaron de fecha (desengancha de la vieja — nunca se borra, puede
  seguir compartida por otra reserva — y reintenta `engancharSalidaOperativa()` con las
  mismas reglas que al aceptar). **No toca `SalidaMayorista.cupo_ocupado`**: confirmado
  leyendo el código antes de escribir la Fase 2 (no asumido del brief) que ese contador
  es por RESERVA completa (`reserva.mayorista_elegida_id`, fijado una única vez al
  aceptar/cancelar, atado a una salida de catálogo con fecha propia), nunca por
  `reserva_item` — no existe ningún camino donde recalcular `reserva_items.fecha` deba
  mover ese cupo. Columnas de auditoría simple en `reserva`
  (`fecha_viaje_desde_original`/`fecha_viaje_hasta_original`/`fecha_reprogramacion`/
  `motivo_reprogramacion`) — mismo trade-off que `fecha_cancelacion`/
  `motivo_cancelacion`: solo la reprogramación más reciente queda visible, no un
  historial completo. `reasignarDia()`/`moverBloque()`
  (`AlternativaItemController.php`) ahora rechazan con 422 explícito si
  `alternativa.estado === 'aceptada'` ("usa reprogramar sobre la reserva en vez de mover
  ítems acá"). Frontend: botón "Reprogramar viaje" + modal en `reservas/detalle.vue`,
  badge "Fecha manual" en los ítems con `fecha_origen='manual'`. Verificado con 8 tests
  nuevos (`ReservaReprogramarTest`, `AlternativaItemBloqueaMoverSiAceptadaTest`) y
  contra datos reales de `agencia-demo` (reserva #12: reprogramada de 2026-08-27 a
  2026-11-01, ítems auto movidos, un ítem marcado manual a mano quedó intacto y listado,
  guard de `reasignarDia()` confirmado con 422 real — datos revertidos a su estado
  original después de verificar).
- Antes de tocar lógica tributaria (IGV, exoneraciones, `resolverTipAfeIgv()`), revisar
  el flujo completo: producto → destino → tipo de operación, ya que estas reglas están
  interrelacionadas y un cambio aislado puede romper otro caso.
- Antes de tocar `SaleController::update()`, confirmar que cualquier cambio mantenga la
  transacción DB atómica (todo-o-nada) entre venta, detalles y comprobante SUNAT.
- Al agregar migraciones nuevas en `database/migrations/tenant/` (`core/` o
  `verticals/*`), correr `php artisan tenants:migrate-verticales` para aplicarlas a los
  tenants ya provisionados — **NO** usar `tenants:migrate` a secas para esto, ese
  comando solo cubre `tenant/core/` (ver `config/tenancy.php`
  `migration_parameters.--path`, hardcodeado) y deja las de `verticals/` sin aplicar en
  silencio (bug real, arrastrado desde el primer vertical — ver
  `arquitectura-multitenant-backend_1.md`).

## Migración a multi-tenancy (stancl/tenancy) — estado al 2026-07-12

### 1. Drift de schema — CERRADO
El schema real de Postgres (dev) y las migraciones en disco estaban desincronizados: 16
tablas sin `Schema::create` en absoluto (`categories`, `clients`, `client_addresses`,
`companies`, `products`, `sale_details`, `sales`, `sale_payments`, `system_categories`,
`systems`, `system_features`, `system_media`, `system_modules`, `plans`, `manual_recursos`,
`services`), más `users`/`orders`/`tax_configs`/`detraction_codes` con columnas incorrectas
o de más. Causa raíz: varias tablas se crearon/editaron directo en Postgres (pgAdmin/SQL
manual) sin pasar nunca por una migración.

Resuelto con 22 migraciones correctivas nuevas (`2026_06_01_*` y `2026_07_13_*`), validadas
contra una base vacía (`sistemafe_test_migrations`) con `migrate:fresh` — 43 migraciones en
total corren sin error, y el diff de `information_schema` contra dev quedó limpio. Backup de
dev tomado antes de tocar nada: `sv_facturacion_20260712_211130.dump`.

3 mejoras aplicadas a dev (fuera de las migraciones, vía `ALTER TABLE` directo + `INSERT` en
el ledger `migrations`, batch 13): `companies.id` ahora tiene secuencia/autoincrement;
`plans.billing_period `/`description ` corregidos (tenían un espacio al final del nombre,
era un typo); `sale_payments` ahora tiene primary key sobre `id`. Las 22 migraciones nuevas
están registradas en `migrations` de dev como ya ejecutadas (su efecto ya existía en dev
antes de escribir las migraciones — nunca deben correrse literalmente contra dev, están
pensadas para `tenants:migrate` sobre bases nuevas).

### 2. Mapa central vs. tenant — CERRADO

**Nota de ubicación física (actualizada 2026-07-20):** la categorización central/tenant de
abajo sigue siendo correcta, pero las tablas centrales listadas ya no viven físicamente en
`sv_facturacion` — se migraron a `db_tenant_central` en Fase B.0.5 del panel superadmin
(`plan-panel-superadmin.md`), junto con `tenants`/`domains`. `sv_facturacion` ya no cumple
ningún rol de infraestructura; solo conserva los datos históricos del negocio original
pre-multitenant.

**Central** (compartido por todos los tenants): `systems`, `system_categories`,
`system_features`, `system_modules`, `system_media`, `plans`, `manual_recursos`
(catálogo/marketplace del SaaS); `tax_configs`, `detraction_codes`, `note_motivos`
(catálogos legales SUNAT).

**Tenant** (dato propio de cada negocio): `products`, `categories`, `clients`,
`client_addresses`, `sales`, `sale_details`, `sale_payments`, `orders`, `order_items`,
`notes`, `note_details`, `note_series` (es configuración propia del negocio, no ley),
`companies`, `users`, `roles`/`permissions`/`model_has_roles`/`model_has_permissions`/
`role_has_permissions` (Spatie), `advances`, `advance_applications`, `advance_refunds`,
`cache`/`cache_locks`, `jobs`/`job_batches`/`failed_jobs`.

**Excluida por completo**: `personal_access_tokens` (Sanctum instalado pero sin usar — 21
tokens de prueba abandonados, ninguno con `last_used_at`; no se migra a ningún lado).

**Referencias cross-boundary detectadas** (tenant → central, sin FK de Postgres posible
entre bases distintas):
- `products.codigo_detraccion` y `sales.codigo_detraccion` → `detraction_codes.codigo`
- `notes.cod_motivo` + `notes.tipo_doc` → `note_motivos.(catalogo, codigo)`
- `tax_configs` no tiene referencia persistida (se lee en vivo, el valor calculado se copia
  a `sales.*`)

### 3. Mecanismo cross-boundary — diseño aprobado

- Los 10 modelos centrales (`DetractionCode`, `NoteMotivo`, `TaxConfig`, `System`,
  `SystemCategory`, `SystemFeature`, `SystemModule`, `SystemMedia`, `Plan`,
  `ManualRecurso`) deben usar el trait `Stancl\Tenancy\Database\Concerns\CentralConnection`
  (fuerza la conexión central sin importar el contexto de tenant activo).
- `Product::belongsTo(DetractionCode::class, ...)` no necesita cambios — Eloquent resuelve
  la conexión desde el modelo relacionado.
- `NotaElectronicaController::validarMotivo()` ya valida `cod_motivo` correctamente — no
  necesita cambios, solo que `NoteMotivo` tenga el trait.
- Gap real encontrado: `codigo_detraccion` (en `products` y `sales`) no tenía ninguna
  validación en PHP — se sostenía 100% por la FK de Postgres. Se agrega un check explícito
  (mismo estilo que `validarMotivo()`) en `ProductController`/`SaleController`.
- **Orden de implementación**: el check de validación de `codigo_detraccion` debe
  implementarse y probarse **antes** de mover `detraction_codes`/`tax_configs` a la base
  central — para no dejar una ventana sin ninguna de las dos protecciones (ni FK de
  Postgres, ni validación de aplicación).

### 4. Pendientes conocidos (no resueltos, fuera del alcance de esta ronda de auditoría)
- `DatabaseSeeder`/`PermissionsDemoSeeder` no están listos para `tenants:seed` — usuario
  admin hardcodeado (`umbosac@gmail.com`/`12345678`) y `Permission::create()` no idempotente.
- `TaxConfigSeeder`/`DetractionCodeSeeder` deberían correr una sola vez contra la base
  central (no por tenant) — falta decidir el mecanismo concreto de cuándo/cómo se disparan.
- Rutas de storage `xml/`/`cdrs/` con nombre fijo para `debug_xml.xml` (bug de concurrencia
  ya detectado en single-tenant, se agrava con multi-tenant) — sin resolver. (El
  particionado por tenant del storage en sí ya funciona vía `FilesystemTenancyBootstrapper`
  para los discos `public`/`private`, esto es específicamente el nombre fijo del archivo de
  debug, no la partición.)
- **CERRADO (2026-07-20, plan-panel-superadmin.md Fase B.1):** Config de SUNAT (RUC/usuario
  SOL/clave SOL) ya no vive en `.env` — `GreenterService::getSee()` la lee de `SunatConfig`
  por tenant (`sandbox`/`umbo` migrados con las credenciales que antes eran implícitas,
  `modo='beta'`). Cada tenant sin `SunatConfig` activo falla explícito al emitir, sin
  fallback a una identidad compartida.
- **CERRADO (2026-07-20, plan-panel-superadmin.md Fase B.2):** el certificado SUNAT ya no
  vive en el disco `public` — disco `private` nuevo (`storage/app/private`, sin symlink
  público), particionado por tenant vía `FilesystemTenancyBootstrapper`. El certificado
  demo central compartido (`certificate-demo.pem`, para tenants en `beta` sin certificado
  propio todavía) sigue con `base_path()` directo a propósito, fuera de este disco.
- **CERRADO (2026-07-30, rama `fix/infra-migracion-verticals-pendientes`, mergeada a
  `main` en `3fc2c6f`):** `config/tenancy.php` `migration_parameters['--path']` está
  hardcodeado a `tenant/core/` — así que el comando genérico `php artisan tenants:migrate`
  (sin `--path` explícito), usado como mantenimiento normal para aplicar migraciones
  **nuevas** a tenants **ya provisionados**, nunca corría `tenant/verticals/*`, para
  ningún tenant, sin importar su `giro`. Bug preexistente desde el primer vertical
  (agencia de viajes, Sesión 2) — se venía compensando con migración manual cada sesión
  (ver hallazgo real de la Sesión 11b4b en
  `docs/planning/agencia-de-viajes/plan-hoja-de-ruta-ejecucion.md`) sin que nadie notara
  que el mecanismo automático estaba roto. Distinto del camino de **provisioning
  inicial** (`TenantProvisioningService::provision()` → `migrarVertical()`), que sí
  funciona bien porque arma un `--path` explícito por tenant según su `giro` en el
  momento de crearlo.
  **Fix**: comando nuevo `php artisan tenants:migrate-verticales`
  (`app/Console/Commands/MigrateVerticalesPendientes.php`) — reemplaza a `tenants:migrate`
  a secas como comando de mantenimiento de acá en adelante (ver checklist en "Cómo
  trabajar en este proyecto", arriba). Corre `tenant/core/` para todos los tenants, agrupa
  por `giro`, y corre `tenant/verticals/{giro}/` con `--path` explícito por grupo —
  reutiliza `TenantProvisioningService::rutaVertical()` (extraído de `migrarVertical()`,
  antes privado, para no duplicar el mapeo snake_case→kebab-case). Idempotente por diseño.
  Test de cobertura real (`tests/Feature/MigrateVerticalesPendientesTest.php`): crea 2
  tenants físicos descartables (uno `agencia_viajes`, uno `retail`), confirma que las
  tablas de vertical llegan solo al que corresponde. Corrido de verdad contra los tenants
  reales existentes (`negocio2`/`umbo`/`umbo-archivado`/`sandbox`/`agencia-demo`): puso al
  día un backlog de `tenant/core/` acumulado en varios de ellos; `agencia-demo` ya estaba
  al día en `verticals/agencia-viajes/` (venía del workaround manual de la Sesión 11b4b),
  confirmando el fix sin necesitar backfill adicional ahí. Detalle completo en
  `docs/planning/arquitectura-multitenant-backend_1.md`.
