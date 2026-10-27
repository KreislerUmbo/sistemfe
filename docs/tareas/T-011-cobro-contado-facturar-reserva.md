# T-011 — Cobro al contado al facturar una reserva

| | |
|---|---|
| **Módulo** | AGE + CAJ |
| **Depende de** | T-010 |
| **Reglas** | — (ver `docs/modulos/agencia/reglas.md` si ya tiene la sección de facturación) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 1 (respuestas 1-5, 8-10; "El cobro al facturar una reserva"; R6) |
| **Rama** | `feat/T-011-cobro-contado-reserva` |

## Contexto
La venta que sale de una reserva nace "Pendiente de pago" y sin pagos, aunque sea al contado: no
hay control de caja. El usuario confirmó: al contado se cobra en el momento, puede ser mixto, el
n.º de operación es opcional y sin caja abierta se bloquea.

## Alcance
- `ReservaFacturacionController::store()`: nuevo campo `pagos[]` (`method_payment`, `moneda_pago`,
  `monto_pagado`, `tipo_cambio`, `nro_operacion`) y `vuelto_moneda`. Obligatorio si la condición
  es contado y el saldo a cobrar es > 0; prohibido si es crédito.
- Validaciones que no dependen del total (método, moneda, caja abierta): antes de la
  transacción, con `CobroVentaService` (T-010).
- **Comparación contra el saldo dentro de la transacción**, después de aplicar los anticipos
  (el total definitivo se calcula ahí bajo `lockForUpdate()`): si lo cobrado no coincide (o el
  vuelto no es válido), 422 "El saldo cambió, revisa el cobro" y no se emite nada (R6).
  `store()` ya relanza `HttpException` sin convertirla en 500: mantenerlo.
- Guardar pagos + movimientos (+ vuelto) con el servicio, en la misma transacción. La venta queda
  `state_payment` pagada, `paid_out` = total, `debt` = 0.
- Saldo 0 (todo cubierto por anticipos): no pide pagos ni caja, igual que hoy.
- `prepararFactura()`: devolver lo necesario para el bloque (formas de pago activas con sus
  monedas, tipo de cambio del día de `TipoCambioSunatResolver`, si el usuario tiene caja abierta).
- Frontend, `FacturarReservaSimpleModal.vue` y `FacturarReservaEspecialModal.vue`: bloque
  **"Cobro"** solo al contado, con filas (forma de pago, moneda, monto, tipo de cambio si la
  moneda difiere, n.º de operación), "Agregar pago", equivalente y faltante/vuelto en vivo; aviso
  "Abre tu caja para cobrar" con enlace a Caja si no tiene sesión. Aviso (sin bloquear) si el tipo
  de cambio se sale del rango de `TipoCambioSanityService`. Si el bloque comparte lógica entre los
  dos modales, ponerlo en un componente o en `useOpcionesFacturacion`.

## Fuera de alcance
- Traspaso del cobro tras una NC (T-012).
- **Despliegue a producción con pagos en dólares:** requiere T-013. Si se despliega antes, el
  backend debe rechazar `moneda_pago='USD'` con un interruptor (`config`), documentado en "Resultado".
- Venta Directa: "Crear y cobrar" es T-048 (usa este mismo cobro).
- Ventas ya emitidas pendientes (R3: no se hace nada).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php`
- `admin-start-kit/src/components/AgenciaViajes/FacturarReservaSimpleModal.vue`, `FacturarReservaEspecialModal.vue`
- `admin-start-kit/src/composables/useOpcionesFacturacion.ts` (o donde viva)
- `admin-start-kit/src/services/admin/reservaFacturacionService.ts`
- Tests de facturación de reservas existentes (`tests/Feature/AgenciaViajes/ReservaFacturacion*`)

## Criterios de aceptación
- [ ] **CA1** — Contado, saldo S/ 500, pago efectivo S/ 500 → venta pagada, 1 `sale_payment`,
  1 movimiento en la caja del usuario. → test nuevo
- [ ] **CA2** — Pago mixto: S/ 200 efectivo + S/ 300 Yape con n.º de operación → 2 pagos, 2
  movimientos, n.º guardado. → test nuevo
- [ ] **CA3** — Venta en USD 1,000 pagada con US$ 600 + S/ 1,500 a 3.75 → equivalente correcto,
  movimientos en USD y PEN. → test nuevo
- [ ] **CA4** — Lo cobrado no cubre el saldo → 422 y no existe la venta ni movimientos. → test
- [ ] **CA5** — Sin caja abierta → 422 "Abre tu caja para cobrar" y no se emite nada. → test
- [ ] **CA6** — Anticipos cubren todo → se emite sin pagos y sin caja. → test
- [ ] **CA7** — Crédito con `pagos[]` → 422; crédito sin pagos funciona igual que hoy. → test
- [ ] **CA8** — Vuelto: venta S/ 3,700 pagada con US$ 1,000 a 3.75 en efectivo, vuelto en soles →
  movimiento de salida S/ 50. → test
- [ ] **CA9** — Prueba funcional en `agencia-demo`: facturar simple y especial al contado, ver la
  sesión de caja con los movimientos. (Playwright o a mano)

## Seguridad y correctitud
- [ ] Permisos: sin rutas nuevas; la Policy de reserva ya aplica (`ReservaPolicy`)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: `pagos[]` con reglas explícitas; métodos del catálogo del tenant
- [ ] Concurrencia: la comparación con el saldo se hace bajo el `lockForUpdate()` de la reserva
- [ ] Dinero: comparar en centavos; conversión solo en `CobroVentaService`
- [ ] Fechas con `HoraPeru`: `date_payment` = `HoraPeru::hoyTexto()`
- [ ] Tributario: no aplica (la moneda del comprobante no cambia por cómo paga el cliente)
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica (las hace T-010)

## Cómo verificar
```
php artisan test --filter=ReservaFacturacion
php artisan test   # suite completa, contra PostgreSQL
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
