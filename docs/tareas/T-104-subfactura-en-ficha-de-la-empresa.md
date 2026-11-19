# T-104 — La sub-factura emitida a otra empresa aparece en la ficha de esa empresa

| | |
|---|---|
| **Módulo** | CLI + AGE |
| **Depende de** | T-060 (hecha) |
| **Reglas** | visibilidad de la ficha de T-060 (`FichaClienteAgencia`, comentario de cabecera ~líneas 22-33) |
| **Decisiones** | revisión de T-060 (16-nov-2026): la sub-factura a otra empresa no es compra del que cotizó. Decisión del usuario, 18-nov-2026: **misma visibilidad que T-060** (abajo) |
| **Rama** | `feat/T-104-subfactura-ficha-empresa` |

## Contexto
Con «Facturación múltiple por grupo», una reserva cotizada por el cliente A puede emitir una
sub-factura a la empresa B (`sales.client_id` = B). La ficha de A la excluye a propósito: no es una
compra suya. Pero la ficha de B tampoco la muestra, porque `ventasDeReservas()` **parte de las reservas
del cliente** (cotizaciones con `cliente_id` = B) y B no tiene reservas propias. Resultado: el
comprobante no aparece en ninguna ficha y no suma al total comprado de nadie.

**Decisión (18-nov-2026):** el comprobante aparece en la ficha de B, con sus pagos y su monto en el
total comprado, **solo si el usuario puede ver la reserva** (`Reserva::propias()`), igual que el resto
de la ficha. Un vendedor sin «ver todas» no ve comprobantes de reservas ajenas, sean del cliente o de otro.

## Alcance
- **Nueva definición de los comprobantes de la ficha:** los que tienen `sales.client_id` = este
  cliente y están ligados (por cualquiera de los 3 caminos de hoy: `reserva_ventas`,
  `sale_details → sale_detail_items → reserva_items`, `advances → reserva_anticipos`) a una reserva
  **visible** para el usuario, la haya cotizado quien la haya cotizado.
  - Reescribir `ventasDeReservas()` para que **parta de las ventas del cliente** (`sales.client_id`,
    sin borrar), no de sus reservas, y filtre la reserva con `Reserva::propias()` como subconsulta.
    Sigue devolviendo `sale_id => reserva_id`.
  - Con eso, `totalComprado()`, `eventosComprobantes()` y `eventosPagos()` incluyen la sub-factura
    sin más cambios: ya leen `contexto()['ventas']`.
- **Indicar de quién es la reserva:** en los eventos de comprobante y de nota cuya reserva la cotizó
  **otro** cliente, agregar `reserva_de` = nombre de ese cliente (`clients.full_name` vía
  `reservas → alternativas → cotizaciones.cliente_id`). Una consulta para todas, no una por evento.
  `null` cuando la reserva es del propio cliente.
- **Frontend:** `LineaTiempoCliente.vue`, en `case 'comprobante'` (~línea 172), agregar
  «Reserva de <nombre>» a las partes del detalle cuando venga `reserva_de`. El enlace a la reserva
  no cambia (~línea 189): ya es visible por definición.
- La ficha de A sigue sin mostrar la sub-factura emitida a B (el filtro por `client_id` se mantiene).

## Fuera de alcance
- El resumen de B: `viajes_realizados` y `ultimo_viaje` siguen contando solo reservas cotizadas
  por B (`contexto()['reservas']` no cambia). B pagó, no viajó como cliente de esa reserva.
- La reserva de A no aparece como evento «reserva» en la ficha de B (solo sus comprobantes y pagos).
- Comprobantes del cliente sin reserva (ventas retail o POS dentro de un tenant de Agencia): no
  entran, como hoy.
- Retail: la ficha de Agencia responde 404 fuera del giro; sin cambios.

## Dónde tocar
- `api-sistema-fe/app/Services/Crm/FichaClienteAgencia.php`
  - `ventasDeReservas()` (~línea 80) y su llamada en `contexto()` (~línea 73): ya no recibe `$reservaIds`
  - `eventosComprobantes()` (~línea 311): `reserva_de` en comprobantes (~322) y notas (~335)
  - comentario de cabecera (~líneas 30-33): actualizar la regla
- `admin-start-kit/src/components/Clientes/LineaTiempoCliente.vue` → `case 'comprobante'` (~línea 171) y el tipo del evento si lo declara
- `api-sistema-fe/tests/Feature/AgenciaViajes/CrmFichaClienteTest.php` → helpers `cotizacion()`, `reserva()`, `venta(Reserva, clienteId, total)` (~líneas 110-160) ya permiten emitir a otro cliente

## Criterios de aceptación
- [ ] **CA1** — Reserva cotizada por A con una sub-factura a B (y un pago) → la línea de tiempo de B muestra el comprobante y el pago, con `reserva_de` = nombre de A y enlace a la reserva; el total comprado de B lo incluye. → test
- [ ] **CA2** — La ficha de A no muestra esa sub-factura ni la suma, y sus propios comprobantes de esa reserva siguen con `reserva_de` = null. → test
- [ ] **CA3** — Vendedor sin `reservas.ver_todas` que no ve la reserva de A → la ficha de B no muestra el comprobante ni lo suma. → test
- [ ] **CA4** — NC total sobre la sub-factura (borra la fila de `reserva_ventas`) → la sub-factura y la NC siguen en la ficha de B, por sus líneas. → test
- [ ] **CA5** — `test_linea_de_tiempo_paginada_con_una_cantidad_fija_de_consultas` sigue en verde, y la cantidad de consultas no crece con el número de sub-facturas. → test
- [ ] **CA6** — Prueba funcional en agencia-demo: facturación múltiple de una reserva con una sub-factura a otra empresa → la ficha de esa empresa la muestra con «Reserva de …». → prueba funcional
- [ ] **CA7** — Suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas; la visibilidad sale de `Reserva::propias()` (CA3)
- [ ] Aislamiento de tenant: no aplica (todo es del tenant)
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica (solo cambia qué comprobantes entran en la suma existente)
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: el nombre del cliente se muestra con interpolación
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=CrmFichaClienteTest
php artisan test
cd admin-start-kit && npm run type-check
```
Prueba funcional en `http://agencia-demo.sistemafe.test:5173` (admin@agencia-demo.test): una reserva
con 2+ pasajeros → Facturar → facturación múltiple → un grupo a nombre de otra empresa con RUC →
abrir la ficha de esa empresa → Historial: el comprobante con «Reserva de <cliente que cotizó>» y su
enlace; el resumen suma el monto. La ficha del que cotizó no la muestra.

---

## Resultado
- **Commits:** ver `git log feat/T-104-subfactura-ficha-empresa`.
- **Tests:** 4 nuevos en `CrmFichaClienteTest` (CA1+CA2, CA3, CA4, CA5); el archivo, 14/14. Suite completa desde el worktree: 2348 pasan, 2 errores de entorno (`certificate-demo.pem` no está en el `storage/` del worktree; esos 2 tests pasan en el repo principal). `type-check`: los 47 errores de siempre, ninguno en archivos tocados.
- **CA6 (prueba funcional):** pendiente; se hace en agencia-demo después del merge (Apache sirve el repo principal, no el worktree).
- **Desviaciones de la tarea y por qué:** ninguna. El recorrido es una actualización del 66 (§2.2 y §2.3), que ya explicaba esta parte de la ficha, en vez de uno nuevo.

## Hallazgos fuera de alcance
- Ninguno.
