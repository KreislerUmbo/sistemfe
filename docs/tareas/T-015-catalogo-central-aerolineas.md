# T-015 — Catálogo central de aerolíneas + lista en el panel central

| | |
|---|---|
| **Módulo** | PLA + AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 3 (respuestas 1-2, "Despliegue") |
| **Rama** | `feat/T-015-catalogo-aerolineas` |

## Contexto
La aerolínea es texto libre y los datos están inconsistentes ("Latam", "LATAM", "latam"). El
usuario decidió un catálogo **común** para todas las agencias, que crece cuando una agencia
agrega una que falta, y que el Super-Admin puede corregir, unir y desactivar. Esta tarea arma el
catálogo y su API; el buscador en pantallas es T-016.

## Alcance
- Migración **central** (`database/migrations/`): tabla `aerolineas` (`nombre`, `codigo_iata`
  nullable, `activa`, `creada_por_tenant` nullable, timestamps) con índice único sobre el nombre
  normalizado (`lower(trim(...))`, por ejemplo con columna `nombre_normalizado`). **La lista
  inicial se inserta en la misma migración** (no en un seeder: `deploy.sh` corre los seeders al
  final): LATAM, Sky Airline, JetSMART, Star Perú, ATSA, Avianca, Copa Airlines, American
  Airlines, Iberia, Aerolíneas Argentinas (ajustar con el usuario si quiere otras).
- Modelo `App\Models\AgenciaViajes\Aerolinea` (o `Central\`) con `CentralConnection` (mismo patrón
  que `ProveedorTipo`).
- API tenant (solo giro Agencia):
  - `GET aerolineas?buscar=` — activas, por nombre o código, máx. 20;
  - `POST aerolineas` — agrega; si el nombre normalizado ya existe devuelve la existente (200) en
    vez de duplicar; si choca por carrera, captura la violación de único y devuelve la existente.
    Aplica `TextoFormatoService` solo si no viene en mayúsculas intencionales (siglas como "LATAM"):
    decidir regla simple y anotarla.
  - Permiso: el de editar cotizaciones o reservas (ver los permisos existentes de Agencia).
- API central (panel superadmin, mismo grupo que `proveedor-tipos`): listar, editar, desactivar y
  **unir** (A se une en B: A queda inactiva con `unida_a_id`; la corrección del texto ya guardado
  en los tenants la hace el comando de T-016).
- Panel central (`central-panel/`): vista de aerolíneas, siguiendo `ProveedorTiposView.vue`.

## Fuera de alcance
- El componente buscador y reemplazar los campos de texto (T-016).
- Corregir datos ya guardados en los tenants (T-016).

## Archivos probables
- `api-sistema-fe/database/migrations/2026_10_xx_create_aerolineas_table.php`
- `api-sistema-fe/app/Models/.../Aerolinea.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AerolineaController.php`
- `api-sistema-fe/app/Http/Controllers/Central/AerolineaController.php`
- `api-sistema-fe/routes/api.php`
- `central-panel/src/views/AerolineasView.vue` (+ ruta y menú)

## Criterios de aceptación
- [ ] **CA1** — Tras migrar, la lista inicial existe en la base central. → test
- [ ] **CA2** — Buscar "lat" desde un tenant Agencia devuelve LATAM. → test
- [ ] **CA3** — `POST` "  latam " devuelve la LATAM existente, sin crear otra. → test
- [ ] **CA4** — Agregar "Viva Air" desde el tenant A → visible al buscar desde el tenant B. → test
- [ ] **CA5** — Desde un tenant retail, `GET`/`POST aerolineas` → 403/404. → test
- [ ] **CA6** — Panel central: editar, desactivar (deja de salir en la búsqueda) y unir. → test + pantalla
- [ ] **CA7** — Sin permiso de Agencia correspondiente → 403 en `POST`. → test

## Seguridad y correctitud
- [ ] Permisos: rutas tenant con permiso de Agencia; rutas centrales con el auth del panel central
- [ ] Aislamiento de tenant: **escritura central desde un tenant** a propósito; solo `aerolineas`,
  vía el modelo con `CentralConnection`; `creada_por_tenant` = id del tenant actual
- [ ] Validación de entrada: nombre 2-100 caracteres, IATA 2-3 alfanuméricos
- [ ] Concurrencia: índice único + captura de la violación
- [ ] Dinero: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: nueva **central** (`php artisan migrate`)

## Cómo verificar
```
php artisan migrate
php artisan test --filter=Aerolinea
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:** en la rama `feat/T-015-catalogo-aerolineas` (backend, panel central, docs).
- **Tests:** `php artisan test --filter=Aerolinea` → 18 pasan tras la revisión (antes 16; se sumaron
  tildes al buscar/agregar y al renombrar desde central) (CA1-CA7, carrera del único, validación,
  regla de mayúsculas, unir con re-apuntado, reactivar). `RutasAgenciaPermisosTest` en verde y ahora
  cubre `api/aerolineas*`. Suite completa: 2103 pasan, 1 falla (`VentaDirectaLineasTest` «ca1 dos
  lineas en dias distintos…»): pasa sola (15/15) y también con mis cambios apartados, o sea es
  intermitente dentro de la suite completa y no tiene relación con esta tarea. Panel central:
  `npm run build` (incluye `vue-tsc -b`) sin errores; el panel no tiene script `type-check` aparte.
  Pantalla real (Playwright, base dev): lista de 10, editar nombre/IATA, rechazo de nombre duplicado,
  unir (queda «inactiva · unida a …») y desactivar. Datos de prueba borrados al terminar.
- **Desviaciones de la tarea y por qué:**
  - Fecha de la migración `2026_11_09_100000` (la tarea decía `2026_10_xx`, ya vencido). Se aplicó en
    dev con `php artisan migrate --path=…`: el `migrate` a secas falla por otras migraciones (ver Hallazgos).
  - Códigos IATA iniciales agregados a la lista (LA, H2, JA, 2I, AV, CM, AA, IB, AR; ATSA sin código
    por no tener uno que pueda afirmar). Ajustar si el usuario quiere otros.
  - **CA5:** se usa `GiroActual` → 404 en otro giro, el mismo mecanismo de
    `TraspasoTrabajoController` (T-061); no se inventó un chequeo nuevo. Un permiso solo no corta a un
    retail (el Propietario los tiene todos).
  - **Permisos:** `GET` y `POST` con `cotizaciones.editar|reservas.editar` (no el conjunto de lectura
    de Agencia): el buscador se usa donde se escribe la aerolínea (cotizador y reserva), que ya exigen
    editar. Si T-016 lo necesita en una pantalla de solo lectura, se amplía el `GET`.
  - **Regla de mayúsculas del POST** (corregida en la revisión): solo si el nombre viene TODO en
    minúsculas se aplica `TextoFormatoService` («viva air» → «Viva Air»); con alguna mayúscula se
    respeta tal cual («KLM», «easyJet», «KLM Royal Dutch Airlines»: `TextoFormatoService` las pasaba a
    «Easyjet»/«Klm…» y en un catálogo común eso le llega a todas). Si ya existe, se devuelve el del
    catálogo. Está comentada en el controller.
  - **Normalización sin tildes** (agregada en la revisión): `Aerolinea::normalizar()` quita tildes con
    `Str::ascii`, así «Star Peru» no duplica «Star Perú» y buscar «peru»/«aerolineas» las encuentra.
    La migración tiene su propia copia de la función (no depende del modelo): mantenerlas iguales.
    En dev se recalculó `nombre_normalizado` de las filas ya migradas; producción aún no la corrió.
  - El POST responde 201 al crear y 200 con la existente; si la existente fue unida a otra devuelve la
    vigente a la que apunta. Si la existente está solo desactivada (sin unir) también se devuelve, para
    no duplicar: decide el Super-Admin si reactivarla.
  - `creada_por_tenant` sin FK (informativo); `unida_a_id` sí con FK a `aerolineas`.

## Hallazgos fuera de alcance
- `php artisan migrate` a secas falla en dev: hay 3 migraciones de `menu_items` pendientes en la tabla
  `migrations` de la base por defecto aunque la tabla ya existe en central (deriva de esquema). No se
  tocó; verificar que producción no tenga el mismo desfase antes del próximo deploy
  (`php artisan migrate:status`): `deploy.sh` corre con `set -e`, así que si `migrate --force` falla el
  deploy se corta en el paso 5 y `aerolineas` no se crea.
- `VentaDirectaLineasTest` «ca1 dos lineas en dias distintos…» es intermitente dentro de la suite
  completa (pasa sola).
- El panel central no tiene script `type-check` (solo `build` con `vue-tsc -b`); `proceso.md` §3 lo pide.
- Los tests que escriben en la conexión `central` dependen de que la base `sistemafe_test_migrations`
  tenga las tablas centrales; `AerolineaCatalogoTest` crea `aerolineas` si falta, como otros tests centrales.
