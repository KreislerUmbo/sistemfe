# Recorrido: otras direcciones del cliente y la dirección del comprobante

18-nov-2026, tarea **T-065** (H61). Origen: obs. 18 de
`docs/requerimientos/observaciones-pruebas-usuario.md` (decisión del 27-oct-2026) y las decisiones
D1-D3 del 18-nov-2026: se copia solo la línea de dirección, igual que la fiscal; `principal` y `state`
se borran; agregar una dirección lo puede quien emite. El selector en las pantallas de emisión es
**T-107** (19-nov-2026, §3.4).
Archivos:
- Migración `database/migrations/tenant/core/2026_11_18_100000_adapt_client_addresses_and_add_client_address_id.php`
- `api-sistema-fe/app/Models/Client/ClientAddress.php` (nuevo) y `Client::direcciones()`
- `api-sistema-fe/app/Http/Controllers/Client/ClientAddressController.php` y `Http/Requests/Client/ClientAddressRequest.php` (nuevos)
- `api-sistema-fe/app/Services/Client/DireccionDelComprobante.php` (nuevo: la validación única)
- `api-sistema-fe/app/Models/Concerns/CopiaDatosDelCliente.php` (`copiarDatosDelCliente()`, `direccionElegida()`)
- Emisión: `SaleController` (`store()`, `update()`), `ReservaFacturacionController::store()`,
  `VentaDirectaController::store()`, `AdvanceController` (`store()`, `corregir()`), `NotaElectronicaController::store()`
- `admin-start-kit/src/components/Clientes/OtrasDireccionesCliente.vue` y `DireccionClienteModal.vue` (nuevos),
  `views/clients/ficha.vue`, `services/admin/clienteService.ts`
- Tests: `tests/Feature/DireccionClienteTest.php` y 2 en `tests/Feature/AgenciaViajes/Fila13Fase1cFacturacionTest.php`
- T-107: `admin-start-kit/src/components/Clientes/SelectorDireccionComprobante.vue` (nuevo), usado en
  `views/sale/register.vue`, `edit.vue`, `nota-create.vue`, `views/advances/create.vue`,
  `views/agencia-viajes/venta-directa.vue` y `components/AgenciaViajes/FacturarReserva{Simple,Especial}Modal.vue`;
  `SaleResource` expone `client_address_id`

**Al desplegar:** 1 migración de `core/` → `php artisan tenants:migrate-verticales` y revisar su
salida. **Antes**, contar las filas de `client_addresses` en cada tenant de producción: si alguno
tiene filas, la migración se detiene con un mensaje y `deploy.sh` sigue igual, así que el código
nuevo quedaría sin sus columnas. Sin cambios de menú ni de permisos nuevos.

---

## 1. La idea

Un cliente con RUC puede tener sucursales y pedir que su factura lleve la dirección de la sucursal
que compró. Antes había una sola dirección (`clients.address`) y cambiarla para una factura
cambiaba todas las demás. Ahora:

| Qué | Dónde vive |
|---|---|
| La dirección **fiscal** | `clients.address`, como siempre |
| Las **otras** (sucursales), con nombre | `client_addresses`, una fila por dirección |
| Qué dirección eligió cada comprobante | `sales.client_address_id` / `notes.client_address_id` (NULL = la fiscal) |
| El texto que se imprime | `sales.direccion_cliente` / `notes.direccion_cliente` (la copia de T-064) |

La clave es la última fila: el PDF **siempre** imprime `direccion_cliente`. T-065 no tocó los PDF;
solo cambió **qué texto se copia** ahí.

## 2. Recorrido en pantalla → tabla

### 2.1 Ficha del cliente → card «Otras direcciones»

`ficha.vue` monta `OtrasDireccionesCliente.vue` en todos los giros. Al abrir:

```
GET /clients/{id}/direcciones?todas=1   → ClientAddressController::index()
```

Devuelve las activas primero; las desactivadas se ven atenuadas al final. Sin `?todas=1` (lo que
usarán las pantallas de emisión en T-107) solo vienen las activas.

### 2.2 «Agregar» → modal

`DireccionClienteModal.vue` pide nombre, dirección, región/provincia/distrito (los mismos selects de
ubigeo que el cliente) y referencia. Al guardar:

```
POST /clients/{id}/direcciones   → ClientAddressRequest (valida) → ClientAddressController::store()
```

Nace siempre `activo = true`. El modal es reutilizable: T-107 lo abre desde «+ Agregar dirección».

### 2.3 Editar y desactivar

```
PUT /client-direcciones/{id}   → ClientAddressController::update()
```

Desactivar es el mismo `PUT` con `activo: false`. **No hay DELETE:** una dirección que usó un
comprobante no se borra nunca (además la FK desde `sales`/`notes` lo impide).

### 2.4 Permisos (en `routes/api.php`)

| Acción | Permiso |
|---|---|
| Listar | `list_client`, `edit_client` o cualquier permiso de emitir (`register_sale`, `edit_sale`, `nota_electronica`, `register_advance`, `reservas.editar`) |
| Agregar | `edit_client` o cualquier permiso de emitir |
| Editar / desactivar | solo `edit_client` |

Por eso un cajero puede agregar la sucursal en el momento de cobrar, pero no corregir ni apagar las
que existen. En la ficha, `puedeAgregar` y `puedeEditar` esconden los botones con el mismo criterio.

## 3. Lo que pasa al emitir

Todos los endpoints de emisión aceptan `client_address_id` opcional. El camino es siempre el mismo:

```
request.client_address_id
   → DireccionDelComprobante::validar(id, client_id)      ¿existe? ¿es de ESTE cliente? ¿está activa?  si no → 422
   → Sale::create([... 'client_address_id' => $id ...])
   → hook creating de CopiaDatosDelCliente
   → copiarDatosDelCliente(): direccion_cliente = dirección elegida ?? clients.address
```

| Pantalla | Endpoint | Nota |
|---|---|---|
| Venta retail | `SaleController::store()` / `update()` | |
| Factura de reserva | `ReservaFacturacionController::store()` | responde el 422 en JSON, como el resto del método |
| Venta directa con cobro | `VentaDirectaController::store()` | solo reenvía el campo a la factura de reserva |
| Anticipo | `AdvanceController::store()` | `corregir()` conserva la dirección del comprobante que corrige |
| Nota de crédito/débito | `NotaElectronicaController::store()` | ver §3.5 |

### 3.1 Por qué la dirección elegida no se pierde

T-064 vuelve a copiar los datos del cliente al **editar** y al **enviar** un comprobante que todavía
no tiene CDR. Si eso copiara siempre `clients.address`, la sucursal elegida se perdería al enviar.
`copiarDatosDelCliente()` mira primero `client_address_id` (`direccionElegida()`), y solo si está
vacío usa la fiscal. `direccionElegida()` además exige que la dirección sea del mismo `client_id`:
un id viejo de otro cliente nunca imprime la dirección ajena.

### 3.2 Casos al editar una venta (`SaleController::update()`)

| Llega en el request | Resultado |
|---|---|
| No llega `client_address_id`, mismo cliente | se conserva la elegida |
| No llega, **otro** cliente | `NULL` → fiscal del cliente nuevo |
| Llega `null` | `NULL` → fiscal |
| Llega la **misma** que ya tenía, aunque se desactivó después | se acepta (corregir un precio no se bloquea) |
| Llega otra, desactivada o de otro cliente | 422 |

Una venta con XML o CDR no se puede editar (`update()` lo corta al inicio), así que la copia nunca
deja de coincidir con lo enviado a SUNAT.

### 3.3 Desactivar no cambia lo ya emitido

`activo` solo filtra lo que se puede **elegir**. Un comprobante sin enviar que ya tenía esa dirección
la sigue imprimiendo, también al enviarlo.

### 3.4 En pantalla: «Dirección del comprobante» (T-107)

`SelectorDireccionComprobante.vue` es el **único** campo de dirección al emitir (decisión del usuario,
19-nov: antes la venta tenía además un texto «Dirección» editable que no se enviaba al backend; se quitó).

| Situación | Qué muestra |
|---|---|
| Sin cliente | deshabilitado, «Elige un cliente» |
| Cliente sin otras direcciones | «Fiscal — <clients.address>» y «+ Agregar dirección» |
| Cliente con sucursales | la fiscal (elegida por defecto) y cada sucursal activa como «<nombre> — <dirección>» |
| Editar una venta | la que tenía guardada, aunque se haya desactivado después («(desactivada)») |

- `v-model` = `client_address_id`; `null` = la fiscal. Al **cambiar** de un cliente a otro vuelve a `null`;
  en la primera carga (editar una venta) respeta el valor guardado.
- «+ Agregar dirección» abre `DireccionClienteModal.vue` (el mismo de la ficha) y deja elegida la nueva.
- En los modales de factura de reserva la primera opción dice solo «Fiscal»: el cliente que reciben no
  trae su dirección.

### 3.5 La nota de crédito o débito

`nota-create.vue` propone la dirección de la venta que modifica y manda **siempre** el campo. En
`NotaElectronicaController::store()`:

| Se elige | `direccion_cliente` de la nota |
|---|---|
| La **misma** que la venta (fiscal = fiscal, o la misma sucursal) | el texto copiado en la venta (T-064), aunque el cliente o la sucursal se hayan editado después |
| Otra sucursal del cliente | el texto de esa sucursal |
| De sucursal a la fiscal | la fiscal **actual** del cliente (la venta nunca tuvo la fiscal copiada) |
| No se manda el campo (API) | hereda la de la venta |

La primera fila es la importante: una NC hecha desde la pantalla no debe imprimir una dirección distinta
de la factura que modifica solo porque alguien corrigió el cliente (fue una regresión encontrada en la
revisión de T-107).

## 4. Qué NO hace

- La dirección **no va al XML** (decisión del 27-oct: basta con el PDF). `GreenterService::getCliente()` no cambió.
- El ticket de 80 mm no imprime dirección, ni antes ni ahora.

## 5. Para probarlo

```
php artisan test --filter=DireccionClienteTest
```

A mano: ficha de un cliente con RUC → «Otras direcciones» → agregar «Sucursal Tarapoto», editarla,
desactivarla (queda atenuada al final) y reactivarla. Probado en agencia-demo; en umbo (retail),
pendiente.
