# T-109 — Permiso para cambiar el precio en la venta y validaciones en el backend

| | |
|---|---|
| **Módulo** | VEN + PLA (permiso nuevo) |
| **Depende de** | T-106 (la pantalla bloquea la casilla y muestra los motivos) |
| **Reglas** | roles Propietario (`roles:sync-propietario`); lo que la pantalla bloquea, el backend lo rechaza |
| **Decisiones** | 18-nov-2026: solo quien tiene el permiso cambia el precio en la venta; sin permiso no hay ▾ ni precio a mano. La afectación IGV por línea queda libre por ahora. `docs/requerimientos/retail-presentaciones-y-precios.md` §7 |
| **Rama** | `feat/T-109-permiso-precio-validaciones` |

## Contexto
Hoy cualquiera con `register_sale` cambia el precio de una línea; no hay ningún permiso sobre el
precio. Además, la pantalla nueva (T-106) bloquea casos que el backend acepta: boleta de más de
S/ 700 sin identificar al cliente (SUNAT lo exige) y crédito a «Clientes varios».

## Alcance
- **Permiso nuevo** `cambiar_precio_venta` (migración de permisos como
  `2026_07_19_150500_add_document_emission_permissions.php`; agregar a `PermissionsDemoSeeder`). El
  Propietario lo recibe con `roles:sync-propietario`; ningún otro rol lo recibe solo.
- **Backend** (`SaleController::store()` y `update()`): sin el permiso, el precio de cada línea debe
  ser el que corresponde (`price_general`, o `price_company` si el cliente es empresa). Ojo: el
  front manda `price_base` **sin IGV** calculado desde el precio de catálogo según `include_igv`
  (`register.vue` ~1192): comparar con la misma conversión y una tolerancia de redondeo de céntimos.
  Si no coincide → 422 «No tienes permiso para cambiar el precio».
  En `update()`, las líneas que no cambiaron de precio respecto de lo guardado no se rechazan.
- **Validaciones nuevas** (422 con mensaje claro):
  - Boleta (03) con total **mayor a S/ 700** (o su equivalente si es en dólares, con el T.C. de la
    venta) y cliente sin documento → «SUNAT pide el DNI del cliente en boletas de más de S/ 700».
  - Venta a crédito (`type_payment = 2`) con cliente sin documento → en `validarConfiguracionCredito()`.
- **Pantalla**: el login ya trae los permisos (`getAllPermissions()`); sin el permiso, la casilla de
  precio es de solo lectura y no aparece ▾; con el permiso, ▾ y precio a mano («Precio manual»).

## Fuera de alcance
- Permiso para la afectación IGV (decisión: libre por ahora).
- Listas de precios y regla mayorista (plan aparte).
- Límite de descuento por línea: ya existe (`max_discount`).

## Dónde tocar
- `api-sistema-fe/database/migrations/tenant/core/` → migración nueva de permiso
- `api-sistema-fe/database/seeders/PermissionsDemoSeeder.php`
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` → `store()` (~640), `update()`,
  `validarConfiguracionCredito()`
- Componente de líneas de T-106 (`admin-start-kit/src/components/Sales/`)

## Criterios de aceptación
- [ ] **CA1** — Sin el permiso, `POST sales` con un precio distinto del de catálogo → 422; con el
  precio de catálogo (general o empresa según cliente) → 200 → test de feature.
- [ ] **CA2** — Con el permiso, cualquier precio → 200 → test.
- [ ] **CA3** — Boleta de S/ 700.01 sin documento → 422; de S/ 700.00 → 200; con DNI → 200 → test.
- [ ] **CA4** — Crédito con cliente sin documento → 422 → test.
- [ ] **CA5** — Tras `roles:sync-propietario`, el Propietario tiene el permiso; otros roles no.
- [ ] **CA6** — Editar una venta vieja sin tocar precios, sin el permiso → 200 → test.

## Seguridad y correctitud
- [ ] Permisos: validados en backend, no solo ocultos en pantalla.
- [ ] Aislamiento de tenant: permiso en cada tenant (migración tenant).
- [ ] Dinero: comparación con tolerancia de céntimos, sin floats sueltos.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`; en deploy, `roles:sync-propietario`.
- [ ] `update()` sigue en una sola transacción.
- [ ] Lo demás: no aplica.

## Cómo verificar
```
php artisan test --filter=Sale
```
Usuario vendedor sin el permiso y Propietario, en un tenant retail.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
