# Recorrido: RUC no único, ciudad, alta rápida y «sin documento» en el proveedor

26-nov-2026, tarea **T-134**. Con el catálogo único de T-133, a un proveedor lo crean Agencia,
Caja y pronto Compras, muchas veces con pocos datos. Esta tarea lo prepara para eso:
- un mismo RUC puede ser varios establecimientos (cadena de hoteles), con un aviso para no duplicar sin querer;
- la ciudad los distingue en pantalla;
- un alta en segundos con la consulta RUC;
- un listado para limpiar los que no tienen documento.

Lo que se aprende acá:
- **una regla compartida por dos controladores sin copiarla**;
- **un dato que se calcula en vez de guardarse**;
- **validar solo lo que cambia**;
- **descartar la respuesta vieja de una consulta asíncrona**.

Archivos:
- `api-sistema-fe/app/Services/ProveedorDocumentoService.php` — `preparar()` y `avisoDuplicado()` (nuevo)
- `api-sistema-fe/app/Models/Proveedor.php` — `scopeSinDocumento()` y `datosFaltantes()`
- `api-sistema-fe/app/Http/Controllers/ProveedorController.php` — base (`proveedores-base`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ProveedorController.php` — `store()`, `validarPayload()`, `index()`
- `api-sistema-fe/app/Http/Controllers/Client/ClientController.php` — `searchDocument()` (solo el mensaje de error)
- `api-sistema-fe/database/migrations/tenant/core/2026_11_26_100000_add_direcciones_y_no_domiciliado_to_proveedores.php`
- `admin-start-kit/src/components/Proveedores/ProveedorAltaRapida.vue` y `AvisoDocumentoRepetido.vue` (nuevos)
- `admin-start-kit/src/helpers/proveedor.ts` — `nombreConCiudad()`, `datosDeConsultaRuc()`
- `admin-start-kit/src/views/cash/proveedores.vue`, `views/agencia-viajes/proveedores/index.vue` y `form.vue`,
  `components/AgenciaViajes/ProveedorBuscador.vue`, `views/cash/session.vue`

**Al desplegar:** `tenants:migrate-verticales` (4 columnas nuevas en `proveedores`, solo agrega).

---

## 1. Pantalla → tabla

| Pantalla | Llama a | Lee/escribe |
|---|---|---|
| Configuraciones → Proveedores (`/proveedores`) → «Alta rápida» | `GET search-document/ruc/{n}`, luego `POST proveedores-base` | `proveedores` |
| La misma pantalla, interruptor «Sin documento» | `GET proveedores-base?sin_documento=1` | `proveedores` (sin referenciales) |
| La misma pantalla, teléfono en la fila | `PUT proveedores-base/{id}` con `{ telefono }` | `proveedores.telefono` |
| Agencia → Catálogos → Proveedores, «Ver todos» | `GET proveedores?alcance=todos` | `proveedores` (turísticos y generales) |
| Agencia → formulario del proveedor | `POST proveedores` (vertical) | `proveedores` (+ lo turístico) |
| Caja → contraparte proveedor | `buscarContraparte` | lee `proveedores.ciudad` para mostrarla |

Las columnas nuevas:

| Columna | Qué guarda |
|---|---|
| `direccion_fiscal` | La de SUNAT, que llena la consulta RUC |
| `direccion` | Ya existía; ahora es **la del local** |
| `ciudad` | Texto libre: la provincia de la consulta, o el distrito si no hay provincia |
| `es_no_domiciliado`, `pais` | Proveedor del extranjero. El país es texto por ahora: el código SUNAT va en T-157 |

## 2. Una regla, dos controladores

El proveedor se crea por dos puertas:
- el controlador base, que usan Caja y Compras;
- el de Agencia, que además sube logo y fotos.

Si el aviso de RUC repetido viviera en cada uno, tarde o temprano dirían cosas distintas. Por eso
vive en un servicio y los dos lo llaman igual:

```php
// en los dos store(), después de validar
if ($aviso = ProveedorDocumentoService::avisoDuplicado($request, $validado)) {
    return $aviso;   // 409 con la lista de los que ya tienen ese documento
}
```

`avisoDuplicado()` responde **409 Conflict**, no 422. El dato no está mal; lo que hay es otro
proveedor con el mismo RUC, y el usuario decide. La pantalla muestra los existentes, con nombre,
ciudad y dirección, y dos salidas:
- **«Ir al existente»**: el componente emite `existente` y la pantalla abre ese proveedor.
- **«Es otro establecimiento»**: se reenvía con `confirmar_duplicado: true` y se crea.

En Agencia el aviso va **antes** de subir el logo. Si fuera después, un alta que el usuario
termina cancelando dejaría archivos huérfanos en el disco.

Un detalle: en un no domiciliado, el mismo número en **otro país** es otro documento. Por eso, en
ese caso, la búsqueda compara también `pais`.

## 3. «Datos incompletos» no es una columna

```php
public function datosFaltantes(): array   // App\Models\Proveedor
{
    if ($this->es_referencial) return [];
    return array_keys(array_filter([
        'documento' => blank($this->numero_documento),
        'teléfono'  => blank($this->telefono) && blank($this->celular) && blank($this->whatsapp),
        'dirección' => blank($this->direccion) && blank($this->direccion_fiscal),
    ]));
}
```

Si fuera una columna `datos_incompletos`, cada lugar que crea o edita un proveedor tendría que
acordarse de actualizarla, incluso el «Promover a proveedor» del cotizador. Calculada, vale para
cualquier proveedor, venga de donde venga, y **desaparece sola** al completar el dato. El API la
manda como `datos_faltantes`, y la pantalla muestra el badge «Datos incompletos» con lo que falta
en el `title`.

`scopeSinDocumento()` sigue la misma idea: excluye `es_referencial` (proveedores que solo sirven
para costear) y cuenta como vacío un documento con solo espacios (`trim(coalesce(...)) = ''`).

## 4. Validar solo lo que cambia

`ProveedorDocumentoService::preparar()` exige que un RUC tenga 11 dígitos. Pero en la base hay
proveedores viejos con RUC mal escrito. Si la regla se aplicara siempre, editar el teléfono de
uno de ellos daría error por un dato que el usuario ni tocó.

```php
$cambiaDocumento = $actual === null                          // creando
    || $valor('tipo_documento') !== $actual->tipo_documento
    || $valor('numero_documento') !== $actual->numero_documento
    || $actual->es_no_domiciliado;
```

El patrón sirve para cualquier regla nueva sobre datos que ya existen: se exige al crear y al
cambiar ese campo, nunca de rebote. `$valor()` lee el dato de la petición y, si no viene, el
guardado, porque el `PUT` del teléfono en la fila manda solo `{ telefono }`.

## 5. La consulta RUC y la respuesta que llega tarde

El alta rápida consulta el RUC apenas tiene 11 dígitos. Si el usuario corrige un dígito mientras
la primera consulta viaja, pueden llegar dos respuestas, y la vieja **no debe pisar** a la nueva:

```ts
const numero = form.numero_documento;            // lo que se consultó
const { data } = await httpClient.get(`/search-document/ruc/${numero}`);
if (numero !== form.numero_documento) return;     // el usuario ya escribió otro
```

Y al revés: si cambia el RUC después de autollenar, se borran razón social, dirección y ciudad
(la marca `autollenado`), porque eran del RUC anterior. Lo que el usuario escribió a mano no se
borra.

`datosDeConsultaRuc()` traduce la respuesta de apisperu (`razonSocial`, `direccion`,
`provincia`…) a los campos del proveedor y limpia el `"-"` que SUNAT pone cuando no tiene el dato.
Si la consulta falla, solo se muestra un aviso y el formulario se puede guardar igual.

**Seguridad:** antes, `searchDocument()` devolvía `$e->getMessage()`, y ese mensaje traía **la URL
con el token de apisperu**. Ahora devuelve un texto genérico y el detalle va al log, con el token
reemplazado por `***`. Lo que queda pendiente de esa ruta (no exige permiso y reenvía el 401 de
apisperu) está en «Por analizar» del backlog.

## 6. Los tests

`tests/Feature/ProveedorRucYAltaRapidaTest.php`, un test por criterio:
- `test_ca1_…` — 409 con los existentes y alta con `confirmar_duplicado`, por las dos rutas;
- `test_ca2_…` — consulta RUC con `Http::fake()`: trae los datos, y si falla no expone el detalle;
- `test_ca3_…` — los buscadores devuelven la ciudad;
- `test_ca4_…` — «sin documento» sin referenciales, y `datos_faltantes`;
- `test_ca6_…` — no domiciliado sin RUC y fuera de «sin documento».

El CA5 (sin `proveedores.gestionar` no hay alta) está en
`ProveedorBaseTest::test_t134_ca5_el_alta_rapida_pide_gestionar`, por HTTP con un tenant nuevo.
En el frontend, `helpers/proveedor.test.ts` cubre `nombreConCiudad()` y `datosDeConsultaRuc()`.

Además se regrabaron los dorados de T-095 (`fixtures/T095/*.json`): guardan el proveedor completo
y ahora tiene cuatro columnas más.

## Ejercicios

1. Crea en agencia-demo dos proveedores con el mismo RUC y ciudades distintas. Búscalos en el
   buscador del cotizador: ¿cómo los distingues?
2. En `datosFaltantes()`, ¿qué pasaría con los proveedores viejos si `dirección` exigiera la
   **fiscal** y no cualquiera de las dos? ¿Cuántos saldrían como incompletos en agencia-demo?
3. Busca en el código otro lugar que cree un proveedor sin pasar por `avisoDuplicado()`. Pista:
   está en «Por analizar».
4. ¿Por qué el aviso es un 409 y no un 422? ¿Qué haría el interceptor de Axios distinto si fuera
   un 401?
