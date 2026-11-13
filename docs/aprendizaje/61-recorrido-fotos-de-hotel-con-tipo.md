# Recorrido: fotos de hotel con tipo (fachada / habitación / otra)

13-nov-2026, tarea **T-020**. Origen: punto 7 de las pruebas del usuario
(`docs/requerimientos/observaciones-pruebas-usuario.md`, respuesta 1, «Diseño»).
Archivos:
- `api-sistema-fe/app/Models/AgenciaViajes/Concerns/FotosConTipo.php` (nuevo: el trait compartido)
- `api-sistema-fe/app/Models/AgenciaViajes/OpcionHotel.php` y `Proveedor.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ProveedorController.php` (`actualizarTipoFoto`, `eliminarFoto`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/OpcionHotelController.php` (`promover` → `copiarFotosAProveedor`)
- `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/2026_11_13_100000_migrar_fotos_proveedores_a_tipo_foto.php`
- `admin-start-kit/src/components/AgenciaViajes/GaleriaFotosProveedor.vue` (nuevo)
- `admin-start-kit/src/views/agencia-viajes/proveedores/detalle.vue`, `form.vue`, `cotizador/editar.vue`

**Al desplegar:** `php artisan tenants:migrate-verticales` (migración de datos, sin cambio de schema).

---

## 1. La idea

El PDF de la cotización (T-021) necesita saber **cuál foto de un hotel es la fachada**. Había dos
lugares donde un hotel guarda fotos, y no hablaban el mismo idioma:

| Dónde | Qué hotel | Antes | Ahora |
|---|---|---|---|
| `opciones_hotel.fotos` | hotel de mayorista o **no registrado** (Local/Nacional) | `{path, tipo_foto}[]` | igual |
| `proveedores.fotos` | hotel **registrado** como proveedor | `string[]` (solo rutas) | `{path, tipo_foto}[]` |

T-020 pone a los dos en **un solo formato** y agrega lo que faltaba: marcar la fachada, borrar una
foto del proveedor y no perder las fotos al promover un hotel no registrado.

Tipos:
- `fachada`: **máximo una** por hotel.
- `habitacion`.
- `otra`: **solo en proveedores**. Las fotos que ya existían quedaron como `otra`, porque nadie
  había dicho cuál era la fachada. En `opciones_hotel` el formulario solo ofrece fachada (1) y
  habitación (2).

> Para T-021: al leer fotos, `otra` es relleno; primero la fachada, después habitaciones.

## 2. El recorrido

### 2.1 Un solo lugar para normalizar (`Concerns/FotosConTipo.php`)

Antes, `normalizarFotos()` y `fotosResueltas()` vivían en `OpcionHotel`. Ahora están en un
**trait** que usan los dos modelos. Un trait es un bloque de métodos que se "pega" en varias clases.

```php
trait FotosConTipo {
    public const TIPOS_FOTO = ['fachada', 'habitacion', 'otra'];
    abstract protected static function tipoFotoPorDefecto(): string;   // cada modelo dice el suyo
    public static function normalizarFotos(array $fotos): array { ... }
    public static function fotosResueltas(array $fotos): array { ... }
}
```

- `normalizarFotos()`: si encuentra un string suelto (forma vieja), lo convierte en
  `{path, tipo_foto: <por defecto>}`. Es **defensivo**: aunque la migración ya convirtió todo, una
  entrada vieja no rompe nada.
- `fotosResueltas()`: agrega la `url` con `StorageUrl::resolve()` (la URL del tenant, nunca
  `/storage/` a mano). Es lo que recibe el frontend: `{path, tipo_foto, url}`.
- El tipo por defecto: `OpcionHotel` → `habitacion` (como siempre); `Proveedor` → `otra`.

**Por qué importa:** el 05-sep-2026 un controller llamó `StorageUrl::resolveMuchas($hotel->fotos)`
directo cuando la forma ya era `{path, tipo_foto}` y reventó. **Cualquier código que lea fotos de
hotel debe pasar por estos dos métodos.**

### 2.2 La migración de datos

`2026_11_13_100000_migrar_fotos_proveedores_a_tipo_foto.php` recorre `proveedores` y convierte
cada string en `{path, tipo_foto: 'otra'}`. Si la entrada ya es un array la deja igual, así que
correrla dos veces no cambia nada (**idempotente**). La columna ya era `json`: no hay cambio de
schema, solo de datos. Mismo patrón que la de `opciones_hotel` del 05-sep.

### 2.3 Ficha y formulario del proveedor → `GaleriaFotosProveedor.vue`

Pantalla: **Proveedores → ficha** (`detalle.vue`) y **Editar** (`form.vue`). Las dos usan el mismo
componente nuevo, en vez de duplicar la lógica.

```
GaleriaFotosProveedor  (props: proveedorId, fotos, conTipo)
  ├─ miniatura + insignia «Fachada» + botón 🗑
  └─ <select> Fachada / Habitación / Otra      ← solo si conTipo (proveedor de Alojamiento)
```

- `conTipo` es `true` solo si el tipo de proveedor tiene slug `alojamiento-hoteles`. Un transporte
  o restaurante ve sus fotos y puede borrarlas, pero sin selector.
- **Cada cambio se guarda al instante**, sin esperar al botón «Actualizar» del formulario. Por eso
  la confirmación de borrar lo dice. El componente avisa al padre con el evento `actualizado`, que
  trae la lista nueva que devolvió el servidor.

**Subir fotos** sigue igual: `POST proveedores/{id}` con archivos (`FotoUploadService`, tope de 10).
En `store()`/`update()` cada foto nueva entra como `{path, tipo_foto: 'otra'}`.

### 2.4 Cambiar el tipo: `PUT proveedores/{id}/fotos/tipo`

`ProveedorController::actualizarTipoFoto()`. Body: `{path, tipo_foto}`.

1. Valida `tipo_foto` contra `Proveedor::TIPOS_FOTO` → si no, 422.
2. `StorageUrl::relativo($path)`: acepta tanto el path como la URL completa y la reduce a path.
3. Si ese path **no está en la galería de este proveedor** → 422 («no pertenece a este
   proveedor»). Así nadie puede tocar archivos ajenos mandando un path inventado.
4. Recorre las fotos: la elegida toma el tipo nuevo; si el tipo nuevo es `fachada`, **la fachada
   anterior pasa a `otra`**. Así nunca hay dos.

### 2.5 Borrar una foto: `DELETE proveedores/{id}/fotos`

`ProveedorController::eliminarFoto()`. Body: `{path}`. Mismo patrón que
`OpcionHotelController::eliminarFoto()` y `DestinoAtractivoController::eliminarFoto()`:
mismo chequeo de pertenencia (422 si es ajena, **sin borrar nada**), borra el **archivo físico** del
disco y lo saca del array. Si era la fachada, el proveedor queda sin fachada.

Antes no existía: un proveedor con 10 fotos quedaba trabado (no podía subir más ni borrar).

Los dos endpoints nuevos usan el permiso `agencia.proveedores`, el mismo que editar el proveedor
(`routes/api.php`).

### 2.6 Promover un hotel no registrado → copia de archivos

En el cotizador, un hotel no registrado (`opciones_hotel` sin proveedor) se puede **promover** a
proveedor (`OpcionHotelController::promover()`). Antes sus fotos se perdían. Ahora
`copiarFotosAProveedor()`:

```
para cada foto del hotel:
    si el archivo existe → copiarlo a proveedores/<uuid>.<ext>
    guardar {path NUEVO, tipo_foto igual}
```

**¿Por qué copiar el archivo y no compartir el path?** Porque los dos lados borran el archivo al
eliminar una foto. Si compartieran el path, borrar la foto del hotel rompería la del proveedor (y al
revés). Decidido con el usuario el 13-nov.

> Mismo riesgo, sin resolver: al **duplicar una alternativa**, `AlternativaCopiaService` copia
> `opciones_hotel.fotos` tal cual y los hoteles copiados comparten paths (ver «Por analizar» en el
> backlog).

### 2.7 Cotizador: fotos del hotel en el drawer de la matriz

Pantalla: **Cotizador → Agregar servicio → «Hotel sin catálogo»** → Guardar hotel → se abre el
drawer «Elegí la habitación» (`editar.vue`, `pasoDrawer === 'matrizHotel'`).

- **Hotel no registrado** (`matrizHotelActiva.adhoc` con `hotelId`): bloque «Fotos del hotel
  (salen en el PDF)» con dos campos, Fachada y Habitación. Usa los endpoints de siempre,
  `POST/DELETE opciones-hotel/{id}/fotos` (`onSubirFotoHotelAdhoc`, `onEliminarFotoHotelAdhoc`). El
  campo Fachada se deshabilita con 1 y Habitación con 2: son los mismos topes del backend
  (`MAX_FOTOS_FACHADA`, `MAX_FOTOS_HABITACION`).
- **Hotel registrado** (elegido de la biblioteca): no se suben fotos ahí; aparece el enlace
  **«Fotos en la ficha del proveedor»**, que abre `/agencia-viajes/proveedores/{id}` en otra
  pestaña.

No se activó `permitir-gestion-hoteles` del picker de mayorista (lo pedía la tarea): ese flag
también muestra renombrar/borrar hotel y editar tarifas, que para un hotel no registrado no tienen
cómo recargarse.

## 3. Cómo se probó

- `tests/Feature/AgenciaViajes/ProveedorFotosTipoTest.php` (11 tests): migración idempotente,
  fachada única, 422 por path ajeno o tipo inválido, subir/borrar, promover con archivos propios.
  Los tests existentes de `opciones_hotel` siguen en verde (nada cambió ahí).
- Prueba en `agencia-demo` con Playwright (revisión, 13-nov): ficha y formulario de un hotel y de
  un transporte, y el drawer del cotizador con un hotel no registrado y uno de biblioteca.

## 4. Si algo falla

| Síntoma | Dónde mirar |
|---|---|
| Error «must be of type ?string, array given» al listar fotos | alguien llamó `StorageUrl::resolveMuchas()` sobre fotos de hotel; usar `Modelo::fotosResueltas()` |
| 422 «no pertenece a este proveedor» con una foto que sí está | el front mandó una URL de otro host; revisar `StorageUrl::relativo()` (busca `/tenancy/assets/`) |
| Foto rota en un proveedor promovido | el archivo original no existía al promover (se omite) |
| Dos fachadas | alguien escribió `fotos` sin pasar por `actualizarTipoFoto()` |
