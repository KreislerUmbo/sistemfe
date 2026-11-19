# T-114 — Totales de la venta validados en el backend contra la suma de sus líneas

| | |
|---|---|
| **Módulo** | VEN |
| **Depende de** | T-109 (las líneas ya se validan sin el permiso de precio) |
| **Reglas** | CLAUDE.md «Ventas» (atomicidad de `update()`); exoneración Amazonía (`resolverTipAfeIgv()`) |
| **Decisiones** | 19-nov-2026, el usuario: va como tarea aparte de T-109 y aplica a **todos** (con o sin permiso) |
| **Rama** | `feat/T-114-totales-venta-backend` |

## Contexto
`SaleController::store()` (~799-819) y `update()` (~1177) guardan los totales de la cabecera
(`subtotal`, `igv`, `total`, `mto_oper_gravadas/exoneradas/inafectas/exportacion/gratuitas`,
`isc_total`, `icbper_total`, `ivap_total`, `total_impuestos`, `mto_imp_venta`, retención,
detracción, percepción…) tal como llegan del front. T-109 valida cada **línea** sin el permiso de
precio, pero con un request armado las líneas pueden cuadrar y la cabecera decir menos: la caja,
el PDF y el XML usan la cabecera. Revisión de T-109, 19-nov.

## Alcance
- Antes de abrir la transacción, recalcular la cabecera desde las líneas del payload con la **misma
  cuenta de `sumDetails()`** de `register.vue` (leerla entera; incluye descuento global prorrateado,
  gratuitas, exportación, IVAP, ISC, ICBPER, retención, detracción, percepción y anticipos) y
  rechazar con 422 si algún total no coincide (tolerancia de céntimos).
- Aplica con y sin el permiso `cambiar_precio_venta`: el permiso deja cambiar precios, no mandar
  totales que no salen de las líneas.
- Un servicio aparte (como `PrecioVentaValidator`), no más código dentro del controlador.
- `update()`: misma validación sobre el payload completo.

## Fuera de alcance
- Recalcular y **guardar** los totales en el backend en lugar de los del front (posible después;
  primero validar, para no cambiar documentos que hoy cuadran).
- Ventas creadas por Agencia (`ReservaFacturacionController`) y adelantos (`AdvanceController`):
  no pasan por `SaleController::store()`.

## Dónde tocar
- `admin-start-kit/src/views/sale/register.vue` → `sumDetails()` (solo leer: es la referencia)
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` → `store()` (~735), `update()` (~1127)
- `api-sistema-fe/app/Services/Ventas/` → servicio nuevo

## Criterios de aceptación
- [ ] **CA1** — Una venta armada por la pantalla (cada régimen: gravada, exonerada Amazonía,
  exportación, gratuita, IVAP, ISC, ICBPER, retención, detracción, percepción, con anticipo) → 200
  → un test por caso.
- [ ] **CA2** — Mismas líneas con `total` o `igv` rebajados → 422, con y sin el permiso de precio.
- [ ] **CA3** — Descuento global prorrateado: la cabecera cuadra → 200.
- [ ] **CA4** — `update()` con totales que no cuadran → 422 y nada cambia.
- [ ] **CA5** — La suite de ventas existente sigue en verde (si un fixture traía totales que no
  cuadran, corregir el fixture y anotarlo).

## Seguridad y correctitud
- [ ] Validación de entrada: totales recalculados en el backend.
- [ ] Dinero: `BigDecimal`, tolerancia de céntimos.
- [ ] Tributario sin fallback silencioso: un régimen que no se pueda reproducir → preguntar, no dejarlo pasar.
- [ ] `update()` sigue en una sola transacción.
- [ ] Lo demás: no aplica.

## Cómo verificar
```
php artisan test --filter=Sale
```
Ventas reales desde la pantalla en agencia-demo (boleta Amazonía, factura con detracción, venta con anticipo).

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
