# T-014 — Forma de pago y n.º de operación en el PDF del comprobante

| | |
|---|---|
| **Módulo** | VEN (todos los giros) |
| **Depende de** | T-010 (hecha: `sale_payments.nro_operacion`, `moneda_pago`, `monto_pagado`, `tipo_cambio`; `sales.vuelto_*`) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 2 |
| **Rama** | `feat/T-014-pdf-forma-de-pago` |
| **Implementa** | otro agente (Sonnet), **1 commit**. Análisis, tests y revisión (incluida la verificación visual): Claude Opus, 29-oct-2026 |

## Contexto (verificado contra el código el 29-oct-2026)
El requerimiento dice que la forma de pago "no sale" en el PDF: **no es exacto**. Ya hay un bloque
**"FORMA DE PAGO"** en las dos plantillas, visible solo si la venta tiene pagos:
- `resources/views/pdf/a4.blade.php` ~395-418: tabla `pagos-table` con Método | Fecha | Monto.
- `resources/views/pdf/ticket80mm.blade.php` ~217-228: "MÉTODO (dd/mm/aaaa)" y el monto.

Lo que falta o está mal:
- Muestra el **código** (`YAPE`), no el nombre de `payment_methods`. Y los nombres del catálogo
  traen **emoji** ("📱 Yape", `PaymentMethodSeeder`): **DomPDF no los dibuja** (ya pasó con otro
  glifo en el PDF de cotización), así que hay que quitarlos al imprimir.
- No muestra el **n.º de operación**.
- El monto es `amount` (equivalente en la moneda de la venta): un pago en dólares de una venta en
  soles sale como "S/ 3,750.00" en vez de lo que el cliente entregó ("US$ 1,000.00, T.C. 3.75").
- No muestra el **vuelto** (`sales.vuelto_monto`/`vuelto_moneda`, T-010).
- Ticket 80 mm: `SaleController::pdf()` (~`$alto_estimado`) calcula el alto a mano; las líneas
  nuevas tienen que sumarse o el final del ticket se corta.

`SaleController::pdf()` ya carga `sale_payments`.

## Diseño (contrato fijo: lo usan los tests)
En `app/Models/Sale/Sale.php`, junto a `resumenImpresion()`:
```php
// T-014: filas del bloque "FORMA DE PAGO" (A4 y ticket), en el orden de date_payment y id.
// forma: nombre de payment_methods sin emoji ni símbolos al inicio (si el código no está en el
//        catálogo, el código tal cual); operacion: nro_operacion o null;
// fecha: d/m/Y; monto: lo entregado, en moneda_pago ("S/ 300.00", "US$ 1,000.00");
// tipo_cambio: "T.C. 3.75" solo si moneda_pago difiere de la moneda de la venta, si no null.
/** @return \Illuminate\Support\Collection<int, array{forma: string, operacion: ?string, fecha: string, monto: string, tipo_cambio: ?string}> */
public function pagosImpresos(): Collection

// "Vuelto S/ 50.00" si la venta tiene vuelto; null si no.
public function vueltoImpreso(): ?string
```
- Quitar el emoji: `trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $nombre))`.
- Formato de montos: `number_format($monto, 2)` con coma de miles (como el resto del PDF);
  símbolo `S/` o `US$`. Tipo de cambio sin ceros de más ("3.75", "3.7512").
- Un pago sin `moneda_pago` (no debería haber tras T-010): la moneda de la venta.

**Plantillas** (solo mostrar lo que devuelven los métodos; nada de lógica nueva en Blade):
- A4: columnas **Forma de pago | N.º operación | Fecha | Monto**; si hay `tipo_cambio`, debajo del
  monto en letra chica. Si hay vuelto, una fila al final con el texto de `vueltoImpreso()`.
- Ticket: por pago, "Yape (29/10/2026)" a la izquierda y el monto a la derecha; debajo, en chico,
  "op. 123456" y/o "T.C. 3.75" si existen. Vuelto en su línea al final del bloque.
- Todo con `{{ }}` (escapado), nunca `{!! !!}`.
- `SaleController::pdf()`: sumar al alto del ticket ~14 pt por cada línea extra (op./T.C. y vuelto).

## Antes de empezar (obligatorio)
1. Leer `CLAUDE.md` (raíz) y `docs/proceso.md`. Comentarios y mensajes en español.
2. `git checkout main && git checkout -b feat/T-014-pdf-forma-de-pago`
3. Tests ya escritos: `tests/Feature/ComprobantePdfFormaPagoTest.php` (8; hoy pasa solo CA2, que
   debe seguir pasando). **No cambiarlos** para que pasen; si uno parece incorrecto, para y anótalo.
4. **No correr** `composer install`/`composer update`. No correr dos `php artisan test` completos a la vez.

## Pasos
1. `Sale::pagosImpresos()` y `Sale::vueltoImpreso()`.
2. Las dos plantillas y el alto del ticket.
3. `php artisan test --filter="ComprobantePdf|DatosClienteEnComprobante"` y la suite completa.
4. Commit: `feat(ventas): forma de pago con n.º de operación, moneda y vuelto en el comprobante impreso (T-014)`.

## Fuera de alcance (no tocar)
- PDF de notas, recibos de Cuentas por Cobrar y de Créditos.
- Traspasos del cobro tras una NC (T-012 los agregará a este mismo bloque).
- Condición de pago y cuotas (ya salen desde F5).

## Criterios de aceptación
- [ ] **CA1** — Yape con op. + efectivo: nombre sin emoji, n.º y monto en A4 y ticket. → test
- [ ] **CA2** — Sin pagos no sale el bloque. → test (ya pasa: que siga pasando)
- [ ] **CA3** — Pago en otra moneda: lo entregado + T.C.; vuelto. → test
- [ ] **CA4** — Verificación visual con Ghostscript de A4 y ticket (con 2 pagos, uno en dólares, y
  vuelto): sin cortes ni desbordes en 80 mm. → la hace Opus en la revisión
- [ ] `ComprobantePdfCondicionPagoTest` y `DatosClienteEnComprobanteTest` siguen en verde.

## Seguridad y correctitud
- [ ] Salida escapada: `nro_operacion` con `{{ }}` → test
- [ ] Dinero: solo formatear, sin recalcular
- [ ] Permisos, tenant, concurrencia, fechas, tributario, migraciones: no aplica

## Cómo verificar
```
cd api-sistema-fe
php artisan test --filter="ComprobantePdf|DatosClienteEnComprobante"
php artisan test        # suite completa; intermitente conocido: Fila13Fase1cFacturacionTest::descuento_del_combo…
```

## Al terminar
Llenar "Resultado". Commit con `Co-Authored-By:` de tu sesión. **No** unir a `main` ni pushear: lo revisa Opus.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
