# D-AGE-030 — Una modificación de reserva es una cotización propia, oculta, ligada a la reserva

| | |
|---|---|
| **Fecha** | 2026-10-24 |
| **Estado** | aceptada |
| **Reglas que afecta** | R-AGE-01, R-AGE-02 |
| **Decidió** | usuario (con Claude) — `docs/requerimientos/agencia-modificar-reserva.md`, pregunta 1 |

## Contexto
"Modificar reserva" necesita una versión nueva de la alternativa aceptada, con la posibilidad
de cambiar pasajeros. Hay dos hechos del código que lo condicionan. Primero, los pasajeros son
de la **cotización** (`cotizacion_pasajeros`), no de la alternativa. Segundo, facturar lee el
precio **en vivo** de `alternativa_items`, así que la alternativa vendida no se puede editar.

## Opciones consideradas
1. **Cotización de modificación** (`cotizaciones.origen = 'modificacion'`): copia de los
   pasajeros que hoy tiene la reserva y de la alternativa vigente, ligada a la reserva. A
   favor: se usa el cotizador tal cual (recalcular por pasajeros, tours, hoteles) y la
   versión vendida no se toca. En contra: después de aplicar, `reserva.alternativa.cotizacion`
   pasa a ser la de modificación, así que hay que revisar quién lee esa relación.
2. **Alternativa nueva en la misma cotización:** obliga a mover los pasajeros de la cotización
   a la alternativa, lo que toca casi todo el cotizador. Más riesgo y más trabajo.

## Decisión
Opción 1. La cotización de modificación tiene `origen = 'modificacion'`, por lo que
`Cotizacion::delCotizador()` la deja fuera de los listados comerciales sin código nuevo. Copia
el cliente y la moneda, y nunca se puede "aceptar" por el camino normal (generaría una segunda
reserva). Cada modificación queda registrada en `reserva_modificaciones`, con estado
`abierta | aplicada | descartada`.

## Consecuencias
- Agregar o quitar pasajeros en la modificación recalcula con el código existente.
- Una reserva puede tener una cadena de modificaciones: cada una parte de la versión vigente.
- Hay que hacer un inventario de lecturas de `reserva.alternativa.cotizacion` (T-005).
- `cotizacionTieneReservaActiva()` debe seguir viendo la reserva desde la cotización
  original, para que no se acepte otra alternativa (T-005).
