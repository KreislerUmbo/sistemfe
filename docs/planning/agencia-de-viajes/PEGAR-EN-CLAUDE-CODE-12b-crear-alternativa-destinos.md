# Brief para Claude Code — Sesión 12b: crear `alternativa_destinos`

> Referencia de contexto: `plan-ejecucion-multidestino-mayoristas.md` §1/§2
> (fila 12b), `auditoria-arquitectonica-agencia-viajes.md` §7 (diseño de la
> tabla). Depende de 12a en el orden del roadmap — sin dependencia de
> código real (tabla aditiva nueva, no toca el guard ni el índice de
> 12a). Rama apilada sobre `feature/sesion-12a-fase0-gaps-mayoristas`
> (commit `3d78656`, todavía sin mergear a `main`).
>
> Sesión aditiva: crea una tabla nueva + backfill. **No toca**
> `AlternativaItem`/`OpcionMayorista` todavía (eso es 12c/12d) ni la UI del
> cotizador (12f). `Alternativa.moneda_cotizacion`/`tipo_cambio_aplicado`
> no se tocan — la moneda se queda a nivel de `Alternativa` (§13 de la
> auditoría).

---

## 0. Gap real encontrado al redactar este brief (resuelto con el usuario, 01-sep-2026)

La auditoría (§7) dice que el backfill usa "destino = `Cotizacion.destino`
actual", pero `cotizaciones.destino` es texto libre (`string`, sin FK) y el
diseño de la tabla nueva usa `destino_atractivo_id` (FK real a
`destinos_atractivos`). No es un mapeo directo.

Verificado contra `agencia-demo` (único tenant con datos reales): 4 valores
distintos de `cotizaciones.destino` (`Sauce`, `Alto Mayo`, `Alto mayo`,
`Cusco`). 3 matchean exacto contra `destinos_atractivos.nombre` pero uno
**ya tiene una variante de mayúsculas real en producción**
(`Alto mayo` vs. `Alto Mayo`) — un match case-sensitive lo perdería.

**Decisión del usuario:** `destino_atractivo_id` **nullable** + columna
nueva `destino_texto` de respaldo (siempre el texto original de
`Cotizacion.destino`, nunca se pierde). Mismo patrón ya usado en
`OpcionHotel` (`nombre_hotel` libre + `proveedor_id` nullable) para casos
ad-hoc sin catálogo.

---

## 1. Migración 1 — crear la tabla

`database/migrations/tenant/verticals/agencia-viajes/`, archivo
`2026_09_01_100000_create_alternativa_destinos_table.php`.

```
alternativa_destinos
  - id
  - alternativa_id        FK → alternativas, constrained() (mismo estilo sin onDelete que alternativa_items)
  - destino_atractivo_id  FK → destinos_atractivos, nullable, nullOnDelete()
  - destino_texto         string, nullable — texto original/override cuando no hay match en el catálogo
  - orden                 integer, default 1
  - fecha_inicio          date, nullable (cotizaciones.fecha_viaje_desde también es nullable)
  - fecha_fin             date, nullable
  - timestamps
```

Sin índice único en `orden` todavía (la auditoría §23.2 marca esto como
"si se decide proteger" — no forma parte de este brief; puede agregarse
en una sesión posterior si aparece un caso real de duplicado).

## 2. Modelo `AlternativaDestino`

`app/Models/AgenciaViajes/AlternativaDestino.php`, mismo estilo que
`Alternativa.php`/`AlternativaItem.php` (fillable + relaciones
`belongsTo(Alternativa)`/`belongsTo(DestinoAtractivo)`). Agregar
`AlternativaDestino::hasMany` desde `Alternativa` (método `destinos()`,
`orderBy('orden')` — mismo motivo que `Alternativa::items()` ya
documenta para `orderBy('id')`, no confiar en el orden físico de
Postgres).

## 3. Migración 2 — backfill

Archivo separado, `2026_09_01_100100_backfill_alternativa_destinos.php`
(mismo patrón de "1 create + 1 backfill" que usó 11r para
`reserva.fecha_viaje_desde/hasta`). Por cada `Alternativa` existente,
crear exactamente 1 fila:

- `destino_texto` = `Cotizacion.destino` (verbatim, de la cotización
  padre vía `alternativa.cotizacion_id`).
- `destino_atractivo_id` = match de `destinos_atractivos.nombre` contra
  `Cotizacion.destino`, **case-insensitive + trim** (mismo criterio ya
  usado en el fix de duplicados de `ServicioController`, no inventar uno
  nuevo — `LOWER(TRIM(nombre)) = LOWER(TRIM(?))`). Si no hay match, queda
  `null` — no es un error, es el caso esperado para destinos que no están
  en el catálogo todavía.
- `fecha_inicio`/`fecha_fin` = `Cotizacion.fecha_viaje_desde`/`fecha_viaje_hasta`
  (ambas pueden ser `null`, cotizaciones viejas sin fecha cargada).
- `orden` = 1.

## 4. Verificación esperada

- Cero filas de producción perdidas — contar `Alternativa::count()` antes
  vs. `AlternativaDestino::count()` después de la migración: deben ser
  iguales (backfill 1 destino por alternativa, sin excepciones).
- Correr el backfill contra `sistemafe_test_migrations` primero, revisar
  con una query cuántas filas quedaron con `destino_atractivo_id` null vs.
  resuelto, documentar el resultado en el commit (aunque sea "0 nulls" o
  "1 null: Alto mayo con capitalización distinta" — no ocultar el caso
  real ya detectado).
- Test: crear 2-3 `Alternativa`/`Cotizacion` de prueba con destino exacto,
  con variante de mayúsculas, y con destino que no existe en el catálogo
  — confirmar que el backfill resuelve los 2 primeros casos y deja `null`
  + `destino_texto` poblado en el tercero.
- `PriceEngineService::convertirMoneda()`/`evaluarPiso()` sin cambios de
  comportamiento (no se tocan en esta sesión) — no hace falta test nuevo,
  pero correr la suite completa para confirmar cero regresiones.
- Ningún reporte/PDF existente que lea `Cotizacion.destino`/
  `fecha_viaje_desde/hasta` se rompe (siguen existiendo sin cambios,
  `alternativa_destinos` es 100% aditiva) — no hace falta tocar
  `ReporteOperativoController` ni ningún PDF en esta sesión.
- Suite completa de backend en verde. Type-check de frontend sin
  regresiones sobre el baseline vigente (no se toca frontend en esta
  sesión — no debería haber ningún cambio en el conteo).
- Actualizar `plan-hoja-de-ruta-ejecucion.md`: marcar fila 12b como `[x]`
  con fecha, commit/rama, y el resultado real del backfill (cuántas filas,
  cuántos `destino_atractivo_id` quedaron null).
