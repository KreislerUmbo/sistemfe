# T-048 — Venta directa: "Crear y cobrar" en un solo paso (H45)

| | |
|---|---|
| **Módulo** | AGE + CAJ |
| **Depende de** | T-011 (y por ella T-010) |
| **Reglas** | las de cobro de T-010/T-011 |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 12, H45 y respuestas 1-2 (27-oct-2026: también por transferencia; comprobante elegido cada vez) |
| **Rama** | `feat/T-048-venta-directa-crear-cobrar` |

## Contexto
Después de crear una venta directa hay que ir a la reserva, facturar y cobrar: 3 pantallas para una
venta que se paga en el momento, en la oficina o por transferencia.

## Alcance
- Segundo botón en Venta directa: **"Crear y cobrar"**. Muestra el bloque de cobro de T-011 (formas
  de pago, moneda, n.º de operación, pago mixto, vuelto) y el **tipo de comprobante sin valor por
  defecto** (boleta / factura / nota de venta, según los permisos de emisión del usuario).
- Backend: crea la venta directa y, **en la misma transacción**, factura la reserva completa al
  contado con el cliente de la venta y registra el cobro, con la lógica de
  `ReservaFacturacionController::store()` + `CobroVentaService` (extraída a un servicio si hace
  falta, nunca copiada). Si algo falla, no queda ni reserva ni venta ni movimientos.
- Transferencia: n.º de operación como en T-010.
- Al terminar: la ficha de la reserva con la venta emitida y el botón de imprimir. El envío a SUNAT
  sigue siendo manual, como en el resto del sistema.
- "Crear" sigue igual: para pagar después (crédito, transferencia que aún no llega, facturar más
  tarde).

## Fuera de alcance
- Enviar a SUNAT automáticamente.
- Cobro parcial con saldo pendiente (eso es "Crear" + facturar a crédito desde la reserva).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php`
- `admin-start-kit/src/views/agencia-viajes/venta-directa.vue` + el componente de cobro de T-011

## Criterios de aceptación
- [ ] **CA1** — Crear y cobrar con boleta, efectivo S/ 120 → reserva, venta pagada, 1 pago y 1 movimiento de caja. → test
- [ ] **CA2** — Transferencia con n.º de operación → pago con el n.º guardado. → test
- [ ] **CA3** — Sin tipo de comprobante → 422. → test
- [ ] **CA4** — Lo cobrado no cubre el total, o sin caja abierta → 422 y no existe la reserva. → test
- [ ] **CA5** — Factura a un cliente sin RUC → 422 con el mismo mensaje que al facturar una reserva. → test
- [ ] **CA6** — De punta a punta en agencia-demo (crear y cobrar, imprimir). → prueba funcional
- [ ] **CA7** — Suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: crear venta directa + facturar reserva + permiso de emisión del tipo de comprobante
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: las de T-011
- [ ] Concurrencia (`lockForUpdate`): correlativo con `SerieComprobanteService`, todo en una transacción
- [ ] Dinero en centavos: como en T-011
- [ ] Fechas con `HoraPeru`: fecha de emisión `HoraPeru::hoyTexto()`
- [ ] Tributario sin fallback silencioso: como al facturar una reserva
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter="VentaDirecta|ReservaFacturacion"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
