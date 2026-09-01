# Brief para Claude Code — Sesión 12d: `OpcionMayorista.alternativa_destino_id`

> Referencia: `plan-ejecucion-multidestino-mayoristas.md` §1/§2 (fila 12d),
> `auditoria-arquitectonica-agencia-viajes.md` §7/§9/FASE 2 (§19), §20
> (riesgo de doble-escritura). Depende de 12b (mergeada). Independiente de
> 12c (ya cerrada, pero el orden entre ambas no importaba).
>
> Superficie mucho más chica que 12c: solo 2 puntos de escritura de
> `OpcionMayorista` en todo el código (`OpcionMayoristaController::store()`
> y `AlternativaController::duplicar()`), contra los 9 de `AlternativaItem`.

---

## 0. Diseño (mismo patrón que 12b/12c, sin gaps nuevos)

`opcion_mayorista.alternativa_id` **NO se toca ni se dropea** — sigue
existiendo "en modo lectura de compatibilidad" (§20 de la auditoría:
código viejo como `OpcionMayoristaController::index()` sigue funcionando
sin cambios). Se agrega `alternativa_destino_id` (nullable) como columna
nueva, y **ambos** puntos de escritura pasan a setear los dos campos de
forma consistente (nunca solo uno — es exactamente el riesgo de
desincronización que la auditoría marca en §20): `alternativa_destino_id`
se resuelve primero, `alternativa_id` sale de ahí mismo (`$alternativa->id`,
ya conocido en ambos call-sites), nunca se derivan por separado.

## 1. Migración 1 — columna

`2026_09_01_120000_add_alternativa_destino_id_to_opcion_mayorista_table.php`:
`opcion_mayorista.alternativa_destino_id` — `foreignId nullable
constrained('alternativa_destinos') nullOnDelete()`, después de
`alternativa_id`.

## 2. Migración 2 — backfill

`2026_09_01_120100_backfill_alternativa_destino_id_en_opcion_mayorista.php`.
Mismo patrón exacto que el backfill de ítems de 12c: agrupar
`alternativa_destinos` por `alternativa_id` (primer destino por
`orden`/`id`), y asignar ese id a todas las filas de `opcion_mayorista`
con ese `alternativa_id`.

## 3. Migración 3 — índice único parcial nuevo

`2026_09_01_120200_add_unique_index_alternativa_destino_opcion_mayorista.php`.
**Antes de crearlo**, correr la misma verificación de 12a pero sobre la
columna nueva: ¿existe algún `alternativa_destino_id` con más de una fila
`estado='elegida'`? (Debería dar 0 por construcción — el backfill mapea
1:1 desde `alternativa_id`, que 12a ya verificó sin duplicados — pero se
confirma igual, no se asume.) Si sale limpio:
`CREATE UNIQUE INDEX opcion_mayorista_alternativa_destino_elegida_unique
ON opcion_mayorista (alternativa_destino_id) WHERE estado = 'elegida'`.

**No se dropea el índice de 12a** (`opcion_mayorista_alternativa_elegida_unique`,
sobre `alternativa_id`) — coexisten hasta 12g (limpieza final), mismo
criterio que las columnas deprecadas de `alternativa_items`.

## 4. Modelo `OpcionMayorista`

- Agregar `alternativa_destino_id` a `$fillable`.
- `alternativaDestino()` — `belongsTo(AlternativaDestino::class)`.

## 5. `OpcionMayoristaController::store()`

Resolver `alternativa_destino_id` = `$alternativa->destinos()->orderBy('orden')->orderBy('id')->value('id')`
y agregarlo al array de `OpcionMayorista::create()` (junto a `alternativa_id`,
ya presente).

## 6. `AlternativaController::duplicar()`

En el loop que clona `OpcionMayorista` (ya usa `$destinosClonados`,
construido en la misma sesión 12c para remapear ítems): agregar
`'alternativa_destino_id' => $opcion->alternativa_destino_id !== null
? ($destinosClonados[$opcion->alternativa_destino_id] ?? null) : null`
al `OpcionMayorista::create()` del clon — mismo patrón que ya usa para los
ítems, nunca apuntar al destino del original.

## 7. Verificación esperada

- Backfill corrido contra `agencia-demo`: contar
  `OpcionMayorista::whereNull('alternativa_destino_id')->count()` antes/después
  (documentar el número real, aunque sea 0 filas totales — confirmar
  cuántas `OpcionMayorista` existen hoy antes de asumir el resultado).
- Query de verificación del índice (punto 3) corrida y documentada en el
  commit, igual que 12a.
- Test: `OpcionMayoristaController::store()` sobre una alternativa nueva
  → la opción creada tiene `alternativa_destino_id` = el destino único de
  esa alternativa.
- Test: `duplicar()` sobre una alternativa con una `OpcionMayorista`
  → la copia tiene su propia `OpcionMayorista` con `alternativa_destino_id`
  apuntando al destino de la COPIA, no al original.
- Test: índice único rechaza 2 `OpcionMayorista` con el mismo
  `alternativa_destino_id` marcadas `elegida` (mismo test que 12a hizo
  para `alternativa_id`, ahora sobre la columna nueva). Confirmar que
  `OpcionMayoristaController::elegir()` sigue funcionando (no se toca en
  esta sesión — no lee/escribe `alternativa_destino_id`, solo cambia
  `estado`, así que no necesita cambios).
- Suite completa de backend en verde. Type-check de frontend sin
  regresiones (no se toca frontend).
- Actualizar `plan-hoja-de-ruta-ejecucion.md`: marcar fila 12d como `[x]`
  con fecha, commit, y el resultado real de la verificación del índice y
  del backfill.
