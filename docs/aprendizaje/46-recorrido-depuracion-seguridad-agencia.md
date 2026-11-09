# Recorrido: depuración de seguridad de Agencia

09-nov-2026, tarea **T-092**. Origen: auditoría del vertical del 09-nov-2026 (las rutas y las
Policies estaban bien; quedaban huecos chicos).

---

## 1. El problema

Seis huecos sueltos, sin relación entre sí:

1. **Avisos que ejecutaban HTML.** Varios `Swal.fire({ html: … })` armaban el HTML pegando
   nombres escritos por un usuario (guía, combo, alternativa, archivo de foto). Un guía llamado
   `<img src=x onerror=alert(1)>` ejecutaba código en el navegador de **otro** usuario del mismo
   tenant cuando le salía el aviso.
2. **Un endpoint que se saltaba «Modificar reserva».** `POST reservas/{id}/pasajeros` sumaba un
   pasajero a una reserva sin recalcular el precio (el bug que cerró la ola 4). Ninguna pantalla
   lo usaba.
3. **Rutas que daban 500.** `Route::resource` registra `create` y `edit`, que en una API no
   existen: `GET api/guias/create` llamaba a un método inexistente.
4. **`orden` repetido.** El siguiente `orden` de una fila hija se calculaba con `max()+1` sin
   lock: dos altas a la vez leían el mismo máximo.
5. **Comodines en las búsquedas.** En `ilike`, `%` y `_` son comodines: buscar «50%» traía todo
   lo que tuviera «50».
6. Un comentario desactualizado en `ReporteOperativoController::pdf()`.

## 2. Archivos

| Archivo | Qué cambió |
|---|---|
| `admin-start-kit/src/helpers/escaparHtml.ts` (nuevo) | `escaparHtml(texto)`: escapa `& < > " '`; `null`/`undefined` → `''` |
| `helpers/avisosProveedores.ts`, `components/AgenciaViajes/SalidaReprogramar.vue` | Usan el helper (tenían cada uno su copia local) |
| `views/agencia-viajes/cotizador/editar.vue` | `guardarPasajeros()`, «No se tocaron», «Guías pendientes de asignar» |
| `views/agencia-viajes/paquetes/detalle.vue` | Combos afectados al desactivar un tour (el id con `Number()`) |
| `views/agencia-viajes/destinos/form.vue`, `proveedores/form.vue` | Fotos rechazadas en el navegador y no subidas por el servidor (y `res.message`) |
| `api-sistema-fe/routes/api.php` | Sin `POST reservas/{id}/pasajeros`; `->except(['create','edit'])` en proveedores, servicios, temporadas y guías |
| `.../AgenciaViajes/ReservaPasajeroController.php` | Sin `store()`; `buscarCatalogo()` con `BusquedaLike` |
| `.../AgenciaViajes/AlternativaDestinoController.php`, `SalidaServicioController.php`, `SalidaVehiculoController.php`, `CrmMotivoResultadoController.php` | `store()` dentro de una transacción con `lockForUpdate()` |
| `.../AgenciaViajes/ConsultaController.php` | `coincidencias()` con `BusquedaLike` |
| `api-sistema-fe/app/Services/BusquedaLike.php` (nuevo) | `escapar()` / `contiene()` |
| `tests/Feature/AgenciaViajes/T092DepuracionSeguridadAgenciaTest.php` (nuevo) | 7 tests (CA3-CA6) |
| `admin-start-kit/src/helpers/escaparHtml.test.ts` (nuevo) | Vitest del helper (CA1) |

Tablas: ninguna nueva. Migraciones: ninguna.

## 3. El recorrido

### 3.1 Escapar antes de meter texto en HTML

`Swal` tiene dos formas de mostrar texto: `text:` (lo pone literal) y `html:` (lo interpreta).
Cuando el aviso necesita formato (lista, negrita) se usa `html:`, y entonces **cada pedazo que
venga de un usuario o del servidor** pasa por `escaparHtml()`:

```ts
const lista = res.guias_pendientes
  .map((g) => `<li>${escaparHtml(g.guia_nombre ?? 'Guía')} — ${escaparHtml(g.tour_origen_nombre)}</li>`)
  .join('');
```

`<` se vuelve `&lt;`: el navegador lo **muestra** como `<`, pero no arma una etiqueta.

- **`escaparHtml()` no es `sanearHtml()`.** `sanearHtml()` es para HTML con formato guardado a
  propósito (Quill): deja pasar `<b>` y quita lo peligroso. `escaparHtml()` es para texto plano:
  todo se ve literal.
- **No escapar dos veces.** El backend devuelve JSON sin escapar; si también se escapara allá, el
  usuario vería `&amp;` en vez de `&`.
- Queda un riesgo parecido fuera de esta tarea: el segundo argumento de
  `Swal.fire(título, texto, icono)` y `title:` también son HTML (ver «Por analizar»).

### 3.2 El pasajero nuevo entra por «Modificar reserva»

Se borraron la ruta y `ReservaPasajeroController::store()`. Siguen `PUT reserva-pasajeros/{id}`
(completar nombre y documento) y `GET pasajeros-catalogo`. Sumar un pasajero a una reserva
aceptada es un cambio de precio, y el único camino que lo recalcula es «Modificar reserva».
Los tests que llamaban a `store()` directo se retiraron con el endpoint.

### 3.3 `except(['create','edit'])`

```php
Route::resource("guias", GuiaController::class)
    ->except(['create', 'edit'])
    ->middleware('permission:agencia.guias');
```

Quedan las cinco de una API: `index`, `store`, `show`, `update`, `destroy`.

### 3.4 El `orden` con lock

```php
$destino = DB::transaction(function () use ($alternativa, $validado) {
    Alternativa::whereKey($alternativa->id)->lockForUpdate()->first();   // 1. bloquea el padre
    $siguienteOrden = (int) ($alternativa->destinos()->max('orden') ?? 0) + 1; // 2. lee el máximo
    return AlternativaDestino::create($validado + [..., 'orden' => $siguienteOrden]); // 3. inserta
});
```

La segunda alta simultánea se queda esperando en el paso 1 hasta que la primera termina; cuando
sigue, el `max()` ya ve la fila nueva. El orden de los pasos importa: el lock **antes** del
`max()`.

| Controller | Qué se bloquea |
|---|---|
| `AlternativaDestinoController::store()` | La alternativa |
| `SalidaServicioController::store()` | La salida operativa |
| `SalidaVehiculoController::store()` | La salida; dentro entra también «¿es el primer vehículo?», que tenía la misma carrera (las dos altas se llevaban todas las reservas) |
| `CrmMotivoResultadoController::store()` | No hay padre: las filas del mismo `tipo`. Postgres no permite `FOR UPDATE` junto con `max()`, por eso es una consulta aparte. Funciona porque «Otro» existe siempre y no se renombra ni se desactiva |

El test lo comprueba con el registro de consultas: el `SELECT … FOR UPDATE` va antes del `max` y
del `INSERT`.

### 3.5 Comodines escapados

```php
BusquedaLike::contiene('50%');   // '%50\%%'
```

`\` es el carácter de escape por defecto de `LIKE` en Postgres, así que se escapan `\`, `%` y
`_`. El valor sigue yendo como parámetro (no hay inyección SQL; el problema era solo que traía de
más). Se usa en `ConsultaController::coincidencias()` y en los cinco `ilike` de
`ReservaPasajeroController::buscarCatalogo()`.

## 4. Cómo se verificó

- Tests: `php artisan test --filter="T092|ConsultaOrigenContacto|ReservaPasajero|AlternativaDestino"`
  y `npx vitest run src/helpers/escaparHtml.test.ts`.
- En pantalla (agencia-demo, Playwright): combo y foto con `<img src=x onerror=…>` en el nombre →
  se ven literales, sin `<img>` en el aviso.
- El aviso «Guías pendientes de asignar» no se recorrió: solo sale con `modo_tours = clasico` y
  agencia-demo está en `nuevo`. Se dio por cubierto con el Vitest y el diff.

## 5. Lo que queda fuera

- Las ~147 llamadas `Swal.fire(título, res.message, …)` (el texto también es HTML).
- Los ~20 `ilike` de otras búsquedas de Agencia que todavía no usan `BusquedaLike`.
- `use PasajeroDocumento` sin uso en `ReservaPasajeroController` (T-096).
