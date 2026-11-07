# D-AGE-035 — En Agencia, la afectación depende de quién opera el servicio; gravado y exonerado van en el mismo comprobante, sin confirmar la Amazonía ítem por ítem

| | |
|---|---|
| **Fecha** | 2026-11-07 |
| **Estado** | aceptada en los puntos 2, 3 y 4 (mezcla, sin confirmación, alcance), que reemplazan el punto 2 de D-AGE-034. **El punto 1 ("quién opera") queda en revisión desde el 07-nov**: la norma y SUNAT apuntan a otra cosa cuando la agencia revende (`agencia-tributario-simplificado.md` §2.4b); mientras tanto se usa el criterio conservador (lo ejecutado fuera de la Amazonía nace gravado). Una decisión nueva cerrará el punto 1 con las respuestas de la parte A0 |
| **Reglas que afecta** | — (el tributario de Agencia todavía no tiene reglas en `reglas.md`; lo usarán P2, P4, P5 y P7 de `agencia-tributario-simplificado.md`) |
| **Decidió** | usuario (con Claude), con las respuestas del contador que transmitió el usuario |

## Contexto
El contador pidió series por afectación y el usuario quiso simplificar el tributario de los
servicios (`docs/requerimientos/agencia-tributario-simplificado.md`). Hasta hoy el sistema:
- deducía la exoneración de **dónde se presta** el servicio (destino Amazonía / nacional);
- bloqueaba un comprobante con exonerado y gravado, salvo dentro de una misma `carga_tour`;
- exigía que una persona confirmara cada servicio de la Amazonía antes de facturarlo
  (`motivo_override_tributario`), y D-AGE-034 llevaba esa confirmación al resumen de Venta directa.

## Opciones consideradas
1. **Criterio de la exoneración:** (a) dónde se consume el servicio; (b) quién lo opera.
2. **Mezcla:** (a) un comprobante por afectación; (b) un comprobante con líneas de distinta
   afectación.
3. **Confirmación de la Amazonía:** (a) mantenerla ítem por ítem; (b) quitarla y dejar que la
   grilla de facturar muestre y corrija cada línea.

## Decisión
1. **Opción 1(b), quién opera** (C1, C1a, C1b del 07-nov):
   - La exoneración solo existe si la **agencia** es de la Amazonía, está inscrita en los
     Registros Públicos de la región y está en el Régimen MYPE Tributario o en el General.
   - Cumplido eso, un servicio de un **tercero** (hotel, mayorista, pasaje aéreo, tours de otro
     operador), dentro o fuera de la zona, va **exonerado**, y también el **fee** de la agencia.
   - Va **gravado** solo lo que la agencia **opera en destino fuera de la Amazonía**, con guía
     **y** vehículo propios.
   - **Pasaje aéreo:** la agencia revende el boleto completo (tarifa + cargos + fee), exonerado.
   - Se concilia con N5 del plan de tours (14-oct, "según dónde se presta"): N5 habla de los
     servicios de un **tour propio**, que opera la agencia; para esos, dónde se opera sí decide.
2. **Opción 2(b):** gravado y exonerado en el mismo comprobante, en cualquier combinación (C4).
3. **Opción 3(b):** se quita la confirmación (C5). Se van el "Sin confirmar" de la reserva, el modal
   "Confirmar tratamiento tributario" y la casilla de Venta directa (punto 2 de D-AGE-034).
4. **Alcance:** solo agencias de la Amazonía. Una empresa fuera de la zona (o un cambio de la ley)
   se prepara después.

## Consecuencias
- Casi todo nace exonerado: los valores por defecto pasan a ser "terceros = Exonerado" y "operado
  por la agencia fuera de la zona = Gravado".
- H21 (paquete de mayorista exonerado) deja de ser un error.
- T-048 se reescribe sin la casilla y depende de P7 (quitar el bloqueo de mezcla y la
  confirmación).
- El criterio "quién opera" no está por escrito: si SUNAT entiende que un servicio se presta donde
  se consume, lo vendido fuera de la zona quedaría gravado. Se pide al contador por escrito.
- Una empresa fuera de la Amazonía necesitará partir el pasaje (tarifa y asiento gravados, TUA sin
  IGV) y otros valores por defecto: queda fuera a propósito.
