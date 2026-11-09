# Recorrido: test que impide una ruta de Agencia sin permiso

09-nov-2026, tarea **T-093**. Origen: auditoría del vertical del 09-nov-2026. Las 246 rutas de
Agencia tenían permiso, pero nada lo garantizaba.

---

## 1. El problema

Un pedido a Agencia pasa por **dos barreras**:

1. **La ruta** (`routes/api.php`): `->middleware('permission:reservas.editar')` deja pasar solo a
   quien tiene ese permiso. Sin él, cualquier usuario logueado del tenant llega al controller.
2. **La Policy por fila** (`CotizacionPolicy`, `ReservaPolicy`): dentro del controller,
   `authorize('update', $reserva)` pregunta "¿es **tu** reserva, o tienes `ver_todas`?". Sin ella,
   un vendedor edita el trabajo de otro con solo cambiar el id en la URL.

Casi todos los tests de Agencia llaman al controller directo, **sin pasar por la ruta**. Quitar
un `->middleware('permission:…')`, o cambiarlo por un permiso más débil, no rompía ningún test.

## 2. Archivos

| Archivo | Qué hace |
|---|---|
| `api-sistema-fe/tests/Feature/AgenciaViajes/RutasAgenciaPermisosTest.php` (nuevo) | Barrida de la barrera 1 |
| `api-sistema-fe/tests/Feature/AgenciaViajes/PolicyFilaPorVendedorTest.php` (nuevo) | Barrera 2, un test por controller |

No se tocó código de la aplicación.

## 3. El recorrido

### 3.1 Barrera 1: la ruta (`RutasAgenciaPermisosTest`)

No usa base de datos. Lee la lista **real** de rutas que Laravel registró:

```php
foreach (Route::getRoutes()->getRoutes() as $ruta) {
    $controller = $ruta->getControllerClass();       // App\Http\Controllers\AgenciaViajes\...
    ...
}
```

Así, una ruta nueva entra sola al test, sin que nadie la agregue a una lista.

- **`permisosDe($ruta)`** recorre `gatherMiddleware()` (los middleware de la ruta y de su grupo) y
  saca los permisos de `permission:a|b`.
- **Toda ruta exige permiso.** Si alguna da `[]`, el test falla y la nombra:
  `PUT api/alternativa-items/{id} (…AlternativaItemController@update)`.
- **Única excepción:** `reporte-operativo-pdf`. Se abre en una pestaña nueva, sin token, con una
  URL firmada que solo genera `reporte-operativo/pdf-url` (esa sí exige permiso). El test comprueba
  que siga llevando `signed`; si un día deja de ser firmada, falla.
- **Permiso exacto por grupo** (`PERMISO_POR_GRUPO`): 40 reglas `[métodos, patrón de URI, permisos]`,
  por ejemplo `configuracion-*` → `agencia.configuracion`. Cambiar el permiso por otro más débil
  falla. La primera regla que coincide es la que vale, así que lo específico va arriba.
- **Una regla sin rutas también falla.** Si se renombra una ruta, la regla dejaría de vigilar en
  silencio; el test lo avisa: "Reglas que ya no coinciden con ninguna ruta".

### 3.2 Barrera 2: la Policy (`PolicyFilaPorVendedorTest`)

`escenario()` arma dos vendedores con **todos** los permisos de ruta, pero sin `ver_todas`:

1. El dueño crea una cotización con alternativa, ítem, reserva, ítem de reserva y pasajero.
2. **Control:** se comprueba que al dueño la Policy sí lo deja (`Gate::forUser($duenio)->allows(…)`).
   Sin este paso, una Policy que bloqueara a todos haría pasar todo. Ya pasó una vez: ver el
   comentario de `ReservaPolicy` sobre el scope por vendedor.
3. Se actúa como el otro vendedor y se llama al controller: `esperarBloqueo()` exige
   `AuthorizationException`, que Laravel convierte en 403.

Hay un test por controller (cotización, alternativa, ítem, reserva, ítem de reserva, asignación
de pasajeros, pasajero, facturación, anticipo, modificación). No hay uno por método: lo que se
prueba es que ese controller sigue preguntando a la Policy.

Los otros 7 controllers con Policy ya tenían su prueba de vendedor ajeno en su propio archivo.
La cabecera del test lista dónde está cada una. Si se crea un controller nuevo con Policy, su
prueba va ahí o en su archivo.

### 3.3 ¿Y el permiso de facturar?

Facturar una reserva pasa por **tres** chequeos:

1. la ruta: `reservas.editar`;
2. la Policy: que la reserva sea tuya;
3. dentro del controller: `emitir_factura` o `emitir_boleta`, según el comprobante
   (`ReservaFacturacionController::PERMISOS_EMISION`).

El tercero lo prueba `ReservaFacturacionTest::test_rechaza_sin_permiso_de_emision`.

## 4. Cómo se verificó

- `php artisan test --filter="RutasAgenciaPermisos|PolicyFilaPorVendedor"`: 14 pasan.
- A mano, rompiendo y revirtiendo cada barrera:
  - sin el `permission:` de `PUT alternativa-items/{id}`, el test falla nombrando la ruta;
  - sin el `authorize` de `ReservaModificacionController::store`, falla solo ese test;
  - con `ReservaPolicy` bloqueando a todos, falla el control del dueño.

## 5. Lo que queda fuera

- Pasar los tests por el stack completo de tenancy (`tenant`, `tenant.token`): ningún test lo hace.
- Convertir los tests existentes de Agencia a peticiones HTTP.
