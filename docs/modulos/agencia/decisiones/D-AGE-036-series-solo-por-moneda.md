# D-AGE-036 — Las series se separan solo por moneda; el comprobante mixto va en la serie de siempre

| | |
|---|---|
| **Fecha** | 2026-11-16 |
| **Estado** | aceptada. Descarta P8 de `agencia-tributario-simplificado.md` |
| **Reglas que afecta** | — (`serie_comprobantes` ya es `unique(branch_id, tipo_comprobante_codigo, moneda)`; no cambia) |
| **Decidió** | usuario (con Claude) |

## Contexto
El contador pidió series por afectación (exonerado / gravado / mixto × moneda, en facturas y
boletas: 12 por sucursal), y eso quedó como P8 del requerimiento tributario, esperando las
preguntas C11, C12 y U1. Con D-AGE-035 un comprobante puede mezclar líneas gravadas, exoneradas e
inafectas.

## Opciones consideradas
1. **Series por afectación × moneda** (P8): migración del índice único, `resolverSerie()` recibe la
   afectación, 12 series por sucursal; si falta una, la venta falla.
2. **Series solo por moneda**, como hoy: el comprobante mixto va en la serie normal de su tipo y
   moneda.

## Decisión
**Opción 2.** Se sigue con las series como están; se separa solo por moneda (F001 soles,
F002 dólares, si la empresa lo crea en el módulo de Series). Un comprobante mixto se emite en la
serie de siempre. Vale para facturas, boletas y notas.

## Consecuencias
- SUNAT no exige series por afectación (es orden interno del contador, §2.4 del requerimiento):
  el XML ya lleva la afectación de cada línea y los totales por separado.
- P8 se descarta. C11 y C12 dejan de ser preguntas para el contador (se le comunica la decisión);
  U1 (¿también en retail?) ya no aplica.
- El contador separa gravado / exonerado / inafecto por **columnas del Registro de Ventas**, no por
  serie. Ese reporte todavía no existe (backlog, "Registro de Ventas para el contador (SIRE)"):
  conviene adelantarlo antes de que empiecen las facturas mixtas.
