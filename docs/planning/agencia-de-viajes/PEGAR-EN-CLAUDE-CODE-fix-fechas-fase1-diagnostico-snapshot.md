# Brief para Claude Code — Fix fechas Cotización↔Reserva, FASE 1 (diagnóstico + snapshot, SIN reprogramación)

> Pégale este archivo completo a una sesión nueva de Claude Code sobre el
> repo del sistema. Referencia de contexto general del módulo:
> `docs/planning/agencia-de-viajes/plan-modulo-cotizaciones-reservas.md`
> (secciones 3.2, 4, 4.2, 5.1, 5.3, 9).
>
> **Este brief es la Fase 1 de 2, a propósito separadas.** Fase 1 cierra
> el bug activo hoy (reserva sin fecha propia, dependiente en vivo de la
> cotización) con el mínimo riesgo posible. Fase 2 (endpoint de
> reprogramación, reenganche de `SalidaOperativa`, auditoría) es una
> sesión aparte, **después** de que esta Fase 1 esté mergeada y el
> diagnóstico de datos existentes (§1 abajo) haya sido revisado por el
> usuario — no la empieces en la misma sesión aunque parezca rápido.
> Motivo de la separación (decisión tomada 18-ago-2026, cruzando el
> análisis de dos sesiones de IA distintas — ver
> `historial-archivo.md`/`plan-hoja-de-ruta-ejecucion.md` fila 11r): el
> bug lleva tiempo activo en producción, así que puede haber reservas
> reales ya con datos divergentes — mezclar "diagnosticar y corregir
> datos posiblemente corruptos" con "construir una feature nueva
> (reprogramar)" en el mismo commit/sesión es más riesgo del necesario.

---

## 1. Antes de escribir cualquier migración: diagnóstico de datos existentes

**Esto va primero, no es opcional y no es solo un `dd()` rápido — es el
paso que decide si el backfill de la migración (§2) puede ser directo o
necesita casos especiales.**

### Por qué hace falta

Hoy `reserva_items.fecha` se calculó, en el momento de creación, como
`cotizacion.fecha_viaje_desde` (en ese instante) `+ dia_referencial - 1`.
Pero **la cotización sigue siendo editable sin ningún guard después de
aceptada** (confirmado, es el bug de fondo). Eso significa que, para
cualquier reserva ya existente, no podemos asumir que
`cotizacion.fecha_viaje_desde` (el valor de HOY) coincide con el valor
que existía cuando esa reserva se creó. Si migramos copiando el valor
actual de la cotización a ciegas, podemos estar "oficializando" en
`reserva.fecha_viaje_desde` una fecha que nunca fue la fecha real de esa
reserva — congelando el bug en vez de corregirlo.

### Qué construir

Un comando Artisan de solo lectura (ej.
`php artisan agencia-viajes:diagnosticar-fechas-reserva`, ajusta el
namespace al patrón que ya usa el proyecto para comandos de
diagnóstico/mantenimiento) que recorra **todas** las reservas existentes
y las clasifique. Antes de escribir la lógica, confirma en el código real
(no asumas) cómo `reserva_items` conoce el `dia_referencial` de su
`alternativa_item` de origen — revisa si `ReservaItem` guarda una FK
directa al `alternativa_item` (lo más probable, dado que el cálculo
inicial lo usa) o si hay que resolverlo por otro camino (ej. por
`origen_tipo`/`tour_origen_id` + orden). Documenta cuál es en el propio
comando con un comentario, para que quien lo lea después no tenga que
volver a investigarlo.

**Algoritmo de clasificación, por reserva:**

1. Para cada `reserva_item` de la reserva que tenga un `dia_referencial`
   resoluble (viene de un tour/alternativa_item con offset conocido) y
   una `fecha` ya poblada, calcular la **fecha base inferida**:
   `fecha_base_inferida = reserva_item.fecha - (dia_referencial - 1)`.
2. Si **todos** los `reserva_item` de esa reserva infieren la **misma**
   `fecha_base_inferida` entre sí:
   - Y esa fecha coincide con `cotizacion.fecha_viaje_desde` (el valor
     actual, en vivo) → **CONSISTENTE**. Seguro hacer backfill directo
     desde la cotización actual.
   - Y esa fecha **no** coincide con `cotizacion.fecha_viaje_desde`
     actual (la cotización se editó después de crear/sincronizar esta
     reserva, pero de forma uniforme — no hay doble calendario todavía)
     → **AMBIGUA**. El backfill debe usar la `fecha_base_inferida` de
     los ítems (la fecha real con la que se operó), **no** la cotización
     actual — pero igual queda marcada para que un humano la revise,
     porque significa que el bug ya se manifestó al menos una vez sobre
     esta reserva.
3. Si los `reserva_item` de esa reserva infieren **fechas base
   distintas entre sí** (algunos ítems calculados contra una base, otros
   contra otra — el escenario exacto del doble calendario que motivó
   todo este fix) → **DIVERGENTE**. No hacer backfill automático — cada
   caso necesita revisión manual antes de decidir qué fecha es la
   correcta operativamente.
4. Si la reserva no tiene ningún `reserva_item` con `dia_referencial`
   resoluble (todos son manuales/sueltos sin offset, o la reserva no
   tiene ítems todavía) → **SIN_FECHA**. Backfill cae al valor actual de
   `cotizacion.fecha_viaje_desde` por defecto (mejor esfuerzo, no hay
   otra fuente), marcada para revisión.
5. Adicional, sin importar la categoría anterior: si la reserva está
   `estado = activa` (no cancelada) y tiene al menos un `reserva_item`
   enganchado a una `SalidaOperativa` cuya fecha **no coincide** con la
   `fecha_base_inferida`/fecha de cotización que le tocaría → marcar
   además como **REQUIERE_REVISION_OPERATIVA** (impacto real: hay una
   salida operativa ya armada que podría estar agrupando pasajeros bajo
   la fecha equivocada). Esta es la categoría de mayor prioridad para
   revisar antes de cualquier backfill.

**Salida esperada del comando:** una tabla/reporte (a consola +
opcionalmente CSV/export, según qué sea más fácil de revisar para el
usuario) con columnas: `reserva_id`, `codigo` (si existe), `categoria`,
`fecha_cotizacion_actual`, `fecha_base_inferida` (si aplica),
`cantidad_items`, `requiere_revision_operativa` (bool). Al final, un
resumen con el conteo total por categoría.

**No modifica nada** — es de solo lectura. El resultado de este comando
es lo que decide, junto con el usuario, cómo se ajusta el backfill de la
migración de §2 antes de correrlo contra un tenant con datos reales.
Corre este comando contra cada tenant con datos reales que tenga el
vertical activo (no solo contra el de pruebas) y comparte el resumen con
el usuario antes de continuar — no avances a §2-§5 sin ese visto bueno.

---

## 2. Migración

```php
Schema::table('reservas', function (Blueprint $table) {
    $table->date('fecha_viaje_desde')->nullable();
    $table->date('fecha_viaje_hasta')->nullable();
});

Schema::table('reserva_items', function (Blueprint $table) {
    $table->string('fecha_origen')->default('auto'); // 'auto' | 'manual'
});
```

**No agregues todavía** las columnas de reprogramación
(`fecha_viaje_desde_original`, `fecha_viaje_hasta_original`,
`fecha_reprogramacion`, `motivo_reprogramacion`) — son de Fase 2, agregar
columnas que ninguna lógica de esta fase usa solo genera ruido en el
schema.

**Backfill**, basado en el resultado de §1 (esto reemplaza un backfill
"directo desde cotización" ingenuo):
- `CONSISTENTE` → `reserva.fecha_viaje_desde/hasta` = valor actual de
  `cotizacion.fecha_viaje_desde/hasta`.
- `AMBIGUA` → `reserva.fecha_viaje_desde/hasta` = la `fecha_base_inferida`
  calculada en el diagnóstico (la fecha real con la que se operó), no la
  cotización actual.
- `DIVERGENTE`/`SIN_FECHA` → backfill de mejor esfuerzo (usa la
  cotización actual como fallback) pero dejar alguna marca visible para
  el usuario (ej. log al final de la migración con los IDs afectados, o
  un flag temporal) de que estas reservas necesitan revisión manual
  post-migración — no bloquees la migración completa por estos casos,
  pero no los escondas tampoco.
- Todo `reserva_item` ya existente → `fecha_origen = 'auto'` (no hay
  forma de saber retroactivamente cuáles se editaron a mano; ver
  trade-off ya documentado en la fila 11r de la hoja de ruta).

---

## 3. Cambios de código

### 3.1 `ReservaController::crearReservaDesdeAlternativa()` (línea ~208)

- Al hacer `Reserva::create()`, copiar
  `fecha_viaje_desde`/`fecha_viaje_hasta` desde `$alternativa->cotizacion`
  **una sola vez**, en ese momento.
- El cálculo de cada `reserva_item.fecha` pasa a usar
  `$reserva->fecha_viaje_desde` (la reserva recién creada), no
  `$alternativa->cotizacion->fecha_viaje_desde`.
- Cada `reserva_item` creado acá queda `fecha_origen = 'auto'`.

### 3.2 `ReservaController::sincronizarItems()` (línea ~364)

- Cambia su fuente de `$reserva->alternativa->cotizacion->fecha_viaje_desde`
  a `$reserva->fecha_viaje_desde`.
- **Confirma explícitamente en el código (y dilo en el commit) que este
  método sigue significando "incorporar a la reserva los ítems nuevos de
  la alternativa"** — nunca "recalcular toda la reserva". Los
  `reserva_item` que ya existían no se tocan. Solo los ítems nuevos que
  se sincronizan quedan `fecha_origen = 'auto'`, calculados contra
  `reserva.fecha_viaje_desde`.

### 3.3 `ReservaController::respuestaDetalle()` (líneas ~398, 416-417)

- El bloque cabecera pasa a leer `$reserva->fecha_viaje_desde/hasta` en
  vez de `$cotizacion->fecha_viaje_desde/hasta`.

### 3.4 Regla explícita sobre qué campo usar — IMPORTANTE, no es solo cosmético

`RELACIONES_DETALLE` sigue cargando `alternativa.cotizacion` (se necesita
para cliente/destino/precios/trazabilidad — **no se quita esa relación**).
Eso significa que, aunque la cabecera de `respuestaDetalle()` ya lea
`reserva.fecha_viaje_desde`, el JSON completo de `show()` **puede seguir
exponiendo** `reserva.alternativa.cotizacion.fecha_viaje_desde` en
paralelo, porque Eloquent serializa la relación cargada completa. Si
alguien (frontend nuevo, integración futura, otra sesión de Claude Code
apurada) lee ese campo en vez del de la cabecera, el bug reaparece por la
puerta de atrás aunque el backend esté "arreglado".

Por eso:
- Deja un comentario explícito en el modelo `Reserva` (docblock sobre
  `fecha_viaje_desde`/`hasta`) y en `ReservaController` marcando la
  regla: **la fecha de una reserva se lee siempre de
  `reserva.fecha_viaje_desde/hasta` — nunca de
  `reserva.alternativa.cotizacion.fecha_viaje_desde/hasta`**, que solo
  refleja la propuesta comercial, no el compromiso operativo.
- Si el repo tiene un `AGENTS.md`/`CLAUDE.md` con convenciones para
  sesiones futuras, agrega esta regla ahí también — es exactamente el
  tipo de trampa silenciosa que ese documento existe para prevenir.
- Evalúa (sin que sea bloqueante para esta sesión si el patrón no encaja
  fácil) si tiene sentido ocultar `cotizacion.fecha_viaje_desde/hasta`
  del JSON serializado cuando se accede a través de
  `reserva.alternativa.cotizacion` específicamente — ej. un
  `$hidden`/accessor condicional, o simplemente confiar en la
  documentación si el proyecto no usa API Resources en este vertical
  (confirmado que no las usa, todo es serialización cruda de Eloquent).
  Si no hay una forma limpia de ocultarlo sin romper otros consumidores
  que sí necesitan la fecha de la cotización por ese camino (ej. el
  propio cotizador), **prioriza la documentación explícita sobre un
  hack de serialización** — no vale la pena una solución frágil para
  este caso.

### 3.5 `ReservaItemController::update()`

- Cuando el request trae `fecha` explícita (edición manual de un ítem),
  setear `fecha_origen = 'manual'` en el mismo `update()`.

### 3.6 `VentaDirectaController::store()`

- Sin cambios propios de código — al reusar
  `crearReservaDesdeAlternativa()`, hereda el fix automáticamente.
  Confírmalo con un test, no lo des por sentado.

### 3.7 Frontend

- `types/agencia-viajes.ts`: el tipo del bloque cabecera gana
  `fecha_viaje_desde`/`fecha_viaje_hasta` como campos propios de la
  reserva — documenta en el tipo que ya no son un espejo de `Cotizacion`.
- `reservas/detalle.vue`: sin cambios funcionales (sigue leyendo
  `cabecera.fecha_viaje_desde/hasta`) — el fix real ya ocurrió en el
  backend.
- **No agregues todavía** ningún botón/UI de "reprogramar" — es Fase 2.

---

## 4. Fuera de alcance de ESTA fase (no lo toques acá)

- Endpoint `POST reservas/{id}/reprogramar` — Fase 2.
- Columnas de auditoría de reprogramación
  (`fecha_viaje_desde_original`/`fecha_reprogramacion`/
  `motivo_reprogramacion`) — Fase 2.
- Bloqueo de `reasignarDia()`/`moverBloque()` sobre alternativa
  `aceptada` — Fase 2 (tiene sentido junto con reprogramar, no antes).
- Reenganche de `SalidaOperativa` por cambio de fecha — Fase 2 (en esta
  fase la fecha de una reserva no cambia después de creada, así que no
  hay reenganche que resolver todavía).
- `AlternativaController::pdf()` — no se toca, sigue leyendo
  `cotizacion.fecha_viaje_desde/hasta`, es correcto así.

## 5. Verificación esperada

- El comando de diagnóstico (§1) corrió contra todos los tenants con
  datos reales y el usuario vio/aprobó el resumen antes de migrar.
- Migración + backfill corren limpios, con los tres casos (consistente/
  ambigua/divergente-o-sin-fecha) verificados contra los datos reales
  encontrados en el diagnóstico — no solo contra un tenant de prueba
  vacío.
- Caso real que originó el fix: crear cotización → aceptar alternativa →
  reserva creada con fecha X. Editar `cotizacion.fecha_viaje_desde` a Y
  directamente → confirmar que la reserva **sigue mostrando X** en
  `reservas/detalle.vue` y en la respuesta de `show()` (tanto en la
  cabecera como, documentado, en que `reserva.alternativa.cotizacion.
  fecha_viaje_desde` sí puede mostrar Y — eso es esperado y aceptado,
  ver §3.4).
- `sincronizarItems()`: agregar un ítem nuevo después de que la
  cotización cambió de fecha, confirmar que el ítem nuevo se calcula
  contra `reserva.fecha_viaje_desde` (X), no contra la cotización (Y) —
  ítems viejos y nuevos coinciden en la misma base.
- `ReservaItemController::update()` con `fecha` explícita marca
  `fecha_origen = 'manual'`; el resto de flujos automáticos dejan
  `fecha_origen = 'auto'`.
- Suite de tests completa en verde, incluido un test de regresión nuevo
  para el caso de arriba (editar cotización después de aceptada no debe
  mover `reserva.fecha_viaje_desde` ni el resultado de un
  `sincronizarItems()` posterior).

---

## Estado real al cierre — 18-ago-2026

**Fase 1 completa y verificada.** Diagnóstico corrido contra el único
tenant real con el vertical (`agencia-demo`): 5 reservas — 2
CONSISTENTE, 1 AMBIGUA, 1 DIVERGENTE, 1 SIN_FECHA, confirmadas por el
usuario como datos de prueba descartables. Migración + backfill
aplicados primero a `sistemafe_test_migrations`, luego al tenant real vía
`tenants:migrate-verticales` — el resultado coincidió exactamente con lo
predicho por el diagnóstico. Todos los puntos de §3 implementados tal
cual. 135 tests de backend en verde (131 preexistentes + 4
nuevos/extendidos), type-check de frontend sin regresiones (45 errores
preexistentes, mismo baseline). Verificación E2E real contra
`agencia-demo` confirmó el comportamiento exacto de §3.4/§5. Detalle
completo en `plan-hoja-de-ruta-ejecucion.md`, fila 11r e historial
18-ago-2026 (c).

**Hallazgo colateral, fuera de alcance, no arreglado:**
`VentaDirectaController::store()` pierde `costo_snapshot` al reenviar el
payload a `crearItemManual()` para `origen_tipo='manual'` — ver fila 11t
de `plan-hoja-de-ruta-ejecucion.md`.

**Siguiente paso:** Fase 2, ver
`PEGAR-EN-CLAUDE-CODE-fix-fechas-fase2-reprogramacion.md` (pendiente de
pegar en el repo — pídeselo al usuario si no está todavía).
