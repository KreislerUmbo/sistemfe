# T-112 — Elegir de nuevo un producto ya personalizado en la venta abre otra fila

| | |
|---|---|
| **Módulo** | VEN |
| **Depende de** | T-108 (descripción de línea), T-109 (precio a mano) |
| **Reglas** | ninguna |
| **Decisiones** | Opción A, elegida por el usuario el 19-nov-2026 (sin botón «Duplicar línea») |
| **Rama** | `feat/T-112-fila-nueva-linea-personalizada` |

## Contexto
Hoy, elegir un producto que ya está en la venta **siempre suma la cantidad** a su línea
(`register.vue::agregarProducto()`, `findIndex` por `product.id`). Con T-108 cada línea puede llevar su
propia descripción, y el usuario necesita vender el mismo servicio dos veces con descripción y precio
distintos. Ejemplo: «Mantenimiento de equipo de cómputo» → línea 1 «Mantenimiento de 2 PC + cambio de
pasta térmica», 2 × S/ 80; línea 2 «Mantenimiento simple», 2 × S/ 60.

## Alcance
- **Línea intacta** = sin `descripcion_linea` y con el precio que pondría el sistema: no
  `precio_manual` y `price_base` igual a `precioBaseDe(product, cliente)`.
- Al elegir un producto: si hay una línea **intacta** de ese producto, se suma a esa línea (como hoy).
  Si no la hay (no está, o todas sus líneas están personalizadas), se agrega una **fila nueva**.
- La regla va como función pura en `helpers/ventaRetail.ts` (con tests), para que la reuse la pantalla
  de edición (T-110).
- **Stock por producto:** el control de stock (aviso al agregar y motivo del botón Cobrar) compara la
  **suma de cantidades de todas las líneas del producto** con su stock, no cada línea por separado.

## Fuera de alcance
- Botón «Duplicar línea» (opción B): se descartó; se retoma solo si hace falta separar seguido líneas
  ya sumadas.
- Validar stock en el backend: hoy `SaleController::store()/update()` solo descuenta (`decrement`) sin
  validar, y eso ya pasaba antes. Queda en «Por analizar».
- La pantalla de edición (`edit.vue`): la toma T-110.
- Backend y payload: sin cambios (`sale_details` ya admite varias líneas del mismo producto).

## Dónde tocar
- `admin-start-kit/src/views/sale/register.vue` → `agregarProducto()` (~línea 473: el `findIndex` y
  el aviso de stock al sumar o agregar).
- `admin-start-kit/src/views/sale/register.vue` → `motivoCobro` (~línea 1414: `lineaSinStock` por línea).
- `admin-start-kit/src/views/sale/register.vue` → `precioBaseDe()` (~línea 402), para comparar el precio.
- `admin-start-kit/src/helpers/ventaRetail.ts` → funciones nuevas (p. ej. `indiceLineaIntacta()`,
  `cantidadEnVenta(productId)`) y sus tests en `ventaRetail.test.ts`.

## Criterios de aceptación
- [ ] **CA1** — Dado un producto sin personalizar en la venta, cuando se lo elige de nuevo, entonces su
  cantidad sube en 1 y no aparece otra fila (escanear 3 veces = 1 fila × 3) → test de `ventaRetail` + manual.
- [ ] **CA2** — Dada una línea con descripción editada, cuando se elige el mismo producto, entonces
  aparece una fila nueva con el nombre del catálogo, cantidad 1 y precio del sistema → test + manual.
- [ ] **CA3** — Igual que CA2 con una línea de precio a mano (o con otro precio de la lista ▾) → test.
- [ ] **CA4** — Dadas una línea personalizada y una intacta del mismo producto, cuando se lo elige,
  entonces se suma a la intacta → test.
- [ ] **CA5** — Dado un bien con stock 5 en dos filas (3 + 2), cuando se lo elige otra vez o se sube una
  cantidad, entonces avisa «Solo hay 5 unidades» y Cobrar queda deshabilitado con ese motivo → test + manual.
- [ ] **CA6** — El ejemplo del contexto (2 × 80 y 2 × 60, mismo servicio) se guarda como boleta con dos
  líneas y cada una sale con su descripción en el PDF → manual en agencia-demo o umbo.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas).
- [ ] Aislamiento de tenant: no aplica.
- [ ] Validación de entrada / archivos: no aplica.
- [ ] Concurrencia (`lockForUpdate`): no aplica.
- [ ] Dinero en centavos: no aplica (no cambian los cálculos).
- [ ] Fechas con `HoraPeru`: no aplica.
- [ ] Tributario sin fallback silencioso: cada fila nueva resuelve su `tip_afe_igv` con `resolverTipAfeIgv()`, como hoy.
- [ ] Sin `v-html` sin sanitizar: no aplica.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica.

## Cómo verificar
```
cd C:/xampp/htdocs/sistemfe/admin-start-kit && npx vitest run src/helpers/ventaRetail.test.ts
```
Manual: `http://agencia-demo.sistemafe.test:5173` → Ventas → Registrar; un servicio con dos
descripciones (CA6) y un bien con stock bajo en dos filas (CA5).

---

## Resultado
- **Commits:** ver `git log feat/T-112-fila-nueva-linea-personalizada` (1 commit: `feat(ventas): …(T-112)`).
- **Tests:** `npx vitest run src/helpers/ventaRetail.test.ts` → 29 pasan (7 nuevos: CA1-CA5 y servicio sin stock). `vue-tsc --noEmit` sin errores. **No hecho:** `build`, prueba manual en navegador (CA1-CA6 manual, incl. el PDF de CA6): pendiente en la revisión.
- **Desviaciones de la tarea y por qué:**
  - Se agregó `productoSinStock()` además de `indiceLineaIntacta()`/`cantidadEnVenta()`, para que `motivoCobro` use la suma por producto.
  - Si `agregarProducto()` recibe `opciones.precio` (ítems de una cotización), siempre abre fila propia: ese precio es «a mano» y no calza con una línea intacta. Antes sumaba a la línea existente ignorando el precio.
  - El aviso de stock ahora se evalúa antes de decidir fila (suma de todas las líneas + lo que se agrega).

## Hallazgos fuera de alcance
- La insignia «Solo hay N» de cada fila (`TablaLineasVenta.vue::sinStock`) sigue comparando por línea: con 3+2 y stock 5 no sale en ninguna fila, aunque el aviso y el motivo de Cobrar sí cuentan la suma. Cosmético; tarea aparte si se quiere.
