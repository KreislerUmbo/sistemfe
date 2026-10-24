# D-AGE-033 — Penalidad por quitar un pasajero: servicio con monto y motivo del vendedor, IGV elegido sin valor por defecto

| | |
|---|---|
| **Fecha** | 2026-10-24 |
| **Estado** | aceptada (el tratamiento de IGV queda abierto hasta que responda el contador) |
| **Reglas que afecta** | R-AGE-05 |
| **Decidió** | usuario (con Claude) — requerimiento, pregunta 5 y reglas del 23-oct |

## Contexto
Los términos y condiciones son texto libre (`condiciones_generales_servicio`); no hay una
regla de cálculo. `reglas_cancelacion` (franjas de días → %) existe pero nada la usa. Para una
penalidad por cancelación no está definido si lleva IGV (indemnización) o no (servicio).

## Opciones consideradas
1. Servicio "Penalidad por cancelación — <pasajero>" en la versión nueva, con monto (≥ 0) y
   motivo escritos por el vendedor, y tratamiento de IGV **elegido a mano**.
2. Calcular el monto con `reglas_cancelacion`: queda para después, cuando las reglas sean
   configurables.
3. Fijar un IGV por defecto: descartado, porque el proyecto no admite valores tributarios por
   defecto silenciosos.

## Decisión
Opción 1. Un monto 0 es válido, pero el motivo es obligatorio.

## Consecuencias
- Cuando el contador defina el tratamiento, se escribe una decisión nueva y se puede fijar.
- El cálculo automático con `reglas_cancelacion` se apoya en esta misma estructura.
