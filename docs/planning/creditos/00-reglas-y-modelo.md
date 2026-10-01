# Créditos — 00 Reglas y modelo (fuente de verdad)

Versión consolidada 2026-09-30 (reemplaza plan v24 + sección 12 + `creditos-decisiones.md`). Sin parches: si algo cambia, se edita aquí en su lugar.
Leer siempre este archivo + el de la fase en curso (`03-api.md`, …). `historial.md` es solo consulta.

## 0. Contexto y convenciones
- Tenant nuevo de un cliente externo (prestamista), `giro='creditos'`. Monedas: solo soles.
- Stack: Laravel `api-sistema-fe` (stancl/tenancy, migraciones en `tenant/core/`), Vue 3 `admin-start-kit`, Postgres.
- Namespaces: `App\Services\Creditos\Motor\*` (motor puro, hecho), `App\Services\Creditos\*` (servicios), `App\Models\Creditos\*`, `App\Enums\Creditos\*`, `App\Http\Controllers\Creditos\*`, frontend `views/creditos/`. **No usar `Credit`** (es Amortizaciones).
- Identificadores en español, sin tildes/ñ; `$table` explícito; toda columna con `->comment()`.
- Estados: `enum()` en BD + PHP backed enums. Sin `softDeletes`: anulación por estado + `motivo_anulacion`, `anulado_por`, `anulado_en`. Columnas de usuario sin FK.
- Dinero: `numeric(12,2)` en BD, **centavos enteros** en código; única función de redondeo `Motor\Redondeo`. `paso_redondeo` = S/ 0.10.
- Fechas: el motor recibe la fecha; "hoy" = controller en `America/Lima`. Vencida = `fecha_vencimiento < hoy`.
- Regla de trabajo: Claude Code no cambia código/BD sin aprobación de la fase.

## 1. Reglas de negocio

### 1.1 Interés (`simple_fijo`)
- Se calcula una vez sobre el capital inicial. `unidad_tasa`: `total` (capital × tasa) o `mensual` (capital × tasa × n × fracción). Mes comercial de 30 días: mes 1, año 12, quincena ½, semana 7/30, día 1/30.
- `interes_total` se calcula en fracción exacta y se redondea **una vez a S/ 0.10** (medio hacia arriba). Ese es el interés "exacto" del contrato.
- `monto_capital` debe ser múltiplo de 0.10 (motor: `CondicionesInvalidas`; FormRequest).
- Métodos futuros sin cambiar tablas: `sobre_saldo`, `cuota_fija`, `capital_fijo`.

### 1.2 Cuotas y redondeo
- Capital ÷ n e interés ÷ n se redondean a 0.10 **por separado**; la última cuota absorbe la diferencia de cada componente → toda cuota (incl. la última) es múltiplo de 0.10 y las sumas cuadran exactas. Ej.: 1,000 + 200 en 30 → 33.30 + 6.70; última 34.30 + 5.70.
- Salvaguarda: si un componente de la última cuota quedara negativo, ese componente se redondea hacia abajo (ej. interés 1.50 en 30 → 0.00 × 29 y 1.50 al final).
- Adelantar cuotas no descuenta interés; solo la liquidación (1.5).

### 1.3 Frecuencias y calendario
- `frecuencia_unidad`: `dia | semana | mes | anio | quincena`; `frecuencia_intervalo ≥ 1`. Quincenal: `dia`+15 (corrido) o `quincena` + `dias_quincena` (ej. [15,"ultimo"]). Fin de mes: ajustar al último día.
- Días no laborables (config del tenant → copiados al crédito → congelados al activar): `dias_no_laborables` (ISO 1-7, default [7]), `saltar_feriados`, `regla_no_laborable` (`siguiente|anterior|mantener`), `mora_cuenta_no_laborables`.
- Frecuencias en **días** (cualquier intervalo): el día no laborable **no genera cuota** (se salta). Otras frecuencias: se mueve la fecha según la regla. Si `anterior` retrocedería antes de la cuota previa o del desembolso → se usa `siguiente` y la cuota marca `fechaForzadaASiguiente`.
- Feriados por tenant (sembrados: nacionales desde 2024 + Semana Santa calculada); el motor los recibe como parámetro.

### 1.4 Exigible y excedente
- **Exigible hoy** = cuotas vencidas + mora (incl. congelada) + cargos pendientes + **la próxima cuota por vencer** (`AplicadorPagos::montoExigible`).
- Lo que supere el exigible es excedente. Destinos: **`devolver` (default)**, `adelanto`, `saldo_a_favor`. Con `devolver`/`saldo_a_favor` no se rechaza el pago. Con `adelanto`, si lo aplicado supera la liquidación → `PagoExcedeDeuda` ("usa Liquidar").
- Caja registra el total recibido; la devolución es una salida aparte. Efectivo en persona: se da vuelto y se registra lo que corresponde.

### 1.5 Orden de aplicación de pagos
1. Cuotas vencidas (antigua → nueva): interés → capital → cargo.
2. Mora (antigua → nueva).
3. Excedente según 1.4.
- `fecha_pago` = ahora, salvo pago retroactivo autorizado (1.12). Reparto determinista por `fecha_pago`.
- Crédito `finalizado` (`pagado_completo`) cuando todas las cuotas vigentes están pagadas y no queda mora ni cargos.

### 1.6 Mora
- Por cuota, diaria, sobre el **saldo pendiente de la cuota**, por tramos (reconstruidos desde las aplicaciones). No corre sobre cargos; no se capitaliza; se detiene al pagar la cuota.
- `mora_diaria = saldo_pendiente ÷ días calendario del período ORIGINAL de la cuota`.
- Cuenta desde el **fin de la gracia** (`dias_gracia`): vence 10, gracia 3, paga 15 → 2 días. `mora_cuenta_no_laborables` solo afecta el conteo de días de mora; `diasAtraso` (escalamiento/castigo/bloqueo) es siempre calendario.
- **Mora habilitable** (`cobra_mora`, default `true`, 01-oct-2026): default en `credito_configuracion`, editable por crédito en "Opciones avanzadas", congelado al activar (cambia vía Corregir si no hay pagos). `false` = el motor no genera mora (`ReglasMora::cobraMora`); `diasAtraso` sigue corriendo (escalamiento, castigo, bloqueo); el contrato dice "no cobra interés moratorio"; no se ofrece "Condonar mora".
- Tope configurable (congelado al activar): `porcentaje_cuota` (default 100%), `porcentaje_capital`, `dias_maximos`, `sin_tope`.
- Castigo: `ReglasMora` recibe lista de `PeriodoCastigo`; los días castigados no generan mora; al revertir, corre desde la reversión (sin retroactivo).
- Condonación en `credito_condonaciones` (se conserva al recalcular). Mora pendiente se calcula en vivo; cobrada/condonada se guarda. En documentos: "Interés moratorio".
- Ej.: 1,000 al 20% en 1 cuota de 1,200 (30 días), paga 5 días tarde, gracia 0 → 40 × 5 = 200.

### 1.7 Liquidación anticipada
```
interes_devengado = interés de cuotas vencidas (cobrado o no) + proporcional de la cuota en curso (días reales)
interes_final     = min( max(interes_devengado, capital × tasa_interes_minimo), interes_total )
monto_liquidacion = capital pendiente + interes_final − interés ya cobrado + mora pendiente + cargos pendientes
```
- `tasa_interes_minimo` default 10% (sobre capital prestado), editable por crédito.
- Reparto: capital a todas las cuotas; interés por cobrar en orden de cuotas; el resto → `interes_condonado` (derivado del reparto, nunca guardado aparte). Todas las cuotas quedan `pagada`.
- Crédito `finalizado` (`liquidacion_anticipada`), pago `origen='liquidacion'`, `es_cierre=true`. Cotización válida solo para la fecha; el backend recalcula al confirmar.
- Ej.: 5,000 al 20%, 10 × 600, pagó 3, liquida a mitad de la cuota 4 → 3,500 + 500 − 300 = **3,700** (500 condonado).

### 1.8 Anulación de pagos (extorno con recálculo)
- Nunca se edita ni borra un pago (solo `referencia`/`observaciones`, auditado). Se puede anular cualquiera: pago → `anulado`; se **reaplican todos los pagos válidos** en orden de `fecha_pago`; aplicaciones previas `vigente=false`, nuevas con `generacion+1`; acumulados de cuotas recalculados; caja: reverso con `CashCorrectionService` (si la sesión cerró, va a la caja abierta actual).
- **Bloqueos**: (a) si al reaplicar una operación de cierre (liquidación, renovación, venta de prenda que liquidó) deja de alcanzar (`ResultadoAplicacion::cierresInsuficientes`), primero se anula esa operación de cierre (reabre crédito, revierte condonación y caja); (b) no se anula un pago anterior a una reprogramación de fechas (usar condonación/ajuste).
- Permisos: el usuario anula su propio pago con su caja abierta; si no, `creditos.anular_pago`.

### 1.9 Corrección, anulación y reprogramación del crédito
- `borrador`: edición libre. `activo` sin pagos: **Corregir** (`creditos.corregir`, motivo) → mismo número, cuotas viejas `anulada`, `version_cronograma_actual+1`; si cambia el capital, ajuste de caja. **Anular crédito**: solo sin pagos, revierte desembolso.
- **Reprogramar fechas** (`creditos.reprogramar`, motivo, preview): desplazar N días desde la cuota X o editar una a una; solo cuotas pendientes, fechas ascendentes, no antes de hoy. Cambia la fecha en la misma cuota (historial en `credito_reprogramacion_cuotas`, `fecha_vencimiento_original`). Mora ya acumulada: admin elige **mantener** (`mora_congelada`) o **condonar**. Cargo configurable (`ninguno|fijo|interes_por_dias`), editable por admin, sumado a la primera cuota reprogramada. Genera "Acuerdo de reprogramación".
- Reprogramación con cambio de montos y amortización parcial de capital: fase posterior.

### 1.10 Límites de otorgamiento (`EvaluadorLimites`)
| Regla | Efecto |
|---|---|
| `max_creditos` (default 2), `deuda_maxima` (saldo capital + nuevo), `moroso` (atraso > `dias_atraso_bloqueo`, default 7), `bloqueado` (manual o con crédito castigado) | Bloquea; admin autoriza con motivo (`creditos.autorizar_excepcion`, `credito_autorizaciones`) |
| `propio_garante` | Bloquea, no autorizable (también en migración) |
| `garante_moroso`, `garante_saturado` (`max_garantias_por_garante`, default 3) | Advierte |
- Ajustes por cliente en `credito_cliente_limites`. Migración: todo advierte salvo `propio_garante`. Renovación: el crédito renovado se excluye de deuda y conteo pero **sí** cuenta para `moroso`. Castigados cuentan como activos.
- Se evalúa al crear borrador (advertencia) y al activar (bloqueo real, dentro de la transacción).

### 1.11 Escalamiento por atraso (job diario)
| Días de atraso (calendario) | Acción |
|---|---|
| > `dias_atraso_bloqueo` (7) | Cliente bloqueado para nuevos créditos |
| > `dias_aviso_garante` (15) | Lista "Cobrar al garante" |
| > `dias_para_venta` (30) | Prendas `apta_para_venta` (vuelven a custodia si se pone al día) |
| > `dias_para_castigo` (90) | Crédito `castigado` (también manual: `creditos.castigar`; reversible, auditado) |
- Castigado: sale de cartera activa y del % en riesgo; mora congelada; pagos = **recupero** (`esRecupero`, solo con castigo vigente); pagado todo → `finalizado` (`recupero_castigo`); cliente bloqueado hasta desbloqueo manual.

### 1.12 Operaciones especiales
- **Crédito existente (migración)** (`creditos.migrar`): condiciones originales con fecha pasada; pagos históricos modo rápido ("cuotas 1..N a tiempo") o detallado (fecha + monto); `origen='saldo_inicial'`, `origen_registro='migracion'`, **sin caja**; límites solo advierten; se sube el contrato firmado.
- **Renovación**: liquidación del actual (1.7) descontada del nuevo; entrega neta = capital nuevo − liquidación (> 0); anterior `finalizado` (`renovacion`), pago `origen='renovacion'` `es_cierre=true` sin caja; caja solo la salida neta; `credito_renovado_id`. Ej.: debe 300, pide 1,000 → entrega 700.
- **Pago con fecha anterior** (`creditos.pago_fecha_anterior`, motivo, máx. `dias_max_pago_retroactivo` = 3): `fecha_pago` real, `created_at` = registro; reaplica pagos posteriores; caja: entra a la caja abierta actual.
- **Desembolso** siempre completo (sin comisión ni interés adelantado).
- **Activar** exige `fecha_desembolso` = hoy (desembolsos pasados → migración). `fecha_primer_vencimiento` guarda la fecha **pedida** (ancla del cronograma), no la ajustada.
- `fecha_pago` se guarda en hora de Lima (fecha de negocio, indicado en el comment de la columna); timestamps de auditoría en UTC.
- Mora congelada por reprogramación lleva la fecha de la reprogramación (`MoraCongelada`) y solo es cobrable desde ese día.

### 1.13 Garantías (módulos adicionales, fase 7)
- Garantes: opcionales, varios, son clientes (`credito_garantes`); pagos registran `pagado_por_cliente_id`.
- Prendas (`equipo|vehiculo`), custodia del prestamista, sin cobro de custodia, fotos al ingreso y devolución, `valor_tasado` (+ `porcentaje_prestamo_max`/`validacion_tasacion` preparados, hoy `ninguna`). Ciclo `en_custodia ⇄ apta_para_venta → en_venta → vendida` / `devuelta`.
- Venta: si precio ≥ liquidación → liquida (1.7), excedente se devuelve (salida `prenda_excedente`); si es menor → pago normal con destino `adelanto`, cronograma intacto, saldo al titular/garante.
- Módulos `creditos_garantes`, `creditos_prendas`, `creditos_cobradores` activables desde central-panel (sistema de `plan-modulo-planes-acceso.md` §2). Desactivar con datos = solo lectura/gestión de lo existente.

### 1.14 Roles
| | Admin | Cajero | Cobrador |
|---|---|---|---|
| Ver créditos | Todos | Todos | Su cartera (filtrado en backend) |
| Crear/activar/desembolsar | ✓ | ✓ | — |
| Cobrar | ✓ | ✓ | ✓ su cartera |
| Anular propio pago con su caja abierta | ✓ | ✓ | ✓ |
| Anular cualquiera, corregir, reprogramar, condonar, castigar, migrar, pago retroactivo, autorizar excepción, configurar | ✓ | — | — |
- Roles sembrados al crear el tenant: Administrador de créditos, Cajero de créditos, Cobrador. 16 permisos `creditos.*` (incluye `creditos.ver_todos`: sin él, solo se ve la cartera asignada).
- Cobradores/cartera/caja del cobrador: módulo `creditos_cobradores` (fase 8). v1 solo online; `clave_idempotencia` en toda escritura.

## 2. Modelo de datos (implementado, commit `675e320`)
Fuente de verdad: las migraciones de `database/migrations/tenant/core/` y sus comments. Tablas:
- Núcleo: `creditos` (condiciones congeladas, `version_cronograma_actual`, `origen_registro`, `credito_renovado_id`, tope de mora, `fecha_castigo`), `credito_cuotas` (acumulados derivados, `fecha_vencimiento_original`, `mora_congelada`, cargo, `dias_atraso_al_pagar`), `credito_pagos` (`numero_recibo`, `monto_recibido/aplicado/excedente`, `destino_excedente`, `origen`, `es_cierre`, `clave_idempotencia` unique, `pagado_por_cliente_id`), `credito_pago_aplicaciones` (`concepto interes|capital|cargo|mora`, `generacion`, `vigente`).
- Ajustes: `credito_condonaciones`, `credito_cargos`, `credito_reprogramaciones`, `credito_reprogramacion_cuotas`, `credito_castigos`, `credito_autorizaciones`, `credito_saldo_favor_movimientos`.
- Config y clientes: `credito_configuracion` (fila única, todos los defaults), `credito_cliente_limites`, `credito_cliente_fichas`, `credito_cliente_archivos`, `feriados`, `credito_correlativos` (`CR-00000001`, `RC-00000001`; número se asigna al **activar**).
- Documentos y otros: `credito_plantillas`, `credito_documentos`, `credito_gestiones`, `cartera_asignaciones`, `credito_garantes`, `prendas`, `prenda_fotos`, `credito_cartera_diaria`, `credito_auditoria`.
- Central: `menu_items.giros_excluidos` (tenant `creditos` no ve retail/agencia; sí Clientes, Caja, Métodos de pago).

## 3. Caja (`reference_type`)
`credito_desembolso` (salida) · `credito_pago` (entrada) · `credito_devolucion_excedente` (salida) · `prenda_venta` (entrada) · `prenda_excedente` (salida) · correcciones vía `CashCorrectionService` · transferencia caja cobrador → principal. Todo dentro de la misma transacción que la operación. Sin caja: migración y la parte de liquidación en una renovación.

## 4. Estándares
- **Código**: `strict_types`, tipos completos, DTOs `readonly`, clases chicas, sin números mágicos, controllers delgados (FormRequest → servicio → Resource), comentarios solo para el "por qué" citando la regla.
- **Seguridad**: montos siempre calculados en backend (nunca se aceptan totales del cliente); `permission:creditos.*` + verificación en servicio para acciones sensibles; `$fillable` explícito; `DB::transaction` + `lockForUpdate()` sobre el crédito en toda escritura; idempotencia por `clave_idempotencia`; nada se borra; auditoría en `credito_auditoria`; condiciones inmutables tras activar; errores sin datos internos.
- **UI**: mobile-first (360/768/1280), tarjetas en celular, botones ≥44px, `inputmode="decimal"`, previews antes de confirmar, color + texto. Mockups: canvas "Pantallas Módulo Créditos".

## 5. Legal y negocio (pendiente con el cliente)
- Tasas máximas BCRP (compensatoria y moratoria): exceder puede configurar **usura (art. 214 CP)** → fijar `tasa_maxima`. Registro SBS, venta de prendas (Ley de Garantía Mobiliaria), texto del contrato, protección de datos (Ley 29733): con su abogado.
- Confirmar: interés mínimo 10%, mora diaria sobre saldo con tope 100% de la cuota, domingos, lista de feriados, módulos a contratar, si emite comprobantes por intereses.