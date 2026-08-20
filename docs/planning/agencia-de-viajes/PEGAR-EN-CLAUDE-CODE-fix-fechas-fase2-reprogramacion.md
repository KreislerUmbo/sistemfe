# Brief para Claude Code — Fix fechas Cotización↔Reserva, FASE 2 (reprogramación)

> Pégale este archivo a una sesión nueva de Claude Code **solo después**
> de que la Fase 1 (`PEGAR-EN-CLAUDE-CODE-fix-fechas-fase1-diagnostico-snapshot.md`)
> esté mergeada, y de que las reservas marcadas `DIVERGENTE`/
> `REQUIERE_REVISION_OPERATIVA` en el diagnóstico de esa fase hayan sido
> revisadas por el usuario. Esta fase asume que ya existen
> `reserva.fecha_viaje_desde/hasta` y `reserva_items.fecha_origen`
> (`auto`/`manual`), y que ambos ya funcionan como fuente real (no la
> cotización) — confírmalo leyendo el código antes de empezar, no lo
> asumas del texto de este brief.
>
> Referencia de contexto general del módulo:
> `docs/planning/agencia-de-viajes/plan-modulo-cotizaciones-reservas.md`
> (secciones 3.2, 4, 4.2, 5.1, 5.3, 9).

---

## 1. Qué construir

### 1.1 Migración — columnas de auditoría de reprogramación

```php
Schema::table('reservas', function (Blueprint $table) {
    $table->date('fecha_viaje_desde_original')->nullable();
    $table->date('fecha_viaje_hasta_original')->nullable();
    $table->timestamp('fecha_reprogramacion')->nullable();
    $table->string('motivo_reprogramacion')->nullable();
});
```

Sin backfill — estas columnas nacen vacías, se llenan solo cuando ocurre
la primera reprogramación real.

**Decisión de alcance ya tomada, no la reabras:** campos simples en
`reserva` (no una tabla de historial `reserva_reprogramaciones` aparte).
Trade-off aceptado: si una reserva se reprograma más de una vez, solo
queda visible la reprogramación más reciente — mismo patrón que
`fecha_cancelacion`/`motivo_cancelacion` ya usa en este proyecto. Si en
el futuro hace falta el historial completo, se migra a tabla aparte
entonces, no antes.

### 1.2 Endpoint nuevo — `POST reservas/{id}/reprogramar`

Nueva acción de negocio, no reutiliza el `PUT` de cabecera. En una sola
transacción:

1. Validar `fecha_viaje_desde`/`fecha_viaje_hasta` nuevas (con
   `after_or_equal` entre ambas cuando las dos vienen) + `motivo`
   (string, requerido — queda en `motivo_reprogramacion`).
2. Guardar `fecha_viaje_desde_original`/`fecha_viaje_hasta_original` =
   los valores actuales de `reserva` **antes** de sobreescribir (si ya
   hubo una reprogramación previa, este campo solo conserva la fecha tal
   como quedó tras la última reprogramación, no la fecha original de
   creación — trade-off ya aceptado, documéntalo en el docstring del
   método).
3. Actualizar `reserva.fecha_viaje_desde/hasta` (las columnas nuevas de
   Fase 1 — **nunca** las de `cotizacion`, que queda como el registro
   original salvo pedido explícito de propagar también ahí — no lo
   hagas automático).
4. Recalcular `reserva_items.fecha` **solo** para los ítems con
   `fecha_origen = 'auto'`, aplicando el mismo offset
   (`fechaBase->addDays(dia_referencial - 1)`) sobre la nueva base. Los
   `fecha_origen = 'manual'` quedan intactos.
5. Re-evaluar el enganche a `SalidaOperativa` de cada ítem recalculado
   que cambió de fecha: como esa tabla agrupa por
   (`tour_origen_id`, `fecha`), un ítem que cambia de fecha debe
   desengancharse de la salida vieja y engancharse (o crear) una nueva —
   mismo mecanismo que `engancharSalidaOperativa()` ya usa al crear.
   Cuidado con `SalidaMayorista.cupo_ocupado` si el ítem viene de un
   `opcion_mayorista`: restar del cupo de la salida vieja, sumar a la
   nueva, igual que ya se hace al cancelar (§4.2 del plan de módulo).
6. Set `fecha_reprogramacion = now()`.
7. Responder con: la reserva actualizada + la lista de `reserva_item`s
   con `fecha_origen = 'manual'` que **no** se tocaron (para que el
   vendedor los revise manualmente) — mismo principio "nunca automático
   en silencio" que ya rige `sincronizarItems()`.

No hace falta permiso especial nuevo si el proyecto no tiene un sistema
de roles granular para esto — revisa qué guard/policy usa el resto de
`ReservaController` y sigue el mismo criterio.

### 1.3 Bloqueo de `reasignarDia()`/`moverBloque()` sobre alternativa aceptada

En el controller donde viven estos dos métodos (sobre
`alternativa_items`, pre-aceptación), agregar guard explícito: si
`$alternativaItem->alternativa->estado === 'aceptada'`, responder `422`
con mensaje claro (ej. "Esta alternativa ya fue aceptada y generó una
reserva — usa reprogramar sobre la reserva en vez de mover ítems acá").
Cierra el hueco de que alguien mueva fechas en `alternativa_items`
después de que la reserva ya se generó a partir de un snapshot distinto.

### 1.4 Frontend

- Nueva UI en `reservas/detalle.vue`: botón "Reprogramar viaje" (modal:
  nueva fecha desde/hasta + motivo obligatorio) que llama al endpoint de
  §1.2. Tras confirmar, mostrar la lista de ítems `fecha_origen =
  'manual'` que quedaron sin tocar, si los hay — mismo patrón visual que
  ya usa el flujo de "Sincronizar" para avisar qué quedó pendiente de
  revisión.
- En la lista de `reserva_items` de `reservas/detalle.vue`, marcar
  visualmente (badge o ícono) los ítems con `fecha_origen = 'manual'`.

### 1.5 Tests

- `POST reservas/{id}/reprogramar`: fecha se actualiza, ítems `auto` se
  recalculan, ítems `manual` quedan intactos y aparecen en la respuesta
  como "no tocados", `SalidaOperativa` se re-engancha correctamente
  (incluye caso de cupo en `SalidaMayorista`).
- Bloqueo de `reasignarDia()`/`moverBloque()` sobre alternativa
  `aceptada` (422 explícito).
- Columnas `_original`/`fecha_reprogramacion`/`motivo_reprogramacion`
  quedan pobladas correctamente tras reprogramar.

## 2. Fuera de alcance

- Propagar la fecha reprogramada de vuelta a `cotizacion` — no.
- Tabla de historial completo de reprogramaciones — no (ver 1.1).
- Política de cancelación/reembolso (§4.2 del plan de módulo) — Fase 2
  aparte de ese módulo, sin relación directa con esto.
- Agregar un pasajero nuevo a una reserva ya aceptada — no relacionado.

## 3. Verificación esperada

- Reprogramar una reserva real (creada en Fase 1) a una fecha nueva →
  ítems `auto` se mueven, ítems `manual` (editar uno a mano antes de
  probar) no se mueven y aparecen listados en la respuesta.
- Re-enganche correcto a `SalidaOperativa` tras reprogramar (la salida
  vieja pierde el ítem, aparece en la nueva fecha — crear una si no
  existía).
- Intentar `reasignarDia()`/`moverBloque()` sobre una `alternativa_item`
  cuya alternativa ya está `aceptada` → 422 explícito.
- Suite de tests completa en verde.

---

## Estado real al cierre — 19-ago-2026

**Fase 2 completa y verificada.** Todo lo de §1 implementado tal cual:
migración (`reserva.fecha_viaje_desde_original/hasta_original/
fecha_reprogramacion/motivo_reprogramacion`), `ReservaController::
reprogramar()` (mueve la reserva, recalcula solo `fecha_origen='auto'`,
re-engancha `SalidaOperativa`, devuelve `items_no_tocados`), guard 422 en
`reasignarDia()`/`moverBloque()` sobre alternativa `aceptada`, botón
"Reprogramar viaje" + modal + badge "Fecha manual" en
`reservas/detalle.vue`.

**Hallazgo confirmado leyendo código, no asumido del brief**: el punto 5
de §1.2 ("cuidado con `SalidaMayorista.cupo_ocupado`") no aplica —
`cupo_ocupado` es un contador por RESERVA completa
(`reserva.mayorista_elegida_id`, fijado una única vez al aceptar/cancelar,
atado a una `salida_mayorista` de catálogo con fecha propia), nunca por
`reserva_item`. No existe ningún camino donde recalcular
`reserva_items.fecha` deba mover ese cupo — documentado en el código
(`ReservaController::reprogramar()`) y en `CLAUDE.md`, sin agregar
ninguna lógica de cupo a la reprogramación.

139/139 tests de backend en verde (131 previos a Fase 1 + 4 de Fase 1 + 8
nuevos de Fase 2: `ReservaReprogramarTest` —4 casos, incluye
recalculo/preservación de manuales/re-enganche real a `SalidaOperativa`/
reprogramar dos veces— y `AlternativaItemBloqueaMoverSiAceptadaTest` —4
casos—). Type-check de frontend sin regresiones (45 errores
preexistentes, mismo baseline que Fase 1, cero nuevos en los archivos
tocados). Verificado end-to-end contra datos reales de `agencia-demo`
(reserva #12, `kur-2026-001`): reprogramada de `2026-08-27` a
`2026-11-01`, los ítems `auto` se movieron a la base nueva, un ítem
marcado manual a propósito (vía `ReservaItemController::update()`)
quedó intacto y apareció en `items_no_tocados`, y el guard de
`reasignarDia()` devolvió 422 real contra la alternativa aceptada real
de esa reserva. Datos revertidos a su estado exacto de antes de la
verificación (mismos valores que dejó el diagnóstico de Fase 1) — sin
rastro persistente de la prueba.

Migración aplicada a `sistemafe_test_migrations` y al tenant real
`agencia-demo` (único tenant con el vertical activo) vía
`tenants:migrate-verticales`.

Detalle completo en `plan-hoja-de-ruta-ejecucion.md`, fila 11s.