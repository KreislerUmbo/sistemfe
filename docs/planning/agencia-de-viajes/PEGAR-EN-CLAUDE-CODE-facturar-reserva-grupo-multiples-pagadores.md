# Brief para Claude Code — Facturación múltiple por grupo de pasajeros (varios pagadores)

> Pégale este archivo completo a una sesión nueva de Claude Code sobre el
> repo del sistema, **después** de que la Sesión 11u
> (`PEGAR-EN-CLAUDE-CODE-facturar-reserva.md`) y su parche de guardia
> tributario (`PEGAR-EN-CLAUDE-CODE-facturar-reserva-guardia-tributario.md`)
> estén commiteados. Referencia de contexto general del módulo:
> `docs/planning/agencia-de-viajes/plan-modulo-cotizaciones-reservas.md`
> §4.4 (varios pagadores), §6.2/§6.3 (agrupación y restricción tributaria).

**Gap encontrado 20-ago-2026 en conversación con el usuario:** 11u
construyó la facturación de reserva asumiendo **un solo responsable de
pago por reserva** — un solo `Sale` cubre todos los `reserva_items`, y el
guard "anti-doble-facturación" bloquea la reserva **completa** apenas
tiene una `reserva_venta` activa. Eso no soporta un caso real y frecuente
en grupos: de 20 pasajeros de una misma reserva, algunos piden boleta a
su nombre, otros piden factura a su empresa, otros a una empresa
distinta — cada uno con su propio texto de sustentación de gastos. Hoy,
apenas se factura a la primera persona, el sistema bloquea facturar al
resto. Esta sesión relaja ese diseño sin rehacerlo desde cero — el plan
(§4.4) ya preveía este caso con `reserva_ventas` como tabla puente n:n,
solo que 11u no lo implementó así por acotar alcance.

---

## 0. Alcance de esta sesión

- Permite **N `reserva_ventas`/`Sale` por reserva**, cada uno cubriendo
  un subconjunto de `reserva_pasajero_ids` (y sus `reserva_item_ids`
  correspondientes) — no necesariamente la reserva completa.
- Cada `Sale` tiene su propio `client_id`, **no fijo** a
  `cotizacion.cliente_id` como asumió 11u — puede ser el pasajero mismo
  (boleta) o una empresa distinta (factura), elegido en el momento de
  facturar ese sub-grupo. El tipo de comprobante (boleta/factura) se
  sigue derivando como ya hace el core hoy según el tipo de documento del
  cliente elegido (DNI → boleta, RUC → factura) — no se toca esa lógica.
- Cada `Sale` puede llevar un **texto personalizado** que reemplaza el
  `descripcion_detalle` autogenerado de sus líneas — confirmado con el
  usuario: **un texto por Sale completo**, no por línea individual dentro
  del mismo Sale. Si no se especifica, se usa el mismo autogenerado por
  categoría que ya construyó 11u.
- **Precios por persona ya resueltos, no hay que repartir nada nuevo.**
  Confirmado con el usuario: en un paquete, cada pasajero ya sabe cuánto
  le toca pagar — el precio ya está resuelto a nivel individual (no es
  "divide el total entre N"). Antes de escribir código, **confirma en el
  código real** cómo se expresa hoy ese precio por pasajero (¿`pax_incluidos`
  + `modo_precio=por_persona` en `alternativa_items`/`reserva_items` ya
  alcanza para derivar cuánto le toca a cada pasajero de un ítem
  compartido, o hace falta leer/sumar algo más?) — **reutiliza ese
  cálculo, no inventes una regla de reparto nueva.** Si algún ítem
  `tarifa_fija` compartido (ej. habitación doble) no tiene forma hoy de
  saber cuánto le toca a cada uno de los 2 pasajeros que la comparten,
  documenta ese hueco explícitamente en vez de adivinar un reparto — no
  es parte del alcance confirmado por el usuario (que asumió que esto ya
  está resuelto).

---

## 1. Cambios sobre el guard de 11u

`ReservaFacturacionController` (y su `preparar-factura`) hoy bloquean
apenas la reserva tiene **cualquier** `reserva_venta` activa. Cambia el
criterio a granularidad de ítem/pasajero:

- Un `reserva_item` está "ya facturado" si aparece en el
  `reserva_item_ids` de alguna `reserva_venta` activa existente.
- Un intento de facturar que incluya un `reserva_item_id` ya facturado
  se bloquea con 422 explícito (mismo patrón que el guard de "reserva ya
  facturada" de 11u, pero ahora a nivel de ítem, no de reserva completa).
- `GET preparar-factura` deja de asumir "todos los ítems no facturados
  de la reserva van en un solo grupo" — ahora debe permitir que el
  vendedor **seleccione qué pasajeros/ítems** está facturando en esta
  pasada (ver §2), y la respuesta solo propone agrupación para esos
  ítems seleccionados, excluyendo los ya cubiertos por otra
  `reserva_venta`.
- El guardia de tratamiento tributario mixto (parche anterior) se
  reevalúa **sobre el subconjunto seleccionado**, no sobre toda la
  reserva — dos pasajeros distintos pueden perfectamente terminar en
  Sales con distinto `destino_tributario` cada uno, siempre que cada Sale
  individual sea homogéneo.

---

## 2. Selección de pasajeros/ítems a facturar

En el modal de "Facturar" (`reservas/detalle.vue`), antes de llamar a
`preparar-factura`, el vendedor selecciona **qué pasajeros** de la
reserva está facturando en esta pasada (checkboxes sobre la lista de
`reserva_pasajeros`, ocultando/deshabilitando los que ya estén cubiertos
por una `reserva_venta` existente). El sistema resuelve automáticamente
qué `reserva_item_ids` corresponden a esos pasajeros vía
`reserva_item_pasajero` — el vendedor no selecciona ítems sueltos
directamente, selecciona personas (evita que alguien facture media
habitación compartida por error).

```
GET reservas/{id}/preparar-factura?pasajero_ids[]=5&pasajero_ids[]=6
```

Devuelve la misma estructura de `grupos_propuestos` que ya construyó
11u, pero acotada a los ítems de esos pasajeros, más:
```json
{
  "pasajeros_incluidos": [5, 6],
  "pasajeros_pendientes": [7, 8, 9, ...],
  ...
}
```

---

## 3. `POST facturar` — nuevos campos

```json
{
  "pasajero_ids": [5, 6],
  "grupos": [ { "reserva_item_ids": [...], "tipo_servicio": "hoteleria" } ],
  "client_id": 123,
  "texto_personalizado": "Servicio de movilización y hospedaje — comisión de servicio",
  "forma_pago": { ... },
  "serie_id": ...
}
```

- `client_id`: **obligatorio explícito** — ya no se asume
  `cotizacion.cliente_id`. Validar que exista y pertenezca al tenant. El
  cliente puede ser el pasajero mismo (si tiene perfil de cliente propio,
  ver `pasajeros_catalogo.cliente_id` nullable, §6.5 del plan) o un
  tercero (la empresa) — no hay restricción de que el `client_id` tenga
  que estar relacionado con la reserva de antemano; el vendedor lo elige
  con el buscador de clientes que ya existe en Ventas.
- `texto_personalizado`: opcional. Si viene, se usa como
  `descripcion_detalle` de **todas** las líneas de este Sale (reemplaza
  el autogenerado por categoría). Si el guardia tributario obliga a
  partir en más de una línea dentro de este mismo Sale (mezcla dentro
  del subconjunto seleccionado), el mismo texto se repite en cada línea
  — no se pide al vendedor un texto por línea, solo por Sale (confirmado
  con el usuario).
- Todo lo demás igual que 11u: agrupación por categoría, restricción de
  §6.3, creación de `Sale`/`SaleDetail`/`sale_detail_items` reutilizando
  el mismo flujo ya confirmado en 11u.
- Nueva fila en `reserva_ventas`: `reserva_item_ids` = los facturados en
  este Sale, `reserva_pasajero_ids` = `pasajero_ids` del request — **no**
  todos los pasajeros de la reserva, como asumía 11u.

---

## 4. Frontend

- El botón "Facturar" ya no factura toda la reserva de una — abre el
  modal con la lista de pasajeros **pendientes de facturar** (con
  checkbox), agrupados visualmente si ya se sabe que van al mismo cliente
  (opcional, no bloqueante si no hay tiempo).
- Tras seleccionar pasajeros, muestra la propuesta de líneas (igual que
  11u), un buscador de cliente (reutiliza el componente que ya existe en
  Ventas), y un campo de texto libre opcional ("Texto personalizado para
  este comprobante").
- Tras facturar un sub-grupo, el modal permite **repetir el proceso**
  para los pasajeros restantes sin cerrar/reabrir — vuelve a
  `preparar-factura` con los pasajeros que quedan pendientes.
- La reserva queda "totalmente facturada" quand `pasajeros_pendientes`
  del último `preparar-factura` es `[]` — mostrar ese estado en
  `reservas/detalle.vue` (ej. badge "Facturación completa" vs. "Falta
  facturar a N pasajeros").

---

## 5. Fuera de alcance de esta sesión

- Reparto manual de un ítem `tarifa_fija` compartido entre pasajeros de
  distinto Sale, si el código no lo resuelve ya (documentar el hueco, no
  improvisar una fórmula — ver §0).
- Editar/anular un Sale ya emitido de este flujo (eso es §4.3 del plan,
  todavía sin sesión asignada).
- Aplicar `reserva_anticipos` automáticamente por pasajero — sigue
  informativo, igual que en 11u.

---

## 6. Verificación esperada

- Caso real de punta a punta: una reserva con al menos 3 pasajeros,
  facturar a 1 con boleta (cliente = el pasajero), a otro con factura a
  una empresa A, y al tercero con factura a una empresa B, cada uno con
  su propio `texto_personalizado` — confirmar 3 `Sale` distintos, 3 filas
  en `reserva_ventas`, cada una con su `reserva_pasajero_ids` correcto y
  sin ítems duplicados entre ellas.
- Intentar re-facturar un pasajero ya facturado → 422 explícito.
- El guardia tributario sigue aplicando por sub-grupo, no por reserva
  completa — un caso con mezcla dentro de un mismo Sale sigue bloqueando
  ese Sale puntual, sin afectar a los otros pasajeros ya facturados o
  pendientes.
- Suite de tests en verde, incluidos los casos de arriba.

---

## 7. Estado real al cierre (2026-08-20) — completado en el working tree

**Hallazgo crítico durante la investigación previa a programar (§0, tal
como pedía el brief — no se asumió):** el supuesto "el precio por
pasajero ya está resuelto" resultó parcialmente falso para el mecanismo
de selección propuesto en §2. Investigación real contra `agencia-demo`:
`reserva_item_pasajero` (la tabla que §2 proponía como fuente de verdad
para "qué ítems le tocan a los pasajeros seleccionados") tiene **0 filas
en el 100% de los ítems de la reserva de prueba** (8/8) y solo 26 de 37
ítems en todo el tenant tienen alguna vinculación — la pestaña
"Asignación pasajero↔ítem" casi no se usa en la práctica. Seguir el
diseño literal de §2 (derivar ítems solo desde esa tabla) habría dejado
la función inutilizable con datos reales.

**Decisión tomada con el usuario (`AskUserQuestion`) antes de programar:**
selección explícita de ítems sin asignar, en vez de exigir asignación
completa o asignar automáticamente al primer Sale. Diseño final:
- Ítem CON pasajeros vinculados: se auto-incluye SOLO si TODOS sus
  pasajeros vinculados están en la selección actual (nunca se fragmenta
  un ítem compartido entre dos Sales).
- Ítem SIN ningún pasajero vinculado (la mayoría de los datos reales
  hoy): se ofrece aparte (`items_sin_asignar_disponibles`) y el vendedor
  lo agrega a mano vía `reserva_item_ids_manual` si corresponde a este
  Sale.
- Reparto de un ítem `tarifa_fija` compartido entre pasajeros que
  terminan en Sales DISTINTOS: confirmado que no existe ningún mecanismo
  en el proyecto — documentado como hueco real, no resuelto (queda
  permanentemente pendiente de facturar por este flujo hasta resolución
  manual), tal como preveía el §0 de este brief.

**Bug real encontrado y corregido durante la verificación en vivo (no en
tests, en `agencia-demo` real):** el diseño inicial de `prepararFactura()`
devolvía 422 ("no hay ítems para facturar con esta selección") apenas se
abría el modal, para cualquier reserva sin ítems auto-vinculados — el
caso más común con datos reales. Corregido: el guard de "selección
vacía" es exclusivo de `store()` (ahí sí no tiene sentido crear un Sale
sin líneas); `prepararFactura()` devuelve 200 con 0 líneas y el pool de
ítems sin asignar, para que el vendedor pueda elegir de ahí. Test de
regresión agregado.

**Verificación real completa**: 13 tests nuevos en `ReservaFacturacionTest`
(174 tests backend en verde en total, cero regresiones) — incluye el caso
de punta a punta de §6 (3 pasajeros, 3 Sales, boleta+factura×2, textos
personalizados propios, sin ítems duplicados), ítem compartido facturado
junto vs. pendiente por pasajero faltante, ítems sin asignar,
doble-facturación de pasajero, guardia tributario por subgrupo (no por
reserva completa). Type-check frontend en 45 errores preexistentes
(mismo baseline, cero nuevos). **Verificado con Playwright real contra
`agencia-demo`** (reserva #19, `DKM-2026-001`, la misma que ya tenía
mezcla tributaria real de la sesión anterior): badge "Falta facturar a 2
pasajero(s)" correcto, modal muestra los 2 pasajeros + el pool completo
de 7 ítems sin asignar, total se recalcula en vivo al tildar un ítem
(PEN 0.00 → PEN 90.00), buscador de cliente real (`clients?search=`)
encontró y permitió elegir al cliente de la cotización, botón "Facturar
este grupo" se habilitó recién con cliente elegido — **no se llegó a
confirmar el POST**, para no dejar una venta real persistida en
`agencia-demo` sin que el usuario lo pidiera explícitamente; toda la
verificación fue de solo lectura (`GET preparar-factura`).
