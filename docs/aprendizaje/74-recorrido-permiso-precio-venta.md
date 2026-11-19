# Recorrido: el permiso para cambiar el precio en la venta

19-nov-2026, tarea **T-109**. Construida por Sonnet, revisada por Opus en tres rondas, probada en
navegador en agencia-demo. Archivos:
- Migración `database/migrations/tenant/core/2026_11_19_200000_add_cambiar_precio_venta_permission.php`
- `app/Services/Ventas/PrecioVentaValidator.php` (nuevo)
- `SaleController.php`: `validarPrecioDeVenta()`, `validarBoletaSinDocumento()`, `validarConfiguracionCredito()`, `config()`
- `PermissionsDemoSeeder.php`, `AgenciaViajesRolesSeeder.php`
- Front: `TablaLineasVenta.vue`, `TotalesVenta.vue`, `register.vue`, `helpers/ventaRetail.ts`
- Tests: `tests/Feature/Ventas/T109PrecioYValidacionesVentaTest.php`

**Al desplegar:** `tenants:migrate-verticales` y `roles:sync-propietario` (los corre `deploy.sh`).
Antes, confirmar en producción los nombres de los roles administradores (§4).

---

## 1. La idea

Antes cualquiera con `register_sale` ponía el precio que quería. Ahora, **sin** el permiso
`cambiar_precio_venta`, cada línea lleva el precio que pone el sistema, y el backend lo comprueba:
esconderlo en la pantalla no basta, porque un request armado a mano saltaría la pantalla.

Además (para todos, con o sin permiso): boleta de más de S/ 700 sin DNI → 422, y venta a crédito a
un cliente sin documento → 422.

## 2. Qué se valida sin el permiso

```
SaleController::store()/update()  (antes de abrir la transacción)
└─ validarPrecioDeVenta()
     ├─ discount_global ≠ 0 → 422 (en update, si no cambió respecto de lo guardado, pasa)
     └─ PrecioVentaValidator::validar(), línea por línea:
          1. precio  ¿price_base = el del catálogo? (o el de la cotización de origen)
          2. importes ¿mto_valor_venta, subtotal, IGV, ISC, ICBPER, price_final salen de ese precio?
```

**1. El precio.** El front manda `price_base` **sin IGV**. El backend repite la cuenta de
`register.vue`: `price_company` si el cliente es empresa y tiene ese precio, si no `price_general`;
si el producto trae IGV incluido, se divide entre 1 + tasa. Tolerancia de un céntimo (el front no
redondea la división). Todo con `BigDecimal`, nunca floats.

Tres excepciones que valen como precio correcto:
- **Catálogo en 0** = producto «a precio variable» (los genéricos de Agencia: Boleto Aéreo, Tour…):
  se escribe a mano, no hay con qué comparar.
- **Venta desde una cotización comercial**: vale el precio cotizado, si la cotización es del mismo
  cliente y se puede convertir (`cotizacionValida()`). El payload ahora trae `commercial_quote_id`.
- **Al editar**, una línea que no cambió de precio respecto de lo guardado.

**2. Los importes.** Validar solo `price_base` no alcanzaba: el backend guarda `subtotal`, `igv`,
`price_final`… tal como llegan, así que se podía mandar el precio correcto con montos menores.
`importesIncoherentes()` rehace la cuenta de `updateItem()` del front (gravada, gratuita,
exonerada, IVAP, los tres tipos de ISC, ICBPER) y además exige descuento 0 si el producto no lo
admite (`is_discount ≠ 2`) y no más que `max_discount` si lo admite.

Lo que **no** se valida todavía: los totales de la cabecera de la venta → **T-114**.

## 3. La pantalla

`authStore.isPermitedRoute('cambiar_precio_venta')` → prop `puedeCambiarPrecio`:
- sin el permiso, la casilla de precio es de solo lectura y no aparece ▾ — salvo catálogo en 0;
- la línea de una cotización dice «Precio de la cotización»;
- `TotalesVenta` oculta «+ Descuento global».

Ojo al probar: el login toma el rol para la pantalla de `users.role_id`, no de Spatie. Un usuario
creado a mano sin `role_id` queda en 1 = Super-Admin y la pantalla se salta todos los permisos
(el backend no, valida con Spatie). Ver «Por analizar».

## 4. Quién tiene el permiso

- **Propietario**: con `roles:sync-propietario` (en cada deploy).
- **Roles administradores que ya existan** en el tenant: la migración se lo da a `Admin-General`,
  `Administrador de agencia` y `Admin Empresario` (no crea roles). Necesario porque en producción el
  Propietario todavía no tiene usuarios (T-078). Si un tenant llama distinto a su administrador,
  asignarlo a mano.
- Vendedores, cajeros y demás: no.

## 5. Para probarlo

```
php artisan test --filter=T109PrecioYValidacionesVentaTest
```
Prueba en navegador del 19-nov (agencia-demo): sin el permiso 15/15 casos, con el permiso 9/9
(precio bloqueado y editable, ▾, descuento de línea y global, precio 0, cotización, y 4 requests
armados que dan 422). Datos de prueba que quedaron: ventas 30-34 (notas de venta), productos
«PRUEBA T-109 Mouse/Cable», cotización `PRUEBA-T109-1`, rol «Prueba T-109 (sin precio)», usuario
`prueba-t109@agencia-demo.test` y su «Caja PRUEBA T-109» abierta.
