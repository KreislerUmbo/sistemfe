# Plan — Módulo de Series de Comprobantes (con Nota de Venta interna)

> **✅ COMPLETO — construido, verificado y migrado a `umbo` real el 2026-07-19.** Los 6
> pasos originales (0-4 + ajustes de UI) están cerrados, con 2 gaps reales encontrados y
> cerrados el mismo día (`AdvanceController` desconectado del módulo, preview de serie
> faltante en el form). Ver CLAUDE.md para la narrativa completa sesión por sesión; este
> documento es la referencia de diseño/esquema, ya as-built.

## 1. Objetivo y alcance

Dos problemas resueltos en un mismo módulo:

1. **Series de comprobantes asignadas por sucursal** (no por usuario/cajero), con fila
   semilla creada explícitamente antes de cualquier venta — cierra de raíz el bug de
   concurrencia real de `reservarCorrelativo()` (serie nueva sin fila previa → dos
   requests podían calcular ambos `correlativo=1`).
2. **Nota de venta**: tipo de documento interno y terminal (nunca se convierte en
   factura/boleta) para productos/servicios sin sustento de compra — mueve stock/kardex y
   participa del circuito de crédito igual que cualquier venta, pero nunca pasa por
   `enviarSunat()`.

**Fuera de alcance, confirmado explícitamente:**
- Migrar NC/ND (`07`/`08`) al mecanismo nuevo — siguen usando `note_series`/
  `SerieNotaResolver` (que ya anticipaba en su propio comentario que este módulo lo
  reemplazaría algún día). `tipos_comprobante` ya tiene `07`/`08` sembrados con
  `activo_greenter=true` para cuando se aborde esa migración.
- Completar el Catálogo 01 SUNAT más allá del código `14` — no se adivinó, queda pendiente
  explícito (ver sección 8).
- Permisos de emisión para el flujo de Adelantos — el tipo se deriva del cliente, no se
  elige, así que no aplica el mismo gate que en ventas normales (ver sección 5).

---

## 2. Modelo de datos

```
tipos_comprobante                              -- CENTRAL (mismo criterio que note_motivos/
                                                   detraction_codes/tax_configs — catálogo
                                                   legal idéntico para todos los tenants)
- codigo (PK string)         -- '00'..'14' Catálogo 01 SUNAT, más 'NV'
- nombre
- es_documento_sunat (bool)  -- true para TODO el Catálogo 01 real; false solo para NV.
                                 Este es el campo que decide si SaleController::store()
                                 llama a enviarSunat() — NO activo_greenter.
- activo_greenter (bool)     -- true SOLO en '01'/'03'/'07'/'08' (únicos setTipoDoc() reales
                                 en GreenterService, confirmado por grep). NV siempre false.
CHECK real de Postgres: activo_greenter=true → es_documento_sunat=true (nunca al revés).

serie_comprobantes                             -- TENANT, instancia operativa por sucursal
- id
- branch_id (FK real, misma base)
- tipo_comprobante_codigo    -- referencia cross-boundary a tipos_comprobante (central),
                                 SIN FK real de Postgres — mismo caso que
                                 products.codigo_detraccion → detraction_codes. Se valida
                                 en la app (SerieComprobanteService/Controller), no en BD.
- moneda ('PEN'/'USD')
- serie                      -- 'F001', 'B001', 'NV001'... prefijo validado SOLO si
                                 es_documento_sunat=true (F/B para 01/03, F o B para 07/08
                                 según el comprobante afectado); libre para NV.
- correlativo_actual          -- FILA SEMILLA: arranca en 0 para una serie nueva sin ventas
                                 previas. En un tenant CON historial real, debe sembrarse
                                 con el MAX(correlativo) real de esa serie, nunca en 0 (ver
                                 incidente real, sección 7).
- correlativo_inicial         -- solo referencia/config de arranque, no se usa para calcular
                                 el primer correlativo real.
- fecha_inicio, activo
unique(branch_id, tipo_comprobante_codigo, moneda) — una sucursal puede tener 2 series del
mismo tipo solo si la moneda es distinta (práctica contable interna, NO requisito SUNAT).

sales.tipo_comprobante_codigo   -- fuente de verdad del tipo, nunca se infiere del prefijo
                                    de 'serie' en código nuevo (reportes, enviarSunat()).
sales.serie_comprobante_id      -- FK real (misma base tenant) a la fila EXACTA de
                                    serie_comprobantes usada — necesaria para que
                                    enviarSunat() no tenga que re-derivar branch/tipo/moneda
                                    al momento del envío (ambiguo si el usuario que envía no
                                    es el mismo que creó la venta, o si la venta cambió de
                                    sucursal). Ambas columnas nullable, sin backfill — no
                                    hay datos que migrar, se llenan hacia adelante desde el
                                    día del deploy.

users.branch_id                 -- sucursal fija del usuario (editable en su form de
                                    usuarios). Determina qué series ve register.vue/edit.vue
                                    salvo permiso can_switch_branch.
```

---

## 3. Decisiones de diseño (ya tomadas, no reabrir)

- **Reserva de correlativo, split por tipo** — no es uniforme:
  - Documentos **fiscales** (`es_documento_sunat=true`): el correlativo se reserva recién en
    `enviarSunat()`, igual que siempre — nunca en `store()`. Protege contra correlativos
    huérfanos (venta creada y luego eliminada sin enviar nunca quemaría un número real).
  - **Nota de venta**: se reserva **de inmediato** en `store()`, porque nunca existe un paso
    de "enviar" posterior para ella. Nunca setea `n_operacion` (exclusivo del envío SUNAT
    real).
- **`reservarCorrelativo()`** (`FacturacionElectronicaController`) tiene dos caminos:
  - Con `serie_comprobante_id` poblado → `lockForUpdate()` sobre la fila de
    `serie_comprobantes` vía `SerieComprobanteService`. Este camino **debe** sincronizar
    `sales.correlativo` de inmediato (`$venta->update(['correlativo' => ...])`) — un bug
    real de esta clase se coló en el primer intento (ver sección 7).
  - Sin `serie_comprobante_id` (venta creada antes de este módulo, sin backfill) → fallback
    legado intacto (`MAX(sales.correlativo)`), preservado para no romper el envío de una
    venta vieja pendiente.
- **Permisos de emisión** (`emitir_factura`/`emitir_boleta`/`emitir_nota_venta`, sin entrada
  para `07`/`08` — esos van por `NotaElectronicaController`): filtran el selector en
  `register.vue`/`edit.vue` Y se validan de nuevo en `SaleController::store()` — nunca se
  confía solo en que el frontend filtre.
- **Editar el tipo de documento de una venta ya creada** (`SaleController::update()`):
  permitido con las mismas reglas que `store()` **mientras `correlativo === null`** (mismo
  momento exacto para fiscales y NV). Con correlativo ya reservado, 422 explícito si el
  payload intenta cambiar el tipo.
- **`SerieComprobanteService::resolverParaUsuario()`**: resolución compartida de
  sucursal+tipo+serie, **sin** el chequeo de permiso bundleado — cada llamador valida su
  propio permiso porque la semántica es distinta (el usuario ELIGE en `SaleController`,
  el tipo se DERIVA del cliente en `AdvanceController`).

---

## 4. Fases (as-built)

- **Paso 0** — Auditoría de `register.vue`/`edit.vue`: confirmó que la serie era un
  `<select>` de texto libre (`F001`/`B001` hardcodeado), sin ningún concepto de sucursal en
  ventas, y que `tipo_comprobante` estaba acoplado 1:1 al prefijo de `serie` en
  `enviarSunat()`.
- **Paso 1** — Migraciones (`tipos_comprobante` central + `serie_comprobantes`/columnas de
  `sales` tenant), validadas con rollback/reapply real contra `sistemafe_test_migrations`
  antes de tocar cualquier tenant.
- **Paso 2** — `SerieComprobanteService`, `reservarCorrelativo()` con el split fiscal/NV,
  guard de `es_documento_sunat` en `enviarSunat()`.
- **Paso 3** — CRUD de series (`SerieComprobanteController`/`TipoComprobanteController`,
  frontend en `views/series-comprobante/`), permisos nuevos.
- **Paso 3.5** — `users.branch_id` + selector en el form de usuarios, permisos de emisión,
  selector de tipo de documento en `register.vue`/`edit.vue` con ocultamiento/reseteo de
  campos fiscales para NV, candado de `update()`.
- **Paso 3.6** — `Sale::scopeSoloDocumentosFiscales()` como regla reusable para el futuro
  reporte PLE (que no existe todavía).
- **Paso 4** — Tests formales contra `sistemafe_test_migrations` (52 casos, ver sección 6).
- **Cierre del mismo día — dos gaps reales encontrados y cerrados**:
  - `AdvanceController::store()` (comprobante propio de un adelanto) creaba su `Sale` al
    margen completo del módulo — conectado vía `SerieComprobanteService::resolverParaUsuario()`
    + guard defensivo (adelanto nunca puede resolver a NV).
  - Preview en vivo de la serie resuelta (`GET sales/serie-preview`) — el `<select>` viejo
    mostraba "F001 — Factura" directamente; el nuevo selector de tipo de documento no decía
    nada por sí solo. Agregado a `register.vue`/`edit.vue`.

---

## 5. Adelantos — por qué no llevan permiso de emisión

`AdvanceController::store()` deriva el tipo de comprobante directo de
`cliente->cod_tipo_doc_sunat` (`'6'` → factura, si no → boleta) — misma condición exacta
que ya existía antes de este módulo, nunca elegida por el usuario en un `<select>`. Por eso
NO se le agregó ningún permiso `emitir_*` nuevo: decisión explícita de alcance, no un
descuido. Guard defensivo agregado de todos modos: un adelanto nunca puede resolver a un
tipo `es_documento_sunat=false` — estructuralmente inalcanzable hoy (la derivación solo
produce `'01'`/`'03'`), pero protege contra un cambio futuro que lo permita por accidente
(el IGV de un adelanto ya nació al recibirse el pago).

---

## 6. Tests (52 casos, `sistemafe_test_migrations`)

- `TiposComprobanteCatalogTest` (5): catálogo sembrado correcto, invariante CHECK real.
- `SerieComprobanteServiceTest` (10): resolución, reserva secuencial (fiscal y NV), unique
  constraint, lock real entre dos conexiones Postgres sobre una serie NV.
- `SaleControllerSerieComprobanteTest` (7): permisos de emisión, sucursal, `store()` de
  punta a punta con venta NV a crédito (stock descontado, cronograma generado, correlativo
  inmediato, cero `n_operacion`, cero invocación a Greenter).
- `AdvanceControllerSerieComprobanteTest` (4): adelanto RUC→factura y no-RUC→boleta con
  `serie_comprobante_id` poblado; `reservarCorrelativo()` real sobre la venta del adelanto
  confirma que usa el mecanismo nuevo, no el fallback; lock real de dos conexiones sobre la
  serie que usaría un adelanto.
- Preexistentes sin regresión: `ReservarCorrelativoTest`, `GreenterServiceFormaPagoTest`,
  `EnviarSunatCdrFailureTest`, `ValidarRegimenEspecialTest` (26 casos).

**Nota técnica para quien reabra estos tests**: `TipoComprobante` usa `CentralConnection` —
cualquier test que lo toque necesita redirigir TAMBIÉN la conexión `central` (no solo
`pgsql`) a `sistemafe_test_migrations`, o las consultas van a `sv_facturacion` real por
accidente.

---

## 7. Incidentes reales encontrados en el camino (no solo diseño de mesa)

- **`Sale::$fillable`** nunca incluyó `tipo_comprobante_codigo`/`serie_comprobante_id` —
  habría descartado ambos campos en silencio por protección de asignación masiva (mismo
  patrón que el bug de `Company::$fillable` en Caja Fase 4). Encontrado releyendo el modelo
  antes de escribir los tests, no por un test que fallara.
- **Bug real del propio refactor de `reservarCorrelativo()`**: el camino nuevo (con
  `serie_comprobante_id`) incrementaba `serie_comprobantes.correlativo_actual` pero nunca
  sincronizaba `sales.correlativo` de vuelta — a diferencia del camino legado. Como
  `enviarSunat()` nunca vuelve a tocar esa columna en su propio `update()` de éxito (confía
  en que `reservarCorrelativo()` ya la dejó puesta), esto habría dejado `sales.correlativo`
  en `NULL` para siempre en cualquier venta fiscal real bajo el mecanismo nuevo —
  `n_operacion` sí queda bien (se arma desde variable local, no desde la columna), así que
  el bug habría pasado desapercibido en un vistazo rápido. Detectado por
  `test_correlativo_del_adelanto_se_reserva_via_servicio_nuevo_no_fallback` antes de tocar
  ningún tenant real.
- **Migración a `umbo` real (2026-07-19)** — mismo patrón ya documentado con Caja/
  `payment_methods` (migraciones nunca corridas contra el tenant real hasta que el usuario
  reportó el síntoma en la app de verdad). Dos riesgos reales encontrados al aprovisionar:
  - Sembrar `serie_comprobantes.correlativo_actual` en `0` para `F001`/`B001` habría
    duplicado un correlativo que SUNAT ya tenía aceptado de verdad (máximo real: 29 y 4).
    Se sembró continuando la secuencia real, no en 0.
  - El guard de `branch_id` en `resolverSerieComprobante()` **no es un permiso** —
    `Gate::before` (Super-Admin bypasea `->can(...)`) no lo cubre. Sin asignar
    `users.branch_id` a los usuarios reales existentes, ni siquiera Super-Admin podía crear
    una venta. Ver `project_umbo_tenant_dev_env.md` (memoria) para el detalle completo.

---

## 8. Pendientes explícitos (no bloquean el módulo, no resolver sin pedirlo aparte)

- Completar el Catálogo 01 SUNAT del código `15` en adelante (espectáculos públicos,
  retenciones, etc.) — no adivinado a propósito, es una tabla de referencia legal.
- Migrar NC/ND (`07`/`08`) de `note_series`/`SerieNotaResolver` a este módulo — el propio
  comentario de `note_series` ya anticipaba este reemplazo.
- CRUD administrable completo de `branches` (hoy solo listado de solo lectura, heredado de
  Caja Fase 5) — si este módulo llega a necesitar crear una sucursal nueva desde la UI, no
  existe todavía.
- Reporte Registro de Ventas SUNAT/PLE (no construido) — usar siempre
  `Sale::scopeSoloDocumentosFiscales()` cuando se construya, nunca inferir por prefijo de
  `serie`.
