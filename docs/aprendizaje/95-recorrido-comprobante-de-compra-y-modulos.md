# Recorrido: comprobante de compra y módulos activables

26-nov-2026, tarea **T-135**. Es el primer paso de **Compras**: registrar el comprobante que
entrega un proveedor (factura, boleta, NC, recibo, comprobante del exterior), **solo con montos**.
Los pagos llegan en T-136, las líneas de producto en T-154 y la asignación a una reserva o salida
en T-152.

Para que Compras no les aparezca a todos los tenants, la tarea construyó también lo mínimo de los
**módulos activables**: un interruptor por tenant en el Panel Superadmin. Rendiciones y Registros
van a usar el mismo mecanismo.

Lo que se aprende acá:
- **la doble capa** de un módulo apagado: el menú no lo muestra y la API lo bloquea;
- **un índice único parcial** para que no se registre dos veces la misma factura;
- **bloquear el periodo** para que cerrar y registrar no se crucen;
- **adjuntos privados** con una URL firmada que vence;
- **un servicio con su propia excepción**, que el controlador no tiene que atrapar.

Archivos:
- Central: `app/Services/ModulosTenant.php`, `app/Http/Middleware/EnsureModuloActivo.php`,
  `app/Services/MenuResolver.php` (`resolver()`), `app/Http/Controllers/Central/TenantModuloController.php`,
  `app/Models/Central/Modulo.php` y `TenantModuloOverride.php`
- Migraciones centrales: `database/migrations/2026_11_28_100000_create_modulos_tables.php` y
  `2026_11_28_100100_add_uso_compras_to_tipos_comprobante.php`
- Tenant: `database/migrations/tenant/core/2026_11_28_100000_create_compras_tables.php` y
  `2026_11_28_100100_add_compras_permissions.php`
- `app/Services/Compras/CompraService.php` y `CompraException.php`
- `app/Http/Controllers/Compras/CompraController.php` y `CompraPeriodoController.php`
- `app/Models/Compras/Compra.php` y `CompraPeriodo.php`
- Frontend: `admin-start-kit/src/views/compras/` (`index.vue`, `form.vue`, `periodos.vue`),
  `src/components/Compras/`, `src/helpers/compras.ts`; `central-panel/src/components/tenant-detail/ModulosCard.vue`
- Tests: `tests/Feature/CompraTest.php`, `ModulosTenantTest.php`, `ComprasMigracionesPorGiroTest.php`

**Al desplegar:** `deploy.sh` corre `migrate` (centrales), `tenants:migrate-verticales`,
`MenuItemsSeeder` y `roles:sync-propietario`. Después, encender «Compras» en el detalle de cada
tenant que lo vaya a usar: **nace apagado en todos**.

---

## 1. Pantalla → tabla

| Qué pasa | Ruta | Permiso | Tabla |
|---|---|---|---|
| Superadmin enciende o apaga un módulo | `PUT central/tenants/{id}/modulos/{codigo}` | Super-Admin | `tenant_modulo_overrides` (central) + `central_audit_logs` |
| Listado de compras, con filtros y totales en soles | `GET compras` | `compras.ver` | lee `compras` |
| Tipos de la tabla 10 y tipo de cambio sugerido | `GET compras/catalogos` | `ver` o `registrar` | lee `tipos_comprobante` (central) |
| Registrar | `POST compras` (multipart) | `compras.registrar` | `compras` (+ `compra_periodos` si el mes no tenía fila) |
| Editar | `POST compras/{id}` (multipart) | `compras.registrar` | `compras` |
| Anular con motivo | `POST compras/{id}/anular` | `compras.anular` | `compras.anulada_at/por/motivo` |
| Ver el PDF o XML | `GET compras/{id}/adjunto/pdf` → URL firmada → `GET compra-archivo/{id}/pdf` | `compras.ver` | disco `private` |
| Cerrar o reabrir un mes | `POST compra-periodos/{periodo}/cerrar` o `/reabrir` | `compras.cerrar_periodo` | `compra_periodos` (+ `historial`) |

Todas las rutas de tenant llevan además `modulo:compras`. **No hay ruta para borrar**: una compra
se anula.

## 2. Módulos activables: dos capas

Un módulo apagado tiene que desaparecer en dos lugares, porque cada uno solo cubre una parte:

1. **El menú** (`MenuResolver::resolver()`): un `menu_item` con `modulo_id` se cae si su módulo no
   está activo, y sus hijos se caen con él. Esto es comodidad: el usuario no ve lo que no puede usar.
2. **La API** (`EnsureModuloActivo`, alias `modulo:`): responde 403 «Este módulo no está
   habilitado». Esto es seguridad: alguien que escribe la URL a mano tampoco entra.

¿Por qué hacía falta? El Propietario recibe **todos** los permisos en cada deploy
(`roles:sync-propietario`). Sin el interruptor, Compras les habría aparecido a todos los dueños.

`ModulosTenant::efectivos()` guarda en caché la lista de módulos activos de cada tenant. Cuando el
Superadmin cambia el interruptor, `cambiar()` borra esa caché **y** la del menú, y lo hace desde
adentro del tenant (`$tenant->run(...)`), porque las dos viven en la base del tenant. Por eso el
menú cambia sin volver a iniciar sesión (CA1).

## 3. No registrar dos veces la misma factura

Un mismo RUC puede tener varios proveedores, uno por establecimiento («Casa Andina — Tarapoto»
y «Casa Andina — Cusco»). Si la clave fuera `proveedor_id`, la misma factura entraría dos veces.
Por eso la compra guarda una copia del documento y una **`clave_proveedor`**: el RUC en mayúsculas
y sin espacios, o `id:<proveedor_id>` si el proveedor no tiene documento (el no domiciliado).

```sql
create unique index compras_comprobante_unico on compras
  (clave_proveedor, tipo_comprobante_codigo, serie, numero) where anulada_at is null
```

El `where anulada_at is null` lo convierte en un **índice parcial**: una compra anulada no impide
volver a registrar bien el mismo comprobante.

`CompraService::guardar()` busca primero el duplicado y responde **409** con la compra que ya
existe, para que la pantalla muestre un enlace. Si dos personas guardan al mismo tiempo, las dos
búsquedas pasan, pero el índice frena a la segunda: el `catch` reconoce la
`UniqueConstraintViolationException` y responde el mismo 409.

## 4. Periodo de anotación y periodo cerrado

`periodo` (`AAAA-MM`) es el mes en que la compra se anota para el contador. Por defecto es el mes
de la emisión y nunca puede ser anterior. Un mes **sin fila** en `compra_periodos` está abierto.

Cerrar un mes mientras alguien registra una compra en ese mes es una carrera. La resuelve
`CompraPeriodo::bloquear()`:

```php
static::query()->insertOrIgnore([...'estado' => 'abierto'...]); // la fila existe siempre
return static::where('periodo', $periodo)->lockForUpdate()->firstOrFail();
```

Registrar, editar, anular, cerrar y reabrir piden esa misma fila bloqueada, así que se ejecutan
una después de la otra. Al editar se bloquean el periodo de antes y el nuevo, **siempre en el
mismo orden** (`sort`), para que dos ediciones cruzadas no se traben entre sí.

Reabrir exige motivo y queda registrado. Las columnas sueltas (`cerrado_at`, `reabierto_por`…)
solo muestran el último ciclo. Por eso existe `historial` (JSON): cada cierre y cada reapertura se
agregan ahí y nada se pisa.

## 5. Las reglas viven en el servicio

`CompraController` es delgado: llama a `CompraService` y arma la respuesta. Cuando una regla no se
cumple, el servicio lanza `CompraException` con su código HTTP y los datos que necesita la
pantalla:

```php
throw new CompraException($mensaje, 422, ['requiere_documento' => true, 'proveedor_id' => $proveedor->id]);
```

La excepción sabe convertirse en respuesta (`render()`) y no se escribe en el log (`report()`
vacío), porque es un rechazo esperado, igual que una validación. El controlador no necesita
ningún `try/catch`.

Reglas que se ven en `validar()` y `guardar()`:
- **Total al centavo** con `BigDecimal`: `base_gravada + igv + exonerado + inafecto + otros = total`.
  El IGV **no se recalcula**: se guarda lo que dice el comprobante.
- **Fecha de emisión** no futura, comparada con `HoraPeru::hoyTexto()` y nunca con `now()`.
- **USD** exige tipo de cambio. La pantalla sugiere el de **venta** de SUNAT de esa fecha.
- **Crédito** exige vencimiento, que no puede ser anterior a la emisión.
- **Proveedor sin documento** → 422 con `requiere_documento`, y la pantalla abre un modal para
  completar el RUC. Las facturas exigen RUC de 11 dígitos; la liquidación de compra (04) acepta
  DNI. Solo un no domiciliado con el tipo 91 entra sin documento.
- **NC/ND** exige la compra que modifica: del mismo emisor (`clave_proveedor`), vigente y que no
  sea otra nota (esto último se agregó en la revisión).

## 6. Adjuntos privados con URL firmada

El PDF y el XML son facturas de terceros, así que van al disco **`private`** del tenant, con un
nombre aleatorio (`compras/pdf/<uuid>.pdf`). La ruta nunca sale en la respuesta: solo
`tiene_pdf: true`.

Para descargar hay dos pasos:
1. `adjuntoUrl()` (con login y `compras.ver`) devuelve una URL firmada que vence en 10 minutos
   (`URL::temporarySignedRoute`).
2. El navegador abre esa URL en otra pestaña. `descargar()` no pide login, pero la ruta lleva el
   middleware `signed`: una URL vencida o modificada da 403.

La firma incluye el dominio, así que una URL de un tenant no sirve en otro.

**Lo que encontró la revisión:** el nombre del archivo descargado se armaba con la serie y el
número. Symfony no acepta `/` ni `\` en ese nombre y respondía 500, y una factura del exterior
suele numerarse `INV/2026/001`. Ahora solo el nombre de la descarga cambia esos caracteres por `-`.

## 7. Cómo probarlo

```
php artisan test --filter=Compra
php artisan test --filter=Modulo
```

En pantalla (agencia-demo): encender «Compras» en el Panel Superadmin → registrar una factura con
PDF → repetirla (409) → una en USD → cerrar el mes, intentar editar y reabrir con motivo → anular
con motivo → apagar el módulo y ver que se cierran el menú y la API.

## 8. Lo que quedó para después

En «Por analizar» del backlog: anular una compra que tiene una nota vigente, `F001-123` frente a
`F001-0000123`, los permisos que necesita un rol que no es Propietario, el adjunto reemplazado que
queda en el disco y el bloqueo compartido del periodo.
