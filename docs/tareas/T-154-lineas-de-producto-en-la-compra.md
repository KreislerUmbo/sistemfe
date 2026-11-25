# T-154 — Líneas de producto en la compra: stock y costo promedio

| | |
|---|---|
| **Módulo** | COM + VEN |
| **Depende de** | T-135, T-153 |
| **Reglas** | «Dinero y caja» de `CLAUDE.md` (aplicada al stock con el kárdex de T-153) |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7f (T-151, 25-nov-2026) |
| **Rama** | `feat/T-154-lineas-producto-compra` |

## Contexto
Una tienda compra mercadería y hoy el stock solo sube a mano. Con esta tarea una compra (T-135)
puede detallar productos: suman stock por el servicio único (T-153) y actualizan el costo
promedio del producto. Sin líneas, la compra sigue siendo solo montos (gastos y Agencia).

## Alcance
- `compra_lineas`: compra, producto, cantidad, `presentacion_id` (nullable, sin tabla todavía),
  `factor` (copia, 1 por defecto), valor unitario sin IGV, IGV, total de la línea.
- `products.costo_promedio` (nullable) en `tenant/core/`.
- Al guardar una compra con líneas: suma `cantidad × factor` al stock por el servicio de T-153
  (tipo `compra`, con costo unitario de la unidad base) y recalcula el promedio ponderado. Si el
  stock era ≤ 0, el costo nuevo es el de la compra.
- Costo de la línea: valor sin IGV si el IGV va a crédito fiscal; con IGV si no (§3.7f punto 2).
- La suma de las líneas se compara con la base del comprobante: si no cuadra, aviso (no bloqueo).
- Anular una compra con líneas: resta lo sumado (tipo `compra_anulada`) y recalcula el promedio
  quitando esa entrada; si el stock no alcanza, 422 con mensaje que explica la salida (NC del
  proveedor o ajuste).
- NC de compra (07) con líneas: resta stock al costo promedio vigente (tipo `nc_compra`).
- Productos que no controlan stock: pueden ir en una línea, no mueven stock ni costo.
- Pantalla de compra: sección opcional «Productos» con buscador de productos.

## Fuera de alcance
- Presentaciones (la línea queda preparada con `presentacion_id` y `factor`).
- Costo en cada línea de venta y ganancia por venta; reportes de valorización.
- Editar una compra con líneas ya guardada: se anula y se registra de nuevo (confirmar al construir
  T-135 si la compra se puede editar; si se puede, las líneas se tratan como la edición de ventas:
  solo la diferencia).

## Dónde tocar
- Lo que cree T-135 (modelo, controlador y pantalla de `compras`).
- El servicio de stock de T-153 (tipos `compra`, `compra_anulada`, `nc_compra`, con costo).
- `api-sistema-fe/app/Models/Product/Product.php` → `costo_promedio`.

## Criterios de aceptación
- [ ] **CA1** — Producto con 10 en stock a S/ 5 + compra de 10 a S/ 6 (sin IGV) → stock 20, costo
  promedio 5.50, fila de kárdex `compra` con costo 6 y saldo 20.
- [ ] **CA2** — Compra sin líneas no toca stock ni kárdex.
- [ ] **CA3** — Anular la compra de CA1 sin ventas en medio → stock 10, promedio 5.00. Con 15 vendidos
  en medio → 422 con mensaje.
- [ ] **CA4** — NC de compra con 2 unidades → stock baja 2, promedio no cambia.
- [ ] **CA5** — Línea con factor 12 y cantidad 2 suma 24 y el costo unitario base es el de la línea ÷ 24.
- [ ] **CA6** — Dos compras simultáneas del mismo producto dejan stock y promedio correctos.

## Seguridad y correctitud
- [ ] Permisos: los de Compras (T-135).
- [ ] Aislamiento de tenant: tablas de tenant.
- [ ] Validación: producto existente, cantidad > 0, factor > 0.
- [ ] Concurrencia: `lockForUpdate` del producto (servicio de T-153), dentro de la transacción de la compra.
- [ ] Dinero en centavos para los montos de la línea; costo unitario con más decimales (definir escala).
- [ ] Fechas: no aplica más allá de T-135.
- [ ] Tributario: costo con o sin IGV según crédito fiscal, sin fallback silencioso (si no se sabe, preguntar).
- [ ] `v-html`: no aplica.
- [ ] Migraciones nuevas en `tenant/core/` + `tenants:migrate-verticales`.

## Cómo verificar
```
php artisan test --filter="Compra|Stock|Kardex"
```
Tenant de tienda: compra con líneas, venta, anulación y NC de compra; revisar kárdex y costo.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
