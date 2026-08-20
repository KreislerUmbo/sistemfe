# Parche para Claude Code — Guardia de tratamiento tributario mixto en facturación de reserva

> Pégale esto a la sesión de Claude Code que está trabajando (o acaba de
> cerrar) la fila 11u de `plan-hoja-de-ruta-ejecucion.md`
> (`PEGAR-EN-CLAUDE-CODE-facturar-reserva.md`). Es un **complemento**, no
> reemplaza nada de lo ya definido — agrégalo como parte de la misma
> sesión si todavía sigue abierta, o como una sesión chica aparte
> inmediatamente después si 11u ya se mergeó.
>
> Contexto: al revisar el diseño con el usuario, salió un hueco real que
> el brief original de 11u no cubría del todo — ver
> `plan-modulo-cotizaciones-reservas.md` §9 (pendiente ya documentado
> desde antes: `Sale.destino`/`Sale.es_exportacion` son un solo valor por
> venta completa, mientras que `tip_afe_igv`/`destino_tributario` son por
> línea/ítem). El brief de 11u resolvía la mezcla **dentro de una misma
> línea de factura** (§6.3: dos ítems con distinto tratamiento nunca se
> fusionan en la misma línea) — pero no resolvía qué pasa si la
> **reserva completa** mezcla tratamientos entre categorías distintas
> (ej. un tour local exonerado Amazonía + un vuelo nacional gravado): en
> ese caso ambos pueden terminar en el mismo `Sale`, y la cabecera de esa
> venta solo puede reflejar UNO de los dos tratamientos — riesgo real de
> emitir un comprobante SUNAT con la exoneración mal calculada.

---

## 1. Por qué esto no puede quedar para "después" como crédito/cobro

A diferencia de las otras 3 decisiones que ya tomó el usuario para esta
sesión (cobro pendiente vs. inmediato, crédito fuera de fase, líneas
agrupadas por categoría — todas conveniencia operativa, corregibles sin
drama), esto es **cumplimiento tributario**: si se emite un comprobante
real con el tratamiento mal calculado, la corrección no es "ajustar
código" — es Nota de Crédito sobre un documento fiscal ya enviado a
SUNAT. Y el caso de mezcla (paquete que combina algo local exonerado con
algo nacional/internacional gravado) es precisamente el más probable en
los primeros usos reales de este flujo, dado que la agencia vende mucho
paquete mixto (ver `plan-modulo-cotizaciones-reservas.md` §2.4).

**No se pide construir el motor completo de facturación multi-tratamiento
en esta sesión** — solo un guardia que impida emitir algo incorrecto en
silencio.

---

## 2. Qué agregar

### 2.1 En `GET reservas/{id}/preparar-factura`

Después de armar los grupos por categoría (respuesta del brief original),
agrega una validación adicional **a nivel de toda la reserva** (no solo
dentro de cada categoría, que ya cubre §6.3):

- Reúne el `destino_tributario` de **todos** los `reserva_items` que se
  van a facturar (sin importar su categoría/línea).
- Si aparece **más de un** `destino_tributario` distinto entre ellos
  (ej. `amazonia` y `nacional` mezclados), marca la respuesta con:
  ```json
  {
    "bloqueado_tributario": true,
    "motivo": "La reserva combina servicios con tratamiento tributario distinto (ej. exonerado Amazonía + gravado nacional). No se puede facturar en un solo comprobante todavía — requiere revisión manual con el contador antes de emitir.",
    "destinos_tributarios_detectados": ["amazonia", "nacional"]
  }
  ```
  y **no** incluyas `grupos_propuestos`/`total` en ese caso (o inclúyelos
  mnemónicamente para referencia, pero deja clarísimo en el frontend que
  no se puede continuar a `POST facturar` desde este estado).

### 2.2 En `POST reservas/{id}/facturar`

Repite la misma validación **server-side**, independiente de lo que haya
mostrado el frontend — si los `reserva_item_ids` que llegan en el body
mezclan `destino_tributario`, responde 422 con el mismo mensaje de 2.1.
No confíes en que el frontend ya lo bloqueó.

### 2.3 Frontend

En el modal/pantalla de "Facturar" (`reservas/detalle.vue`), si
`preparar-factura` devuelve `bloqueado_tributario: true`, muestra el
mensaje en vez del formulario de confirmación, y no ofrezcas el botón de
confirmar. No hace falta ninguna UI de resolución todavía — el vendedor
resuelve esto por fuera del sistema por ahora (contactando al contador,
como ya indica el plan para decidir `tip_afe_igv`/`destino_tributario`
de cada proveedor).

---

## 3. Fuera de alcance — para una sesión futura, sin número asignado

- Emitir automáticamente **varios `Sale`** (uno por `destino_tributario`)
  cuando la reserva mezcla tratamientos, en vez de bloquear. Esto es la
  resolución real y completa del pendiente de §9, pero es trabajo
  bastante mayor (decidir cómo se reparten pasajeros/pagos entre esos
  Sales, cómo se refleja en `reserva_ventas` — empieza a parecerse al
  caso de varios pagadores de §4.4, aunque la causa acá es tributaria, no
  de responsable de pago). No lo construyas ahora.
- Cualquier lógica de sugerencia automática de qué `Sale` debería llevar
  qué ítems en el caso mixto — el guardia de esta sesión solo bloquea y
  explica, no propone una solución.

---

## 4. Verificación esperada

- Caso homogéneo (todos los `reserva_items` con el mismo
  `destino_tributario`, que va a ser la mayoría de los casos reales):
  factura normal, sin cambios de comportamiento respecto al brief
  original.
- Caso mixto real o simulado (ej. reserva con un ítem `destino_tributario
  = amazonia` y otro `= nacional`): `preparar-factura` devuelve
  `bloqueado_tributario: true` con el mensaje; `POST facturar` con esos
  mismos `reserva_item_ids` devuelve 422 aunque se intente forzar vía API
  directa, no solo desde la pantalla.
- Test nuevo específico para el caso mixto, además de los ya pedidos en
  el brief original.