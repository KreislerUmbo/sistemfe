# Endoso de pasajeros entre agencias

> **Estado: DISEÑO CERRADO (versión 2, 14-oct-2026) — pendiente de aprobación, no implementar todavía.** Reescrito sobre el
> modelo de `plan-tours-venta-simple-costo-real.md` (tour como una línea, operación por salida,
> Compras y rentabilidad). La versión 1 (11-oct) se apoyaba en el plan de reparto de precio,
> ya descartado (`tour_bloque_id`, `cobro_por`, costo en `reserva_items`: nada de eso aplica).
> Depende de las fases 1-2 del plan de tours; el costo real, de la fase 3 (Compras).

---

## 1. El caso de negocio

En los tours compartidos, cada salida necesita un mínimo de pasajeros. Cuando no se llega
(o por otra razón), la agencia **endosa** sus pasajeros a otra agencia u operador que sí sale
ese día. El precio al cliente no cambia; cambia **quién hace el tour y cuánto le cuesta a la
agencia**.

Dos lados, según la agencia:
- **Enviar endosos:** todas las agencias (dakamu, sin movilidad propia, solo envía).
- **Recibir endosos:** las agencias con movilidad propia, que completan sus salidas con pasajeros de otras agencias. Necesario para ofrecer el sistema a ese tipo de agencia.

## 2. Respuestas del usuario (11-oct-2026)

| # | Respuesta |
|---|---|
| A1 | El precio del operador que recibe **cubre todo** (movilidad, guía, entradas, almuerzo): un solo costo por persona. |
| A2 | Recibir endosos **depende de la agencia**: activable por tenant. |
| A3 | El endoso **a veces se planifica antes**, no siempre el día anterior: debe poder hacerse en cualquier momento después de aceptada la reserva. |

## 3. Endoso contra comprar a un operador

- Si la agencia **siempre** vende un tour que hace otro operador (nunca lo opera), **no es un endoso**: es un servicio comprado. El tour se arma con ese operador en el Incluye (o con costo estimado sin detalle), y su factura entra a Compras como cualquier gasto.
- El endoso es para cuando la agencia **pensaba operar** el tour y lo pasa a otro.

## 4. La idea central: una salida operada por un tercero

En el modelo nuevo, la operación es por **salida** (tour + fecha). Endosar es simplemente
**mover las líneas de tour de unas reservas a una salida operada por otro**:

- `salidas_operativas` gana `operador_proveedor_id` (nullable). Vacío = la opera la agencia; con valor = **la opera un tercero (endoso)**.
- Endosar = mover esas líneas de tour a la salida "Laguna Azul 15-nov — operada por Turismo Selva".
- **Revertir** = devolverlas a la salida propia.

Todo lo demás ya existe en el plan de tours y funciona igual para una salida endosada:

| Necesidad | Cómo se resuelve |
|---|---|
| Costo real del endoso (antes E-Q4) | La **factura del operador** se registra en Compras y se asigna a la salida endosada (§3.7 del plan de tours). Rentabilidad por salida y por reserva sin columnas nuevas de costo. |
| Pago al operador (antes E-Q3) | Compras: contado o crédito, pagos desde Caja o Banco, cuentas por pagar. |
| Estimado contra real | El **costo acordado por persona** del endoso pasa a ser el estimado de esa salida (reemplaza al costo estimado del tour para esos pasajeros). Si la factura difiere, la pestaña "Costos" lo marca. |
| Qué ve operaciones | El Reporte Operativo muestra la salida como **"operada por Turismo Selva (endoso)"**, con los pasajeros (incluido el infante), la hora y el punto de encuentro acordados, y el contacto del operador. Sin vehículos, guía ni servicios a reservar (A1). |
| Calificación del cliente | Se atribuye al operador. |

## 5. Enviar un endoso

**Dónde y cuándo (A3):**
- Desde la **salida propia**: "Endosar", eligiendo qué reservas (por defecto, todas). Caso típico: no se llegó al mínimo.
- Desde la **reserva**: "Endosar este tour", en cualquier momento después de aceptada (planificado con anticipación).

**Qué se pide:**
- Operador (proveedor turístico, normalmente tipo "Operador de turismo").
- Costo acordado por persona (adulto/niño si difiere) y moneda.
- Hora y punto de encuentro, contacto en el operador.
- Motivo.

**Qué pasa:**
1. Se crea (o se reutiliza, si ya existe para ese tour, fecha y operador) la salida operada por el tercero, y se mueven ahí las líneas de tour de las reservas elegidas.
2. La salida propia recalcula su conteo y su mínimo. Si queda vacía, pasa a "cancelada" (no se borra).
3. **Aviso:** si la salida propia ya tenía proveedores asignados (restaurante, vehículo, guía) y queda vacía o con menos pasajeros, se avisa qué reservas hay que cancelar o ajustar con esos proveedores.
4. **Datos para avisar al cliente:** nueva hora y punto de encuentro, para comunicárselos (el envío por WhatsApp o correo queda para después).
5. **Auditoría:** quién, cuándo, motivo; la salida de origen queda registrada para poder revertir. Mismo patrón que las reasignaciones existentes (`*_original_id`, `motivo_*`, `fecha_*`).

**El precio y la factura al cliente no cambian** (ya puede estar facturado).

> **Choque con el código actual — resolver al empezar E1 (encontrado el 22-oct-2026, al cerrar la
> fase 2c del plan de tours):** el punto 2 dice que una salida propia que queda vacía "pasa a
> cancelada (no se borra)". Hoy el sistema **la borra**: `SalidaOperativa::eliminarSiQuedoVacia()`
> (auditoría del 22-sep-2026, para que no quedaran salidas fantasma con 0 pasajeros en el tablero),
> llamado al cancelar o reprogramar una reserva, al quitar un ítem y al desenganchar. Desde la fase
> 2b, borrar la salida **borra también sus servicios a reservar y sus vehículos** (cascada en
> `salida_servicios` y `salida_vehiculos`). Revertir un endoso necesita volver a la salida propia
> con lo que ya tenía armado, así que para la salida que se endosa hay que decidir entre:
> - marcarla `cancelada` en vez de borrarla (como dice este plan), o
> - borrarla igual y que "revertir" la arme de nuevo, guardando antes lo configurado (lo que ya hace
>   "reprogramar salida completa" de la fase 2c con `SalidaOperacionService::foto()` y
>   `llevarConfiguracion()`).
>
> En cualquiera de los dos casos, el aviso del punto 3 ya existe: `SalidaOperacionService::avisos()`
> (fase 2c). La lista de pasajeros para el operador puede reutilizar el generador de las órdenes de
> servicio (`SalidaOperacionService::ordenes()`).

**Revertir:** devuelve las líneas a la salida propia de ese tour y fecha (la crea si no existe).
Si ya hay una factura del operador asignada a esa salida, se avisa antes de revertir.

## 6. Recibir un endoso

Solo para tenants con **"Recibe endosos"** activo en Configuración de Agencia (A2).

**Registro rápido "Endoso recibido":**
- Agencia que envía: es un **cliente** (con RUC).
- Tour, fecha, pasajeros (nombre, documento, tipo, residencia), punto de recojo, observaciones.
- Precio: **precio para agencias** del tour, o el del **convenio** de esa agencia si tiene uno (E-Q2).

**Cómo se guarda:** igual que Venta Directa, que ya crea una cotización de soporte con
`cotizaciones.origen` propio y su reserva. Se agrega el origen `endoso_recibido`. Así las
cotizaciones de soporte no ensucian el Cotizador (regla ya existente de `Cotizacion::delCotizador()`).

**Efectos:**
- La línea del tour se engancha a la salida propia del día: **suma al conteo y al mínimo**.
- Cupo: aviso si se pasa del `cupo_maximo` de la salida, sin bloquear (E-Q5; mismo criterio actual, donde el cupo es informativo).
- En la salida se distingue de dónde viene cada reserva ("endoso de Agencia X").
- La rentabilidad de la salida suma su ingreso.

**Facturación y cobro:**
- Se factura a la agencia que envió (cliente), con las mismas reglas de IGV que cualquier tour.
- **Las dos opciones** (E-Q6): una factura por endoso, o una **factura mensual por agencia** que agrupe varios endosos; se elige al facturar, con preferencia por defecto en la agencia aliada. La agrupación usa el mismo reparto entre reservas que ya se diseñó para comisiones (§3.7d del plan de tours).
- Si se cobra después, queda en Cuentas por Cobrar (existente).

**Reciprocidad:** si dos agencias se endosan entre sí (A le envía a B y B le envía a A), lo que
B le debe a A y lo que A le debe a B se pueden **compensar**, con el mismo mecanismo de
compensación cobro-pago ya diseñado para comisiones (§3.7d del plan de tours).

## 7. Decisiones (14-oct-2026)

| # | Decisión |
|---|---|
| E-Q1 | **Se endosa la reserva completa** en ese tour (todos sus pasajeros). El endoso parcial (parte de los pasajeros de una reserva) **queda fuera por ahora**; si aparece como caso real, se planifica aparte. |
| E-Q2 | **Precio para agencias en el tour** (adulto/niño, columnas nuevas en `paquetes_plantilla`) + **convenio opcional por agencia aliada** que lo reemplaza para esa agencia. |
| E-Q3 | *Resuelta por el diseño:* el pago al operador va por Compras (contado o crédito, Caja o Banco). |
| E-Q4 | *Resuelta por el diseño:* el margen real sale de la rentabilidad por salida y por reserva; no hace falta costo en `reserva_items`. |
| E-Q5 | **Cupo al recibir: avisar, no bloquear** (mismo criterio actual: el cupo de la salida es informativo). |
| E-Q6 | **Las dos opciones de factura al recibir:** una factura por endoso, o una factura mensual por agencia que agrupa varios endosos. Se elige al facturar; la agencia aliada puede tener una preferencia por defecto. |
| E-Q7 | **Reciprocidad: se incluye.** No aplica a dakamu, pero el usuario la ha visto en otras agencias. Compensación de saldos cobro-pago con el mismo mecanismo de comisiones (§3.7d del plan de tours). |

No quedan preguntas abiertas.

## 8. Fases y estimado

| Fase | Contenido | Depende de | Estimado |
|---|---|---|---|
| E1 | **Enviar endoso:** `operador_proveedor_id` en salidas, mover y revertir líneas, costo acordado como estimado, avisos (en la bandeja de pendientes, §3.14 del plan de tours), **lista de pasajeros para el operador** (PDF y WhatsApp, con alimentación especial y punto de encuentro; mismo generador que las órdenes de servicio, §3.13), Reporte Operativo, permisos y menú | Fases 1-2 del plan de tours (el costo real, de la 3) | 3–3.5 días |
| E2 | **Recibir endoso:** configuración por tenant, registro rápido con origen `endoso_recibido`, precio para agencias en el tour y convenio opcional por agencia, enganche a la salida con aviso de cupo, facturación por endoso o mensual por agencia, compensación por reciprocidad, permisos y menú | E1; facturación agrupada y compensación, de la fase 3 | 3.5–4.5 días |
