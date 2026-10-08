# Plan — Módulo de Créditos

Versión: **v24** (la versión vive aquí, no en el título ni en el nombre del archivo; ubicación en el repo: `docs/planning/creditos/Plan — Módulo de Créditos.md`).

Estado (08-oct-2026): **Fases 1-4d construidas y en producción** (tenant real `credishirley`). Este documento es el plan original de diseño; las reglas vigentes —con todos los ajustes posteriores— están en `00-reglas-y-modelo.md`, y la cronología y el estado de cada fase en `historial.md`. Quedan sin fecha las fases 6-8 (módulos contratables, garantes y prendas, cobradores con caja propia). Mockups aprobados (canvas "Pantallas Módulo Créditos").

Contexto: tenant **nuevo** para un cliente externo (negocio de préstamos), `giro='creditos'`. No se relaciona con retail ni agencia de viajes.

Regla de trabajo: Claude Code NO modifica código ni BD sin aprobación de cada fase.

## 0. Convenciones (ajustadas a la auditoría)

- Tablas/columnas/estados nuevos **en español**, sin tildes ni ñ. `$table` explícito en cada modelo.
- **No usar `Credit`** (ocupado por Amortizaciones). Namespaces:
  - `App\Models\Creditos\*` (`Credito`, `CreditoCuota`, `CreditoPago`, `CreditoPagoAplicacion`)
  - `App\Http\Controllers\Creditos\*`
  - `App\Services\Creditos\Motor\*` (clases puras, sin Laravel, sin `now()`)
  - `App\Services\Creditos\CreditoService`
  - Frontend: `views/creditos/` (no confundir con `views/credit/`)
- Toda columna con `->comment('...')`.
- Estados con `enum()` en BD + **PHP backed enums** en código.
- Sin `softDeletes`; anulación por `estado` + `motivo_anulacion`, `anulado_por`, `anulado_en`.
- Columnas de auditoría de usuario sin FK real.
- Dinero: `numeric(12,2)` en BD; **centavos enteros** en el motor. Una única función de redondeo; S/ 0.10 = múltiplo de 10 centavos.
- Fechas: el motor recibe `fechaReferencia`. "Hoy" lo calcula el controller en `America/Lima`.
- Permisos: `creditos.ver`, `creditos.crear`, `creditos.cobrar`, `creditos.anular_pago`, `creditos.condonar_mora`, `creditos.corregir`, `creditos.configurar`, `creditos.prendas.gestionar`, `creditos.prendas.vender`, `creditos.cartera.asignar`, `creditos.autorizar_excepcion`, `creditos.reprogramar`, `creditos.castigar`, `creditos.migrar`, `creditos.pago_fecha_anterior`.

## 1. Reglas de negocio (cerradas)

### 1.1 Interés `simple_fijo`
- Interés calculado una vez sobre el capital inicial.
- `unidad_tasa`: `total` | `mensual`; futuras `semanal`, `diaria`, `anual`. Conversión a meses: ver §12.1.
- Cuotas iguales; la última absorbe el redondeo (detalle en §12.2).
- Métodos futuros sin cambiar tablas: `sobre_saldo`, `cuota_fija`, `capital_fijo`.

### 1.2 Redondeo a S/ 0.10
Σ cuotas = capital + interés exacto. Liquidación y mora también a S/ 0.10.

### 1.3 Frecuencias
- `frecuencia_unidad`: `dia | semana | mes | anio | quincena`; `frecuencia_intervalo` ≥ 1.
- Fin de mes: ajustar al último día (31/01 → 28/02 → 31/03).
- Quincenal configurable: `dia`+15 (corrido) o `quincena` + `dias_quincena` (ej. [15,"ultimo"]).

### 1.4 Días no laborables (configurable)
| Campo | Valores | Default |
|---|---|---|
| `dias_no_laborables` | json, días ISO 1-7 (ej. `[7]`, `[6,7]`, `[]`) | `[7]` |
| `saltar_feriados` | bool (usa tabla `feriados` del tenant) | false |
| `regla_no_laborable` | `siguiente` \| `anterior` \| `mantener` | `siguiente` |
| `mora_cuenta_no_laborables` | bool (true = días calendario) | true |

- Dos niveles: default en `credito_configuracion` → se copia al crédito (editable al crear) → congelado al activar.
- Frecuencia diaria: el día no laborable **no genera cuota** (el nº de cuotas se mantiene, el cronograma se alarga).
- Otras frecuencias: se **mueve la fecha** según `regla_no_laborable`.
- `anterior` nunca puede mover una fecha antes de la cuota previa ni del desembolso (validación del motor).
- `feriados` por tenant, sembrable con feriados nacionales; cada negocio agrega/quita.
- El motor recibe la lista de feriados como parámetro (no consulta BD).

### 1.5 Liquidación anticipada
```
interes_devengado = interés de cuotas ya vencidas (cobrado o no) + proporcional cuota en curso (días reales)
interes_final     = min( max(interes_devengado, capital × tasa_interes_minimo), interés total pactado )
monto_liquidacion = capital pendiente + interes_final − interés ya cobrado + mora pendiente
```
- `tasa_interes_minimo` default 10%, editable por crédito.
- **Adelantar cuotas ≠ liquidar**: pagar cuotas futuras por adelantado (excedente, 1.7) no descuenta interés; el descuento solo aplica al cancelar el total con liquidación.
- **Pago adelantado parcial (v1)**: el monto cubre cuotas siguientes en orden (1.7); cronograma sin cambios, sin ahorro de interés. Ej.: quedan 7 cuotas de 600 y paga 1,000 → cuota 4 completa + 400 de la cuota 5.
- **Amortización parcial de capital con recálculo** (reducir plazo o reducir cuota) → **fase posterior** (reprogramación, nueva `version_cronograma`, permiso admin, preview, respetando interés mínimo). Falta definir reglas con el negocio.
- **Reparto del pago de liquidación**: capital → todas las cuotas pendientes; interés a cobrar (`interes_final − interés ya cobrado`) → cuotas en orden hasta agotarse; el interés restante de cada cuota → `interes_condonado`. Todas las cuotas quedan **`pagada`** (su capital sí se pagó). `anulada` se reserva solo para versiones de cronograma reemplazadas (1.8).
- Crédito → `finalizado`, `motivo_cierre='liquidacion_anticipada'`. Pago con `origen='liquidacion'`. Se genera constancia de cancelación; prendas → `devuelta`; garantes liberados.
- La cotización vale **solo para la fecha indicada** (proporcional y mora cambian cada día); al confirmar, el backend recalcula.
- **Ejemplo de referencia**: 5,000 al 20% total, 10 cuotas de 600 (500+100); pagó cuotas 1-3; liquida a mitad del período de la cuota 4 (15/30 días), sin mora.
  - Capital pendiente 3,500 · interés cobrado 300 · proporcional 100×15/30 = 50 → devengado 350
  - Mínimo 5,000 × 10% = 500 → `interes_final = min(max(350, 500), 1,000) = 500`
  - **Monto de liquidación = 3,500 + 500 − 300 + 0 = 3,700** (sin mínimo serían 3,550)
  - Reparto: cuotas 4 y 5 con interés 100 pagado; cuotas 6-10 con interés 100 condonado c/u (500 condonado); capital 500 pagado en cada cuota 4-10.

### 1.6 Mora
- Por cuota, diaria, sobre saldo pendiente de la cuota, por tramos (reconstruidos desde aplicaciones).
- `mora_diaria = saldo_pendiente_cuota ÷ días del período de la cuota`.
- Días contados según `mora_cuenta_no_laborables`.
- No se capitaliza; se detiene al pagar la cuota.
- `dias_gracia` configurable. **La mora cuenta desde el fin de la gracia**: vence día 10, gracia 3, paga día 15 → 2 días de mora. Pagar dentro de la gracia = 0 mora.
- **Condonación**: registro en `credito_condonaciones` (monto, motivo, usuario); no se pierde al recalcular pagos (1.9). `mora_condonada` de la cuota = suma de condonaciones vigentes.
- Calculada al consultar, no guardada día a día.
- **Ejemplo de referencia**: 1,000 al 20%, 1 cuota de 1,200 (período 10/09→10/10, 30 días), paga 15/10, gracia 0 → mora diaria 40 × 5 días = **200**. Paga 1,400: interés 200 → capital 1,000 → mora 200 → `finalizado`. Paga solo 1,200: cuota pagada, mora 200 queda pendiente (ya no crece) y el crédito sigue `activo` hasta pagarla o condonarla.
- En documentos y contrato se rotula **"Interés moratorio"**; en BD las columnas usan el prefijo `mora_`.
- **Qué se guarda y qué se calcula**:
  | Dato | Dónde | Guardado / calculado |
  |---|---|---|
  | `fecha_vencimiento` | `credito_cuotas` | Guardado |
  | `fecha_pago` (cuándo quedó totalmente pagada) | `credito_cuotas` | Guardado (derivado de aplicaciones) |
  | `fecha_pago` de cada abono | `credito_pagos` | Guardado |
  | `dias_atraso_al_pagar` (historial de puntualidad, congelado al pagar) | `credito_cuotas` | Guardado |
  | Días de atraso **actuales** (cuota impaga) | — | Calculado en vivo (cambia cada día); foto diaria en `credito_cartera_diaria` |
  | Mora pendiente | — | Calculada en vivo |
  | Mora cobrada / condonada | `credito_pago_aplicaciones` (`concepto='mora'`) / `credito_condonaciones` → acumulados en la cuota | Guardado |
- ⚠️ Legal: tasa moratoria máxima BCRP y registro SBS — el cliente debe confirmarlo con su abogado/contador.

### 1.7 Orden de aplicación de pagos
1. Cuotas vencidas (antigua → nueva); dentro de cada una: interés → capital → cargo (si tiene, 1.18).
2. Mora (antigua → nueva).
3. Excedente → cuotas siguientes (sin descuento de interés).
- Lo **aplicado** al crédito nunca supera el total adeudado (monto de liquidación a la fecha). Para cancelar todo se usa la acción Liquidar.
- **Pago mayor a lo que se debe hoy (excedente / pago por error)**: el dinero ya pudo haber entrado (Yape, transferencia), así que no se rechaza; la pantalla pregunta qué hacer con el excedente:
  | Opción | Efecto | Disponible |
  |---|---|---|
  | **Devolver** (**default**) | Salida de caja por el excedente (`reference_type='credito_devolucion_excedente'`), con método y referencia | Siempre |
  | Adelantar cuotas | Se aplica a cuotas siguientes (sin descuento de interés) | Si hay cuotas pendientes |
  | Saldo a favor | Queda al cliente con historial de movimientos; se usa en su próximo pago como `origen='saldo_a_favor'` (sin nuevo ingreso a caja) | Siempre |
  - En caja entra el monto total recibido; la devolución es una salida separada → la caja refleja lo que realmente pasó.
  - Pago en efectivo en persona: el cajero da vuelto y registra solo lo que corresponde (no aplica).
  - Verificar en código si `clients.saldo_a_favor` (Amortizaciones) tiene historial de movimientos reutilizable; si no, crear uno para créditos.
- `fecha_pago` = fecha/hora del servidor al registrar, salvo pago con fecha anterior autorizado (1.22).
- **Fin del crédito**: pasa a `finalizado` (`motivo_cierre='pagado_completo'`) cuando todas las cuotas vigentes están pagadas **y** no queda mora pendiente (pagada o condonada).

### 1.8 Corrección del crédito
| Estado | Corrección |
|---|---|
| `borrador` | Edición libre; el cronograma se regenera |
| `activo` sin pagos válidos | Acción **Corregir crédito** (permiso `creditos.corregir`, motivo obligatorio) |
| `activo` con pagos | Primero anular pagos (1.9); cambiar condiciones con pagos = reprogramación (fase posterior) |

- Corregir: mismo `numero_credito`; cuotas anteriores → `anulada`; nuevo cronograma con `version_cronograma + 1`.
- `AuditLogger` con snapshot de condiciones antes/después.
- Si cambia el monto de capital → ajuste del movimiento de desembolso con `CashCorrectionService`.
- Preview obligatorio del cronograma antes de activar (prevención).
- **Anular crédito** (`estado='anulado'`): solo sin pagos válidos; revierte el desembolso en caja (`CashCorrectionService`); permiso `creditos.corregir`; motivo obligatorio.
- `creditos.version_cronograma_actual` indica qué cuotas son las vigentes; todas las consultas filtran por ella.

### 1.9 Anulación de pagos (extorno con recálculo)
- Un pago nunca se edita ni se borra. Solo `referencia` y `observaciones` son editables (auditado).
- Se puede anular **cualquier** pago válido (no solo el último).
- Al anular:
  1. Pago → `anulado` (motivo, usuario, fecha).
  2. `AplicadorPagos` **vuelve a repartir todos los pagos válidos** del crédito en orden de `fecha_pago` (el reparto es determinista).
  3. Las aplicaciones anteriores quedan como historial (`vigente = false`); se insertan las nuevas con `generacion + 1`.
  4. Campos acumulados de `credito_cuotas` (capital_pagado, interes_pagado, mora_pagada, estado, fecha_pago) se recalculan desde las aplicaciones vigentes. La mora se recalcula sola.
  5. Caja: movimiento inverso vía `CashCorrectionService` (si la sesión del pago está cerrada, el reverso va a la caja abierta actual). Los demás pagos no tocan caja (sus montos no cambian).
  6. Una transacción + `lockForUpdate` sobre el crédito + `AuditLogger`.
- Permisos: el cajero puede anular su propio pago mientras la misma sesión de caja está abierta; después, solo quien tenga `creditos.anular_pago`.
- Antes de confirmar un cobro, la pantalla muestra cómo se aplicará (cuota N: interés / capital / mora).

### 1.10 Garantes
- Opcionales, **varios por crédito**. Se registran como **clientes** (tabla `clients`), así se reutilizan datos y se ve si el garante debe o ya respalda otros créditos.
- Responden por el saldo que quede tras vender las prendas (1.11).
- Los pagos pueden venir del titular o de un garante → `credito_pagos.pagado_por_cliente_id`.

### 1.11 Prendas en garantía
- Tipos v1: `equipo` (electrodomésticos, celulares, laptops, herramientas) y `vehiculo`. Varias prendas por crédito; cada prenda respalda un solo crédito.
- **Custodia**: el prestamista guarda la prenda. Sin cobro de custodia.
- **Tasación**: se registra `valor_tasado`. Configuración lista para crecer sin tocar código: `porcentaje_prestamo_max` (null) + `validacion_tasacion` (`ninguna|advertir|bloquear`, hoy `ninguna`).
- Fotos obligatorias al **ingreso** y a la **devolución** (evita reclamos).
- Ciclo:
  ```
  en_custodia ⇄ apta_para_venta → en_venta → vendida
       │               │
       └───────────────┴──→ devuelta (crédito cancelado)
  ```
  - `apta_para_venta`: proceso diario la marca cuando el crédito supera `dias_para_venta` de atraso (configurable). Solo alerta. Si el cliente se pone al día, vuelve a `en_custodia`.
  - `en_venta`: paso manual con permiso `creditos.prendas.vender`.
  - `vendida`: se registra precio → ingreso a caja (`reference_type='prenda_venta'`) y se aplica al crédito como pago `origen='venta_prenda'`.
- **Deuda al vender** = monto de liquidación a la fecha de venta (regla 1.5), usada para decidir si la venta cancela el crédito.
- Varias prendas: se venden una a una; si la deuda queda cubierta, las restantes pasan a `devuelta`.
- **Excedente** (venta > deuda): se devuelve al cliente. Queda `excedente_por_devolver` pendiente hasta registrar la devolución (salida de caja `reference_type='prenda_excedente'`). Alerta mientras esté pendiente.
- **Faltante** (venta < deuda de liquidación): el precio de venta se aplica como **pago normal** (orden 1.7) y el **cronograma original sigue con su interés completo** (no hay descuento de interés futuro). El saldo se cobra al garante o al titular; mora según 1.6.
- Regla de decisión al vender: si `precio_venta ≥ monto_liquidacion` → se liquida (1.5) y el resto es excedente; si es menor → pago normal.
- ⚠️ Legal: confirmar con el abogado del cliente el procedimiento de venta de prendas (Ley de Garantía Mobiliaria: aviso previo, plazos).

### 1.12 Módulos contratables (control desde central-panel)
No todos los negocios usan garantías; las que se usen se cobran como **adicional al plan**.

- Se construye el diseño ya aprobado en `docs/planning/agencia-de-viajes/plan-modulo-planes-acceso.md` §2 (`modulos`, `plan_modulo`, `tenant_modulo_overrides`, `modulos_efectivos()`), no una tabla propia.
- Módulos del giro créditos:
  | Código | Contenido | Tipo |
  |---|---|---|
  | `creditos` | Núcleo: créditos, cronograma, pagos, mora, liquidación | Incluido en el plan |
  | `creditos_garantes` | Garantes por crédito | Adicional |
  | `creditos_prendas` | Prendas, fotos, custodia, venta | Adicional |
  | `creditos_cobradores` | Cartera, caja del cobrador, cobranza del día, gestiones | Adicional |
  | (futuros) `creditos_whatsapp`, `creditos_reportes_avanzados` | | Adicional |
- Garantes y prendas son **módulos separados** (un negocio puede usar uno sin el otro).
- **Precio**: cada módulo adicional tiene `precio_mensual`; la factura de suscripción = plan + adicionales activos del tenant (ajustar generación de `TenantInvoice`).
- **central-panel**: tab "Módulos" en el detalle del tenant → activar/desactivar adicionales, ver precio. Auditado con `AuditLogger`.
- **Enforcement en 3 capas**:
  1. Backend: middleware `modulo:<codigo>` (ahora sí se crea) en las rutas de garantes/prendas.
  2. Menú: `MenuResolver` filtra por `menu_items.modulo_id` (punto ya marcado en el código).
  3. Frontend: `respondWithToken()` agrega `tenant{giro, modulos[]}`; el formulario de crédito oculta secciones de garantes/prendas si el módulo no está activo.
- **Desactivar un módulo con datos → modo solo lectura** (nunca se bloquea la desactivación, nunca se borran datos):
  - Se bloquea **crear**: registrar nuevas prendas o agregar garantes a créditos.
  - Se permite **gestionar lo existente**: ver, devolver prendas, continuar su ciclo de venta (para recuperar deuda), registrar pagos de garantes ya asignados, imprimir actas.
  - El middleware distingue: `modulo:creditos_prendas` (crear) vs rutas de lectura/gestión que solo exigen permiso.
  - Frontend: si el módulo está inactivo pero hay datos, las secciones se muestran en solo lectura con aviso "Módulo no contratado — solo gestión de registros existentes".
  - Reactivar el módulo restaura todo sin migraciones ni pérdida de datos.
- El motor no cambia: garantes/prendas son capas encima del crédito.

### 1.13 Roles y permisos
| Permiso | Administrador | Cajero | Cobrador |
|---|---|---|---|
| `creditos.ver` | Todos | Todos | **Solo su cartera** |
| `creditos.crear` (borrador + activar + desembolso) | ✓ | ✓ | — |
| `creditos.cobrar` | ✓ | ✓ | ✓ (su cartera) |
| Anular pago propio con su caja abierta | ✓ | ✓ | ✓ |
| `creditos.anular_pago` (cualquiera / caja cerrada) | ✓ | — | — |
| `creditos.corregir` | ✓ | — | — |
| `creditos.condonar_mora` | ✓ | — | — |
| `creditos.configurar` | ✓ | — | — |
| `creditos.cartera.asignar` | ✓ | — | — |
| `creditos.prendas.gestionar` / `.vender` | ✓ / ✓ | ✓ / — | — |
| Reportes | Todos | Caja del día | Su cartera y su caja |

- Roles sembrados al crear el tenant de giro `creditos`; el admin puede ajustar permisos (sistema de roles existente).
- **La restricción "solo su cartera" se aplica en backend** (scope en consultas + validación en el servicio de cobro), no solo ocultando en pantalla.

### 1.14 Cobradores y cartera (módulo adicional `creditos_cobradores`)
- Cobro en **local y en campo** (celular).
- **Asignación de cartera**, preparada para 3 modos (config `modo_asignacion_cartera`):
  | Modo | v1 | Resolución |
  |---|---|---|
  | `cliente` | ✓ activo | Todos los créditos del cliente → su cobrador |
  | `credito` | preparado | Asignación directa a un crédito (sobrescribe al cliente) |
  | `zona` | preparado | Cliente pertenece a zona → cobrador de la zona |
  - Prioridad al resolver: crédito > cliente > zona (`ResolvedorCartera::cobradorDe(credito)`).
  - Tabla única `cartera_asignaciones` con historial (vigente_desde / vigente_hasta); reasignar no borra, cierra la vigencia.
  - `zonas` y `clients.zona_id` se crean cuando se active el modo zona (no en v1).
- **Caja propia del cobrador**: abre su sesión de caja en el celular, cobra, al final del día hace arqueo (solo efectivo; Yape/transferencia van directo a la cuenta del negocio y se concilian por método) y **entrega** el efectivo → transferencia a la caja principal.
  - ⚠️ Auditar: ¿el módulo de Caja soporta varias sesiones abiertas simultáneas (una por usuario) y transferencia entre cajas? Si no, se extiende.
- **Ruta / cobranza del día** (pantalla principal del cobrador, mobile-first): cuotas que vencen hoy + vencidas de su cartera, con monto a cobrar (cuota + mora), dirección y teléfono (tap para llamar / abrir mapa / WhatsApp).
- **Gestiones de cobranza** (`credito_gestiones`): cada visita registra resultado `pago | no_encontrado | promesa_pago | se_niega | otro`, `fecha_promesa`, nota. Las promesas vencidas aparecen como alerta.
- GPS de la visita: opcional, fase posterior.
- **Conectividad v1: solo online.** El cobro requiere internet. `clave_idempotencia` protege contra duplicados por reintentos. La UI debe: detectar sin conexión, avisar claramente, conservar el formulario lleno y permitir reintentar sin re-digitar.
- **Offline = iniciativa transversal futura** (créditos + agencia de viajes + otros giros), fuera de este plan. Para no cerrarle la puerta desde ahora:
  - Toda operación de escritura acepta `clave_idempotencia` generada en el cliente.
  - Registrar `fecha_pago` (momento real del cobro) separado de `created_at` (momento de sincronización).
  - Cálculos de montos siempre en backend (el celular offline solo mostraría estimados).

### 1.15 Documentos
Reutilizar la infraestructura existente de sistemafe: generación de PDF, QR, **links firmados** y botón WhatsApp (modelo Alegra), y `formato_impresion_default` del usuario. (Auditar librería PDF y servicio de links firmados.)

| Documento | Cuándo | Formatos |
|---|---|---|
| Contrato de préstamo | Al activar | A4 / PDF |
| Cronograma de pagos | Preview, al activar, a pedido | A4 / PDF, ticket 80mm |
| Recibo de pago | Cada cobro | **Ticket 80mm, A4 / PDF, WhatsApp** |
| Estado de cuenta | A pedido | A4 / PDF, ticket 80mm |
| Constancia de cancelación (no adeudo) | Al finalizar el crédito | A4 / PDF |
| Acta de ingreso de prenda (con fotos) | Al registrar prenda | A4 / PDF |
| Acta de devolución de prenda (con fotos) | Al devolver | A4 / PDF |
| Liquidación de venta de prenda (deuda, precio, excedente/faltante) | Al vender | A4 / PDF |
| Acuerdo de reprogramación | Al reprogramar | A4 / PDF, WhatsApp |

- **Formato**: el usuario elige al imprimir; default por usuario (`formato_impresion_default`). El cobrador imprime ticket desde el celular con impresora bluetooth (impresión del navegador / app de impresión térmica en Android).
- **Recibo**: correlativo propio (`numero_recibo`, con `lockForUpdate`), desglose (cuota N: interés / capital / mora), saldo restante, próximo vencimiento, cobrador/cajero, método, QR de verificación. Reimpresión marca **"COPIA"**; pago anulado marca **"ANULADO"**.
- **Contrato con plantilla editable** por el negocio (su abogado define el texto):
  - Tabla `credito_plantillas` (tipo, version, contenido, activa). Editar crea nueva versión; nunca se sobrescribe.
  - Variables de **lista blanca**: `{cliente_nombre}`, `{cliente_documento}`, `{monto_capital}`, `{monto_capital_letras}`, `{tasa_interes}`, `{interes_total}`, `{numero_cuotas}`, `{frecuencia}`, `{fecha_desembolso}`, `{cronograma}`, `{garantes}`, `{prendas}`, `{mora_regla}`, `{empresa_razon_social}`, `{empresa_ruc}`, etc.
  - **Seguridad**: reemplazo simple de variables (nunca evaluar la plantilla como Blade/PHP) + HTML sanitizado (sin scripts). Preview antes de guardar.
  - Plantilla por defecto sembrada al crear el tenant, marcada "revisar con su abogado".
  - Al activar un crédito se **congela** el contrato: se guarda el PDF generado + `plantilla_version` + hash. Cambios futuros de plantilla no alteran contratos ya emitidos.
  - Se puede **subir el contrato firmado** (foto/escaneo) al crédito.
- **WhatsApp**: botón que abre `wa.me/<teléfono>` con mensaje prearmado + link firmado al PDF. Sin costo. Recibos: link permanente (como comprobantes). Contrato y estado de cuenta: link con **expiración** (ej. 7 días) por contener datos financieros sensibles.
- Envío automático (API WhatsApp Business, recordatorios de vencimiento) → módulo adicional futuro `creditos_whatsapp`.
- Documentos generados a pedido desde datos (excepto contrato, que se congela).

### 1.16 Reportes
Principios: **correctos, rápidos, fáciles de leer y de generar.**

**Exactitud**
- Una sola fuente de verdad: los reportes calculan desde pagos válidos + aplicaciones vigentes, usando **las mismas funciones del motor** (mora, saldos). Nunca fórmulas duplicadas en SQL "a mano" que puedan divergir.
- Todo reporte tiene **fecha de corte** ("al día X"): mismo corte = mismo resultado, siempre reproducible.
- Montos en centavos enteros; totales de reporte = suma exacta de sus filas.
- Base **caja** para ingresos: interés/mora "ganados" = efectivamente cobrados (no devengados). Se indica en el reporte.
- Cada indicador tiene un ícono "¿cómo se calcula?" con su definición en lenguaje simple.
- **Conciliación automática**: cobrado en créditos del día = ingresos de caja con `reference_type='credito_pago'`; si no cuadra, alerta visible.
- Tests con datasets conocidos: cada indicador tiene un test con resultado esperado exacto.

**Velocidad**
- Consultas agregadas en BD con índices (`credito_cuotas(fecha_vencimiento, estado)`, `credito_pagos(fecha_pago, estado)`, `credito_id` en todas las hijas).
- **Foto diaria de cartera** (`credito_cartera_diaria`): job nocturno guarda por crédito saldo capital, saldo interés, días de atraso, mora, cobrador. Históricos y tendencias leen la foto (instantáneo); "hoy" se calcula en vivo.
- Meta: cualquier reporte < 2 s; listados paginados.

**Fácil de usar**
- Filtros rápidos por defecto: Hoy · Esta semana · Este mes · Rango. Filtros opcionales: cobrador, estado, rango de atraso.
- **Clic en cualquier número → detalle** (tarjeta → lista → crédito).
- Mobile-first: tarjetas en celular, tablas en escritorio. Color + texto.
- Según rol: el cobrador ve solo su cartera y su caja.
- Exportar: **en pantalla + PDF** (Excel en versión futura).
- Gráficos mínimos y claros, con la librería de gráficos ya usada en `admin-start-kit` (auditar).

**Reportes v1** (* los datos de cobradores, gestiones y promesas aparecen solo con el módulo `creditos_cobradores` activo)
| Reporte | Contenido |
|---|---|
| **Panel de inicio** | Cartera activa (saldo capital), Por cobrar hoy, Cobrado hoy, % Cartera en riesgo, Promesas de pago de hoy*; cobrado últimos 30 días (gráfico); créditos más atrasados |
| **Cobranza del día** | Programado vs cobrado por cobrador, % efectividad, pendientes, gestiones y promesas del día |
| **Cartera** | Créditos activos: cliente, capital prestado, saldo capital, saldo interés, cuotas pagadas/total, próximo vencimiento, días de atraso, cobrador |
| **Morosidad** | Rangos de atraso 1-7 · 8-15 · 16-30 · 31-60 · +60 días (cantidad y saldo), por cobrador; **Cartera en riesgo % = saldo total de créditos con atraso > días de gracia ÷ saldo total de cartera** (criterio SBS) |
| **Ingresos y desembolsos** | Capital prestado, capital recuperado, intereses cobrados, mora cobrada, mora condonada, interés descontado por liquidación; por día/semana/mes |
| **Castigados y recuperos** | Créditos castigados (saldo, fecha, cliente), recuperos cobrados por período |
| **Cobrar al garante** | Créditos que superaron `dias_aviso_garante`, con garantes y teléfonos |
| **Control y auditoría** | Anulaciones de pagos, correcciones de crédito, condonaciones: quién, cuándo, monto, motivo; alerta si un usuario supera un umbral de anulaciones |

### 1.17 Límites y reglas de otorgamiento
Se validan en el **backend** al crear el borrador (advertencia temprana) y **de nuevo al activar** (bloqueo real, dentro de la transacción).

| Regla | Config del negocio | Ajuste por cliente | Si se incumple |
|---|---|---|---|
| Créditos activos simultáneos | `max_creditos_activos` (default 2) | sí | Bloquea; admin puede autorizar |
| Deuda máxima por cliente | `deuda_maxima_cliente` (null = sin tope) | sí | Bloquea; admin puede autorizar |
| Cliente moroso | `dias_atraso_bloqueo` (default 7) | — | Bloquea; admin puede autorizar |
| Cliente bloqueado manualmente | — | `bloqueado` + motivo | Bloquea; admin puede autorizar |
| Garante moroso o saturado | `max_garantias_por_garante` (default 3) | — | Solo advierte |

- **Deuda del cliente** = saldo de capital pendiente de sus créditos activos + capital del nuevo crédito (no incluye intereses futuros).
- **Moroso** = tiene algún crédito activo con cuota vencida más de `dias_atraso_bloqueo` días.
- Garante: advertir si tiene cuotas atrasadas como titular o si ya respalda `max_garantias_por_garante` créditos activos. Un cliente no puede ser garante de su propio crédito (bloqueo).
- **Autorización de excepción**: permiso `creditos.autorizar_excepcion` (admin), motivo obligatorio, se guarda en `credito_autorizaciones` + `AuditLogger`, aparece en el reporte de Control.
- La pantalla de nuevo crédito muestra al seleccionar el cliente: créditos activos, deuda actual, deuda disponible, días de atraso máximo y un historial resumido de puntualidad.

### 1.18 Reprogramación de fechas (MVP)
Mover fechas de cuotas pendientes **sin cambiar montos** (el cambio de montos sigue en fase posterior).

- Acción **"Reprogramar fechas"**, permiso `creditos.reprogramar` (admin), motivo obligatorio, preview antes de confirmar.
- Dos formas: **desplazar N días desde la cuota X** (aplica a esa y siguientes) o **editar fechas una por una**.
- Validaciones: solo cuotas pendientes (pueden tener abonos parciales); fechas en orden ascendente; no antes de hoy ni de la cuota anterior; advertencia si cae en día no laborable.
- **Se modifica la fecha en la misma cuota** (no se crea nueva versión de cronograma, porque los montos no cambian) y el historial queda en `credito_reprogramacion_cuotas` (fecha anterior → nueva).
- **Mora del período nuevo**: el divisor de la mora diaria sigue siendo los días del período **original** de la cuota (evita tasas raras al alargar).
- **Mora ya acumulada** en cuotas vencidas al reprogramar → **el admin decide** en la pantalla:
  - *Mantener*: se congela como `mora_congelada` en la cuota (deuda pendiente); desde hoy no corre hasta la nueva fecha.
  - *Condonar*: se registra en `credito_condonaciones` (auditado).
  - `CalculadoraMora` = mora congelada + mora desde la fecha de vencimiento vigente.
- **Cargo por reprogramación, configurable** (`credito_configuracion.cargo_reprogramacion_tipo`):
  | Tipo | Cálculo |
  |---|---|
  | `ninguno` (default) | 0 |
  | `fijo` | `cargo_reprogramacion_monto` (ej. S/ 20) |
  | `interes_por_dias` | interés total ÷ días del plazo original × días que se extiende la **última** cuota |
  - Valor sugerido desde la config; el admin puede ajustarlo en la pantalla (auditado).
  - Se registra en `credito_cargos` y se suma a la **primera cuota reprogramada**. Concepto de aplicación `cargo`; orden de pago: interés → capital → **cargo** → mora. La mora **no** corre sobre el cargo.
- Documento **"Acuerdo de reprogramación"** (A4/PDF, WhatsApp) con fechas anteriores y nuevas, cargo y firma.
- Tras reprogramar se reevalúan alertas (prendas `apta_para_venta` → `en_custodia` si ya no hay atraso; promesas de pago).
- Ejemplo: cuotas 5/6/7 del 10/11, 17/11, 24/11 → desplazar 10 días desde la cuota 5 → 20/11, 27/11, 04/12.

### 1.19 Cliente que no paga por mucho tiempo (escalamiento)
Proceso diario (mismo job nocturno) evalúa los días de atraso de cada crédito y aplica, según configuración:

| Días de atraso | Acción | Config |
|---|---|---|
| > `dias_gracia` | Empieza la mora | `dias_gracia` |
| > `dias_atraso_bloqueo` | Cliente bloqueado para nuevos créditos (1.17) | default 7 |
| > `dias_aviso_garante` | Crédito aparece en lista **"Cobrar al garante"** con datos y teléfono de garantes | default 15 |
| > `dias_para_venta` | Prendas → `apta_para_venta` (1.11) | default 30 |
| > `dias_para_castigo` | Crédito → **`castigado`** | default 90 |

**Tope de mora (configurable)** — `tope_mora_tipo`:
| Tipo | Regla |
|---|---|
| `porcentaje_cuota` (default, 100%) | La mora de una cuota no supera X% del monto de la cuota |
| `porcentaje_capital` | La mora total del crédito no supera X% del capital prestado |
| `dias_maximos` | La mora deja de correr después de N días de atraso |
| `sin_tope` | Sin límite |
- Parámetro `tope_mora_valor`. Se copia al crédito y se congela al activar (como el resto de condiciones).
- `CalculadoraMora` aplica el tope; el recibo y la cotización indican "mora con tope alcanzado".

**Castigo (crédito incobrable)**
- Automático al superar `dias_para_castigo`; el admin puede también castigar manualmente antes (permiso `creditos.castigar`, motivo). Se puede revertir si el cliente regulariza (auditado).
- Al castigar: la mora se **congela** a esa fecha; el crédito sale de *Cartera activa* y del % Cartera en riesgo; pasa a reporte **"Créditos castigados"** (saldo castigado por período).
- La deuda **sigue registrada**: se pueden cobrar pagos, que se reportan como **"Recupero de castigados"** (ingreso aparte en reportes).
- Si se paga todo → `finalizado` (`motivo_cierre='recupero_castigo'`).
- El cliente queda bloqueado permanentemente (desbloqueo solo manual por admin).
- Cobranza judicial: no en v1 (el castigo + notas de gestión cubren el caso).

### 1.20 Carga de créditos existentes (migración inicial)
El negocio ya tiene préstamos vivos (cuaderno/Excel). Acción **"Registrar crédito existente"** (permiso `creditos.migrar`, admin):
- Se ingresan las condiciones originales con `fecha_desembolso` **pasada**; el motor genera el cronograma normal.
- Pagos históricos, dos modos:
  - **Rápido**: "cuotas 1 a N pagadas a tiempo" → un pago histórico por cuota en su fecha de vencimiento.
  - **Detallado**: lista de pagos (fecha + monto) → el motor los aplica en orden (1.7) y calcula la mora real.
- **Sin movimientos de caja** (ni desembolso ni pagos históricos): el dinero se movió antes del sistema. Pagos con `origen='saldo_inicial'`; crédito con `origen_registro='migracion'`.
- Mora histórica calculada: el admin puede condonarla en el mismo paso.
- Límites de otorgamiento (1.17) solo advierten. Contrato: se sube el firmado existente (no se genera).
- Desde el registro, el crédito se comporta igual que cualquier otro.

### 1.21 Renovación de crédito
Acción **"Renovar"** sobre un crédito activo: el cliente debe un saldo y pide un préstamo nuevo.
- Se calcula la liquidación del crédito actual a hoy (regla 1.5, con interés mínimo y mora).
- Nuevo crédito por el capital pedido; **neto = capital nuevo − monto de liquidación**, con tres casos (ampliado 08-oct-2026):
  - **> 0 → se entrega** la diferencia (salida de caja `credito_desembolso`).
  - **= 0 → sin movimiento de dinero** (ej. ya pagó el interés aparte y renueva por el mismo capital).
  - **< 0 → el cliente paga** la diferencia (entrada de caja `credito_pago`, referida al pago de renovación). Caso típico: le prestó 500 al 20 %, vence hoy (debe 600), paga los 100 de interés y sigue con 500 un mes más.
  - Todo en **una sola operación de cierre**: si el pago del cliente fuera un cobro aparte, en una renovación anticipada el interés adelantado no se descontaría (1.5) y pagaría de más. Con neto ≠ 0 el método de pago es obligatorio.
- Crédito anterior → `finalizado`, `motivo_cierre='renovacion'`, pago `origen='renovacion'`. La parte que cubre el crédito nuevo **no pasa por caja**; solo pasa por caja la diferencia (salida o entrada). Anular el crédito nuevo reabre el anterior y revierte esa diferencia.
- Enlace `credito_renovado_id` en el nuevo crédito; historial de renovaciones visible en la ficha del cliente.
- Límites (1.17): la deuda máxima se evalúa sin el crédito que se cancela; cliente moroso → requiere autorización de admin.
- Documentos: contrato del nuevo + constancia de cancelación del anterior. El recibo del pago de renovación desglosa "cubierto con el crédito X" y "pagado por el cliente".
- Ej.: debe 300 (liquidación) y pide 1,000 → se le entregan 700. Debe 600 y renueva por 500 → el cliente paga 100.

### 1.22 Pago con fecha anterior
Para cobros hechos y registrados después (ej. cobrador sin señal):
- Permiso `creditos.pago_fecha_anterior` (admin), motivo obligatorio, máximo `dias_max_pago_retroactivo` (default 3).
- `fecha_pago` = fecha real del cobro; `created_at` = momento del registro. La mora se calcula con la fecha real.
- Si hay pagos posteriores ya registrados, se reaplica todo en orden de `fecha_pago` (mismo mecanismo que 1.9).
- Caja: el ingreso entra a la caja abierta actual (donde está el dinero), con referencia a la fecha real.

### 1.23 Ficha de cobro del cliente
Datos para ubicar y cobrar (sin tocar `clients`):
- Dirección de cobro (casa / negocio), referencia, ubicación en mapa (lat/lng opcional, "abrir en Maps"), teléfono alterno, ocupación o negocio, notas.
- Archivos: foto del DNI (anverso/reverso), foto del cliente, otros (mismo almacenamiento que fotos de prendas).
- El cobrador solo ve fichas de su cartera. Acceso por permiso.
- Protección de datos personales (Ley 29733): cláusula de consentimiento en el contrato.
- El desembolso se entrega **completo** (sin comisión ni interés adelantado).

## 2. Modelo de datos (migraciones en `tenant/core/`)

### `creditos`
cliente_id (FK `clients.id`) · numero_credito (correlativo con `lockForUpdate`) · monto_capital · tasa_interes numeric(8,4) · unidad_tasa · metodo_calculo · interes_total · frecuencia_unidad · frecuencia_intervalo · dias_quincena json null · dias_no_laborables json · saltar_feriados · regla_no_laborable · mora_cuenta_no_laborables · numero_cuotas · fecha_desembolso · payment_method_id (FK) · cash_movement_id · fecha_primer_vencimiento · tasa_interes_minimo · modo_mora · dias_gracia · paso_redondeo · estado (`borrador|activo|castigado|finalizado|anulado`) · fecha_castigo · tope_mora_tipo · tope_mora_valor · origen_registro (`normal|migracion|renovacion`) · credito_renovado_id · version_cronograma_actual · motivo_cierre · registrado_por · anulacion (motivo/por/en) · timestamps.

### `credito_cuotas`
credito_id · version_cronograma · numero_cuota · fecha_inicio_periodo · fecha_vencimiento · monto_capital · monto_interes · monto_total · capital_pagado · interes_pagado · mora_pagada · mora_condonada · interes_condonado · estado (`pendiente|pagada|anulada`) · fecha_pago · dias_atraso_al_pagar · fecha_vencimiento_original · mora_congelada · cargo_monto · cargo_pagado.

### `credito_pagos`
credito_id · numero_recibo (correlativo) · monto_recibido · monto_aplicado · monto_excedente · destino_excedente (`devuelto|adelanto|saldo_a_favor|null`) · fecha_pago · origen (`cobro|liquidacion|venta_prenda|saldo_a_favor|saldo_inicial|renovacion`) · pagado_por_cliente_id (titular o garante) · payment_method_id (FK) · cash_movement_id · referencia · observaciones · **clave_idempotencia (unique)** · estado (`valido|anulado`) · anulacion · registrado_por.

### `credito_pago_aplicaciones`
pago_id · cuota_id · concepto (`interes|capital|cargo|mora`) · monto · generacion · vigente (bool).
Campos acumulados de `credito_cuotas` = derivados de aplicaciones vigentes (se recalculan en cada cobro/anulación).

### `credito_configuracion` (fila única)
tasa_interes_minimo · dias_gracia · paso_redondeo · dias_no_laborables · saltar_feriados · regla_no_laborable · mora_cuenta_no_laborables · dias_para_venta · porcentaje_prestamo_max (null) · validacion_tasacion · modo_asignacion_cartera (`cliente`) · max_creditos_activos · deuda_maxima_cliente · dias_atraso_bloqueo · max_garantias_por_garante · tasa_maxima · max_numero_cuotas · umbral_alerta_anulaciones · cargo_reprogramacion_tipo (`ninguno|fijo|interes_por_dias`) · cargo_reprogramacion_monto · dias_aviso_garante · dias_para_castigo · tope_mora_tipo · tope_mora_valor · dias_max_pago_retroactivo.

### `credito_condonaciones`
cuota_id · credito_id · concepto (`mora`) · monto · motivo · estado (`vigente|anulada`) · registrado_por · timestamps.

### `credito_garantes`
credito_id · cliente_id (FK `clients`) · observaciones · timestamps. Unique (credito_id, cliente_id).

### `prendas`
credito_id · tipo (`equipo|vehiculo`) · descripcion · marca · modelo · serie · color · accesorios (text) · estado_fisico (text) · ubicacion_custodia · valor_tasado · estado (`en_custodia|apta_para_venta|en_venta|vendida|devuelta`) · fecha_ingreso · fecha_devolucion · fecha_venta · precio_venta · excedente_por_devolver · fecha_devolucion_excedente · cash_movement_venta_id · cash_movement_excedente_id · registrado_por · timestamps.
Solo vehículo: placa · anio · tarjeta_propiedad_recibida (bool) · llaves_recibidas (bool).

### `prenda_fotos`
prenda_id · ruta_archivo · momento (`ingreso|devolucion`) · registrado_por · timestamps.

### `cartera_asignaciones`
cobrador_id (users.id, sin FK) · tipo (`cliente|credito|zona`) · referencia_id · vigente_desde · vigente_hasta (null = vigente) · asignado_por · timestamps. Índice (tipo, referencia_id, vigente_hasta).

### `credito_gestiones`
credito_id · cobrador_id · fecha_gestion · resultado (`pago|no_encontrado|promesa_pago|se_niega|otro`) · fecha_promesa · pago_id (null) · nota · timestamps.

### `credito_plantillas`
tipo (`contrato`) · version · contenido (html sanitizado) · activa (bool) · creado_por · timestamps.

### `credito_documentos`
credito_id · tipo (`contrato|contrato_firmado`) · ruta_archivo · plantilla_version · hash · registrado_por · timestamps.

### `credito_cartera_diaria`
fecha_corte · credito_id · cliente_id · cobrador_id · saldo_capital · saldo_interes · mora_pendiente · dias_atraso · rango_atraso · estado · timestamps. Unique (fecha_corte, credito_id). Generada por job nocturno; solo lectura.

### `credito_cliente_limites` (ajustes por cliente, sin tocar `clients`)
cliente_id (unique, FK) · max_creditos_activos (null = usar config) · deuda_maxima (null = usar config) · bloqueado (bool) · motivo_bloqueo · actualizado_por · timestamps.

### `credito_autorizaciones`
credito_id · regla (`max_creditos|deuda_maxima|moroso|bloqueado`) · detalle (json: valores al momento) · motivo · autorizado_por · timestamps.

### `credito_reprogramaciones`
credito_id · motivo · cargo_tipo · cargo_monto · accion_mora (`mantener|condonar|no_aplica`) · registrado_por · timestamps.

### `credito_reprogramacion_cuotas`
reprogramacion_id · cuota_id · fecha_anterior · fecha_nueva · mora_congelada.

### `credito_cargos`
credito_id · cuota_id · tipo (`reprogramacion`) · monto · reprogramacion_id · estado (`vigente|anulado`) · registrado_por · timestamps.

### `credito_cliente_fichas`
cliente_id (unique, FK) · direccion_cobro · tipo_direccion (`casa|negocio`) · referencia · latitud · longitud · telefono_alterno · ocupacion · notas · actualizado_por · timestamps.

### `credito_cliente_archivos`
cliente_id · tipo (`dni_anverso|dni_reverso|foto_cliente|otro`) · ruta_archivo · registrado_por · timestamps.

### `feriados`
fecha (unique) · descripcion · origen (`nacional|propio`).

## 3. Integración con Caja (MVP)
| `reference_type` | Dirección | Origen |
|---|---|---|
| `credito_desembolso` | salida | Activar crédito |
| `credito_pago` | entrada | Cobro (cualquier método) |
| `prenda_venta` | entrada | Venta de prenda |
| `prenda_excedente` | salida | Devolución de excedente al cliente |
| `credito_devolucion_excedente` | salida | Devolución de pago en exceso / por error |
| corrección (`CashCorrectionService`) | inversa | Anulación de pago / crédito, corrección de capital |
| transferencia | caja cobrador → principal | Entrega del cobrador (1.14) |
- Requiere caja abierta. Anulación → `CashCorrectionService`.
- Todo en una transacción: crédito/pago + aplicaciones + movimiento de caja.

## 4. Tenant de giro `creditos`
- `'creditos'` en `GIROS_VALIDOS`. Verificar `migrarVertical()` sin carpeta de vertical.
- `menu_items` con `giro='creditos'`; verificar que Clientes y Caja aparezcan y retail/SUNAT no.
- Rutas: grupo autenticado + `permission:creditos.*`. Núcleo sin middleware `modulo:`; garantes/prendas con `modulo:` (ver 1.12).
- Provisión sin SunatConfig; wizard de central-panel con opción `creditos`.
- Pendiente con el cliente: ¿comprobantes por intereses? (fuera del MVP).

## 5. Estándares de código

### 5.1 Legible y mantenible
- `declare(strict_types=1)` en clases nuevas; tipos en todos los parámetros y retornos.
- Motor con **DTOs inmutables** (`readonly class`) de entrada/salida — nada de arrays asociativos sueltos entre clases.
- Clases pequeñas, una responsabilidad. Métodos cortos. Sin números mágicos (constantes o enums).
- Nombres de dominio en español coherentes con las tablas (`fechaVencimiento`, `montoCapital`).
- Comentarios solo donde explican el *por qué* de una regla de negocio (citar la sección del plan).
- Controllers delgados: validan (FormRequest) → llaman al servicio → devuelven Resource.
- Tests del motor antes de integrar; cada regla del plan con al menos un test.

### 5.2 Seguridad
- **Montos siempre calculados en el backend.** El frontend solo muestra previews; el servidor recalcula y no acepta totales enviados por el cliente.
- Validación con FormRequest (monto > 0, tasa dentro de límite configurable, cuotas 1-límite, fechas coherentes).
- `permission:creditos.*` por ruta + verificación en el servicio para acciones sensibles (anular, condonar).
- Modelos con `$fillable` explícito; nunca `$guarded = []`.
- Pagos: `DB::transaction` + `lockForUpdate()` sobre el crédito → evita pagos simultáneos aplicados dos veces.
- **Idempotencia**: el frontend genera `clave_idempotencia` por intento de cobro; doble toque o reintento de red no crea dos pagos.
- Nada se borra: anulaciones con motivo y usuario; acciones sensibles registradas con `AuditLogger`.
- Condiciones del crédito inmutables tras activar (el backend rechaza cambios).
- Errores al usuario sin datos internos; detalle solo en logs.

## 6. Diseño de pantallas (responsive)
- **Mobile-first**: diseñar primero a 360px, luego tablet (768px) y escritorio (≥1280px). Probar los tres tamaños antes de cerrar cada pantalla.
- Reutilizar componentes/estilos existentes de `admin-start-kit` (no introducir otra librería UI).
- Tablas (cronograma, créditos, cobranza del día) → **tarjetas apiladas en celular**, tabla en tablet/escritorio.
- Botones grandes (mínimo ~44px de alto), acción principal al alcance del pulgar en móvil.
- Inputs de dinero con `inputmode="decimal"` (teclado numérico en celular).
- Flujo de cobro corto: buscar cliente → deuda del día → monto → método → confirmar (con resumen de cómo se aplicará el pago).
- Preview del cronograma y de la liquidación antes de confirmar.
- Estados con color + texto (pendiente, vencida, pagada), no solo color.
- Estados de carga y error visibles; botón de cobrar deshabilitado mientras procesa.
- **Formulario de nuevo crédito (simple)**: Cliente (con tarjeta de estado: créditos activos, deuda, disponible, puntualidad) · Monto · Interés % + selector **Sobre el total / Mensual** · Forma de pago · N.º de pagos · Entrega del dinero · Primer pago (auto, editable) · "No cobrar domingos". Debajo, **Resumen en vivo** (capital, interés, total, "N pagos de S/ X", primer y último pago) + vista previa del cronograma. Todo lo demás (gracia, interés mínimo, mora, tope, feriados) en **"Opciones avanzadas"** plegado con los valores por defecto. El selector "Método" no se muestra mientras exista un solo método.
- Mockups de referencia: canvas "Pantallas Módulo Créditos" (Nuevo crédito, Detalle, Cobrar, Cobranza del día, Panel de inicio).

## 7. Fases

| Fase | Contenido | Toca BD |
|---|---|---|
| 1. Motor | `GeneradorCronograma`, `CalendarioLaborable`, `CalculadoraInteres`, `CalculadoraMora`, `CalculadoraLiquidacion`, `AplicadorPagos` + tests | No |
| 2. Datos | Migraciones, modelos, enums, config, feriados, correlativo, giro, menú, permisos | Sí |
| 3. API | Crear/preview/activar, registrar crédito existente, renovar, pago con fecha anterior, ficha del cliente, límites de otorgamiento (1.17), cronograma, cobrar, anular pago/crédito, corregir, reprogramar fechas, liquidar, condonar, estado de cuenta, vencidas del día — con caja | Sí |
| 4. Frontend | `views/creditos/` responsive | — |
| 4b. Documentos | Contrato con plantilla, cronograma, recibo (80mm/A4/WhatsApp), estado de cuenta, constancia de cancelación | — |
| 4c. Reportes | Panel, cobranza del día, cartera, morosidad, ingresos, control; foto diaria; conciliación con caja; PDF | Sí |
| 5. Tenant | Alta del tenant del cliente + prueba end-to-end en celular y escritorio | Sí |
| 6. Módulos | Sistema de módulos (plan-modulo-planes-acceso §2), middleware `modulo:`, filtro de menú, tab Módulos en central-panel, adicionales en factura | Sí |
| 7. Garantías | Módulos `creditos_garantes` y `creditos_prendas`: garantes, prendas, fotos, ciclo de venta, excedente/faltante, proceso diario `apta_para_venta` | Sí |
| 8. Cobradores | Módulo `creditos_cobradores`: roles, cartera por cliente (preparada crédito/zona), caja del cobrador + entrega, cobranza del día, gestiones y promesas | Sí |
| Posterior | Otros métodos, reprogramación con cambio de montos, amortización parcial de capital (reducir plazo/cuota), zonas, GPS | |
| Transversal futuro | Modo offline (PWA + sincronización) para todos los giros — plan aparte | |

## 8. Tests mínimos del motor
Diario 24 con `[7]`, `[6,7]` y `[]` · Feriado en medio (siguiente / anterior / mantener) · `anterior` no retrocede antes de la cuota previa · Semanal 10 · Quincenal ambas reglas · Mensual 12 · Anual 5 · 31/01 · 29/02 · Σ cuotas exacta con redondeo 0.10 · Liquidación día siguiente (mínimo) · Liquidación a mitad de período · Mora con abono parcial por tramos · Mora sábado→lunes con `mora_cuenta_no_laborables` true/false · Pago que cubre 2 cuotas + mora · Excedente adelanta cuotas · Anular pago intermedio → recálculo igual a registrar solo los pagos válidos · Anular pago que cubrió mora → mora vuelve a pendiente · Corregir crédito genera versión 2 del cronograma · Venta de prenda con excedente · Venta con faltante (crédito sigue activo) · Segunda prenda devuelta si la primera cubrió la deuda · Límite de créditos simultáneos · Deuda máxima (saldo + nuevo) · Moroso bloqueado y autorización con motivo · Cliente no puede ser su propio garante · Liquidación con cuota vencida impaga (su interés se cobra completo) · Excedente con destino devolver/saldo a favor no se rechaza; con destino adelanto que supera la liquidación → `PagoExcedeDeuda` · Crédito no finaliza con mora pendiente · Condonación se conserva tras anular un pago · Prenda vuelve a custodia si el cliente se pone al día · Gracia 3 días: pago día 13 sin mora, día 15 con 2 días · Venta de prenda menor a la liquidación aplicada como pago normal con cronograma intacto · Liquidación ejemplo 1.5 = 3,700 con cuotas 4-10 `pagada` y 500 condonado · Reprogramar +10 días desde cuota 5 · Reprogramar cuota vencida manteniendo mora (congelada) y condonándola · Cargo fijo e interés por días · Mora no corre sobre el cargo · Tope de mora por % de cuota, % de capital y días máximos · Castigo a los 90 días congela mora y saca de cartera activa · Pago de castigado = recupero · Migración modo rápido y detallado sin caja · Renovación 1,000 con liquidación 300 → caja salida 700 · Pago retroactivo reaplica pagos posteriores · Pago de 700 con deuda de 500 → devolución de 200 con salida de caja; caja cuadra.

## 9. Decisiones abiertas
Ninguna. (9.1 mora desde fin de gracia; 9.2 faltante como pago normal — cerradas 2026-09-29.)

## 10. Verificar en código antes de cada fase (Claude Code)
1. Caja: ¿varias sesiones abiertas simultáneas (una por usuario)? ¿transferencia entre cajas? (Fase 8)
2. Caja: comportamiento de `CashCorrectionService` cuando la sesión original está cerrada.
3. Librería PDF y servicio de links firmados existentes; soporte ticket 80mm. (Fase 4b)
4. Librería de gráficos en `admin-start-kit`. (Fase 4c)
5. Scheduler/cron existente para jobs nocturnos (foto diaria, `apta_para_venta`).
6. Mecanismo de siembra de roles/permisos y `menu_items` al provisionar un tenant.
7. `migrarVertical()` sin carpeta de vertical para `giro='creditos'`.
8. Wizard de alta de tenant en `central-panel`.
9. Generación de `TenantInvoice` para sumar módulos adicionales. (Fase 6)
10. Almacenamiento de archivos (fotos de prendas, contratos firmados): disco, ruta por tenant, tamaño máximo, compresión de imágenes desde celular.

## 11. Pendientes con el cliente (negocio)
- Confirmar reglas sensibles: interés mínimo 10%, mora sobre saldo diario, domingos no laborables.
- Legal: **tasas máximas BCRP (interés compensatorio y moratorio) — cobrar por encima puede configurar delito de usura (art. 214 Código Penal)**; `tasa_maxima` en configuración debe fijarse con ese límite. Registro SBS, procedimiento de venta de prendas (Ley de Garantía Mobiliaria), texto del contrato con su abogado.
- ¿Debe emitir comprobantes por los intereses? (fuera del MVP).
- Módulos que contratará (garantes, prendas, cobradores).

## 12. Aclaraciones técnicas (revisión pre-Fase 1, 2026-09-29) — prevalecen sobre secciones anteriores
**12.1 Tasa `mensual` con otras frecuencias** — mes comercial de 30 días: mes = 1, año = 12, quincena = ½ (ambas reglas quincenales), semana = 7/30, día = 1/30. `interes_total = capital × tasa × n × fracción`, calculado como fracción exacta (enteros) y redondeado **una sola vez, al `paso_redondeo` (S/ 0.10), medio hacia arriba**. Aplica también a `unidad_tasa = total`. Ese valor redondeado es el "interés exacto" de la regla 1.2 y el que muestra el contrato.
- **Capital**: la validación (FormRequest) exige que `monto_capital` sea múltiplo de `paso_redondeo`.

**12.2 División de la cuota** — capital ÷ n e interés ÷ n se redondean a S/ 0.10 **por separado** (medio hacia arriba); la última cuota absorbe la diferencia de cada componente. Resultado (con 12.1): **toda cuota, incluida la última, es múltiplo de 0.10** y Σ capital y Σ interés cuadran exactos. Ej.: 1,000 + 200 en 30 → 33.30 + 6.70 = 40.00; última 34.30 + 5.70 = 40.00. **Salvaguarda**: si algún componente de la última cuota quedara negativo (n grande), se recalcula ese componente redondeando hacia abajo. Ej.: interés 1.50 en 30 cuotas → 0.00 en las 29 primeras y 1.50 en la última (aceptado).

**12.3 Regla `anterior` imposible** — si retrocede antes de la cuota previa o del desembolso, para esa fecha se aplica `siguiente` (sin excepción; el crédito se puede crear). El preview lo indica.

**12.4 Divisor de la mora diaria** — siempre los **días calendario del período original** de la cuota. `mora_cuenta_no_laborables` solo afecta el **conteo de días de atraso**.

**12.5 Excedente (reconcilia 1.7 y §8)** — destino `devolver` o `saldo_a_favor`: el excedente queda fuera del crédito y el pago no se rechaza. Destino `adelanto`: si lo aplicado supera el monto de liquidación a la fecha → excepción `PagoExcedeDeuda` ("usa Liquidar").

**12.6 Qué es "lo que se debe hoy" (exigible)** = cuotas vencidas + mora (incl. congelada) + cargos pendientes + **la próxima cuota por vencer**. Solo lo que supere eso es excedente. Pagar la cuota un día antes no es excedente.

**12.7 Liquidación con cargos** — `monto_liquidacion = capital pendiente + interes_final − interés ya cobrado + mora pendiente (incl. congelada) + cargos pendientes`. Los cargos no tienen descuento.

**12.8 Anular pagos anteriores a una operación de cierre** — si al reaplicar (1.9 / 1.22) una liquidación, renovación o venta de prenda que liquidó dejara de alcanzar, el motor lo **marca en su resultado** (no corrige solo) y la Fase 3 **bloquea** esa anulación: primero se anula la operación de cierre (reabre el crédito, revierte condonaciones de interés y su caja), luego el pago anterior. Anular una operación de cierre: mismo permiso que `creditos.anular_pago`, motivo obligatorio, auditado.

**12.9 Alcance de los tests por fase** — la §8 lista escenarios completos; en la **Fase 1** se prueba solo la **parte de cálculo** de cada uno (motor puro). La parte de persistencia, caja y servicios va a la **Fase 3**:
- Límites (créditos simultáneos, deuda máxima, moroso + autorización, propio garante): Fase 1 = funciones de evaluación puras; Fase 3 = bloqueo real, `credito_autorizaciones`, auditoría.
- Caja (renovación con salida neta 700, "caja cuadra", migración sin caja): Fase 1 = montos netos calculados; Fase 3 = movimientos y conciliación.
- Versión 2 del cronograma, segunda prenda devuelta, pago de castigado = recupero: Fase 1 = cálculo del nuevo cronograma / cobertura de deuda / clasificación del pago; Fase 3 = persistencia y estados.

**12.10 Firmas del motor aprobadas (ajustes derivados de la v21)**
- `CuotaProgramada` incluye `bool $fechaForzadaASiguiente` (12.3; el preview lo muestra).
- `AplicadorPagos::montoExigible(EstadoCredito, array $pagos, Fecha $fecha): int` define el exigible y, por diferencia, el excedente (12.6).
- `ResultadoAplicacion` incluye `list<int|string> $cierresInsuficientes` (12.8).
- `OrigenPago` = `cobro | liquidacion | renovacion | venta_prenda | saldo_a_favor | saldo_inicial`. El aplicador trata `renovacion` (y la venta de prenda que liquida) como operación de cierre, igual que `liquidacion`.
- **Nota Fase 2**: `interes_condonado` no es una aplicación; se **recalcula desde el resultado del reparto** como los demás acumulados de la cuota (nunca se guarda por separado), para que anular una liquidación lo revierta.

**12.11 Motor: evaluación de límites, recupero y vencimiento (aprobado)**
- Séptima clase pura `EvaluadorLimites::evaluar(SolicitudOtorgamiento, PoliticaLimites): ResultadoLimites`, con enum `ReglaLimite` = `max_creditos | deuda_maxima | moroso | bloqueado | propio_garante | garante_moroso | garante_saturado`.
  - `max_creditos`, `deuda_maxima`, `moroso`, `bloqueado`: bloquean, autorizables por admin.
  - `propio_garante`: bloquea, **no autorizable** — también en migración (es integridad de datos, no política).
  - `garante_moroso`, `garante_saturado`: solo advierten.
  - `esMigracion = true`: todo lo demás pasa a advertencia.
  - `creditoARenovarId`: se excluye de la **deuda** y del **conteo** de créditos activos, pero **sí cuenta para `moroso`** (1.21: renovar a un moroso requiere autorización).
  - Los créditos **castigados** del cliente se incluyen en `creditosActivos` (su deuda sigue vigente) y el servicio de Fase 3 pasa `clienteBloqueado = true` si hay bloqueo manual **o** algún crédito castigado (1.19).
- `ResultadoPago` incluye `bool $esRecupero` = `fechaPago ≥ fechaCongelamiento` (fecha de castigo en `ReglasMora`). Derivado, no se guarda: si se revierte el castigo, deja de ser recupero.
- **Cuota vencida** = `fechaVencimiento < fecha`. La que vence hoy es la "próxima por vencer" (12.6); los días de atraso empiezan a contar desde el día siguiente al vencimiento y la mora después de la gracia.

**12.12 Cierre de Fase 1 (motor) — decisiones y pendientes para Fases 2-3**
Fase 1 construida en rama `feat/creditos-fase1-motor`: 7 clases (incl. `EvaluadorLimites`), 24 DTOs, 12 enums, 3 excepciones, utilidades `Redondeo`/`Fecha`/`Tasa`; 112 tests del motor; ejemplos del plan verificados (12.2, 1.6 = 200, 1.5 = 3,700 / 500 condonado, renovación = 700); prueba de mutaciones 5/5.
- Aprobado: `PagoAAplicar::$esCierre`, `CalculadoraLiquidacion` recibe la mora ya calculada, `ModoRedondeo`, `Redondeo::multiplicar()` con control de desborde, DTO `Abono`, `$numerosPagados` en `desplazarFechas()`.
- **Fase 2**: agregar `credito_pagos.es_cierre` (bool) — indica si ese pago cerró el crédito (liquidación, renovación o venta de prenda que liquidó); lo necesita el reparto (12.8).
- Confirmado: toda frecuencia en **días** (`dia` con cualquier intervalo, incl. quincenal corrido) usa "el día no laborable no genera cuota" (no mover fecha), para evitar dos cuotas el mismo día.
- Confirmado: `diasAtraso` para escalamiento, prendas, castigo y bloqueo = **días calendario**; `mora_cuenta_no_laborables` solo afecta la mora.
- Confirmado: venta de prenda con faltante se envía con destino `adelanto` (nunca excede la liquidación por definición).
- **Fase 3 — anular un pago anterior a una reprogramación de fechas**: se **bloquea** (igual que 12.8). La mora de ese tramo se calcularía contra la fecha nueva. Alternativa para el usuario: condonación o ajuste manual.
- **Revertir un castigo** (default, confirmar con el negocio): el período castigado **no genera mora retroactiva**; la mora se reanuda desde la fecha de reversión. Requiere guardar los intervalos de congelamiento (ej. `credito_castigos`: fecha_castigo, fecha_reversion) y que `ReglasMora` reciba la lista de intervalos en vez de una sola fecha.