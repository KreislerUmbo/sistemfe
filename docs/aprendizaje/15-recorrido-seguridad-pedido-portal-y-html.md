# Recorrido: el pedido del portal y el HTML guardado por usuarios (T-068)

27-oct-2026, tarea **T-068** (`docs/tareas/T-068-seguridad-pedidos-portal-y-v-html.md`), pendiente de
la auditoría de seguridad del 08-oct.

---

## 1. Los problemas

1. **El navegador ponía el precio.** El checkout del portal mandaba `precio` por producto,
   `subtotal`, `descuento` y `total`, y `OrderController::store()` los guardaba tal cual. Con las
   herramientas del navegador, cualquiera podía pedir un producto de S/ 7 000 a S/ 0,01.
2. **Un invitado nuevo no podía comprar.** El controlador creaba el cliente **antes** de validar,
   y sin tipo de documento (el portal no lo manda; la columna es obligatoria): error 500.
3. **HTML de usuarios ejecutado en otros navegadores.** Descripciones de paquetes, proveedores,
   productos del portal y manuales se mostraban con `v-html` tal como se guardaron. Un
   `<img src=x onerror="...">` guardado por alguien corría en el navegador de quien lo abría.

## 2. Archivos

| Archivo | Nuevo o cambiado | Qué hace |
|---|---|---|
| `api-sistema-fe/app/Http/Requests/Portal/PedidoPortalRequest.php` | **nuevo** | valida el pedido; no valida precios porque no se leen |
| `api-sistema-fe/app/Http/Controllers/OrderController.php` | cambiado | calcula precios y total; valida antes de crear al cliente |
| `api-sistema-fe/tests/Feature/PedidoPortalTest.php` | **nuevo** | 5 tests |
| `admin-start-kit/src/views/portal/Checkout.vue` | cambiado | manda solo producto y cantidad; sin el cupón de la plantilla |
| `admin-start-kit/src/helpers/sanearHtml.ts` (+ `.test.ts`) | **nuevo** | limpia HTML con DOMPurify |
| `paquetes/detalle.vue`, `proveedores/detalle.vue`, `portal/Producto.vue`, `recursos/manual.vue` | cambiado | `v-html="sanearHtml(...)"` |

## 3. El recorrido de un pedido

```
Checkout.vue ── { items: [{id, cantidad}], datos del cliente } ──► POST /portal/orders
   │
PedidoPortalRequest ── valida (si falla: 422, nada se guarda)
   │
OrderController::store()
   ├─ busca los productos activos (state = 1), como el catálogo
   ├─ precio de cada uno = products.price_general, en centavos
   ├─ subtotal = Σ precio × cantidad;  descuento = 0;  total = subtotal
   └─ transacción: cliente (logueado / por correo / por DNI / nuevo) → orders → order_items
```

**¿Por qué centavos?** `50.10 * 3` en coma flotante puede dar `150.29999…`. Multiplicando enteros
(`5010 * 3 = 15030`) y dividiendo por 100 una sola vez al guardar, no hay errores de redondeo.
Es la regla de dinero de `docs/proceso.md` §4.

**¿Por qué validar antes de crear el cliente?** Si los datos están mal, no debe quedar nada
guardado. Antes, un pedido rechazado dejaba igual un cliente nuevo creado.

## 4. `sanearHtml()`

DOMPurify recorre el HTML y deja solo etiquetas y atributos seguros: conserva negritas, listas y
enlaces del editor Quill, y quita `<script>`, atributos `on…` y enlaces `javascript:`. Se aplica
**al mostrar**, no al guardar: así también se limpia lo que ya estaba guardado.

En las pruebas, Vitest corre sin navegador y DOMPurify necesita uno; por eso el test lleva
`// @vitest-environment jsdom` en la primera línea (jsdom simula el navegador).

## 5. Tests

| Test | Comprueba |
|---|---|
| `el_precio_lo_pone_el_servidor_no_el_navegador` | precio 1 enviado, guardado 50 |
| `un_descuento_enviado_por_el_navegador_no_se_aplica` | cupón guardado, descuento 0 |
| `producto_inactivo_inexistente_o_cantidad_cero_se_rechazan` | 422 y ningún pedido guardado |
| `invitado_sin_cuenta_se_registra_como_cliente` | el caso que antes daba 500 |
| `invitado_con_dni_de_un_cliente_existente_usa_ese_cliente` | no choca con el índice único de documento |
| `sanearHtml.test.ts` (4) | quita lo peligroso y conserva el formato |

## 6. Ejercicios

1. Abre `Checkout.vue` y busca `buscarDni`. ¿Qué hace con un DNI real? ¿Por qué no debe llegar así
   a producción? (Está en "Hallazgos" de la tarea.)
2. Si mañana existieran cupones de verdad, ¿dónde iría la regla del descuento: en `Checkout.vue`,
   en `OrderController` o en ambos? ¿Cuál manda?
3. Busca otro lugar del sistema donde el frontend mande un monto que el servidor guarda sin
   recalcular. (Pista: compara con cómo `ReservaFacturacionController` calcula los totales.)
