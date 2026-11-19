# T-065 — Otras direcciones del cliente y dirección elegida en el comprobante (backend + ficha) (H61)

| | |
|---|---|
| **Módulo** | CLI + VEN (todos los giros) |
| **Depende de** | T-064 (hecha) |
| **Reglas** | ayudas sin fricción (por defecto la fiscal, sin preguntar) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 18, H61 (aprobada 27-oct-2026); decisiones de diseño del 18-nov-2026 (abajo) |
| **Rama** | `feat/T-065-cliente-otras-direcciones` |

## Contexto
Un cliente con RUC y sucursales puede pedir que su comprobante lleve la dirección de la sucursal
que compró. Hoy el cliente tiene una sola dirección (`clients.address`), que T-064 copia en el
comprobante (`direccion_cliente`). La tabla `client_addresses` existe y nadie la usa.
Esta tarea deja las direcciones extra en el cliente y hace que los endpoints de emisión acepten
la elegida. El selector en las pantallas de emisión es **T-107**.

**Decisiones del 18-nov-2026 (usuario):**
- **D1 — Texto copiado:** igual que la fiscal. `direccion_cliente` recibe solo `client_addresses.direccion`
  (una línea). La dirección extra guarda ubigeo y distrito/provincia/región como el cliente, pero el
  PDF imprime solo esa línea.
- **D2 — Columnas sobrantes:** se borran `principal` y `state`; se agrega `activo`.
- **D3 — Permiso para agregar:** agregar una dirección se permite con `edit_client` **o** con el
  permiso de emitir de cada pantalla (como los clientes en Agencia). Editar y desactivar piden
  `edit_client`.

## Alcance

### 1. Migración (core, nueva)
- `client_addresses`:
  - `direccion` → `string(255)`, NOT NULL; `nombre` → NOT NULL (`string(100)`, p. ej. "Sucursal Tarapoto").
  - Renombrar `departamento` → `region` (mismo nombre que en `clients`); agregar `ubigeo_region`,
    `ubigeo_provincia`, `ubigeo_distrito` (nullable, mismo tipo que en `clients`).
  - Borrar `principal` y `state`; agregar `activo` boolean default true.
  - Índice en `client_id`.
  - **Antes de alterar:** si la tabla tiene filas, la migración lanza una excepción con el conteo
    (no se pierden datos en silencio). En local las 7 bases tienen 0 filas (18-nov).
- `sales` y `notes`: `client_address_id` nullable, FK a `client_addresses` (sin cascade), índice.
- `down()` revierte todo.

### 2. Modelo y API de direcciones
- `App\Models\Client\ClientAddress` (nuevo) con `client()`. En `Client`: `direcciones()` (hasMany).
- Rutas (antes de `Route::resource("clients", …)` en `routes/api.php`, ~línea 572):
  | Ruta | Permiso |
  |---|---|
  | `GET clients/{id}/direcciones` (activas; `?todas=1` incluye inactivas) | `list_client\|edit_client\|register_sale\|edit_sale\|nota_electronica\|register_advance\|reservas.editar` |
  | `POST clients/{id}/direcciones` | `edit_client\|register_sale\|edit_sale\|nota_electronica\|register_advance\|reservas.editar` |
  | `PUT client-direcciones/{id}` (editar y activar/desactivar) | `edit_client` |
  Sin `DELETE`: una dirección usada por un comprobante no se borra nunca.
- FormRequest: `nombre` requerido ≤100, `direccion` requerida ≤255, `referencia` ≤200, ubigeo
  opcional (mismas reglas que `ClienteRequest`).

### 3. Copia en el comprobante (`CopiaDatosDelCliente`)
- `copiarDatosDelCliente()`: si `client_address_id` tiene valor, `direccion_cliente` sale de esa
  dirección (aunque después se haya desactivado: `activo` solo filtra lo que se puede **elegir**);
  si no, de `clients.address` como hoy. Documento y razón social, sin cambios.
- Así, al editar y al enviar sin CDR (los lugares que ya llaman a `copiarDatosDelCliente()`), la
  dirección elegida no se pisa con la fiscal.

### 4. Endpoints de emisión
Todos aceptan `client_address_id` **opcional** (`nullable|integer`) y validan en PHP que exista,
que `client_id` sea el del comprobante y que esté **activa**; si no, 422 con mensaje claro. Se
asigna **antes** del `Sale::create` para que el hook `creating` lo use.
- Venta retail: `SaleController::store()` y `update()`. En `update()`, si cambia el cliente y no
  llega una dirección suya, `client_address_id` queda NULL. Todo dentro de la transacción actual
  (regla de atomicidad de `update()`).
- Factura de reserva: `ReservaFacturacionController::store()`. La venta directa con cobro ya pasa
  por ahí (`VentaDirectaController::facturarYCobrar()`): solo reenviar el campo.
- Anticipo: `AdvanceController::store()`.
- Nota: `NotaElectronicaController::store()`. Sin `client_address_id` en el request, hereda el de
  la venta (y su `direccion_cliente`, como hoy); con uno, debe ser del cliente de la venta.

### 5. Ficha y formulario del cliente
- `views/clients/ficha.vue`: card "Otras direcciones" (con `card-header`, como las demás): lista
  nombre + dirección + distrito; botón "Agregar", editar y desactivar/activar (estos dos solo con
  `edit_client`). Inactivas se muestran atenuadas al final.
- El modal de alta/edición de dirección es un componente reutilizable
  (`components/Clientes/DireccionClienteModal.vue`, nuevo) que T-107 usa en las pantallas de
  emisión. Ubigeo con los mismos selects que `ClienteDatosBase.vue` (~líneas 103-118), inputs `-sm`.
- `ClienteDatosBase.vue` (dirección fiscal) no cambia.

## Fuera de alcance
- Selector "Dirección del comprobante" y "+ Agregar dirección" en las pantallas de emisión: **T-107**.
- Dirección en el XML: retirada sin construir (obs. 18, punto 4). `GreenterService::getCliente()` no cambia.
- `cod_tipo_doc_cliente` no se vuelve a copiar al editar el cliente antes de enviar: va a "Por
  analizar" del backlog, no se toca acá.
- Direcciones de envío del portal e-commerce ("Mis direcciones"). El portal hoy no lee `client_addresses`.

## Dónde tocar
- `database/migrations/tenant/core/` → migración nueva (la de creación es `2026_06_01_010200_create_client_addresses_table.php`: **no editarla**)
- `app/Models/Concerns/CopiaDatosDelCliente.php` → `copiarDatosDelCliente()` (~línea 32)
- `app/Models/Sale/Sale.php` (~línea 56) y `app/Models/Sale/Note.php` (~línea 60) → `client_address_id` en `$fillable`
- `app/Http/Controllers/Client/ClientController.php` (métodos nuevos o un `ClientAddressController` aparte)
- `routes/api.php` → bloque de clientes (~líneas 520-572)
- `app/Http/Controllers/Sale/SaleController.php` → `store()` (`Sale::create` ~661), `update()` (~1052, vuelve a copiar en ~1113)
- `app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php` → `store()` (validación ~339, `Sale::create` ~590)
- `app/Http/Controllers/AgenciaViajes/VentaDirectaController.php` → `facturarYCobrar()` (~299)
- `app/Http/Controllers/Advance/AdvanceController.php` → `store()` (validación ~153, `Sale::create` ~282)
- `app/Http/Controllers/Sale/NotaElectronicaController.php` → `store()` (copia de la venta ~237-258)
- `app/Services/AgenciaViajes/LimpiarOperacionPrueba.php` (~línea 60) → ya incluye `client_addresses`; verificar que el orden de borrado respete la FK nueva desde `sales`/`notes`
- `admin-start-kit/src/views/clients/ficha.vue` + `components/Clientes/DireccionClienteModal.vue` (nuevo)

## Criterios de aceptación
- [ ] **CA1** — Cliente con 2 direcciones extra → venta retail con `client_address_id` de "Sucursal Tarapoto" → `direccion_cliente` = esa dirección y el PDF A4 la imprime; la siguiente venta sin `client_address_id` → la fiscal. → test
- [ ] **CA2** — `client_address_id` de otro cliente, o inactiva → 422, en los 4 endpoints. → test
- [ ] **CA3** — Venta con dirección elegida, sin CDR, se edita y se envía → la dirección sigue siendo la elegida (no vuelve la fiscal). → test
- [ ] **CA4** — NC de esa venta sin `client_address_id` → hereda la dirección y el `client_address_id`; con otra dirección del mismo cliente → usa esa. → test
- [ ] **CA5** — Factura de reserva y anticipo con dirección elegida → la copian. → test
- [ ] **CA6** — Permisos: usuario con solo `register_sale` puede listar y agregar, no editar ni desactivar (403). → test
- [ ] **CA7** — Migración con una fila en `client_addresses` → falla con mensaje y no altera nada. → test o prueba manual descrita
- [ ] **CA8** — Ficha: agregar, editar, desactivar y reactivar una dirección en umbo (retail) y agencia-demo. → prueba funcional
- [ ] **CA9** — Suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: tabla de rutas de arriba (D3)
- [ ] Aislamiento de tenant: tabla del tenant (core)
- [ ] Validación de entrada / archivos: dirección del mismo cliente y activa; largos máximos
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica (el XML no lleva dirección)
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí (core). **Al desplegar:** antes, contar filas de `client_addresses` en cada tenant de producción; si hay alguna, parar

## Cómo verificar
```
php artisan test --filter=DireccionCliente
php artisan test
cd admin-start-kit && npm run type-check
```
Prueba funcional: umbo (retail) y agencia-demo, con un cliente con RUC: ficha del cliente →
"Otras direcciones" → agregar "Sucursal Tarapoto" y "Sucursal Yurimaguas", editar una,
desactivarla y reactivarla. El efecto en el comprobante se prueba por API en los tests (el
selector llega con T-107).

---

## Resultado
- **Commits:** ver `git log feat/T-065-cliente-otras-direcciones` (un commit: `feat(clientes): otras direcciones del cliente y dirección elegida en el comprobante (T-065)`).
- **Tests:** nuevos: `tests/Feature/DireccionClienteTest.php` (11: CA1–CA7, edición cambiando de cliente, CRUD, validación, FK) y 2 en `Fila13Fase1cFacturacionTest` (factura de reserva: CA5 y CA2). Suite completa: 2345 pasan, 0 fallan. `type-check`: sin errores nuevos (los 47 existentes son de archivos ajenos: portal, fake-backend). Migración aplicada con `tenants:migrate-verticales` en los tenants locales y en `sistemafe_test_migrations`.
- **CA8 (prueba funcional):** hecha con Playwright en **agencia-demo** (cliente RUC id 3): agregar 2 sucursales, editar, desactivar (queda atenuada al final) y reactivar; sin errores de consola. **Falta umbo (retail)**: no hay contraseña documentada de ningún usuario de umbo y no se reseteó; la ficha y el componente son los mismos para todos los giros. Pendiente que la pruebes tú o me pases la credencial.
- **Desviaciones de la tarea y por qué:**
  - El card "Otras direcciones" es un componente propio (`components/Clientes/OtrasDireccionesCliente.vue`) montado en `ficha.vue`, para no engordar el archivo de 530 líneas.
  - `AdvanceController::corregir()` (reemisión de un anticipo corregido) conserva el `client_address_id` del comprobante original; sin eso la corrección imprimiría la dirección fiscal sin avisar. No estaba en la tarea.
  - En `SaleController::update()`: si no llega `client_address_id` y el cliente no cambió, se conserva la dirección ya elegida (la tarea solo definía el caso de cambio de cliente).
  - Validación centralizada en `App\Services\Client\DireccionDelComprobante` (los 5 endpoints la usan). La factura de reserva responde 422 en JSON; los demás lanzan `ValidationException`.
  - El paso de `client_address_id` en `VentaDirectaController::facturarYCobrar()` (reenvío) no tiene test propio; lo cubre el de `ReservaFacturacionController`.

## Hallazgos fuera de alcance
- `LimpiarOperacionPrueba` no necesita cambios: borra `sales`/`notes` (hijas) y conserva `client_addresses`.
- Local: los 4 tenants retail + creditos tenían `client_addresses` vacía; al desplegar, contar filas en cada tenant de producción antes (ya en la lista de seguridad de la tarea).
