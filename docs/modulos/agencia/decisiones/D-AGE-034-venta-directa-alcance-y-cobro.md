# D-AGE-034 — Venta directa: servicios sueltos de varios días, sin paquetes; cobro con boleta o factura y la Amazonía confirmada en el resumen

| | |
|---|---|
| **Fecha** | 2026-11-05 |
| **Estado** | aceptada; **el punto 2 (Amazonía confirmada en el resumen) lo reemplaza D-AGE-035** (07-nov-2026) |
| **Reglas que afecta** | — (Venta directa todavía no tiene reglas propias en `reglas.md`) |
| **Decidió** | usuario (con Claude), sobre la maqueta del rediseño (05-nov) |

## Contexto
La obs. 12 (`docs/requerimientos/observaciones-pruebas-usuario.md`) aprobó T-045 a T-049. Al
diseñar la pantalla final con las cuatro juntas aparecieron puntos que las tareas no resolvían:

- La **pregunta 3** de la obs. 12 (¿paquetes de varios días?) quedó sin decidir, pero vender dos
  tours en días distintos o una habitación por noches ya es vender varios días.
- **Hotel:** la tarifa es por tipo de habitación y por noche; «por persona / por unidad» no aplica.
- **Facturar una reserva** (`ReservaFacturacionController`) tiene un guardia tributario: cada
  servicio de la Amazonía tiene que confirmarlo una persona antes de facturarse
  (`motivo_override_tributario`, ruta con `reservas.editar`); no se mezclan exonerado y gravado en
  un comprobante (salvo un mismo tour); exportación/extranjero está pausado. Con eso, «Crear y
  cobrar» fallaría casi siempre en una agencia de la Amazonía.
- Facturar una reserva **no emite nota de venta** (`PERMISOS_EMISION` solo 01 y 03), y la obs. 12
  la pedía.

## Opciones consideradas
1. **Alcance:** (a) un solo día para todo; (b) fecha por servicio y hotel con noches, sin
   paquetes; (c) también paquetes y combos. Se descarta (c): sería un segundo cotizador sin
   alternativas, PDF ni margen.
2. **Amazonía:** (a) el vendedor la confirma en el resumen; (b) si hay Amazonía, no se ofrece
   «Crear y cobrar».
3. **Nota de venta:** (a) se quita; (b) se habilita al facturar reservas (toca la regla de que lo
   facturado en una reserva es un documento fiscal).

## Decisión
1. **Servicios sueltos sí, paquetes no** (responde la pregunta 3 de la obs. 12).
   - Cada servicio tiene su fecha. Se guarda como `alternativa_items.dia_referencial` (día del
     viaje); la fecha real es fecha de inicio + día − 1, como en el cotizador. Así cada tour se
     engancha a la salida de su día y «Reprogramar» corre todo junto.
   - `fecha_viaje_hasta` = la fecha más tardía entre los servicios y la salida de cada hotel.
   - Hotel = tarifa con `tipo_habitacion` (la misma señal que usan facturación y el reporte
     operativo). Se cobra precio × noches; `cantidad` = noches. Varias habitaciones = varias
     líneas. No se ofrece «por persona».
   - Combos y paquetes del catálogo se ven marcados «Solo desde el cotizador» y no se agregan.
2. **Amazonía, opción (a)**, con estas condiciones:
   - Cada línea muestra su afectación; la vista previa la calcula en el backend.
   - Si hay líneas de la Amazonía, el resumen pide «Confirmo que estos servicios se realizan en
     la Amazonía». Solo la ve quien tiene `reservas.editar` (el mismo permiso que confirmarla
     desde la reserva). Se guarda en cada línea con el motivo automático «Confirmado en venta
     directa por <usuario>».
   - Sin ese permiso, «Crear y cobrar» queda deshabilitado con el motivo; «Crear sin cobrar»
     sigue disponible.
   - Mezcla de afectaciones o exportación: se avisa **al armar la venta**, «Crear y cobrar» se
     deshabilita y se factura desde la reserva, en comprobantes separados, como hoy.
3. **Nota de venta, opción (a):** en venta directa se emite boleta o factura.

Además:
- **Una sola moneda por venta, sin conversión.** El vendedor elige la moneda de la venta (soles por
  defecto). Un servicio en otra moneda no se agrega: el catálogo lo muestra deshabilitado y el
  backend responde 422. Se descartó convertir con el tipo de cambio del día (como el cotizador):
  en mostrador, un precio convertido que el vendedor no fijó confunde más de lo que ayuda. Pagar
  en otra moneda sigue siendo posible en el cobro (T-010 convierte al cobrar).
- El cobro usa el bloque de T-010/T-011 (Yape/Plin, pago mixto, dólares, n.º de operación,
  vuelto), no uno propio. «Crear y cobrar» aparece solo con `AGENCIA_COBRO_AL_FACTURAR` encendido.
- El concepto libre pide costo (0 por defecto, «0 si no tiene»), en la moneda de la venta, y toma el tratamiento
  tributario por defecto de la agencia (`ConfiguracionAgencia::tratamientoTributarioDefault()`),
  como hoy; definirlo por servicio es la obs. 10.
- La estructura de varias líneas se construye **primero** (T-085) y absorbe T-049; T-046, T-047 y
  T-048 llenan su parte dentro de ella.
- **Maqueta aprobada (05-nov):** «Venta directa — rediseño B»,
  https://claude.ai/artifact/YCcxXjFw7KM9gA32Jajf8C. Es la referencia visual de toda la pantalla:
  cada tarea de Venta directa la sigue bloque por bloque, con el estilo del sistema (cards,
  inputs `-sm`) en vez de sus colores. Lo que se aparte de ella se anota como desviación en la
  tarea. (Agregado el 05-nov: T-085 y la primera versión de T-046 se construyeron leyendo solo el
  texto de las tareas y no la siguieron; lo corrigen T-046 y T-086.)

## Consecuencias
- Si el contador no acepta la confirmación en el resumen, T-048 vuelve a la opción 2(b) sin tocar
  T-085 a T-047.
- Si más adelante se decide vender paquetes por venta directa, se escribe una decisión nueva que
  reemplace el punto 1.
- La capacidad de cada habitación no está en la tarifa: no se avisa si entran más pasajeros de la
  cuenta.
