# Brief para Claude Code — Sesión 12e: `contenido_tour`

> Referencia: `plan-ejecucion-multidestino-mayoristas.md` §1/§2 (fila 12e),
> `auditoria-arquitectonica-agencia-viajes.md` §9.1 (diseño), §23.1.7-11
> (huecos), §20 ("el punto más frágil de todo lo diseñado — su valor
> depende enteramente de que alguien lo use bien"). Depende de 12d
> (mergeada, no estructural — recomendado después para no tocar
> `OpcionMayorista` dos veces en la misma ventana de cambios).
>
> Primera sesión del bloque 12a-12h que toca frontend además de backend
> — decisión confirmada con el usuario 01-sep-2026.

---

## 0. Gap real encontrado al redactar este brief (resuelto con el usuario)

`OpcionMayoristaOpcional` (los "tours opcionales" tipo "Excursión San
Blas USD 170" — el caso que más directamente mapea a `contenido_tour`
categoria='opcional'/'excursion') **no tiene ninguna UI en el cotizador
hoy**. El servicio frontend (`opcionMayoristaService.crearOpcional()`/
`listarOpcionales()`) existe pero `cotizador/editar.vue` nunca lo llama —
es un endpoint sin pantalla, mismo síntoma que la auditoría ya identificó
3 veces en el proyecto (§20).

**Decisión de alcance:** `contenido_tour_id` + snapshot se agregan al
backend de **ambas** tablas (`opcion_mayorista` y
`opcion_mayorista_opcionales`), pero la UI de buscador solo se conecta
donde ya existe pantalla: el formulario de `OpcionMayorista`
(`formMayorista`, `cotizador/editar.vue` línea ~730). La UI base de
Opcionales (crear/listar tours opcionales) queda **fuera de esta sesión**
— es una feature nueva, no una extensión de 12e — para una sesión de
seguimiento con nombre propio.

## 1. Simplificación deliberada: sin filtro de destino en el buscador

`contenido_tour.destino_atractivo_id` existe en el schema (§9.1), pero el
buscador de esta sesión **no lo usa como filtro** — `editar.vue` no
expone hoy el destino activo de la alternativa de forma confiable (eso
es 12f). El buscador filtra por `categoria` + texto libre únicamente.
`destino_atractivo_id` queda **nullable** en el schema y se puede seguir
completando después (a mano, o cuando 12f exponga selección de destino
en la UI) — no bloquea nada mientras tanto.

## 2. Migración 1 — tabla `contenido_tour`

```
contenido_tour
  id
  destino_atractivo_id   nullable, FK → destinos_atractivos, nullOnDelete()
  categoria               string — 'incluido' | 'opcional' | 'excursion'
  nombre                  string
  descripcion             text, nullable
  incluye                 text, nullable
  no_incluye              text, nullable
  fotos                   json, nullable
  activo                  boolean, default true (reversible, sin toggle
                          endpoint en esta sesión — mismo campo que ya
                          usan proveedor_tarifas/guia_tarifas, se conecta
                          cuando haga falta)
  timestamps
```

## 3. Migraciones 2/3 — columnas en `opcion_mayorista`/`opcion_mayorista_opcionales`

Mismas 3 columnas en ambas tablas:
- `contenido_tour_id` — nullable, FK → `contenido_tour`, `nullOnDelete()`.
- `contenido_tour_descripcion_snapshot` — text, nullable.
- `contenido_tour_fotos_snapshot` — json, nullable.

**Snapshot, no referencia viva** (cierra §23.1.8): al vincular
(`contenido_tour_id` presente en el request de `store()`/`opcionales()`),
copiar `descripcion`/`fotos` de `ContenidoTour` a las columnas snapshot
de la fila creada. Editar `ContenidoTour` después no debe tocar ninguna
`OpcionMayorista`/`OpcionMayoristaOpcional` ya creada — mismo principio de
congelamiento que el resto del sistema (§2 de la auditoría).

## 4. Modelos

- `ContenidoTour` — fillable completo, `casts: fotos => array`,
  `belongsTo(DestinoAtractivo)`.
- `OpcionMayorista`/`OpcionMayoristaOpcional` — agregar las 3 columnas a
  `$fillable`, relación `contenidoTour(): belongsTo(ContenidoTour::class)`.

## 5. `ContenidoTourController`

Solo `index()` (búsqueda) y `store()` (crear) — es lo mínimo que necesita
el flujo "buscar antes de crear". `update()`/`destroy()` quedan fuera de
esta sesión a propósito (activo=true por defecto, sin pantalla de gestión
todavía).

- `index(Request)`: filtros `categoria` (opcional), `q`/`search` (nombre,
  `LIKE` case-insensitive), `activo=true` por defecto. Sin paginación
  compleja — la lista de resultados de un buscador siempre es corta.
- `store(Request)`: valida `nombre`/`categoria` requeridos. **Rechaza
  duplicado** — mismo nombre (case-insensitive + trim) en la misma
  `categoria`, mismo criterio que el fix de duplicados de
  `ServicioController` (§23.1.9, punto 9 — "sin mecanismo anti-duplicado
  en `contenido_tour`" cerrado acá).

Rutas (`routes/api.php`, junto a las de `opciones-mayorista`,
`middleware('permission:agencia.cotizaciones')`):
```
GET  contenido-tour   → ContenidoTourController::index
POST contenido-tour   → ContenidoTourController::store
```

## 6. `OpcionMayoristaController::store()`/`opcionales()`

Agregar `contenido_tour_id` como campo opcional al validator
(`nullable|integer|exists:contenido_tour,id`). Si viene presente, resolver
el `ContenidoTour` y copiar `descripcion`/`fotos` a las columnas snapshot
antes de crear la fila — mismo punto de escritura, sin caminos alternos
que puedan desincronizar snapshot vs. `contenido_tour_id`.

## 7. Frontend

- `contenidoTourService.ts` nuevo: `buscar({ categoria, q })`,
  `crear({ nombre, categoria, incluye, descripcion })`.
- `cotizador/editar.vue`, sección `formMayorista` (línea ~730): agregar
  un buscador (mismo patrón de debounce 300ms que `bibliotecaSearch`,
  línea ~1383) **antes** del textarea `incluye` existente:
  - Input de texto + lista de resultados (`categoria='incluido'` fijo,
    texto = lo tipeado).
  - Click en un resultado → `formMayorista.contenido_tour_id = id`,
    prefill de `incluye` **solo si el textarea está vacío** (no pisar
    texto que el vendedor ya escribió).
  - Si no hay resultados y el vendedor ya escribió algo en `incluye`:
    botón "Guardar como contenido reutilizable" → `POST contenido-tour`
    con `nombre` = lo buscado, `categoria='incluido'`, `incluye` = el
    texto del textarea → linkea el `id` devuelto.
  - Resetear el estado del buscador (búsqueda, resultados,
    `contenido_tour_id`) cuando se resetea `formMayorista` tras guardar
    (línea ~1737).

## 8. Verificación esperada

- Tests backend: `ContenidoTourController::store()` rechaza duplicado
  case-insensitive en la misma categoría; `index()` filtra por
  categoria/texto; `OpcionMayoristaController::store()`/`opcionales()`
  con `contenido_tour_id` guardan el snapshot correcto; editar el
  `ContenidoTour` después de vincular NO cambia el snapshot ya guardado
  (test explícito del principio de congelamiento).
- Suite completa de backend en verde.
- Frontend: `npm run dev`, abrir una cotización real en `agencia-demo`,
  agregar una opción de mayorista con el buscador — crear uno nuevo
  (caso "sin resultados"), buscarlo de nuevo y vincularlo (caso "con
  resultados"), confirmar visualmente que el `incluye` se completa y que
  no se pisa texto ya escrito. Verificar con Playwright real, no solo
  visualmente — mismo criterio que sesiones anteriores con cambios de UI.
  Type-check de frontend sin regresiones sobre el baseline vigente.
- Actualizar `plan-hoja-de-ruta-ejecucion.md`: marcar fila 12e como `[x]`
  con fecha, commit, y una nota explícita de que la UI de Opcionales
  queda pendiente como deuda conocida (no confundir con "12e incompleta"
  — es una decisión de alcance, documentada acá).
