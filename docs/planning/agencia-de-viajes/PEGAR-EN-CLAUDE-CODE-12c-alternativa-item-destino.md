# Brief para Claude Code — Sesión 12c: `AlternativaItem` → `alternativa_destinos`

> Referencia: `plan-ejecucion-multidestino-mayoristas.md` §1/§2 (fila 12c),
> `auditoria-arquitectonica-agencia-viajes.md` §7/FASE 2 (§19). Depende de
> 12b (mergeada a `origin/main`, commit `0547123`).
>
> `12c` y `12d` pueden ejecutarse en cualquier orden entre sí una vez
> cerrada 12b, pero no en la misma sesión — regla de oro del proyecto.
> Esta sesión es 12c (12d, `OpcionMayorista.alternativa_destino_id`, queda
> para otra sesión aparte).

---

## 0. Gap real encontrado al redactar este brief (resuelto con el usuario, 01-sep-2026)

`AlternativaController::store()` (crear una alternativa nueva) y
`duplicar()` **nunca crean filas en `alternativa_destinos`** — esa tabla
solo tiene datos hoy porque el backfill de 12b la llenó para alternativas
*históricas*. Sin cerrar esto, cualquier alternativa creada desde ahora en
adelante queda con **0 filas** en `alternativa_destinos`, rompiendo la
garantía "cada alternativa tiene al menos 1 destino" que el propio
backfill de 12b estableció.

**Decisión del usuario — alcance de esta sesión:**
1. `alternativa_items.alternativa_destino_id` (nullable) + backfill de
   ítems **existentes** a su único destino (mismo criterio que 12b: cada
   alternativa hoy tiene exactamente 1 fila en `alternativa_destinos`).
2. `AlternativaController::store()` crea automáticamente 1
   `AlternativaDestino` al crear la alternativa (misma resolución
   texto→catálogo que usó el backfill de 12b, extraída a un método
   compartido — no duplicar la lógica de match).
3. `duplicar()` clona las filas de `alternativa_destinos` (mismo patrón
   que ya usa para `opcion_mayorista`), remapeando
   `alternativa_destino_id` de los ítems clonados al destino
   correspondiente de la copia (no al del original).
4. **NO se tocan** los 9 call-sites de creación individual de
   `AlternativaItem` (8 en `AlternativaItemController`, 1 flujo en
   `ComboExplosionService`) — `alternativa_destino_id` queda `null` en
   ítems nuevos hasta 12f, que es donde recién se empieza a leer/usar ese
   dato (subtotal por destino). Hacerlo ahora sería trabajo especulativo
   para una UI que no existe todavía.
5. **`dia_referencial` no se recalcula en esta sesión** — hoy toda
   alternativa tiene exactamente 1 destino, así que "relativo al inicio
   del destino" y "relativo al inicio de la alternativa" son la misma
   fecha. La recalculación (decisión ya cerrada: reiniciar por destino)
   solo se vuelve relevante cuando exista un segundo destino — eso es
   12f, no acá.

---

## 1. Método compartido de resolución de destino

Extraer de la lógica ya escrita en
`2026_09_01_100100_backfill_alternativa_destinos.php` (12b) — **no
reescribir esa migración ya aplicada**, es historia inmutable — un método
nuevo en el modelo `AlternativaDestino`:

```php
public static function resolverDestinoAtractivoId(?string $destinoTexto): ?int
{
    if ($destinoTexto === null || trim($destinoTexto) === '') {
        return null;
    }

    return DestinoAtractivo::whereRaw('LOWER(TRIM(nombre)) = LOWER(TRIM(?))', [$destinoTexto])
        ->orderBy('id')
        ->value('id');
}
```

Usado por `AlternativaController::store()` (punto 2 de §0) — la migración
de backfill de ítems de esta sesión NO necesita este método (solo
reasigna `alternativa_destino_id` según la fila ya existente en
`alternativa_destinos`, no vuelve a resolver texto→catálogo).

## 2. Migración 1 — columna en `alternativa_items`

`2026_09_01_110000_add_alternativa_destino_id_to_alternativa_items_table.php`:
`alternativa_items.alternativa_destino_id` — `foreignId nullable
constrained('alternativa_destinos') nullOnDelete()`, después de
`alternativa_id`.

## 3. Migración 2 — backfill de ítems existentes

`2026_09_01_110100_backfill_alternativa_destino_id_en_items.php`. Para
cada `Alternativa`, resolver su primer `AlternativaDestino` (`orderBy
orden, id`) y asignarlo a `alternativa_destino_id` de todos sus
`AlternativaItem` — 1 query de lectura agrupada + 1 `UPDATE` por
alternativa (mismo estilo PHP-loop que usó la migración de backfill de
12b, no `UPDATE...JOIN` específico de motor).

## 4. Modelo

- `AlternativaItem::alternativaDestino()` — `belongsTo(AlternativaDestino::class)`.
- `AlternativaDestino::items()` — `hasMany(AlternativaItem::class)->orderBy('id')` (mismo criterio que `Alternativa::items()`, no confiar en el orden físico de Postgres).

## 5. `AlternativaController::store()`

Después de crear `$alternativa`, crear 1 `AlternativaDestino`:
`destino_texto` = `$cotizacion->destino` (verbatim), `destino_atractivo_id`
= `AlternativaDestino::resolverDestinoAtractivoId($cotizacion->destino)`,
`orden` = 1, `fecha_inicio`/`fecha_fin` = `$cotizacion->fecha_viaje_desde`/`fecha_viaje_hasta`.
Dentro de la misma lógica que ya crea la alternativa (no hace falta
transacción nueva si `store()` no tiene una — confirmar el método actual
antes de decidir si envolver).

## 6. `AlternativaController::duplicar()`

Dentro de la transacción existente, después de crear `$nueva` y antes de
clonar ítems: clonar cada fila de `$original->destinos` a `$nueva`
(mismos campos, mismo `orden`), guardando el mapa `id original → id
nuevo`. Al clonar cada `AlternativaItem`, si el original tiene
`alternativa_destino_id` no nulo, usar el mapa para asignar el destino
correspondiente de la copia (nunca el `id` del original — cross-alternativa).

## 7. Verificación esperada

- Backfill de ítems corrido contra `agencia-demo`: contar
  `AlternativaItem::whereNull('alternativa_destino_id')->count()` antes
  (58, todos) y después (debe ser 0) — documentar en el commit.
- Test: crear una `Alternativa` nueva vía `store()` → confirma que queda
  exactamente 1 fila en `alternativa_destinos` con el destino resuelto
  desde `Cotizacion.destino` de esa cotización.
- Test: `duplicar()` sobre una alternativa con ítems e items con destino
  asignado → la copia tiene su propia fila en `alternativa_destinos`
  (no comparte el `id` del original) y los ítems clonados apuntan al
  `alternativa_destino_id` de la COPIA, no al original.
- Test de regresión: `store()`/`duplicar()` sin `alternativa_destinos`
  antes de esta sesión (comportamiento ya cubierto por tests existentes)
  sigue funcionando igual — no romper nada de lo que ya prueban
  `Sesion12aFase0GapsTest`/tests existentes de `AlternativaController`.
- Suite completa de backend en verde. Type-check de frontend sin
  regresiones (no se toca frontend en esta sesión).
- Actualizar `plan-hoja-de-ruta-ejecucion.md`: marcar fila 12c como `[x]`
  con fecha, commit, y el resultado real del backfill de ítems.
