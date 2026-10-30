# D-AGE-032 — Diferencia sobre lo facturado: si sube, servicio de ajuste; si baja, nota de crédito antes de aplicar

| | |
|---|---|
| **Fecha** | 2026-10-24 |
| **Estado** | aceptada |
| **Reglas que afecta** | R-AGE-04, R-AGE-06 |
| **Decidió** | usuario (con Claude) — requerimiento, preguntas 3 y 4 |

## Contexto
Un comprobante enviado a SUNAT no se edita. `plan-modulo-cotizaciones-reservas.md` §4.3 ya
preveía dos caminos para un cambio después de facturar: anular con nota de crédito y
refacturar todo, o emitir un documento adicional.

## Opciones consideradas
1. **Sube:** servicio "Ajuste — <servicio>" por la diferencia, facturado aparte (documento
   adicional). **Baja o se quita algo facturado:** se bloquea la aplicación hasta emitir la
   nota de crédito.
2. Anular todo con nota de crédito y refacturar la reserva completa con cada modificación.
3. Aplicar todo menos lo que necesita nota de crédito.

## Decisión
Opción 1. Es la más simple y no deja reservas a medio modificar. La opción 2 queda para
después, si hace falta.

## Consecuencias
- El aviso de "requiere nota de crédito" lleva directo al comprobante.
- El servicio de ajuste es un ítem manual de la versión nueva, con el tratamiento tributario
  del servicio que ajusta.
- (T-005, 30-oct) El ajuste se marca con `alternativa_items.ajuste_de_item_id` (el ítem con el
  precio subido): la copia de la modificación siguiente no lo copia y la diferencia no lo
  compara, para no cobrarlo dos veces. Nace sin pasajeros asignados (como la penalidad): al
  facturar, el vendedor lo suma a la factura que quiera.
