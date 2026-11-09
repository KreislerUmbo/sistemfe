# Recorrido: catálogo central de aerolíneas

09-nov-2026, tarea **T-015**. Origen: observación 3 de las pruebas del usuario
(`docs/requerimientos/observaciones-pruebas-usuario.md`). La aerolínea era texto libre y los datos
estaban mezclados («Latam», «LATAM», «latam»).

---

## 1. La idea

Un **solo catálogo para todas las agencias**, guardado en la base **central**
(`db_tenant_central`), no en la de cada tenant:

- cada agencia **busca** y, si falta una, la **agrega**, y desde ese momento la ven todas;
- el Super-Admin la **corrige, desactiva o une** desde el panel central.

Esta tarea arma el catálogo y su API. Todavía ninguna pantalla de Agencia lo usa: eso es T-016
(buscador en los formularios y corrección de los nombres ya guardados).

## 2. Archivos

| Archivo | Qué hace |
|---|---|
| `api-sistema-fe/database/migrations/2026_11_09_100000_create_aerolineas_table.php` | Tabla central + lista inicial de 10 |
| `api-sistema-fe/app/Models/AgenciaViajes/Aerolinea.php` | Modelo con `CentralConnection` y `normalizar()` |
| `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AerolineaController.php` | API del tenant: buscar y agregar |
| `api-sistema-fe/app/Http/Controllers/Central/AerolineaController.php` | API del panel: listar, editar, desactivar, unir |
| `api-sistema-fe/routes/api.php` | Rutas de los dos lados |
| `central-panel/src/views/AerolineasView.vue` (+ `stores/aerolineas.ts`, `types/aerolinea.ts`, ruta y menú) | Pantalla del Super-Admin |
| `api-sistema-fe/tests/Feature/AgenciaViajes/AerolineaCatalogoTest.php` | 18 tests |

## 3. El recorrido

### 3.1 La tabla (migración central)

Está en `database/migrations/` (no en `tenant/`), así que la aplica `php artisan migrate`, que es
el paso 5 de `deploy.sh`. Columnas:

| Columna | Para qué |
|---|---|
| `nombre` | Lo que se muestra y se guarda en los tenants («LATAM») |
| `nombre_normalizado` | **Índice único.** Minúsculas, sin tildes ni espacios sobrantes («latam», «star peru») |
| `codigo_iata` | Opcional, 2-3 letras o números («LA») |
| `activa` | Desactivada = no sale en el buscador |
| `creada_por_tenant` | Qué agencia la agregó (sin FK: es informativo) |
| `unida_a_id` | Si se unió a otra, a cuál (FK a la misma tabla) |

La lista inicial se inserta **en la misma migración**, no en un seeder, porque `deploy.sh` corre
los seeders al final y un seeder podría no llegar a ejecutarse.

### 3.2 Normalizar: la clave de todo

```php
public static function normalizar(string $nombre): string
{
    $limpio = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nombre)));
    $ascii = Str::ascii($limpio);            // "perú" → "peru"
    return $ascii !== '' ? $ascii : $limpio; // otro alfabeto: queda en minúsculas
}
```

Con esto, «  LaTam », «latam» y «LATAM» dan «latam», y «Star Peru» da lo mismo que «Star Perú».
La búsqueda y el chequeo de duplicados usan esta misma función. La migración tiene **su propia
copia** (una migración no debe depender de un modelo que puede cambiar): si se cambia una, hay que
cambiar la otra.

### 3.3 Escribir en central desde un tenant

Una petición de Agencia llega con la tenancy inicializada, así que un modelo común consultaría la
base **del tenant**. `Aerolinea` usa el trait `CentralConnection` y por eso va siempre a central.
Es el único cruce tenant → central que se hace a propósito, y solo en esta tabla.

### 3.4 API del tenant (`AgenciaViajes\AerolineaController`)

**Dos barreras:**
1. **Ruta:** `permission:cotizaciones.editar|reservas.editar` (alcanza con uno de los dos).
2. **Giro:** `soloAgencia()` da 404 si el tenant no es `agencia_viajes`. Hace falta porque el rol
   Propietario de un retail tiene **todos** los permisos y pasaría la barrera 1.

**`index` (GET `aerolineas?buscar=`):** solo activas, busca `buscar` normalizado dentro de
`nombre_normalizado` (escapando `%` y `_`) o igual al código IATA. Devuelve como máximo 20.

**`store` (POST `aerolineas`):**
1. Da formato al nombre (`formatearNombre`): **solo si viene todo en minúsculas** se capitaliza
   («viva air» → «Viva Air»); con alguna mayúscula se respeta («KLM», «easyJet»).
2. Si el normalizado ya existe, devuelve esa (200) en vez de crear otra. Si la existente estaba
   unida, sigue `unida_a_id` hasta la vigente.
3. Si no existe, la crea (201) dentro de una transacción.
4. **Carrera:** si dos agencias agregan la misma a la vez, las dos pasan el paso 2, pero el índice
   único deja entrar solo a una. La otra recibe un `QueryException`, lo atrapa, busca la que ya
   quedó y la devuelve. La transacción sirve para que el insert fallido no deje la conexión
   abortada antes de esa búsqueda.

### 3.5 API central (`Central\AerolineaController`)

Las rutas van bajo `api/central/…` con `auth:central` (el login del panel). No hay alta: las
agregan las agencias.

- **Editar:** nombre, IATA y activa. Rechaza el nombre si otra ya tiene el mismo normalizado.
  Reactivar una unida le quita `unida_a_id` (vuelve a ser independiente).
- **Desactivar** (`DELETE`): no borra, solo `activa = false`. Nunca hay borrado real, porque los
  tenants guardan el nombre como texto.
- **Unir A en B:** A queda inactiva con `unida_a_id = B`, y las que ya apuntaban a A pasan a
  apuntar a B, así no se forman cadenas. B tiene que estar activa y no puede ser A misma, lo que
  impide los ciclos. **No** corrige lo que ya está guardado en los tenants: eso lo hace el comando
  de T-016.

Todo queda en el registro de auditoría central (`aerolinea.updated/deactivated/merged`).

### 3.6 Panel central (`AerolineasView.vue`)

Sigue el patrón de `ProveedorTiposView.vue`. Es una tabla con estado («inactiva · unida a LATAM»)
y quién la agregó. Editar abre una tarjeta arriba; desactivar y unir se confirman en una fila
debajo de la aerolínea (unir ofrece solo las activas, menos ella misma).

## 4. Tests (`AerolineaCatalogoTest`)

Corren contra Postgres real. La conexión `central` apunta a la misma base de pruebas y cada test
se revierte con una transacción por conexión. Si falta la tabla, `setUp` corre la migración, así
el test de la lista inicial prueba la migración de verdad.

- Lista inicial, buscar «lat» y por código, máximo 20.
- «  latam » devuelve la LATAM existente; lo que agrega el tenant A lo ve el B.
- Regla de mayúsculas; tildes al buscar, al agregar y al renombrar desde central.
- **Carrera:** un listener `creating` inserta el mismo nombre por **otra conexión** justo antes del
  insert, y el test comprueba que la respuesta es la existente y que hay una sola.
- Retail → 404 aunque tenga todos los permisos; sin permiso, el middleware da 403.
- Central: editar, desactivar (deja de salir), unir, re-apuntar y reactivar.

`RutasAgenciaPermisosTest` fija el permiso exacto de `api/aerolineas*`.

## 5. Al desplegar

`php artisan migrate --force` (paso 5 de `deploy.sh`) crea la tabla con la lista inicial. El
09-nov se verificó que producción no tiene migraciones centrales pendientes, así que solo corre
esta. No hay comando que correr a mano.
