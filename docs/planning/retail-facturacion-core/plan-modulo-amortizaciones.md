# Plan: Módulo de Amortizaciones — Ventas a Crédito

> **✅ MÓDULO CERRADO — Fases 1-9 de 9 completas al 2026-07-16.** Ver §6 para el detalle de
> qué se construyó fase por fase (incluida una Fase 8.0 no prevista acá) y qué queda
> explícitamente fuera de alcance (UI de anular/refund/replace, `credit_type='libre'` sin
> UI). **Historial/PDF de recibos y la edición de venta a crédito antes de SUNAT se
> cerraron después, el 2026-07-18** (sesiones aparte, post-cierre del módulo — ver notas al
> final de §4 y §6). Este documento sigue siendo la referencia de diseño; para la narrativa
> completa de hallazgos/decisiones ver `CLAUDE.md` y `project_amortizaciones_modulo_estado.md`
> (memoria de proyecto).

Proyecto: sistemafe (Umbo) — Laravel 12 / PostgreSQL / Vue 3
Referencia cruzada: sigue el mismo patrón transaccional usado en `SaleController::update()`
(sync de tres casos dentro de `DB::transaction()`). Todo lo que modifica venta + cuotas +
pagos en este módulo debe respetar esa misma disciplina de atomicidad.

---

## 1. Objetivo

Permitir el seguimiento y cobro de ventas a crédito (facturas/boletas ya emitidas a SUNAT
por el monto total), soportando:

- Cuotas fijas con cronograma **y** pagos libres sin cronograma, configurable por venta.
- Mora opcional (nunca obligatoria), con default configurable a nivel empresa.
- Registro de amortizaciones tanto **específicas** (a una sola venta) como **generales**
  (un pago que se reparte automáticamente entre varias ventas antiguas de un mismo cliente).
- Anulación segura de cuotas y de pagos ya cobrados, sin borrar historial financiero.
- Sobrepago → saldo a favor del cliente.

**No dispara documentos SUNAT nuevos** en el flujo normal de amortización: la factura/boleta
ya reconoció el IGV total al momento de la venta. La única excepción es la anulación total
de una venta a crédito sin pagos aplicados, que si requiere anular el comprobante, sigue el
flujo existente de nota de crédito (fuera del alcance de este módulo, solo se referencia).

---

## 2. Modelo de datos

### 2.1 `sales` (columnas nuevas)

| Columna | Tipo | Notas |
|---|---|---|
| `condicion_pago` | enum: `contado`, `credito` | ya podría existir, confirmar |
| `credit_type` | enum nullable: `cuotas_fijas`, `libre` | solo si `condicion_pago = credito` |
| `aplica_mora` | boolean | pre-marcado según default de `companies`, editable por venta |
| `tasa_mora` | decimal nullable | si null, usa default de `companies` |
| `tipo_mora` | enum nullable: `fijo_por_cuota`, `porcentaje_diario`, `porcentaje_fijo_unico` | idem |
| `fecha_limite_pago` | date nullable | solo relevante en `credit_type = libre` **si** `aplica_mora = true` (sin esta fecha, mora en modo libre no tiene contra qué calcularse — deshabilitar el checkbox en el frontend si no está definida) |
| `saldo_pendiente` | decimal | cacheado, se recalcula en cada aplicación de pago |
| `replaces_sale_id` | FK nullable, self-referencing | solo se llena en la venta nueva cuando reemplaza a una anulada con pagos trasladados (§3.13) |

Nota: la venta anulada con reemplazo (§3.13) usa un valor `anulada_reemplazada` sobre el
campo de estado de venta que ya existe en el sistema (fuera de este módulo), para
diferenciarla de una anulación simple sin reemplazo.

### 2.2 `installments` (nueva tabla — solo se llenan filas si `credit_type = cuotas_fijas`)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `sale_id` | FK | |
| `numero_cuota` | int | |
| `monto_programado` | decimal | |
| `fecha_vencimiento` | date | **fecha explícita**, nunca calculada on-the-fly (no asumir mensualidad) |
| `estado` | enum: `pendiente`, `pagada`, `parcial`, `vencida`, `anulada` | |
| `motivo_anulacion` | text nullable | solo si `estado = anulada` |
| `anulado_por` | FK user nullable | |
| `anulado_en` | timestamp nullable | |

La periodicidad (mensual/quincenal/semanal/personalizada) **no se persiste** — es solo una
herramienta de UI para pre-generar fechas editables antes de guardar. Lo único que persiste
es la `fecha_vencimiento` por fila, para poder soportar cuotas irregulares y renegociaciones
sin rehacer el cronograma completo.

### 2.3 `payment_receipts` (recibo de cobro — unifica caso general y específico)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `numero_recibo` | string | correlativo interno propio (ej. `REC-00001`), **no regulado por SUNAT** — solo para impresión/entrega al cliente |
| `client_id` | FK | |
| `fecha_pago` | date | |
| `medio_pago` | mismo catálogo que `sale_payments` | |
| `nro_operacion` | string nullable | |
| `monto_total` | decimal | |
| `monto_no_aplicado` | decimal, default 0 | excedente tras saldar toda la deuda → pasa a saldo a favor |
| `registrado_por` | FK user | |
| `estado` | enum: `activo`, `anulado` | |
| `motivo_anulacion` | text nullable | |
| `anulado_por` | FK user nullable | |
| `anulado_en` | timestamp nullable | |

### 2.4 `payment_applications` (pivot — a qué venta/cuota se aplicó cada parte del pago)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `payment_receipt_id` | FK | |
| `sale_id` | FK | |
| `installment_id` | FK nullable | solo si la venta es `cuotas_fijas` |
| `monto_aplicado` | decimal | capital |
| `monto_mora_cobrado` | decimal, default 0 | congelado en el momento del pago, ver §3.8 |
| `orden_aplicacion` | int | para trazabilidad y para poder revertir en el mismo orden al anular |
| `estado` | enum: `activo`, `anulada`, `trasladada`, default `activo` | `anulada` = revertida por error de caja (§3.6) o liquidada por devolución (§3.12); `trasladada` = movida a otra venta por reemplazo de comprobante (§3.13) |
| `refund_id` | FK nullable → `payment_refunds` | solo si `estado = anulada` por liquidación (§3.12) |
| `origen_application_id` | FK nullable, self-referencing | solo si `estado = trasladada`, apunta a la fila de origen (§3.13) |

Un pago **específico** = 1 `payment_receipt` + 1 `payment_applications`.
Un pago **general** = 1 `payment_receipt` + N `payment_applications` (una por venta, o por
cuota dentro de cada venta si es `cuotas_fijas`).

### 2.5 `companies` (o config de tenant — columnas nuevas)

| Columna | Tipo |
|---|---|
| `mora_habilitada_default` | boolean |
| `tasa_mora_default` | decimal |
| `tipo_mora_default` | mismo enum que `sales.tipo_mora` |

### 2.6 `clients` (columnas nuevas)

| Columna | Tipo | Notas |
|---|---|---|
| `saldo_a_favor` | decimal, default 0 | acumula `monto_no_aplicado` de recibos con sobrepago. **Adelantado a Fase 2** (decisión del 15/07) junto con los modelos, para no reabrir `clients` más adelante — requiere su propia migración pequeña, mostrada para revisión antes de correr, mismo criterio que Fase 1. |
| `mora_override` | boolean nullable | ej. "este cliente nunca paga mora" — **sigue en v2**, sin cambios respecto a §7. |

---

## 3. Flujos

### 3.1 Creación de venta a crédito

1. Cajero marca `condicion_pago = credito`.
2. Elige `credit_type`: `cuotas_fijas` o `libre`.
3. Si `cuotas_fijas`: ingresa número de cuotas + periodicidad sugerida → sistema pre-genera
   una tabla editable (monto + fecha por fila) → cajero ajusta si hace falta → confirma →
   se insertan las filas en `installments` con fechas/montos definitivos.
4. Si `libre`: no se genera cronograma. Si además `aplica_mora = true`, se exige capturar
   `fecha_limite_pago`.
5. Mora: checkbox `aplica_mora` viene pre-marcado según `companies.mora_habilitada_default`,
   editable en esta venta puntual. Si se activa, `tasa_mora`/`tipo_mora` toman el default de
   `companies` salvo override manual.

### 3.2 Registro de amortización — caso general (multi-venta)

1. Cajero busca al cliente. Al cargarlo, el sistema muestra de inmediato un **resumen de
   deuda actual**: listado de todas sus ventas a crédito con saldo pendiente (nº de
   comprobante, fecha de venta, saldo pendiente por venta, y si es `cuotas_fijas`, cuántas
   cuotas vencidas tiene) más el **total general adeudado**. Esto se muestra siempre al
   entrar a la pantalla, exista o no un pago en curso — es la vista base del "estado de
   cuenta" (mismo dato que expone `GET /clients/{client}/credit-summary`, §4).
2. Cajero ingresa: monto total pagado, fecha de pago, medio de pago, nro de operación.
3. Backend ejecuta el algoritmo FIFO (§3.4) y devuelve una **preview editable** que combina
   dos cosas en la misma pantalla: la lista de ventas/cuotas afectadas con el monto que se
   les aplicaría, **y** el saldo que le quedaría a cada una después de ese pago (deuda
   "antes" vs "después", por venta y en total). Así el cajero ve de un vistazo el efecto real
   del pago antes de confirmarlo, no solo el reparto.
4. Cajero puede ajustar montos individuales en la preview antes de confirmar (cubre el caso
   "quiero saldar esta venta específica y no la más antigua por una disputa") — el resumen
   "después" se recalcula en vivo con cada ajuste, sin necesidad de volver a llamar al backend
   si se hace en el frontend, o con una llamada ligera de recálculo si se prefiere que el
   backend sea la única fuente de verdad para los totales.
5. Al confirmar, todo se persiste en una sola transacción DB: 1 `payment_receipts` + N
   `payment_applications` + recálculo de `estado` en cada `installment`/`saldo_pendiente`
   de cada `sale` afectada.

### 3.3 Registro de amortización — caso específico (una sola venta)

Mismo formulario y mismo backend que el caso general, pero acotado a `sale_id` fijo. Genera
1 `payment_receipts` + 1 `payment_applications`. No necesita pantalla de preview separada
(es un caso trivial del mismo algoritmo).

### 3.4 Algoritmo de aplicación (FIFO en dos niveles)

1. Obtener todas las ventas a crédito con `saldo_pendiente > 0` del cliente, ordenadas por
   `fecha_venta` ascendente (decisión cerrada en §7 — criterio único sin importar
   `credit_type`, para no mezclar dos tipos de fecha distintos entre ventas `libre` y
   `cuotas_fijas`).
2. Recorrer venta por venta:
   - `libre`: aplicar contra `saldo_pendiente` de la venta hasta agotarla o hasta agotar el
     monto del pago.
   - `cuotas_fijas`: dentro de la venta, aplicar cuota por cuota, también FIFO por
     `fecha_vencimiento`.
3. Repetir hasta agotar el monto del pago o saldar toda la deuda del cliente.
4. Excedente tras saldar todo → `payment_receipts.monto_no_aplicado` → se acredita a
   `clients.saldo_a_favor`.
5. Todo bajo `lockForUpdate()` sobre las ventas/cuotas involucradas, dentro de
   `DB::transaction()`, para evitar condiciones de carrera con dos cobradores simultáneos.
6. Montos en decimal estricto (no float); validar que la suma de `monto_aplicado` en
   `payment_applications` cuadre exacto con `monto_total` del recibo antes de commitear.

### 3.5 Anulación de una cuota (sin pagos aplicados — renegociación)

1. `installments.estado = anulada`, con `motivo_anulacion`, `anulado_por`, `anulado_en`.
2. El monto liberado requiere decisión explícita del usuario en el momento (no automática):
   - **Redistribuir** entre las cuotas restantes (proporcional o solo en la última).
   - **Condonar** (reduce `sales.saldo_pendiente` — requiere motivo tipo "descuento
     comercial"; considerar exigir rol elevado vía Spatie).
3. No dispara ningún documento SUNAT (la factura/boleta ya cubre el monto total original).

### 3.6 Anulación de un pago ya cobrado (error de caja)

1. `payment_receipts.estado = anulado`, con `motivo_anulacion`, `anulado_por`, `anulado_en`.
   Nunca se hace DELETE.
2. En cascada, dentro de la misma transacción: por cada `payment_applications` asociada,
   recalcular el `estado` de la `installment`/`saldo_pendiente` de la `sale` afectada,
   usando solo pagos con `estado = activo`.
3. Requiere permiso elevado (rol supervisor o similar) — es dinero que "desaparece" de caja.

### 3.7 Sobrepago

Ya cubierto en §3.4 punto 4: excedente va a `clients.saldo_a_favor`. Pendiente de definir en
fase de detalle: si el saldo a favor se puede aplicar automáticamente a la siguiente venta a
crédito de ese cliente, o si requiere que el cliente/cajero decida cuándo usarlo (recomendado
para v1: manual, no automático — menos sorpresas contables).

### 3.8 Mora (cálculo on-the-fly, nunca materializado por cron)

- `monto_mora` de una cuota/venta se **calcula al consultar**, no se guarda como saldo fijo:
  `dias_atraso = fecha_actual - fecha_vencimiento` (si positivo y `aplica_mora = true`).
- Al momento del pago efectivo, el monto de mora cobrado se **congela** en
  `payment_applications.monto_mora_cobrado` — eso sí es inmutable una vez pagado.
- Para listados/dashboards de cartera vencida con muchos registros, un job nocturno puede
  *cachear* el cálculo (no cobrarlo), para no recalcular en cada request.
- **Orden de aplicación dentro de cada cuota/venta (decisión Fase 6): capital primero,
  mora después** — más favorable al cliente que el default común de la industria (mora
  primero), elegido a propósito para este negocio.
- **(Aceptado, no es bug) Mora "perdonada" cuando un pago salda el capital completo sin
  cubrir toda la mora ya generada:** consecuencia directa de las dos decisiones anteriores
  (capital primero + mora nunca persistida aparte del capital). Si un pago deja
  `saldoCapital=0`, la mora de esa cuota deja de poder calcularse hacia adelante (depende
  del capital vigente), y cualquier porción no cubierta en ese mismo pago no se cobra
  después. Se acepta como comportamiento intencional — coherente con la política ya
  elegida de favorecer al cliente —, no se implementa una columna de mora pendiente
  persistida porque contradiría el diseño on-the-fly de esta sección.
- Mora en `cuotas_fijas`: por cuota individual vencida.
  Mora en `libre`: por venta completa, contra `fecha_limite_pago` (única, no cronograma).
- Interés financiero tipo tabla de amortización francesa/alemana: **solo aplica a
  `cuotas_fijas`** (v2, requiere cronograma para prorratear capital+interés; en `libre` como
  mucho mora simple sobre saldo vencido).

### 3.9 Anulación total de una venta a crédito (sin pagos aplicados)

Distinto de anular una cuota: si la venta completa se cancela y aún no tiene ningún pago,
sí impacta SUNAT porque la factura/boleta ya se emitió → requiere nota de crédito (reusar
flujo existente de notas). En cascada, anular todas las `installments` pendientes de esa
venta. **Fuera del alcance de implementación de este módulo**, solo dejar el hook/validación
para que `SaleController` u otro controlador de anulación lo contemple.

Si la venta **sí** tiene pagos aplicados al momento de anularse, este caso simple ya no
aplica — corresponde a §3.12 (devolución con retención parcial) o §3.13 (reemplazo de
comprobante con traspaso de pagos), según si hay devolución de mercadería o solo corrección
del documento.

### 3.10 Recibo de pago en PDF (entregable al cliente)

**No es un comprobante SUNAT** — es un documento interno de constancia, igual en espíritu a
las `constancias_pago` ya existentes en el proyecto para el flujo veterinario (aisladas del
pipeline SUNAT). No requiere serie regulada ni pasa por Greenter; solo necesita
`numero_recibo` como correlativo interno para poder identificarlo/reimprimirlo.

Se genera **a demanda** (no se cachea en disco) a partir de un `payment_receipts` ya
confirmado, vía librería de PDF estándar en Laravel (dompdf o similar — evaluar cuál se use
finalmente en el módulo de "Representación impresa" del roadmap; no es necesario esperar a
esa decisión porque este recibo no necesita el flujo de impresión automática/kiosco, es un
PDF descargable/imprimible manual).

**Contenido del PDF:**

1. Encabezado: datos de la empresa (razón social, RUC, dirección) — reutilizar los mismos
   datos que ya se usan para los comprobantes.
2. Datos del recibo: `numero_recibo`, fecha de pago, medio de pago, nº de operación,
   usuario que lo registró.
3. Datos del cliente: nombre/razón social, documento de identidad.
4. Detalle de aplicación (tabla, una fila por cada `payment_applications` del recibo):
   venta/comprobante afectado, cuota (si `cuotas_fijas`), monto aplicado a capital, mora
   cobrada (si hubo).
5. Totales: monto total pagado, total aplicado a capital, total mora cobrada, excedente no
   aplicado (si hubo sobrepago → saldo a favor).
6. **Resumen de deuda actual del cliente** (post-pago): deuda total pendiente, y detalle por
   cada venta a crédito abierta con su saldo restante y, si es `cuotas_fijas`, la fecha de
   la próxima cuota por vencer. Esto es lo que el cliente se lleva como comprobante de "cuánto
   pagó y cuánto le falta".
7. Pie de página con leyenda: *"Documento interno de control de pagos — no constituye
   comprobante de pago electrónico SUNAT"*, para que quede explícito que no reemplaza a la
   factura/boleta ya emitida en la venta original.

### 3.11 Punto de entrada — pantalla de Cuentas por Cobrar

Nuevo ítem de menú **"Cuentas por Cobrar"** (o "Cobranzas"), con dos vistas conmutables
sobre los mismos datos, para cubrir las dos formas reales en que el cajero necesita buscar:

**⚠️ Corrección tras hallazgo en dev (backfill, Fase 1):** el filtro base de ambas vistas es
`sales.saldo_pendiente > 0`, **sin exigir `condicion_pago = 'credito'`**. Se descubrió en dev
que ya existe deuda real en ventas `contado` (mecanismo previo `debt`/`paid_out`, ajeno a
este módulo — ver §9). `condicion_pago`/`credit_type` solo determinan si una venta tiene
cronograma (`installments`) y mora; el cobro (`payment_receipts`/`payment_applications`)
debe poder aplicarse a cualquier venta con saldo, sea `contado` con deuda informal o
`credito` formal — de lo contrario esas ventas desaparecerían silenciosamente de la cartera.

**A) Vista por cliente (agrupada) — vista principal/default**

Listado de clientes con deuda a crédito activa: nombre/razón social, **deuda total
consolidada** (suma de todas sus ventas a crédito con saldo pendiente), cantidad de ventas
abiertas, cantidad de cuotas vencidas (si aplica), buscador por nombre/documento.

Cubre el caso más común: "vino el cliente X a pagar" → se busca por nombre, se entra a su
detalle, y de ahí se dispara tanto el pago general como el específico (§3.2/§3.3), con el
resumen de deuda que ya se definió (§3.2 paso 1).

**B) Vista por venta (plana) — para cartera y búsqueda puntual**

Listado plano de ventas a crédito con saldo pendiente, una fila por venta (nº de
comprobante, cliente, fecha de venta, saldo pendiente, próxima cuota por vencer si aplica,
estado: `al_dia` / `por_vencer` / `vencida`). Con filtros por estado y rango de fechas, y
orden por antigüedad o por monto.

Cubre dos casos que la vista por cliente no resuelve bien: (1) reporte de cartera vencida
para gestión ("qué facturas están atrasadas ahora mismo"), y (2) búsqueda directa por número
de comprobante cuando el cajero no tiene a mano el nombre del cliente. Click en una fila →
abre directamente el flujo de pago específico (§3.3) pre-cargado con esa venta.

Ambas vistas leen del mismo origen de datos (ventas con `condicion_pago = credito` y
`saldo_pendiente > 0`); la vista A es solo una agregación por `client_id` de la vista B, así
que no hace falta duplicar lógica de cálculo, solo dos formas de presentar el mismo query.

**Acceso adicional (corregido en Fase 9 — no existe ficha de cliente con tabs en el admin
real, los clientes se editan por modal):** en vez de una pestaña "Créditos" en una ficha
inexistente, se construye una página de detalle propia
(`/cuentas-por-cobrar/cliente/:id`, mismo patrón que `advances/show.vue`), a la que se
llega únicamente desde el listado de Cuentas por Cobrar (vista A). Muestra lo mismo que
mostraría esa pestaña — resumen de deuda + flujo de pago — sin tocar el módulo de
Clientes.

### 3.12 Devolución de pagos por anulación con mercadería devuelta (retención parcial)

Caso: la venta se anula (nota de crédito), el cliente devuelve la mercadería, y ya tiene
pagos aplicados a esa venta. El negocio devuelve el dinero pero retiene un monto por gastos
operativos (flete, reembalaje, etc.). **No es lo mismo que anular un pago erróneo (§3.6)**:
ahí el pago nunca debió existir así; acá el pago fue correcto en su momento, lo que cambia es
que la venta ya no se sostiene y corresponde liquidar/devolver.

```
payment_refunds
  ├─ id
  ├─ sale_id                    (la venta anulada)
  ├─ monto_pagado_total         (suma de lo pagado por esa venta hasta el momento)
  ├─ monto_retenido             (gasto operativo / penalización)
  ├─ motivo_retencion           (texto)
  ├─ monto_devuelto             (= monto_pagado_total − monto_retenido)
  ├─ medio_devolucion
  ├─ nro_operacion_devolucion   (nullable)
  ├─ fecha_devolucion
  ├─ autorizado_por             (FK user — requiere permiso elevado)
  └─ estado: pendiente | completado
```

`payment_applications` agrega una columna `refund_id` (FK nullable) — al liquidarse, las
aplicaciones activas de esa venta pasan a `estado = anulada` con `refund_id` apuntando a este
registro, para trazabilidad completa (nunca se borra nada).

**⚠️ Pendiente confirmar con contador** (mismo criterio que quedó abierto para adelantos): el
`monto_retenido` podría considerarse ingreso gravado por un servicio (gestión/logística),
independiente de que la venta original ya tenga nota de crédito, y podría requerir su propio
comprobante separado. No se asume una respuesta — se deja como validación previa a
implementar el cálculo de este monto como "ingreso limpio" sin comprobante.

### 3.13 Reemplazo de comprobante con traspaso de pagos existentes

Caso: la venta se anula (nota de crédito) por error en montos o en el producto descrito, se
reemite un comprobante corregido con fecha actual, y la venta anulada ya tenía pagos
aplicados. A diferencia de §3.12, acá el cliente **no** está devolviendo nada — la operación
comercial sigue siendo válida, solo se corrigió el documento. Los pagos ya hechos deben
trasladarse a la venta nueva, no devolverse ni reversar.

```
sales
  └─ replaces_sale_id   (FK nullable, self-referencing — venta nueva → venta que reemplaza)

payment_applications
  ├─ estado: activo | anulada | trasladada        (nuevo valor: trasladada)
  └─ origen_application_id  (FK nullable, self-referencing, para trazabilidad del traspaso)
```

Flujo, en una sola transacción, disparado por una acción explícita del usuario (no
automático al emitir la nota de crédito — requiere permiso elevado, igual que las demás
anulaciones):

1. Se marca cada `payment_applications` activa de la venta vieja como `estado = trasladada`
   (dejan de contar en el saldo de la venta vieja; distinto de `anulada`, no implica reembolso).
2. Se crean nuevas filas de `payment_applications` contra la venta nueva, mismo
   `payment_receipt_id` y mismo monto, con `origen_application_id` apuntando a la fila vieja.
3. Se recalcula `saldo_pendiente` de la venta nueva, repartiendo los montos trasladados con
   el mismo algoritmo FIFO (§3.4) si la venta nueva es `cuotas_fijas`.
4. La venta vieja pasa a un estado `anulada_reemplazada` (distinto de una anulación simple,
   para diferenciarla en reportes de las anulaciones sin reemplazo).

Casos de descuadre de montos, ya cubiertos por lo existente sin lógica nueva:
- Si el comprobante corregido queda con **menor** total que lo ya pagado → el excedente cae
  en `saldo_a_favor` del cliente (§3.7).
- Si queda con **mayor** total → el resto no cubierto por los pagos trasladados queda como
  `saldo_pendiente` normal de la venta nueva, cobrable con el flujo habitual.

---

## 4. Controladores / Endpoints propuestos

```
CreditReceivablesController
  GET    /credit-sales                          → vista B: listado plano de ventas a crédito
                                                   con saldo pendiente (filtros: estado,
                                                   rango de fechas, cliente; orden por
                                                   antigüedad o monto) — §3.11
  GET    /clients/credit-summary-list            → vista A: listado agrupado por cliente con
                                                   deuda total consolidada — §3.11

CreditInstallmentController
  POST   /sales/{sale}/installments/preview   → genera cronograma editable (no persiste)
  POST   /sales/{sale}/installments           → confirma y persiste cronograma
  PATCH  /installments/{installment}           → editar fecha/monto de una cuota puntual
  POST   /installments/{installment}/anular    → anular cuota (§3.5)
  POST   /installments/schedule-preview        → (nuevo, Fase 8, no estaba en el diseño
                                                   original) mismo cálculo que .../preview
                                                   de arriba pero SIN requerir una Sale ya
                                                   persistida — reusa InstallmentScheduleCalculator
                                                   directo. Necesario porque register.vue
                                                   arma el cronograma sugerido ANTES de que
                                                   la venta exista (en el mismo submit que
                                                   la crea, §6 punto 8).

CreditPaymentController
  POST   /clients/{client}/payments/preview    → calcula reparto FIFO (§3.4), no persiste.
                                                   Respuesta incluye `resumen_por_venta`
                                                   (todas las ventas abiertas del cliente,
                                                   tocadas o no por este pago) además de las
                                                   aplicaciones fila por fila — implementado
                                                   en Fase 4, cubre §3.2 paso 3 (deuda "antes
                                                   vs después" por venta y en total)
  POST   /clients/{client}/payments            → confirma pago (general o específico según
                                                   payload traiga 1 o N sale_id)
  POST   /payment-receipts/{receipt}/anular     → anula recibo completo (§3.6)
  GET    /payment-receipts-pdf-url/{id}         → ✅ CONSTRUIDO (2026-07-18, sesión aparte
  GET    /payment-receipts-pdf/{id}               post-cierre). §3.10 implementado:
                                                   PaymentReceiptController::pdfSignedUrl()/pdf(),
                                                   mismo patrón de URL firmada que ventas/notas,
                                                   A4 + ticket80mm, SIN QR fiscal (no es
                                                   comprobante SUNAT). Vistas nuevas
                                                   resources/views/pdf/recibo_pago_a4.blade.php
                                                   y recibo_pago_ticket80mm.blade.php.
  GET    /clients/{client}/payment-receipts     → ✅ CONSTRUIDO (2026-07-18, mismo cierre).
                                                   Listado paginado real de recibos de un
                                                   cliente — cierra el hueco documentado más
                                                   abajo en esta sección.
  GET    /clients/{client}/credit-summary       → estado de cuenta consolidado (deuda total,
                                                   por venta, cuotas vencidas, mora acumulada)
                                                   — es la misma fuente de datos que alimenta
                                                   el resumen "deuda actual" al abrir la
                                                   pantalla de pago (§3.2, paso 1)
  POST   /sales/{sale}/refund                    → liquidar venta anulada con devolución y
                                                   retención parcial (§3.12)
  POST   /sales/{sale}/replace                   → reemitir comprobante corregido y trasladar
                                                   pagos existentes (§3.13)
```

**✅ RESUELTO (2026-07-18, sesión aparte, post-cierre del módulo)** — el hueco descrito abajo
(histórico, se deja para trazabilidad) ya no existe: `PaymentReceiptController::index()`
(`GET /clients/{client}/payment-receipts`) construido junto con el PDF del recibo (§3.10),
ambos en la misma sesión, exactamente como se anticipaba ("van juntos naturalmente"). Bloque
nuevo "Historial de recibos de pago" agregado a `client-detail.vue`, con paginación
(`<b-pagination>`) y botón de imprimir por fila. Verificado contra recibos reales del tenant
`umbo` (`REC-00001`/`REC-00002`/`REC-00004`).

<details>
<summary>Nota histórica (previa al cierre de arriba, se conserva para contexto)</summary>

**❌ NO existía `GET /clients/{client}/payment-receipts`** (listado de recibos de un
cliente) — ninguna fase lo construyó, pese a que una versión anterior de este documento lo
daba por hecho ("agregado sobre la marcha" en Fase 9). Se verificó contra el código real
(`routes/api.php`, `CreditPaymentController`) al cerrar Fase 9: no existía la ruta ni el
método. Decisión explícita del usuario al planear Fase 9: sin historial de recibos por
ahora — la pantalla de estado de cuenta (`client-detail.vue`) mostraba el resumen agregado
(`credit-summary`) y el formulario de pago nuevo, sin listar recibos pasados.

</details>

---

## 5. Validaciones / edge cases a cubrir en pruebas

- Suma de `monto_aplicado` en `payment_applications` == `monto_total` de `payment_receipts`
  menos `monto_no_aplicado`, siempre exacta (sin diferencias de centavo por redondeo).
- No permitir aplicar pago a una `installment` con `estado = anulada`.
- No permitir anular una `installment` que ya tiene pagos activos aplicados (forzar primero
  anular el pago, o bloquear con mensaje explícito).
- Concurrencia: dos cobros simultáneos al mismo cliente no deben duplicar aplicación sobre
  el mismo saldo (`lockForUpdate()`).
- Pago anticipado de una cuota que aún no vence: debe permitirse sin fricción, el FIFO no
  debe bloquearlo.
- Mora en modo `libre` sin `fecha_limite_pago` definida: checkbox de mora debe quedar
  deshabilitado en el frontend.
- Reversar un `payment_receipt` general que tocó 3 ventas distintas: las 3 deben recalcularse
  correctamente dentro de la misma transacción.
- **(Hallazgo Fase 3)** `PATCH /installments/{installment}` permite editar el monto de una
  sola cuota sin revalidar que la suma total de `installments.monto_programado` siga
  coincidiendo con `sale.saldo_pendiente`. No se resuelve en Fase 3 (no hay endpoint batch
  todavía), pero debe decidirse antes de que el algoritmo FIFO cuota-por-cuota (§3.4, Fase 4)
  dependa de esa suma cuadrando exacto — riesgo de estados confusos (cuotas todas `pagada`
  con `saldo_pendiente > 0`, o viceversa). Opciones a evaluar en Fase 4: bloquear el PATCH si
  rompe la suma, permitir el drift y recalcular `saldo_pendiente` como fuente de verdad única
  (ignorando la suma de cuotas), o exigir que toda edición pase por un endpoint batch que
  fuerce el cuadre. No decidido todavía.

---

## 6. Fases de implementación — ✅ MÓDULO CERRADO (2026-07-16, Fases 1-9 de 9)

Estado real de cada fase, verificado contra el código (no solo contra este diseño). Detalle
narrativo completo de cada una — hallazgos, decisiones, bugs reales encontrados y
corregidos — vive en `CLAUDE.md` (raíz del repo) y en la memoria de proyecto
`project_amortizaciones_modulo_estado.md`; acá solo el resumen de qué se construyó.

1. ✅ Migraciones: columnas nuevas en `sales`/`companies`, tablas `installments`,
   `payment_receipts`, `payment_applications`, `payment_refunds`. Validadas con
   `migrate:fresh` antes de tocar dev.
2. ✅ Migración `clients.saldo_a_favor`. Modelos Eloquent + relaciones + factories con
   estados realistas (`Sale::cuotasFijas()/libre()/conMora()/libreVencida()/contadoConDeuda()`).
3. ✅ `CreditInstallmentController`: preview + confirmación + edición puntual de cronograma
   (`InstallmentScheduleCalculator`, redondeo en centavos, resto en la última cuota).
4. ✅ `CreditPaymentController` + `CreditPaymentAllocator`: FIFO en dos niveles (§3.4), caso
   específico y general con el mismo endpoint (`sale_id` opcional).
5. ✅ Anulaciones: cuota (`installments/{id}/anular`, redistribución proporcional o
   condonación) y recibo (`payment-receipts/{id}/anular`, revierte saldo_pendiente +
   saldo_a_favor). Más §3.12 (`sales/{id}/refund`) y §3.13 (`sales/{id}/replace`) — los 4
   tienen permiso Spatie propio, ninguno asignado a un rol operativo por defecto.
6. ✅ Mora on-the-fly (§3.8): `MoraCalculator`, 3 fórmulas. Decisión cerrada: capital
   primero, mora después (contrario a la recomendación por defecto — mora se "perdona" si
   un pago salda el capital de un tirón; aceptado, no es bug).
7. ✅ Estado de cuenta consolidado — `GET /clients/{client}/credit-summary` +, ampliado
   sobre la marcha en la misma fase, las dos vistas de §3.11 (`GET /credit-sales`,
   `GET /clients/credit-summary-list`) vía `CreditReceivablesController` nuevo.
   `CreditSummaryCalculator` centraliza el cálculo por venta (mora, cuotas vencidas,
   próxima cuota, estado `al_dia`/`por_vencer`/`vencida`) para los 3 endpoints.
8. ✅ **Fase 8.0 (no prevista por este documento, bloqueante, cerrada antes de Fase 8)**:
   `GreenterService::getInvoice()` armaba `FormaPagoCredito` desde `sale_payments`
   (mecanismo previo, ajeno a `installments`), con fallback silencioso a
   `FormaPagoContado()`. Confirmado con datos reales: la única venta `type_payment=2` real
   en dev (id=16) ya fue ACEPTADA por SUNAT como contado siendo en realidad crédito — no se
   corrige retroactivamente. Fix: `FormaPagoCredito` ahora se arma desde `installments`
   activas; sin `installments` activas, bloquea 422 explícito (nunca cae a contado). Guard
   duplicado en `enviarSunat()` antes de reservar correlativo.
   ✅ **Fase 8**: integración real con `SaleController`. `type_payment==2` (selector ya
   existente en `register.vue`) es el único hecho de origen — el backend deriva
   `condicion_pago` de ahí (nunca un campo separado del payload) y persiste `credit_type=
   'cuotas_fijas'` (único soportado — `'libre'` rechazado 422, se sigue creando a mano) +
   cronograma, en la misma transacción de `store()`. `update()` NO crea/edita cronogramas
   (exclusivo de Fase 3) — solo sincroniza `condicion_pago` y bloquea 2→1 si ya hay
   seguimiento real. Nuevo endpoint sale-less `installments/schedule-preview` (§4).
9. ✅ Frontend Vue: pantalla de Cuentas por Cobrar (`src/views/credit/index.vue`, vista A/B
   de §3.11) + página de detalle de cliente (`client-detail.vue`, estado de cuenta +
   formulario de pago general/específico con preview editable del FIFO — con auto-cálculo
   del reparto sin botón manual, y atajo directo a "Guardar pago" cuando el reparto es
   trivial: 1 sola cuota/venta tocada, sin mora). **Desviación acordada del diseño
   original**: sin "pestaña Créditos en la ficha del cliente" (esa página no existe en el
   admin — se reemplazó por la página de detalle propia de arriba, ver nota en §3.11).

**Explícitamente fuera de alcance del cierre del módulo** (endpoints con backend real pero
sin UI, o sin construir ninguno de los dos lados — ver anotaciones en §4):
- ~~Historial/listado de recibos de pago~~ y ~~PDF del recibo de pago (§3.10)~~ —
  **construidos el 2026-07-18** (sesión aparte, ver nota en §4). Ya no están fuera de alcance.
- ~~UI de anular pago~~ — **construido el 2026-07-18** (ver nota abajo). Anular cuota,
  `refund` (§3.12) y `replace` (§3.13) siguen sin UI — los 3 endpoints backend existen y
  funcionan, pero se operan por API directa (Postman/similar), sin pantalla.
- `credit_type='libre'` no se puede crear desde `register.vue` — solo `cuotas_fijas`. Sigue
  creándose a mano (factory/SQL) si hace falta para pruebas.

**UI para anular un recibo de pago + permisos configurables — construido el 2026-07-18**
(sesión aparte, disparado por la necesidad de destrabar la edición de venta a crédito de
arriba cuando ya hay un pago mal registrado). Botón "Anular pago" en el historial de recibos
de `client-detail.vue`, gateado por el permiso Spatie `anular-pago-credito` ya existente
(motivo obligatorio, mínimo 5 caracteres, vía `Swal` con textarea). Cero cambios de backend
— reusa `POST payment-receipts/{id}/anular` (Fase 5A). Dos gaps de infraestructura
encontrados y corregidos en el camino: (1) las 4 migraciones de permisos del módulo
(`anular-cuota-credito`/`anular-pago-credito`/`liquidar-devolucion-credito`/
`reemplazar-comprobante-credito`) nunca habían corrido contra el tenant `umbo`
(`tenants:migrate` pendiente desde 2026-07-15) — corregido con aprobación explícita antes de
tocar la base real; (2) esos 4 permisos tampoco estaban en el catálogo hardcodeado del
frontend (`admin-start-kit/src/types/roles.ts`), así que aunque existieran en el backend no
eran asignables a ningún rol desde la UI de Roles — se agregó el módulo "Créditos
(Amortizaciones)" a ese catálogo.

**Editar venta a crédito antes de enviar a SUNAT — construido el 2026-07-18** (sesión aparte,
no estaba contemplado como fase de este plan): `SaleController::update()` ahora sincroniza
`sale_payments` (antes se ignoraba `$request->payments` en silencio — bug real) y, si la venta
es a crédito y no tiene `payment_applications` activas ni `advance_applications`, exige
regenerar el cronograma completo (reusa `validarConfiguracionCredito()` de `store()`) en el
mismo guardado. Si ya hay cobros formales, la edición de productos/pago inicial se bloquea con
422 — hay que anular esos cobros primero por sus propios flujos. `edit.vue` ganó la misma
tarjeta "Configuración de Crédito" que `register.vue`. Ver `project_amortizaciones_modulo_estado.md`
para el detalle completo de la investigación y verificación.

---

## 7. Decisiones cerradas (v1)

- **Orden FIFO cuando se mezclan `libre` y `cuotas_fijas`**: se usa `sales.fecha_venta`
  ascendente como criterio único para ordenar las ventas del cliente, sin importar el
  `credit_type` de cada una. Es el campo más simple y predecible para el negocio ("se cobra
  primero lo más antiguo que se vendió"), y evita comparar dos tipos de fecha distintos
  (fecha de venta vs fecha de vencimiento de cuota) entre ventas de distinto tipo. Dentro de
  cada venta `cuotas_fijas` ya definido en §3.4: se sigue ordenando por `fecha_vencimiento`
  de sus propias cuotas.
- **Saldo a favor**: aplicación **manual**, nunca automática. El cajero/cliente decide en qué
  momento usarlo contra una venta futura. Menos sorpresas contables y más simple para v1;
  la aplicación automática queda como posible mejora en v2 si se vuelve un caso frecuente.
- **Permisos de anulación**: se crean dos permisos Spatie dedicados —
  `anular-cuota-credito` y `anular-pago-credito` — separados de los permisos normales de
  registrar ventas/pagos. Se asignan por defecto solo al rol de supervisor/administrador,
  nunca al rol de cajero base, para evitar que la misma persona que cobra pueda también
  revertir sin control.
- **`clients.mora_override`** (mora nunca aplica a un cliente puntual): **se deja para v2**.
  No bloquea el diseño de tablas actual — cuando se agregue, solo se lee como una excepción
  adicional antes de aplicar el `aplica_mora`/`tasa_mora` de la venta, sin requerir cambios
  en `installments` ni `payment_applications`.

## 8. Pendiente de confirmar con contador (no bloquea el diseño de tablas)

- **§3.12 — `monto_retenido` en devoluciones**: si el gasto operativo que el negocio retiene
  al liquidar una venta anulada con devolución debe emitir su propio comprobante (boleta por
  servicio de gestión/logística) o si queda cubierto por la nota de crédito de la venta
  original. Mismo tipo de duda que ya existía para el módulo de adelantos.

## 9. Hallazgo en dev — relación con `type_payment` / `FormaPagoCredito` (SUNAT)

Durante la verificación de backfill de la Fase 1 se encontraron **dos mecanismos de
"crédito" preexistentes**, ninguno contemplado en el diseño original (§2.1):

1. **`sales.type_payment == 2`**: ya usado por `GreenterService::getInvoice()` (línea 128)
   para armar `FormaPagoCredito` en el XML UBL enviado a SUNAT, a partir de `sale_payments`.
   Es dato fiscal real, no interno. 0 filas lo usan hoy en dev.
2. **`sales.debt` / `paid_out` / `state_payment`**: mecanismo informal de saldo pendiente,
   independiente de `type_payment`. En dev existen 3 ventas reales con `type_payment=1`
   (Contado ante SUNAT) y deuda pendiente vía este mecanismo (`debt`: S/11.52, S/237.60,
   S/287.98 — ventas F001-18/19/20).

**Decisión de backfill (Fase 1, no bloquea la migración de columnas ya creada):**
- Las 3 ventas existentes mantienen `condicion_pago = 'contado'` (coincide con su
  `type_payment=1` ya reportado a SUNAT, no se reclasifica retroactivamente).
- Se les hace `UPDATE sales SET saldo_pendiente = debt WHERE id IN (5, 6, 7)` — para no
  perder ese saldo real de la cartera del nuevo módulo. SQL a mostrar para revisión antes
  de ejecutar, no auto-run.
- No se les asigna `credit_type` (quedan sin cronograma ni mora) — son cobrables por el
  módulo de amortizaciones vía `saldo_pendiente`, pero sin las features de cuotas fijas.

**Pendiente de resolver antes de la Fase 3 (`CreditInstallmentController`, creación de venta
a crédito):** toda venta nueva que se marque `condicion_pago = 'credito'` en este módulo
**debe** también setear `type_payment = 2`, para que el XML de SUNAT sea coherente con lo
que el módulo de amortizaciones registra internamente. `condicion_pago` no es un concepto
nuevo independiente — debe mantenerse sincronizado con `type_payment` como la misma
información, no una tercera fuente de verdad paralela.

**✅ RESUELTO E IMPLEMENTADO (Fase 8, 2026-07-16):** se reutiliza el selector `type_payment`
ya existente en `register.vue` — no se agregó un control paralelo. Al elegir "Crédito" ahí,
`SaleController::store()`/`update()` deriva `condicion_pago='credito'` de `type_payment==2`
en el mismo punto de escritura, dentro de la misma transacción, junto con la persistencia
del cronograma (reutilizando `InstallmentScheduleCalculator` de Fase 3). Antes de llegar a
esta integración se encontró y cerró un hallazgo bloqueante no previsto (Fase 8.0, ver §6
punto 8) sobre cómo `GreenterService` declaraba la forma de pago ante SUNAT.

**(Nota — drift detectado post-Fase 1, venta id=16, `type_payment=2` sin cronograma):**
durante la corrección de `SalePaymentController` se encontró una venta con `type_payment=2`
(Crédito ante SUNAT, flag legado) pero cobrada íntegramente vía el mecanismo informal
`debt`/`paid_out`, sin ningún cronograma real. Se le aplicó el mismo criterio de backfill
que a las 3 de Fase 1 (`saldo_pendiente = debt`, **sin** reclasificar `condicion_pago` a
`credito`) — reclasificarla habría creado la combinación `condicion_pago='credito'` +
`credit_type=null`, un estado que ningún controlador de este módulo contempla ni fue
probado. Es una inconsistencia propia del sistema legado, anterior a este módulo, que no
se corrige retroactivamente — distinta de la decisión de sincronización a futuro (§6 punto
8), que solo aplica a ventas nuevas creadas después de esa integración.

**(Hallazgo Fase 4) `debt`/`paid_out`/`state_payment` — confirmado riesgo activo, no
histórico.** `SalePaymentController::store()`/`destroy()` es el flujo vigente de cobro
parcial de cualquier venta (vía `sale/edit.vue`, en producción) y escribe `debt`/`paid_out`/
`state_payment` sin tocar `saldo_pendiente`. **Decisión: se corrige antes de Fase 5**, no
queda como riesgo documentado sin acción. Fix acotado en dos partes:
1. Espejar `saldo_pendiente` con la misma aritmética simétrica en `store()`/`destroy()` de
   `SalePaymentController`, en el mismo `update()` donde ya se tocan `debt`/`paid_out`.
2. Bloquear ese flujo legado sobre ventas con `condicion_pago = 'credito'` — esas ventas
   deben cobrarse exclusivamente vía `CreditPaymentController` (Fase 4); si `sale_payments`
   se usa sobre una venta de este módulo, movería `saldo_pendiente` por fuera de
   `payment_applications`, rompiendo el FIFO y el estado de las `installments` sin dejar
   rastro en `payment_receipts`.
`SaleController::store()`/`update()` también escriben estos campos, pero de forma pasiva
(reciben `debt`/`paid_out` ya calculados desde `register.vue`, sin recalcular) — riesgo
preexistente del sistema, ajeno a este módulo, no se toca en esta corrección.

**✅ `state_payment` — RESUELTO el 2026-07-18** (sesión aparte, mismo día que "Editar venta a
crédito" y "UI anular pago" de arriba, detectado por el usuario en el listado de ventas:
F001-00000028 ya cobrada 100% vía Amortizaciones seguía apareciendo "Pendiente"). El riesgo
de `debt`/`paid_out` frente a `SaleController` (arriba) sigue sin tocar — pero
específicamente `state_payment` frente a `CreditPaymentController` (Amortizaciones) sí se
cerró: `actualizarStatePayment()` (helper nuevo, mismo criterio 1/2/3 de
`SalePaymentController` pero basado en `saldo_pendiente`, la fuente de verdad de este
módulo), conectado en `store()`/`anular()`/`refund()`/`replace()`. Decisión de negocio
confirmada con el usuario: `state_payment` es estado ACTUAL, no snapshot histórico — no
sirve ni le sirvió nunca a ninguno de los dos flujos (legado o Amortizaciones) para
"deuda a la fecha X", eso requeriría una feature de reportería aparte. Backfill real de 3
ventas (`#25`/`#27`/`#29`) con el dato viejo, ejecutado con aprobación explícita.