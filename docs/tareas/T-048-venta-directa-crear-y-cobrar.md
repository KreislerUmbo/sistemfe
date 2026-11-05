# T-048 — Venta directa: "Crear y cobrar" con boleta o factura y la Amazonía confirmada en el resumen (H45)

| | |
|---|---|
| **Módulo** | AGE + CAJ |
| **Depende de** | T-047 (la pantalla completa) y T-011 (y por ella T-010). **Antes de empezar:** que el contador haya visto la confirmación de la Amazonía en el resumen (D-AGE-034, punto 2); si no la acepta, se reescribe esta tarea |
| **Reglas** | las de cobro de T-010/T-011 |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 12, H45 y respuestas 1-2 (27-oct-2026: también por transferencia; comprobante elegido cada vez); D-AGE-034 (sin nota de venta; Amazonía confirmada en el resumen) |
| **Rama** | `feat/T-048-venta-directa-crear-cobrar` |

## Contexto
Después de crear una venta directa hay que ir a la reserva, facturar y cobrar: 3 pantallas para una
venta que se paga en el momento, en la oficina o por transferencia.

## Alcance
- En el resumen de la pantalla de T-085: botón **«Crear y cobrar»** arriba y el actual pasa a
  **«Crear sin cobrar»** («a crédito, si el pago llega después o para facturar desde la reserva»).
  «Crear y cobrar» aparece solo con `AGENCIA_COBRO_AL_FACTURAR` encendido.
- Al apretarlo, el resumen muestra el **bloque de cobro de T-011** (el componente, no uno nuevo:
  formas de pago con Yape/Plin, moneda, n.º de operación, pago mixto, vuelto) y el **tipo de
  comprobante sin valor por defecto**: **boleta o factura**, según los permisos de emisión del
  usuario. **Sin nota de venta** (D-AGE-034: facturar una reserva solo emite 01 y 03).
- **Afectación** (de la vista previa de T-085, `facturable`):
  - `ok = false` (mezcla o exportación): «Crear y cobrar» deshabilitado con el motivo; «Crear sin
    cobrar» sigue habilitado.
  - `requiere_confirmar_amazonia`: si el usuario tiene `reservas.editar`, casilla «Confirmo que
    estos servicios se realizan en la Amazonía (exonerados de IGV, Ley 27037)»; sin marcarla,
    «Crear y cobrar» queda deshabilitado con «Confirma la Amazonía para cobrar». Sin ese permiso,
    no hay casilla y el motivo dice «Un usuario con permiso de editar reservas debe confirmar la
    Amazonía».
  - Backend: con la confirmación, cada `reserva_item` de la Amazonía guarda
    `motivo_override_tributario` = «Confirmado en venta directa por <nombre del usuario>», con la
    misma lógica de `ReservaController::overrideTratamientoTributario()` (extraída si hace falta,
    nunca copiada), dentro de la misma transacción. Sin `reservas.editar` → 403 aunque el payload
    traiga la confirmación.
- Backend: crea la venta directa y, **en la misma transacción**, factura la reserva completa al
  contado con el cliente de la venta y registra el cobro, con la lógica de
  `ReservaFacturacionController::store()` + `CobroVentaService` (extraída a un servicio si hace
  falta, nunca copiada). Si algo falla, no queda ni reserva ni venta ni movimientos.
- Transferencia: n.º de operación como en T-010.
- Al terminar: la ficha de la reserva con la venta emitida y el botón de imprimir. El envío a SUNAT
  sigue siendo manual, como en el resto del sistema.
- «Crear sin cobrar» sigue igual: para pagar después (crédito, transferencia que aún no llega,
  facturar más tarde o en comprobantes separados).

## Fuera de alcance
- Enviar a SUNAT automáticamente.
- Cobro parcial con saldo pendiente (eso es «Crear sin cobrar» + facturar a crédito desde la
  reserva).
- Nota de venta (D-AGE-034).
- Emitir dos comprobantes en el mismo paso cuando hay mezcla de afectaciones.

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
- [ ] **CA6** — Nota de venta (`00`) → 422. → test
- [ ] **CA7** — Línea de la Amazonía con la confirmación y `reservas.editar` → venta emitida
  exonerada y `motivo_override_tributario` guardado en el ítem; sin confirmación → 422 y no existe
  la reserva; sin `reservas.editar` → 403 y no existe la reserva. → test
- [ ] **CA8** — Mezcla de afectaciones → 422 con el mismo motivo que la facturación de la reserva y
  no existe la reserva; con «Crear sin cobrar» la reserva se crea. → test
- [ ] **CA9** — `AGENCIA_COBRO_AL_FACTURAR` apagado → el botón no aparece y el endpoint responde
  422. → test + prueba funcional
- [ ] **CA10** — De punta a punta en agencia-demo (tour + traslado, crear y cobrar con Yape,
  imprimir). → prueba funcional
- [ ] **CA11** — Suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: crear venta directa + facturar reserva + permiso de emisión del tipo de comprobante; confirmar la Amazonía exige `reservas.editar` también en el backend
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
