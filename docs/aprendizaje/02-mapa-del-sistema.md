# Mapa del sistema: dónde está cada cosa y cómo rastrear un problema

El objetivo de esta guía no es entender cada línea, sino **saber dónde buscar**. Con eso puedes
ubicar un problema, explicarlo bien y comprobar si una corrección funciona.

---

## 1. Las dos partes del sistema

```
sistemfe/
├── api-sistema-fe/    → BACKEND (Laravel, PHP): reglas de negocio, base de datos, SUNAT
├── admin-start-kit/   → FRONTEND (Vue 3): las pantallas que usa el vendedor
└── docs/              → planes, decisiones y esta guía
```

El **frontend** no guarda nada: muestra pantallas y le **pide** cosas al backend por internet
(la "API"). El **backend** decide, calcula y guarda.

## 2. El recorrido de cualquier acción

Casi todo en el sistema sigue el mismo camino:

```
 PANTALLA (Vue)                admin-start-kit/src/views/...
     │  el usuario hace clic
     ▼
 SERVICIO DEL FRONTEND         admin-start-kit/src/services/admin/...
     │  envía la petición: POST /api/...
     ▼
 RUTA                          api-sistema-fe/routes/api.php
     │  "esta dirección la atiende tal controlador"
     ▼
 CONTROLADOR                   api-sistema-fe/app/Http/Controllers/...
     │  valida los datos y coordina
     ▼
 SERVICIO DEL BACKEND          api-sistema-fe/app/Services/...      (solo si la lógica es grande)
     │  hace las cuentas y reglas de negocio
     ▼
 MODELO                        api-sistema-fe/app/Models/...
     │  representa una tabla
     ▼
 TABLA                         base de datos PostgreSQL (una por agencia)
```

### Ejemplo real: cargar un tour en el cotizador

| Paso | Archivo | Qué pasa |
|---|---|---|
| Pantalla | `views/agencia-viajes/cotizador/editar.vue` → `clicResultadoPlantilla()` | el vendedor hace clic en el tour |
| Servicio frontend | `services/admin/alternativaItemService.ts` → `cargarDesdePlantilla()` | `POST /alternativas/{id}/items/desde-plantilla` |
| Ruta | `routes/api.php` | apunta a `AlternativaItemController::desdePlantilla` |
| Controlador | `Controllers/AgenciaViajes/AlternativaItemController.php` → `desdePlantilla()` | valida; pregunta si la agencia está en modo "nuevo" o "clásico" |
| Servicio backend | `Services/AgenciaViajes/TourLineaService.php` → `cargar()` → `specDelTour()` → `calcular()` | lee el tour, calcula precio y costo |
| Modelo | `Models/AgenciaViajes/AlternativaItem.php` → `create()` | guarda la línea |
| Tabla | `alternativa_items` | queda el registro |

Más recorridos, por fase:
- `03-recorridos-facturacion-y-conversion.md`: fila 13, fase 1c (facturar tours por grupo de
  pasajeros, IGV mezclado, convertir tours).
- `04-recorridos-salidas.md`: fila 13, fase 2a (la salida agrupa los tours: enganche
  compartido/privado, pasajeros y mínimo, estados, reprogramar).
- `05-recorridos-servicios-y-vehiculos.md`: fila 13, fase 2b (servicios a reservar, vehículos,
  guía por vehículo).

## 3. Carpetas del backend (`api-sistema-fe/`)

| Carpeta / archivo | Qué hay |
|---|---|
| `routes/api.php` | todas las direcciones de la API y qué controlador las atiende. También el **permiso** que exigen (`->middleware('permission:...')`) |
| `app/Http/Controllers/` | controladores, agrupados por módulo (`AgenciaViajes/`, `Sale/`, `Cash/`…) |
| `app/Services/` | la lógica de negocio grande (`AgenciaViajes/TourLineaService.php`, `PriceEngineService.php`…) |
| `app/Models/` | un modelo por tabla |
| `database/migrations/tenant/core/` | migraciones de las tablas que tienen **todas** las agencias (ventas, clientes, caja…) |
| `database/migrations/tenant/verticals/agencia-viajes/` | migraciones solo del giro agencia (cotizaciones, tours, reservas…) |
| `tests/Feature/` | los tests: describen cómo **debe** funcionar el sistema |
| `storage/logs/laravel.log` | el registro de errores del backend |

## 4. Carpetas del frontend (`admin-start-kit/src/`)

| Carpeta / archivo | Qué hay |
|---|---|
| `views/` | las pantallas (`agencia-viajes/cotizador/editar.vue`, `reservas/detalle.vue`…) |
| `components/` | piezas reutilizables dentro de las pantallas (`AgenciaViajes/PasajeAereoForm.vue`…) |
| `services/admin/` | las funciones que llaman a la API (una por módulo) |
| `types/` | la forma de los datos (`agencia-viajes.ts`: qué campos tiene un ítem, una cotización…) |
| `router/routes.ts` | qué pantalla se abre en cada dirección del navegador y qué permiso pide |
| `helpers/http-client.ts` | el "cartero" que manda las peticiones a la API |

## 5. Una base de datos por agencia (multi-tenant)

Cada agencia (`agencia-demo`, `dakamu`, `market`…) tiene **su propia base de datos**. Lo que hay
en una no se ve en otra. Existe además una base **central** con datos compartidos (la lista de
agencias, los catálogos de SUNAT, las temporadas).

Consecuencias prácticas:
- En tinker, antes de consultar datos hay que elegir la agencia:
  `tenancy()->initialize(App\Models\Tenant::find('agencia-demo'));`
- Una migración nueva se aplica a todas las agencias con
  `php artisan tenants:migrate-verticales` (**no** con `tenants:migrate` a secas: ese no aplica
  las de `verticals/`).

## 6. "Si falla esto, mira aquí"

| Síntoma | Dónde mirar primero |
|---|---|
| La pantalla muestra un error rojo o un mensaje raro | el mensaje: casi siempre viene del backend. Búscalo con la lupa de VS Code (`Ctrl+Shift+F`) para encontrar dónde se escribe |
| Un botón "no hace nada" | navegador → `F12` → pestaña **Red** (*Network*): ¿salió la petición? ¿qué respondió? |
| La petición responde **500** | `storage/logs/laravel.log`, al final del archivo |
| La petición responde **403** | permisos: el `->middleware('permission:...')` de la ruta en `routes/api.php`, o el rol del usuario |
| La petición responde **422** | una validación del controlador: el mensaje dice cuál |
| Un precio o un total sale mal | el servicio que calcula (para tours: `TourLineaService::calcular()`); pruébalo en tinker con los mismos números |
| Un dato quedó mal guardado | consulta la tabla en tinker con el modelo; revisa la migración de esa tabla |
| "Antes funcionaba" | `git log -p nombre-del-archivo`: qué cambió y cuándo |
| No sabes qué controlador atiende una dirección | `php artisan route:list --path=parte-de-la-direccion` |

## 7. Herramientas para investigar sin romper nada

| Herramienta | Para qué | Ejemplo |
|---|---|---|
| **tinker** | probar funciones y leer datos | `php artisan tinker` |
| **route:list** | qué controlador atiende una dirección | `php artisan route:list --path=modo-tours` |
| **tests** | comprobar que una regla se cumple | `php artisan test --filter=test_e2` |
| **laravel.log** | ver el último error del backend | abrir `storage/logs/laravel.log` y bajar al final |
| **F12 → Red** | ver qué manda y recibe la pantalla | clic en la petición → pestañas *Payload* y *Response* |
| **git log / git diff** | historial de cambios | `git log --oneline -5`, `git diff archivo` |
| **Búsqueda de VS Code** | encontrar dónde se usa algo | `Ctrl+Shift+F` y escribir el nombre de la función |

Todas estas herramientas **solo leen**. Las que cambian datos (`create`, `update`, `delete` en
tinker, o editar archivos) úsalas solo cuando sepas qué estás haciendo, y nunca contra producción
para practicar.

---

## Ejercicios

Las soluciones están al final, ocultas. Intenta primero solo.

### Nivel 1 (básico): encontrar el controlador

La pantalla de Configuración de Agencia guarda el "modo de tours" con la dirección
`PUT /configuracion-agencia/modo-tours`.

1. ¿Qué controlador y qué método la atienden? Encuéntralo de **dos formas**: buscando en
   `routes/api.php` y con `php artisan route:list --path=modo-tours`.
2. ¿Qué permiso exige la ruta?
3. ¿Qué función del frontend hace esa petición? (pista: busca `modo-tours` en
   `admin-start-kit/src/services/admin/`)

### Nivel 2 (intermedio): rastrear un recorrido completo

En el cotizador, el botón **"Pasaje aéreo suelto"** abre un formulario. Al guardarlo, ¿por dónde
pasa la información hasta la base de datos? Completa la tabla del recorrido (pantalla, servicio
frontend, dirección, ruta, controlador y método, modelo y tabla).

Pistas:
- el formulario es el componente `components/AgenciaViajes/PasajeAereoForm.vue`;
- busca ahí la llamada a `alternativaItemService.…`;
- la dirección termina en `/items`, y el controlador decide qué hacer según un campo
  `origen_tipo`. Busca el `match` en el método `store()`.

### Nivel 3 (avanzado): investigar con datos y con historia

1. En tinker, con la agencia `agencia-demo`: ¿cuántos tours tienen `uso = 'solo_itinerario'`?
   ¿Cuál es el precio de venta del tour con id 12? (usa el modelo `PaquetePlantilla`)
2. Con git, encuentra **en qué commit** se agregó la corrección E2 (el costo de una línea por
   persona = total sin infantes). Pista: `git log -S "texto"` busca el commit que agregó un texto.
   Busca en `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php`
   el texto `E2 (fila 13` (es el comentario que explica la corrección).
3. Corre el test que vigila esa regla: `php artisan test --filter=test_e2`. Después abre el test
   (`tests/Feature/AgenciaViajes/Fila13Fase1bTourCotizacionTest.php`, busca `test_e2`) y explica
   con tus palabras qué comprueba.

---

## Soluciones

<details>
<summary>Nivel 1</summary>

1. `AgenciaViajes\ConfiguracionAgenciaController`, método **`actualizarModoTours`**.
   `route:list` muestra:
   `PUT api/configuracion-agencia/modo-tours .... AgenciaViajes\ConfiguracionAgenciaController@actualizarModoTours`
2. `permission:agencia.configuracion`. Además, adentro del método se exige el rol
   **Super-Admin** (si no, responde 403): son dos barreras.
3. `configuracionAgenciaService.actualizarModoTours()` en
   `admin-start-kit/src/services/admin/configuracionAgenciaService.ts`. La usa la pantalla
   `views/agencia-viajes/configuracion/index.vue` en la función `guardarModoTours()`.

</details>

<details>
<summary>Nivel 2</summary>

| Paso | Dónde |
|---|---|
| Pantalla | `components/AgenciaViajes/PasajeAereoForm.vue`, dentro del cotizador (`views/agencia-viajes/cotizador/editar.vue`) |
| Servicio frontend | `alternativaItemService.agregarPasajeAereo()` |
| Dirección | `POST /alternativas/{id}/items`, con `origen_tipo: 'pasaje_aereo'` en los datos |
| Ruta | `routes/api.php`: `Route::post("alternativas/{id}/items", [AlternativaItemController::class, 'store'])` |
| Controlador | `AlternativaItemController::store()`: el `match` mira `origen_tipo` y deriva a **`crearItemPasajeAereo()`** |
| Modelos | `AlternativaItem::create()` y `CotizacionPasajeAereo::create()` |
| Tablas | `alternativa_items` y `cotizacion_pasaje_aereo` |

Lo interesante: **la misma dirección** (`/items`) sirve para crear ítems de proveedor, mayorista,
pasaje aéreo, manual o guía. El campo `origen_tipo` decide cuál de los métodos `crearItem…()` se
ejecuta.

</details>

<details>
<summary>Nivel 3</summary>

1. En tinker:
   ```php
   tenancy()->initialize(App\Models\Tenant::find('agencia-demo'));
   App\Models\AgenciaViajes\PaquetePlantilla::where('uso', 'solo_itinerario')->count();
   App\Models\AgenciaViajes\PaquetePlantilla::find(12)->precio_venta_final;
   ```
   Al 15-oct-2026 daba **6** tours solo de itinerario y un precio de **110.00** para el tour 12.
   Si alguien cambió datos después, puede dar otro número: lo importante es que la consulta sea
   correcta.

2. Desde la carpeta `sistemfe` (funciona igual en PowerShell y en Git Bash):
   ```
   git log --oneline -S "E2 (fila 13" -- api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php
   ```
   Resultado: **`45778bf feat(agencia): fila 13 fase 1b — el tour en la cotización como una sola línea`**.
   Con `git show 45778bf` ves todo lo que cambió en ese commit.

3. El test crea una cotización con **1 adulto y 1 infante**, agrega una línea por persona de una
   tarifa con costo 20 y venta 50, y comprueba que el costo guardado es **20** (el infante no
   suma). Después agrega 2 adultos más y comprueba que la venta pasa a **150** (3 × 50) y el
   costo a **60** (3 × 20). En palabras simples: "cuando cambian los pasajeros, el costo también
   se actualiza, y los infantes no cuentan".

</details>
