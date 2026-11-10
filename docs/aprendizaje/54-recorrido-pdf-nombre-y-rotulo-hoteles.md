# Recorrido: el PDF de cotización se llama con código y cliente; la tabla de hoteles dice qué precio es

09-nov-2026, tarea **T-037** (R8 y H20 de las observaciones de prueba, obs. 9).
Archivos: `AlternativaPdfService.php`, `alternativa.blade.php`, `config/cors.php`,
`cotizador/editar.vue`.

---

## 1. La idea

Había dos problemas en el mismo PDF:

1. **R8.** El archivo se descargaba como `cotizacion-Alternativa a.pdf`. Con varias cotizaciones
   en Descargas no se sabía cuál era cuál.
2. **H20.** La tabla "Opciones de hoteles" decía "tarifa por persona", pero en Local/Nacional
   mostraba el total de la habitación por todas las noches (PEN 1,020 = 340 × 3).

Ahora:

- el archivo se llama `{código} - {cliente} - {alternativa}.pdf`, por ejemplo
  `CDKM-1126-0000004 - Ruiz - Paquete a Cusco Desde Tarapoto.pdf`;
- la tabla dice **"precio por habitación, 3 noches"** (Local/Nacional) o **"precio del paquete por
  persona"** (mayorista).

## 2. El recorrido

### 2.1 El nombre se arma en un solo lugar (`AlternativaPdfService::nombreArchivo()`)

`construir()` arma el PDF y su nombre. De ahí salen los dos usos:

- `generar()` → descarga: `$pdf->download($nombre)` pone el nombre en el encabezado
  `Content-Disposition`;
- `contenido()` → adjunto del correo (`CotizacionEnvioController`).

Como los dos pasan por `construir()`, el archivo descargado y el adjunto siempre se llaman igual.

**El cliente corto** (`clienteCorto()`):

| Cliente | Qué se usa | Ejemplo |
|---|---|---|
| Persona con apellido | primera palabra de `surname` | "Rivera Pérez" → `Rivera` |
| Persona sin apellido (cliente antiguo) | última palabra de `full_name` | "Ana Ríos" → `Ríos` |
| Empresa (`type_client = 2`) | `name_comerc`, o la razón social | `Viajes Selva SAC` |

Ojo: si un cliente antiguo solo tiene "Luis" en `full_name`, el archivo dirá `Luis`.

**El saneado** (`limpiarParteNombreArchivo()`), aplicado a cada una de las tres partes:

1. cambia por espacio los caracteres que Windows no acepta en un nombre (`/ \ : * ? " < > |`)
   y los de control;
2. junta los espacios repetidos;
3. corta a 40 caracteres y **después** quita espacios y puntos sueltos de los bordes. Si se hiciera
   al revés, un corte justo después de un espacio dejaría `"…Selva  - Alternativa"`.

Las tildes se mantienen. Symfony (`HeaderUtils::makeDisposition`) manda el nombre dos veces:
`filename*=utf-8''…` con tildes y `filename="…"` sin tildes (`Str::ascii`) para navegadores viejos.

### 2.2 El frontend usa el nombre del servidor (`editar.vue`, `descargarArchivo()`)

Antes el frontend inventaba el nombre (`cotizacion-${nombre}.pdf`). Ahora lo lee del encabezado con
`nombreDeContentDisposition()`:

1. busca primero `filename*=UTF-8''…` y lo decodifica (`decodeURIComponent`);
2. si no está, toma `filename="…"`;
3. si no hay encabezado, usa el nombre de antes.

La misma función la usa el botón de condiciones generales. Ahí no cambia nada, porque ese PDF ya
venía con su nombre.

### 2.3 Por qué hubo que tocar `config/cors.php`

El frontend (`:5173`) y la API (Apache, `:80` en local; otro dominio en producción) son **orígenes
distintos**. En una petición entre orígenes, el navegador solo deja que JavaScript lea unos pocos
encabezados "seguros", y `Content-Disposition` no es uno de ellos. Aunque el servidor lo mande,
`response.headers['content-disposition']` llega vacío.

Para habilitarlo, el servidor tiene que nombrarlo en `Access-Control-Expose-Headers`:

```php
'exposed_headers' => ['Content-Disposition'],
```

### 2.4 El rótulo de la tabla de hoteles (`opcionesHoteles()`)

El precio que muestra la tabla ya dependía de `origen_tipo`:

- **mayorista:** `precio_convertido`, que ya es el paquete por persona (`cantidad` = adultos);
- **Local/Nacional** (`proveedor`, real o ad-hoc): `total_convertido` = precio × `cantidad`, donde
  `cantidad` = noches.

El rótulo usa **la misma condición**, así que no puede contradecir al número:

```php
$rotulo = $item->origen_tipo === AlternativaItem::ORIGEN_MAYORISTA
    ? 'precio del paquete por persona'
    : 'precio por habitación, ' . $noches . ($noches === 1 ? ' noche' : ' noches');
```

Cada fila (un hotel) guarda su rótulo. El grupo junta los de todas sus filas:

- si todos coinciden → `rotulo` del grupo = ese texto, y se muestra en el título;
- si se mezclan (no debería pasar) → `rotulo` del grupo = `null`, y cada fila muestra el suyo
  debajo del nombre del hotel.

En `alternativa.blade.php`:

```blade
@if ($grupoHotel['rotulo'])
    <span class="hoteles-elegida-nota">— {{ $grupoHotel['rotulo'] }}</span>
@endif
...
<td>{{ $fila['hotel'] }}@if (! $grupoHotel['rotulo'])<br><span ...>{{ $fila['rotulo'] }}</span>@endif</td>
```

¿Y `modo_precio = 'por_persona'`? `total_convertido` solo multiplica por `cantidad` con
`tarifa_fija`. En la tabla de hoteles eso no pasa: todos los caminos que agregan hoteles a un grupo
(matriz, ad-hoc, mayorista) mandan `tarifa_fija`, y el único que permite `por_persona`
(`confirmarModoPrecio`) no manda `grupo_opcion_id`.

## 3. Cómo se probó

`SesionM5OpcionesHotelesPdfTest`, 10 tests nuevos:

- **nombre:** código-cliente-alternativa, saneado de caracteres, corte a 40 sin espacio final;
- **rótulo en los datos** (`opcionesHoteles()['rotulo']`): Local/Nacional con noches, singular,
  mayorista y mezcla;
- **rótulo en el HTML de la plantilla:** `htmlDelPdf()` genera el PDF de verdad con `contenido()`,
  captura con `View::composer` los datos con que se armó la vista y la vuelve a renderizar a HTML.
  Se prueba el HTML porque el PDF de DomPDF va comprimido.

Además:

- PDF real revisado con Ghostscript: "precio por habitación, 3 noches" junto a PEN 1,020.00;
- descarga en el navegador (09-nov): `CDKM-1126-0000004 - Ruiz - Paquete a Cusco Desde Tarapoto.pdf`.

## 4. Al desplegar

Se cambió `config/cors.php`: hay que regenerar la caché de configuración (`php artisan config:cache`,
que `deploy.sh` ya corre). Si el archivo se sigue descargando como `cotizacion-….pdf`, la caché de
config quedó vieja. No hay migraciones.
