# D-AGE-031 — Aplicar una modificación re-apunta los servicios no facturados; los facturados quedan en la versión vendida

| | |
|---|---|
| **Fecha** | 2026-10-24 |
| **Estado** | aceptada |
| **Reglas que afecta** | R-AGE-03, R-AGE-04, R-AGE-08 |
| **Decidió** | usuario (con Claude) — requerimiento, pregunta 2 |

## Contexto
`reserva_items` no guarda precio: facturar lo lee de `reserva_item.alternativaItem`. Para que
la reserva tome los precios nuevos sin cambiar lo ya facturado, hay que decidir a qué
`alternativa_item` apunta cada servicio después de aplicar.

## Opciones consideradas
1. **Re-apuntar por origen:** cada `alternativa_item` copiado recuerda de cuál viene
   (`item_origen_id`). Al aplicar, lo no facturado apunta al ítem nuevo y lo facturado sigue
   en el viejo. A favor: no cambia la facturación. En contra: la reserva queda con servicios
   que apuntan a dos alternativas.
2. **Guardar el precio en cada `reserva_item`:** más robusto a largo plazo, pero cambia toda
   la facturación (tours repartidos en varias líneas, `partes_tour`, notas de crédito). No se
   mezcla con este trabajo.

## Decisión
Opción 1. `reserva.alternativa_id` pasa a la versión nueva y la alternativa anterior queda con
estado `reemplazada`. Una línea de tour con **cualquier** parte facturada
(`ReservaVenta::itemsDeTourAMedias()`) cuenta como facturada.

## Consecuencias
- Aplicar no destruye historial: la versión vendida sigue existiendo y lo facturado sigue
  apuntando a ella.
- Los datos operativos del `reserva_item` re-apuntado (fecha manual, proveedor u hotel
  reasignado, salida) se conservan; solo cambia el ítem de origen del precio.
- Si en el futuro se pasa a la opción 2, esta decisión se reemplaza con una nueva.
