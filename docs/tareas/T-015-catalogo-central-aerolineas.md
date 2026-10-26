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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
