# Plan — Módulo de Caja

> **🚧 EN PROGRESO — Fases 0-6 de 7 completas y verificadas contra `sandbox` al
> 2026-07-19.** Solo queda Fase 7 (multi-caja simultánea), en espera de activación real del
> negocio — ver el checklist de activación en su propia sección más abajo. Ver §11 para el
> detalle real de cada fase (verificado contra el código, no solo contra este diseño) — para
> la narrativa completa de hallazgos/decisiones/gaps encontrados durante la implementación
> ver `CLAUDE.md`.

## 1. Objetivo y alcance

Controlar la apertura, movimiento y cierre de efectivo (y otros medios de pago) por
caja/sede, garantizando trazabilidad de todo dinero que entra o sale del negocio a través
del POS. Diseñado multi-sede desde el inicio, con soporte para múltiples cajas simultáneas
por sede aunque en el lanzamiento solo se habilite **1 caja activa por sede**.

**Fuera de alcance por ahora:**
- Conciliación bancaria automática (solo se registra el depósito como movimiento).
- Multi-moneda (se asume soles, PEN).
- Cobro contra-entrega / caja móvil de repartidor (diseño queda abierto, ver sección 6).

---

## 2. Principio rector (extensión del patrón ya usado en SUNAT/correlativos)

> **Ningún movimiento de dinero debe ser silencioso.**

Así como `validarRegimenEspecial()` bloquea con 422 antes de quemar un correlativo, toda
venta en efectivo/tarjeta/transferencia debe **exigir una sesión de caja abierta** antes de
completarse (excepto e-commerce). Egresos manuales exigen motivo + concepto obligatorios.
Diferencias de cierre fuera de un umbral configurable exigen justificación obligatoria.

---

## 3. Fase 0 (prerequisito) — Catálogos base: `payment_methods`, `suppliers`, `cash_concepts`

**Por qué van separados y primero:** hoy `register.vue` tiene los métodos de pago
hardcodeados (`EFECTIVO`, `TRANSFERENCIA`, `YAPE`, `PLIN`, `TARJETA DE CREDITO`) en un
`<select>` estático. El dueño necesita poder agregar métodos nuevos sin depender de un
despliegue de código, y este catálogo también lo van a necesitar Adelantos y pagos de
cuotas de Amortizaciones — no es exclusivo de caja, así que no debe quedar empaquetado
dentro de la Fase 1. Lo mismo aplica para `suppliers` (necesario desde el primer día para
el buscador de contraparte en egresos manuales, sección 6) y `cash_concepts`
(necesario para clasificar ingresos/egresos manuales).

```
payment_methods                          -- por tenant
- id
- code             -- DEBE copiar exacto los valores actuales: "EFECTIVO", "TRANSFERENCIA",
                        "YAPE", "PLIN", "TARJETA DE CREDITO" (incl. mayúsculas/espacios)
                        para que las ventas históricas sigan coincidiendo sin migrar datos.
- name             -- visible, editable ("Yape", "Plin", con emoji si se mantiene)
- is_active
- sort_order

suppliers                                -- por tenant, catálogo simple (se amplía cuando exista el módulo de Compras)
- id
- name
- document          -- RUC/DNI, opcional
- phone             -- opcional
- is_active

cash_concepts                      -- por tenant, unifica conceptos de ingreso y egreso
- id
- name              -- "Comisión recibida", "Cobro de deuda a terceros", "Pago a proveedor", "Caja chica", "Retiro de seguridad"
- direction: in | out
- is_active
```

**Cambios necesarios:**
- `register.vue`: reemplazar el `<option>` hardcodeado por `GET /api/payment-methods?active=1`
  ordenado por `sort_order`. `method_payment` sigue enviándose igual que hoy — cero cambios
  en `SaleController`, `GreenterService` ni en cómo se guarda `sales.payment_method`.
- Backend: agregar guard en `SaleController::store()` — el método de pago recibido debe
  existir y estar activo en `payment_methods` (comparación normalizada,
  `LOWER(code) = LOWER(?)`, como cinturón de seguridad ante inconsistencias de formato).
  Si no existe, bloquear con 422 explícito, no aceptar el string a ciegas.
- Desactivar un método (`is_active = false`) no borra la fila ni afecta ventas históricas
  que ya lo usaron — solo impide usarlo en ventas nuevas.
- Seed inicial idempotente (`firstOrCreate` por `code`) al provisionar un tenant nuevo, para
  `payment_methods` y `cash_concepts` (conceptos típicos ya activos, editables después).

**Campos específicos de caja** (`affects_cash_count` en `payment_methods`, relación a
`bank_accounts`) se agregan recién en la Fase 1 de caja, no aquí — estos catálogos nacen
genéricos para no tener que rehacerlos.

---

## 4. Modelo de datos del módulo de caja

```
branches (sucursales)                         -- si no existe ya, su propio CRUD
- id
- name, code, address
- is_active

cash_registers (cajas)                        -- lógicas, no necesariamente hardware
- id
- branch_id (FK)
- name / code            -- "Caja 1", "Caja Principal"
- type: fixed | mobile    -- 'mobile' reservado para caja de repartidor a futuro (ver sección 6)
- is_active
- blind_close (bool, nullable)         -- null = hereda config de la sede/tenant
- default_opening_amount (decimal)     -- fondo fijo sugerido, editable al abrir (Opción C)

cash_sessions (aperturas/turnos)
- id
- cash_register_id (FK)
- opened_by (user_id), closed_by (user_id, nullable)
- opening_amount (decimal)             -- monto real ingresado al abrir (puede diferir del default)
- opening_amount_adjusted (bool)       -- true si el cajero editó el default_opening_amount
- opened_at, closed_at (nullable)
- status: open | closed
- expected_cash (calculado al cierre, solo efectivo)
- counted_cash (ingresado por cajero al contar)
- difference (counted_cash - expected_cash)
- difference_reason (text, nullable, requerido si |difference| > difference_tolerance)
- closing_notes

cash_session_totals (desnormalizado, uno por método de pago y sesión)
- cash_session_id, payment_method_id, expected_amount, movement_count

cash_movements (toda entrada/salida dentro de una sesión)
- id
- cash_session_id (FK)
- type: opening_fund | sale_payment | manual_income | manual_expense | correction
        | advance_received | installment_payment | credit_note_refund | bank_deposit
- payment_method_id (FK a payment_methods)
- direction: in | out
- amount
- reference_type + reference_id   -- polimórfico: sale, advance, installment, credit_note, cash_movement (para correction), null
- concept_id (nullable, FK a cash_concepts)   -- obligatorio si type = manual_income/manual_expense
- description                      -- obligatorio si type = manual_income/manual_expense
- counterparty_type (nullable)     -- enum: cliente | proveedor | empleado | socio | otro — solo manual_income/manual_expense
- counterparty_id (nullable)       -- FK a clients.id o suppliers.id según counterparty_type, si existe en el catálogo
- counterparty_name (nullable)     -- snapshot del nombre al momento del movimiento (autocompletado desde counterparty_id,
                                       o escrito manualmente si la persona no está registrada)
- counterparty_document (nullable) -- snapshot del RUC/DNI, mismo criterio que counterparty_name
- attachment_path (nullable)       -- foto/PDF de respaldo (boleta de compra, voucher, recibo firmado); campo listo desde
                                       ahora, la subida de archivos puede implementarse en una fase posterior sin migrar
- corrected_movement_id (nullable) -- si este movimiento es una corrección (type: correction), apunta al movimiento original
- corrected_by (nullable)          -- user_id de quién ejecutó la corrección (vía "editar" o "eliminar" en la UI)
- corrected_at (nullable)
- status: confirmed | pending_approval | rejected   -- solo relevante si require_expense_approval = true
- created_by (user_id), created_at

cash_session_denominations (opcional, detalle físico del arqueo)
- cash_session_id, denomination, quantity, subtotal
```

**Restricción de concurrencia:** una sola `cash_session` con `status = open` por
`cash_register_id` (lock igual al patrón de `reservarCorrelativo()` con `lockForUpdate()`).
Esto es lo que permite activar más cajas por sede mañana sin tocar el esquema.

---

## 5. Reglas de integridad confirmadas (no negociables, afectan el diseño base)

1. **Los `cash_movements` nunca se editan ni se borran a nivel de dato, aunque en la
   interfaz sí exista "Editar" y "Eliminar".** Al cajero se le da la experiencia normal de
   editar/eliminar un movimiento de ingreso/egreso manual, pero por debajo el sistema:
   - genera un `type: correction` que anula el movimiento original (mismo monto, dirección
     inversa, `corrected_movement_id` apuntando al original),
   - si fue "editar", además crea un nuevo movimiento con el dato correcto,
   - el original queda visible en el historial marcado como corregido/anulado
     (`corrected_by`, `corrected_at`), nunca desaparece ni cambia su contenido.
   Esto **solo se permite mientras la sesión sigue abierta** (el cajero corrigiendo su
   propio turno en curso); sobre una sesión ya cerrada requiere permiso de supervisor,
   igual criterio que la regla #5 (cierre por terceros).

2. **Una NC de una venta de una sesión ya cerrada impacta la sesión abierta actual,
   nunca la sesión cerrada de la venta original.** El `cash_movement` tipo
   `credit_note_refund` siempre se ata a la `cash_session` abierta al momento de la NC.

3. **Ventas a crédito no generan `cash_movement` al momento de la venta.** Solo se genera
   `installment_payment` cuando efectivamente se cobra una cuota. Esto ya es consistente con
   cómo diseñaste amortizaciones, pero queda explícito acá para no asumir lo contrario.

4. **Una caja no puede cerrar (ni un egreso puede registrarse) si el `expected_cash`
   resultante sería negativo.** Bloqueo 422 explícito — un negativo no existe físicamente
   en la caja real.

5. **Solo el cajero que abrió la sesión puede cerrarla, salvo que un usuario con permiso de
   supervisor la cierre/reabra en su lugar** (turno de emergencia). Esta acción de
   "cierre por terceros" debe quedar registrada explícitamente (quién, cuándo, por qué) —
   no es un cierre normal silencioso.

6. **Todo reembolso de una nota de crédito debe ir por el mismo `payment_method_id` de la
   venta original, nunca asumir efectivo por defecto.** Si la venta se pagó con tarjeta, el
   dinero nunca estuvo físicamente en la caja, así que el `credit_note_refund` no debe
   descontarse del efectivo esperado — debe reflejarse en el método original (reversión vía
   banco/POS), o quedar marcado como pendiente de reversión externa si el sistema no la
   gestiona directamente. Dejarlo a "efectivo por defecto" genera diferencias falsas de
   caja en cada cierre.

### 5.1 Validación de integridad recomendada (no bloqueante, de monitoreo)

- **Control cruzado venta vs. caja:** reporte que compare el total de `cash_movements`
  tipo `sale_payment` contra el total real de `sales` del mismo día/sesión. Si no cuadran,
  indica una venta sin movimiento generado o un movimiento huérfano — es una alerta de
  integridad del sistema, no un caso de negocio del cajero. Conviene tenerlo listo antes de
  producción, aunque no bloquee ningún flujo (se revisa en segundo plano o en un reporte
  aparte de auditoría técnica).

---

## 6. Casos de negocio a prever

### Apertura
- No se puede abrir una caja que ya tiene una sesión `open`.
- No se puede abrir una segunda caja en la misma sede mientras
  `allow_multiple_registers_per_branch = false`.
- Sesión de un turno anterior que quedó abierta (olvido del cajero): el sistema debe
  **alertar explícitamente** al intentar abrir una nueva sesión en esa caja.
- Fondo de apertura (Opción C): se pre-llena con `cash_registers.default_opening_amount`,
  el cajero puede editarlo; si lo edita, `opening_amount_adjusted = true` queda registrado
  (no bloquea, pero no es silencioso).

### Movimientos durante el turno
- **Venta:** cada pago de una venta genera un `cash_movement` tipo `sale_payment`. Una
  venta con pago mixto (efectivo + tarjeta + yape, ya soportado por `sales`) genera **una
  fila por cada método de pago usado**, mismo `reference_id` (la venta), distinto
  `payment_method_id` y monto.
- **Ingreso/egreso manual:** requiere concepto + descripción obligatoria. Egresos por
  encima de `max_expense_without_approval` (si `require_expense_approval = true`) quedan
  `status: pending_approval` hasta que un supervisor los apruebe o rechace.
- **Contraparte del movimiento manual (a quién se entregó / de quién se recibió):**
  - Ingreso (`counterparty_type = cliente`) → buscador filtra contra `clients` (ya existe
    en el sistema); al seleccionar, se guarda `counterparty_id` + snapshot de
    `counterparty_name`/`counterparty_document`.
  - Egreso (`counterparty_type = proveedor`) → mismo buscador contra `suppliers` (Fase 0).
  - Si la persona no aparece en el buscador (empleado, socio, alguien sin registro) →
    `counterparty_id` queda vacío, se escribe `counterparty_name` manualmente y se elige
    `counterparty_type: empleado | socio | otro`.
  - El nombre/documento se guarda como **foto fija** del momento (no referencia viva): si
    el cliente o proveedor cambia su nombre después en su propio registro, los movimientos
    de caja ya emitidos no cambian retroactivamente.
- **Adelantos (módulo futuro):** al recibirse un adelanto en efectivo, genera movimiento
  `advance_received`. Al aplicarse a una venta futura, no genera nuevo movimiento (el
  dinero ya entró antes).
- **Cuotas de amortización:** cada pago de cuota cobrado en caja genera
  `installment_payment`.
- **Notas de crédito con devolución en efectivo:** generan `credit_note_refund` — ver
  regla de integridad #2.
- **Depósito a banco:** egreso manual tipo `bank_deposit`.
- **Retiro de seguridad a mitad de turno:** se cubre como `manual_expense` con concepto
  "retiro de seguridad" — no necesita tipo ni tabla nueva.

### Cierre
- **Cierre ciego (configurable por caja/sede/tenant):** si está activo, el cajero cuenta
  sin ver el esperado; la diferencia se calcula después de guardado el conteo.
- **Cierre no ciego:** el sistema muestra el esperado y el cajero confirma o ajusta.
- **Diferencia fuera de `difference_tolerance`** (default S/ 2.00, configurable): exige
  `difference_reason` obligatorio para poder cerrar.
- **Cierre X vs Z:** X = consulta de estado sin cerrar la sesión; Z = cierre real, ya no
  admite más movimientos.
- **No puede cerrar en negativo** — ver regla de integridad #4.
- **Sesión abierta demasiado tiempo (> 24h):** alerta proactiva a un admin, no solo al
  momento de intentar abrir otra sesión sobre la misma caja.

### Bloqueos (guard pattern, igual que en ventas/SUNAT)
- Bloquear venta con pago en efectivo/tarjeta/transferencia si no hay `cash_session`
  abierta para la caja del cajero (HTTP 422 explícito).
- Bloquear egresos manuales sin concepto/descripción.
- Bloquear cierre con diferencia fuera de umbral sin justificación.
- Bloquear egreso o cierre que dejaría el esperado en negativo.
- Bloquear venta con `payment_method` que no existe o está inactivo en `payment_methods`.

---

## 7. E-commerce y casos sin caja física

> **✏️ Corregido en Fase 3 (2026-07-18) — este documento asumía `sales.channel = ecommerce`,
> que nunca existió.** Confirmado por grep contra el código real: el portal e-commerce no
> pasa por `Sale`/`SaleController` en absoluto — usa `Order`/`OrderController` (tabla
> `orders`, `order_items`), un pipeline completamente separado, sin ninguna conversión
> `order → sale`. En la práctica esto simplifica el diseño original: el guard de caja de
> `SaleController::store()`/`update()` (Fase 3) no necesita ninguna condición de canal,
> porque no hay otro canal llegando a ese controller hoy — aplica siempre. Si en el futuro
> se conecta e-commerce a `Sale` de alguna forma, ese día sí habrá que revisar esta sección.

El portal e-commerce no tiene caja física asociada. Sus ventas (tabla `orders`, no `sales`)
**no** exigen `cash_session` abierta ni generan `cash_movements` de caja física — se
reconciliarían en un reporte aparte si algún día se decide cruzarlas (no hay nada
implementado en esa dirección todavía).

**Nota para el futuro (no se implementa ahora):** si más adelante se maneja cobro
contra-entrega, el diseño ya lo permite sin rehacer el esquema — `cash_registers.type` se
extiende a `mobile` (atada a un repartidor/usuario en vez de a un punto físico fijo), y la
liquidación del efectivo cobrado al regresar se modela como un `cash_movement` tipo
`mobile_settlement` (mismo patrón, un valor más en el enum `type`).

**Estado real de la regla de integridad #6 (reembolso de NC) al 2026-07-18 (Fase 3,
exploratorio):** el módulo de Notas de Crédito no genera todavía ningún reembolso de dinero
real — lo único que toca plata hoy es `AdvanceRefund` (módulo Adelantos, separado), y solo
actualiza `advances.refunded_amount` como bookkeeping, sin ningún `cash_movement`. La regla
#6 (usar el `payment_method_id` original de la venta, atarse a la sesión abierta actual)
queda con nada que enganchar por ahora — se retoma cuando NC↔Caja se conecte de verdad
(candidato natural: Fase 6, junto con Adelantos/Amortizaciones).

---

## 8. Multi-sede

- Cada `cash_register` pertenece a una `branch`.
- Reportes filtrables por sede, caja, cajero y rango de fechas.
- Un cajero opera normalmente una sola sede/caja por turno; un admin ve el consolidado de
  todas las sedes.

---

## 9. Navegación y pantallas

**Acceso:** menú lateral → "Caja". El destino depende de permisos, no de un rol fijo.

- **Permiso `cash.open_session`** (asignable a cualquier rol vía Spatie, no exclusivo de
  "cajero" — un admin/supervisor puede tenerlo activado si necesita cubrir un turno cuando
  no hay vendedor disponible):
  - Con sesión abierta → entra directo a **Turno activo**.
  - Sin sesión abierta → entra a **Apertura de caja** (elige caja si hay más de una
    habilitada en su sede, ve el fondo sugerido pre-llenado y editable — Opción C).
- **Permiso `cash.view_all`** (típicamente cualquier admin): acceso al **dashboard/historial
  general** de todas las cajas y sedes, incluyendo alerta de sesiones abiertas > 24h.
  No es excluyente con `cash.open_session` — un usuario puede tener ambos.
- Sin ningún permiso de caja → el módulo no aparece en el menú.

**Pantalla — Turno activo (sesión abierta):**
- Encabezado: caja, sede, cajero, hora de apertura, fondo inicial.
- Lista cronológica de movimientos de la sesión en curso, con tipo, método de pago, monto,
  contraparte si aplica. Botones "Editar"/"Eliminar" por fila para movimientos manuales
  (ver regla de integridad #1 — UX de edición, corrección real por debajo).
- Totales en vivo por método de pago (equivale al "corte X", sin pantalla aparte).
- Botones: "Registrar ingreso", "Registrar egreso", "Cerrar caja".

**Pantalla — Historial de cajas (listado por fecha/apertura):**
- Columnas: fecha apertura, fecha cierre, sede, caja, cajero, fondo inicial, esperado,
  contado, diferencia, estado (abierta/cerrada).
- Filtros: rango de fechas, sede, caja, cajero (filtro de cajero visible solo con
  `cash.view_all` — un cajero sin ese permiso solo ve su propio historial).
- Clic en una fila cerrada → detalle de solo lectura (mismos movimientos, totales por
  método de pago, motivo de diferencia si lo hubo). Sesiones cerradas no admiten
  editar/eliminar desde esta vista salvo permiso especial de supervisor.

---

## 10. Configuración (tenant/sede/caja)

- `blind_close_default` (bool) — heredable, sobrescribible por `cash_register`.
- `allow_multiple_registers_per_branch` (bool) — default `false`.
- `difference_tolerance` (decimal) — default **S/ 2.00**, configurable por tenant/sede.
- `require_expense_concept` (bool) — default `true`.
- `require_expense_approval` (bool) — default `false`.
- `max_expense_without_approval` (decimal) — solo aplica si `require_expense_approval = true`.

> **⚠️ Precaución general (no específica de Caja), hallada en Fase 4 — 2026-07-19:**
> `App\Models\Company` no tenía estos 6 campos en su `$fillable`, así que
> `Company::update([...])` los descartaba en silencio (protección de asignación masiva de
> Eloquent, sin excepción ni log) — nadie lo notó porque hasta Fase 4 estos campos solo se
> habían LEÍDO vía el modelo, nunca escrito. Ya corregido (los 6 nombres están en
> `$fillable` desde Fase 4), pero si un módulo futuro agrega MÁS columnas a `companies`
> (candidato conocido: defaults de Adelantos en la Fase 6 de este mismo plan), **hay que
> acordarse de sumarlas también a `$fillable`** — el error no avisa solo, hay que
> verificarlo a mano (`var_dump()` antes/después de un `update()` de prueba, como se hizo
> acá) antes de dar por buena cualquier escritura nueva a esa tabla.

---

## 11. Fases de implementación propuestas

Estado real de cada fase, verificado contra el código (no solo contra este diseño). Detalle
completo de hallazgos/decisiones/gaps encontrados durante la implementación:
`CLAUDE.md`. Acá solo el resumen de qué se construyó.

**✅ Fase 0 — Catálogos base: `payment_methods`, `suppliers`, `cash_concepts`** (cerrada y
verificada contra `sandbox`, 2026-07-18). CRUD propio para cada uno + migración de
`register.vue`/`edit.vue` a consumo dinámico + guard en `SaleController::validarPagosPayload()`
(rechaza el payload completo, incluso con pago mixto, ante un método inválido/inactivo).
Verificado con 7 ventas reales a SUNAT BETA (5 métodos + 1 mixto + 1 inválido rechazado).
Drift real encontrado en `advances.payment_method` — documentado en Fase 6 abajo, no
corregido todavía (fuera de alcance de esta fase).

**✅ Fase 1 — Modelo de datos base de caja** (cerrada y migrada contra `sandbox`,
2026-07-18). `branches` (no existía, se creó), `cash_registers`, `cash_sessions` (con
índice único parcial de Postgres para una sola sesión `open` por caja, verificado en
`pg_indexes`), `cash_movements`, `cash_session_totals`, `cash_session_denominations`.
Settings de configuración (sección 10) agregadas a `companies` (mecanismo de config por
tenant ya existente) en vez de una tabla `cash_settings` nueva — `companies` ya cumplía ese
rol desde Amortizaciones (`mora_habilitada_default` etc.). Sin lógica de negocio, solo
estructura + relaciones.

**✅ Fase 2 — Apertura y cierre de caja** (cerrada y verificada contra `sandbox`,
2026-07-18). `CashSessionController` (open/close/status), lock de concurrencia igual que
`reservarCorrelativo()` + captura de `UniqueConstraintViolationException` como backstop del
índice único parcial. Resolución real de `blind_close` (propio de la caja, si no hereda
`companies.blind_close_default`) y de `expected_cash` en vivo (`expected_cash_live`, no
persistido — cubre el "corte X" sin pantalla aparte). Cierre por terceros con
`cash.close_others_session`. Los 5 puntos de verificación del prompt de esta fase
confirmados con evidencia real (API directa, 2 usuarios), no solo lectura de código. Comando
puntual `cash:seed-sandbox-demo` para poder probar sin CRUD de `branches`/`cash_registers`
todavía (pendiente, no es parte de esta fase).

**✅ Fase 3 — Integración con ventas** (cerrada y verificada contra `sandbox`, 2026-07-18).
Cambio 100% aditivo sobre `SaleController::store()`/`update()` — no tocó lógica fiscal
existente. Guard dispara por "algún pago con `amount > 0`" (no por `type_payment`), así
que el pago inicial de una venta a crédito también exige caja abierta; una venta 100% a
crédito sin pago inicial nunca la exige (regla #3). Sin `channel` que filtrar — confirmado
por grep que e-commerce no pasa por `SaleController` en absoluto (usa `Order`/tabla
`orders`, separado), así que el guard aplica a todo `store()`/`update()` sin condición de
canal (§7 de este documento tiene un dato viejo, `sales.channel = ecommerce`, que no existe
en el código real — pendiente de limpiar). Generación automática de `cash_movements` tipo
`sale_payment` dentro de la misma transacción de la venta (soporta pago mixto). La parte
delicada: los pagos de una venta SÍ son editables desde `update()` (reemplazo total
incondicional ya existente) — resuelto con el patrón `correction` de la regla de integridad
#1 (el original nunca cambia de contenido, solo se marca `corrected_by`/`corrected_at`);
sobre una sesión ya cerrada, corregir exige `cash.close_others_session` (mismo criterio que
cierre por terceros), tal como pide la regla #1 ("requiere permiso de supervisor", no
prohibición absoluta). NC (Paso 5, exploratorio): confirmado que el módulo de Notas de
Crédito no genera ningún reembolso de dinero real todavía — nada que enganchar en esta
fase. 6 puntos de verificación (los 5 originales + uno agregado por el usuario para el
caso de corrección sobre sesión cerrada, con y sin permiso) confirmados con evidencia real
(API + BD) contra `sandbox`.

**✅ Fase 4 — Movimientos manuales** (cerrada y verificada contra `sandbox`, 2026-07-19).
Reutiliza literalmente el patrón de corrección de Fase 3 (`type: correction`,
`corrected_movement_id`, `corrected_by`/`corrected_at`), no un mecanismo nuevo.
`computeExpectedCash()` se extrajo de `CashSessionController` a un service compartido
(`ExpectedCashCalculator`) antes de escribir código nuevo, para que Fase 2 y Fase 4 calculen
el efectivo esperado desde un solo lugar — de paso se agregó el filtro `status='confirmed'`
que faltaba (necesario ahora que existen `pending_approval`/`rejected`). `CashMovementController`
nuevo: `store()` con contraparte-como-snapshot-real (`counterparty_id` presente → el
nombre/documento SIEMPRE se resuelve del registro real, nunca del texto del payload),
aprobación condicional vía `companies.require_expense_approval`/`max_expense_without_approval`,
adjunto real (`Storage::disk('public')->putFile()`, patrón ya usado en 5 controladores del
proyecto). Buscador de contraparte replica el patrón ya existente de
`NotaElectronicaController::buscarVenta()`. Validación de "no negativo" (regla #4)
generalizada en un solo helper reusado por `store()`/`approve()`/`update()`/`destroy()`.
Bug real encontrado y corregido en el camino (no del código de esta fase, pero descubierto
al probarla): `Company::$fillable` nunca incluía los 6 campos de Caja de Fase 1 —
`Company::update()` los descartaba en silencio; la primera corrida del checklist de 8 puntos
falló exactamente por esto, se descartó por completo y se re-verificó desde cero después del
fix (confirmado con `var_dump` antes/después). 8 puntos de verificación confirmados con
evidencia real (API + BD) contra `sandbox`.

**✅ Fase 5 — Reportes** (cerrada y verificada contra `sandbox`, 2026-07-19). Historial de
sesiones con filtros (`CashSessionController::index()`) + detalle de solo lectura (`show()`),
dashboard admin con alerta de sesión abierta >24h (`dashboard()`, gateado binario a
`cash.view_all`, sin pasar por `CashVisibilityResolver` — decisión explícita, es exclusivo de
admin desde la puerta de entrada, no un filtro parcial), PDF de cierre individual (soporta
sesión cerrada real y vista previa de sesión abierta, `expected_cash_live`) y PDF de rango
consolidado (máx. 31 días, 422 explícito si se excede), y export Excel de movimientos —
primera vez que el proyecto usa una librería de Excel (`maatwebsite/laravel-excel`, requirió
habilitar `ext-zip` en `php.ini` + reiniciar Apache). `CashVisibilityResolver` (servicio
nuevo): sin `cash.view_all` el filtro `opened_by` ajeno se ignora en silencio (nunca 403);
con el permiso, se respeta. Bug real encontrado y corregido: `serializeSession()` (desde
Fase 2) armaba `totals_by_payment_method` sin filtrar `status='confirmed'` como sí hacía
`ExpectedCashCalculator` desde Fase 4 — sin impacto en checklists de Fases 2/3 (esos estados
no existían todavía), posible impacto de solo-visualización (nunca de datos) en un punto de
Fase 4. Corrección sobre una decisión previa: el filtro de sede/caja de `history.vue` iba a
derivarse de las sesiones cargadas en pantalla, se corrigió a catálogos reales de solo
listado (`BranchController`/`CashRegisterController::index()`) antes de cerrar la fase.
`stores/auth.ts::isPermitedRoute()` extendido para aceptar `"a|b"` (OR de permisos) —
verificado con 17 casos aislados, sin regresión. **Hallazgo real, fuera de alcance de Caja**:
`AuthController::respondWithToken()` arma `permissions` desde la relación legacy `role_id`
(`role->permissions`), no `getAllPermissions()` de Spatie — cualquier permiso asignado
directamente a un usuario (patrón usado repetidamente en este proyecto) nunca llega al
frontend aunque el backend sí lo respeta; documentado, no corregido (decisión explícita,
afecta el login de todos los tenants). 7 puntos de verificación confirmados con evidencia
real (API + BD + PDF extraído con `pdftotext` + Excel parseado con `PhpSpreadsheet`) contra
`sandbox`.

**✅ Fase 6 — Integración con Adelantos y Amortizaciones** (cerrada y verificada contra
`sandbox`, 2026-07-19). Ambos módulos ya existían y estaban maduros. `AdvanceController::
store()`: guard de sesión abierta + validación de método de pago contra el catálogo (ambos
antes de `DB::beginTransaction()`), genera `cash_movement` tipo `advance_received`; aplicar
un adelanto a una venta futura (`SaleController::store()`) confirmado que NO genera
movimiento nuevo. `advances/create.vue` migrado a `payment-methods?active=1` — resuelve la
inconsistencia de Fase 0 (`TARJETA`→`TARJETA DE CREDITO`, Yape/Plin ya no fusionados); 0
registros históricos afectados (los 3 adelantos reales de `umbo` ya usaban EFECTIVO), fix
puramente hacia adelante. `CreditPaymentController::store()`: mismo guard; decisión de
diseño confirmada — **un solo `cash_movement` por `PaymentReceipt` completo** (no uno por
cuota/aplicación, porque un recibo puede cubrir varias cuotas/ventas a la vez con un único
medio de pago), `amount = monto_total` (incluye lo que quedó como `saldo_a_favor`).
`anular()` conectado. **`CashCorrectionService` extraído** (nuevo, mismo criterio que
`ExpectedCashCalculator`): el patrón de corrección estaba replicado 3 veces
(`SaleController` Fase 3, `CashMovementController` Fase 4, `CreditPaymentController` recién
escrito). Convención unificada: la corrección usa `reference_type='cash_movement'`/
`reference_id=<original>` (apunta a QUÉ anula) — ajustó `SaleController` (antes usaba
`reference_type='sale'`/`venta.id`); impacto rastreado hacia atrás: el checklist de Fase 3
no afirma nada sobre ese campo, sin regresión. `refund()` (§3.12, liquidación de devolución
tras NC) queda **explícitamente fuera de alcance** — mismo límite NC↔Caja anotado en Fase 3
(`medio_devolucion` sin validar contra el método real de la venta, dinero que pudo no haber
tocado la caja física). 6 puntos de verificación + 1 adicional (`anular()` con
`reference_type` correcto) confirmados con evidencia real contra `sandbox`.

**Fase 7 — Multi-caja simultánea (activación futura, sin migración)**
`allow_multiple_registers_per_branch = true`, UI de selección de caja al iniciar turno,
dashboard consolidado por sede con varias cajas abiertas en paralelo.

**Cuándo activar esta fase:** cuando el negocio abra efectivamente una segunda caja
simultánea en alguna sede (no antes — el diseño ya lo soporta sin migración).

**Qué NO hay que hacer:** no hay tablas nuevas ni migraciones — `cash_registers`,
`cash_sessions` y el índice único parcial de Postgres
(`cash_sessions_one_open_per_register`) ya soportan N cajas por sede desde la Fase 1.

**Qué sí hay que hacer al activar:**
- Cambiar `companies.allow_multiple_registers_per_branch` a `true` para el tenant que lo
  necesite (o exponerlo como configuración editable desde el panel si no lo está ya).
- Crear la(s) caja(s) adicional(es) en `cash_registers` para la sede correspondiente (vía
  el CRUD de `branches`/`cash_registers` — confirmar si ya existe para ese momento, dado
  que a julio 2026 seguía pendiente como catálogo administrable completo).
- Frontend: en la pantalla de apertura (`views/cash/session.vue`, Fase 2), agregar el
  selector de caja cuando `available_registers` de `GET /api/cash/status` devuelva más de
  una opción para la misma sede — la lógica de "fija si hay una sola, selector si hay más
  de una" ya estaba prevista en el diseño original de Fase 2, solo nunca se ejercitó en la
  práctica porque `sandbox` nunca tuvo 2 cajas activas simultáneas en la misma sede.
- Dashboard admin (`views/cash/dashboard.vue`, Fase 5): confirmar que el agrupamiento por
  sede sigue siendo legible con varias cajas abiertas a la vez por sede — hoy nunca se
  probó ese caso con datos reales.
- Revisar si el filtro de "caja" en el historial (`history.vue`, Fase 5) necesita algún
  ajuste de UX cuando hay más de una caja activa por sede (hoy el catálogo ya lista todas
  las cajas, así que probablemente no requiera cambios, pero no fue verificado con más de
  una caja real).

**Deuda técnica relacionada, pendiente de otra sesión (no bloquea esta fase):**
- CRUD completo de `branches`/`cash_registers` (hoy solo existe listado de solo lectura,
  Fase 5).
- Bug de `AuthController::respondWithToken()` (permisos directos de usuario no llegan al
  frontend).
- Filtro de cajero en `history.vue` derivado de sesiones cargadas en vez de un catálogo
  real de usuarios.

*(Nota aparte, no es una fase de caja: cuando se defina el módulo de delivery/contra-entrega,
retomar `cash_registers.type = mobile` — ver sección 7.)*

---

## 12. Notas abiertas para revisar cuando lleguen esas fases (no bloquean el diseño actual)

- **Comisiones bancarias** sobre tarjeta/Yape/Plin: el monto cobrado al cliente no siempre
  es el que termina depositado. Relevante cuando se defina `bank_accounts`, no ahora.
- **Ticket de impresión física del cierre** (distinto del PDF de reporte ya definido en la
  Fase 5): se conecta con el módulo de impresión A4/ticket 80mm — un resumen corto pensado
  para imprimirse en la impresora térmica al momento del cierre (no el PDF completo de
  respaldo), para que el cajero se quede con un comprobante físico inmediato.
- **`cash_difference_reasons`** como catálogo (en vez de texto libre) para motivos de
  diferencia — evaluar si se necesita después de ver los primeros cierres reales.
- **`shift_label`** (nullable, en `cash_sessions`): etiqueta opcional ("mañana", "tarde",
  "noche", "libre") elegida por el cajero al abrir, solo para poder agrupar reportes por
  tipo de turno aunque el horario real varíe día a día. No es una hora fija del sistema —
  una sesión puede durar 10 horas y cubrir dos "turnos" de trabajo sin que el sistema
  fuerce a partirla, salvo que cambie el cajero (ver regla de integridad de sesión única).
- **Traspaso de efectivo entre cajas** (relevante recién en Fase 7, multi-caja): no es un
  ingreso ni un egreso real del negocio, es dinero que se mueve de una caja a otra. Se
  modelaría con dos `cash_movements` enlazados (`type: transfer_out` / `transfer_in`,
  referencia cruzada) para no contarlo como gasto ni ingreso real.
- **Vale/adelanto a un empleado** (distinto de un egreso normal, si esto ocurre seguido):
  un egreso normal es dinero que sale y no vuelve; un vale se espera que se descuente
  después del pago del empleado. Si es frecuente, valdría un `type: employee_advance` con
  estado (`pending_return` / `settled`); si es esporádico, un `manual_expense` con
  concepto "vale a empleado" es suficiente por ahora.
- **Redondeo de efectivo** (común en Perú por escasez de monedas de 1 y 5 céntimos): si el
  negocio redondea el cobro en efectivo al múltiplo de S/0.10 más cercano, el `expected_cash`
  debe calcularse con el monto realmente cobrado (redondeado), no con el total exacto de la
  venta, o el cierre mostrará una diferencia falsa todos los días. Pendiente confirmar si
  el negocio aplica este redondeo hoy.
- **Filtro de cajero en `history.vue` (Fase 5) sigue derivado de las sesiones ya cargadas
  en pantalla**, no de un catálogo real — a diferencia de sede/caja (corregidos con
  `BranchController`/`CashRegisterController` de solo listado), este filtro solo muestra
  cajeros que aparecen en la página actual de resultados. Ya existe CRUD de Usuarios que
  podría reutilizarse para resolverlo del todo; decisión explícita de dejarlo así por ahora
  (no bloquea el cierre de Fase 5).
- **⚠️ Bug real encontrado durante la verificación de Fase 5 (2026-07-19), fuera de alcance
  de Caja — afecta el login de TODO el sistema, cualquier tenant**:
  `AuthController::respondWithToken()` arma `permissions` desde
  `auth('api')->user()->role->permissions` (la relación legacy `role_id`, solo permisos
  atados al ROL) en vez de `getAllPermissions()` (el método real de Spatie, que mezcla rol +
  permisos asignados directamente al usuario). El backend (middleware `permission:` de
  Spatie) sí usa `getAllPermissions()` correctamente — la seguridad del servidor nunca
  estuvo comprometida — pero el **frontend** (`isPermitedRoute()`, menú, guard de rutas)
  nunca ve un permiso asignado directamente a un usuario (patrón que este mismo proyecto usa
  repetidamente: los 4 permisos de crédito, `cash.close_others_session`,
  `cash.approve_expenses`), así que oculta menús/bloquea rutas que el backend sí permitiría.
  Confirmado con evidencia real contra `sandbox`: login de `cajero.test` (permisos directos
  `cash.close_others_session`/`cash.approve_expenses`) devuelve `"permissions": []` extra más
  allá de lo que trae el rol Cajero. Decisión explícita: no corregir en esta sesión (blast
  radius = login de todos los tenants), documentar y seguir. Para poder verificar el Paso 8
  de Fase 5 con datos reales, se creó un rol nuevo `Supervisor Caja (test)` con
  `cash.view_all` atado al ROL (no al usuario) — evita el bug para esa prueba puntual, no lo
  resuelve.
  **Además, artefacto de datos separado (ya corregido, no es bug de código)**: los usuarios
  de prueba `cajero.test`/`cajero2.test` en `sandbox` tenían `users.role_id = 1`
  ("Super-Admin") en vez de 5 ("Cajero", su rol Spatie real) — causaba que
  `isPermitedRoute()` los tratara como Super-Admin y bypasee todo chequeo de permiso en el
  frontend. Verificado que usuarios reales del tenant `umbo` (creados vía
  `UserController::store()`) SÍ tienen `role_id` sincronizado correctamente — el desajuste
  era exclusivo de estos 2 fixtures creados por tinker en una fase anterior, no un problema
  del código de `UserController`. Corregido con un `UPDATE` directo sobre esos 2 usuarios.
- **`CreditPaymentController::refund()` (§3.12, liquidación de devolución de venta anulada
  por NC) sigue sin conectar a Caja — decisión explícita, 2026-07-19, al cerrar Fase 6 de
  Caja.** Es exactamente el límite NC↔Caja ya anotado como pendiente en Fase 3 (regla de
  integridad #6), no algo que Fase 6 (Adelantos/Amortizaciones) haya scopeado. Hallazgos
  confirmados en código (no teóricos): `medio_devolucion` es texto libre
  (`required|string`) sin ningún cruce contra el/los método(s) de pago real(es) de la venta
  — una venta puede tener `payment_applications` de varios `payment_receipts` con
  `medio_pago` distintos entre sí, y este endpoint los agrega en un solo `PaymentRefund` con
  un solo `medio_devolucion`, sin validar coherencia. Además, el propio código documenta que
  `medio_devolucion`/`fecha_devolucion` capturan un hecho **ya ocurrido** ("liquidar", no
  "ejecutar") — el dinero pudo haber salido por un canal que nunca tocó la caja física
  (ej. transferencia bancaria directa). Conectarlo bien requiere resolver la regla #6 (qué
  método(s) usar, qué hacer si el pago original fue mixto, qué hacer si el reembolso nunca
  pasó por caja) antes de generar cualquier `cash_movement` de salida ahí.

---

## 13. Otras tablas/formularios recomendados (para un plan aparte)

`payment_methods`, `suppliers` y `cash_concepts` ya quedaron incorporados a la Fase 0
de este plan (sección 3), no a un plan aparte. Lo que queda pendiente para un plan
independiente:

1. **`branches`** (sucursales) — si no existe como tabla propia, caja depende de ella
   igual (sección 4), así que en la práctica también conviene resolverla junto con la Fase 0.
2. **`bank_accounts`** — para que Yape/Plin/tarjeta se asocien a la cuenta bancaria real
   sin repetir el dato en cada método de pago.
3. **`print_profiles`** — formato de impresión (A4 / ticket 80mm) por defecto, por usuario
   o por caja — conecta con el módulo de impresión ya priorizado.
4. **`credit_note_reasons`** — motivos de NC editables por el dueño (la etiqueta, no el
   código SUNAT fijo del catálogo 09).

Opcionales, solo si se vuelven un problema en la práctica: `cash_difference_reasons`,
`units_of_measure` (si no es ya un catálogo normativo SUNAT fijo internamente).

---

## 14. Principios ya establecidos que aplican aquí

- **Backend es fuente de verdad:** todo cálculo de esperado/diferencia se valida en
  backend, frontend solo muestra.
- **Guard pattern:** `abort(422)` explícito ante cualquier estado inválido — nunca
  fallback silencioso.
- **Transacciones atómicas:** apertura, cierre y registro de movimientos dentro de
  `DB::transaction()` con `lockForUpdate()` donde haya riesgo de concurrencia.
- **Nunca se edita ni se borra un movimiento de dinero** — se corrige con un movimiento
  inverso, igual que un documento SUNAT nunca se reescribe.
- **Seeders idempotentes:** cualquier dato semilla (`payment_methods`, cash_concepts) con
  `firstOrCreate()`.
- **Revisión por fases:** cada fase se implementa, se revisa contigo y se prueba antes de
  pasar a la siguiente — igual que en el módulo de amortizaciones.
