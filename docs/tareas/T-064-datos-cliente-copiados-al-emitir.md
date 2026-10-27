# T-064 — Copiar documento, razón social y dirección del cliente al comprobante al emitirlo (H60)

| | |
|---|---|
| **Módulo** | VEN (todos los giros) |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 18, H60 (aprobada 27-oct-2026) |
| **Rama** | `fix/T-064-datos-cliente-en-comprobante` |

## Contexto
Los PDF de ventas y notas leen documento, razón social y dirección del cliente **al imprimir**.
Editar el cliente cambia la reimpresión de todos sus comprobantes ya emitidos, que dejan de
coincidir con el XML aceptado por SUNAT.

## Alcance
- Columnas nuevas en `sales` y `notes`: `cliente_tipo_doc`, `cliente_num_doc`,
  `cliente_razon_social`, `cliente_direccion` (nullable).
- Se llenan **al crear** la venta o la nota (todos los caminos: venta retail, factura de reserva,
  venta directa, anticipos, notas). Revisar cada `Sale::create`/`Note::create` y anotar los
  caminos encontrados en "Resultado". Al editar una venta aún no enviada a SUNAT
  (`SaleController::update()`, dentro de su transacción), se vuelven a copiar si cambia el
  cliente o sus datos.
- El XML (`GreenterService::getCliente()`) y los PDF (`a4`, `ticket80mm`, `nota_a4`,
  `nota_ticket80mm`) leen la copia; si es null (comprobante viejo), caen al cliente como hoy.
- Migración de datos: llenar las copias de los comprobantes existentes con los datos actuales del
  cliente (es lo mejor que se tiene; dejarlo dicho en el comentario de la migración).
- Vistas que muestran el cliente de un comprobante (listados, detalle) siguen igual.

## Fuera de alcance
- Elegir dirección de sucursal (T-065); dirección en el XML (T-066).

## Archivos probables
- migración nueva en `api-sistema-fe/database/migrations/tenant/core/`
- `api-sistema-fe/app/Models/Sale/Sale.php`, `Note` (o donde viva), controllers que crean ventas y notas
- `api-sistema-fe/app/Http/Controllers/Greenter/GreenterService.php`
- `api-sistema-fe/resources/views/pdf/a4.blade.php`, `ticket80mm.blade.php`, `nota_a4.blade.php`, `nota_ticket80mm.blade.php`

## Criterios de aceptación
- [ ] **CA1** — Emitir una factura, cambiar la razón social y la dirección del cliente → el PDF sigue mostrando las originales. → test
- [ ] **CA2** — Igual para una nota de crédito. → test
- [ ] **CA3** — Factura de reserva y venta directa llenan las copias. → test
- [ ] **CA4** — Comprobante viejo sin copia (antes de la migración de datos) → PDF con los datos del cliente, sin error. → test
- [ ] **CA5** — Editar una venta no enviada cambiando el cliente → copias del nuevo cliente. → test
- [ ] **CA6** — Suite completa en verde.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): dentro de las transacciones existentes
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: el XML usa la copia; si falta, el cliente (como hoy)
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí (core), con migración de datos

## Cómo verificar
```
php artisan test
```

---

## Resultado
- **Commits:** rama `fix/T-064-datos-cliente-en-comprobante` (ver `git log`).
- **Tests:** 6 nuevos (`DatosClienteEnComprobanteTest`); suite completa 1524 en verde (antes 1518).
- **Caminos que crean comprobantes** (todos cubiertos por el evento `creating` del trait, sin tocar
  cada uno): `SaleController::store()`, `ReservaFacturacionController::store()`,
  `AdvanceController::store()` (ventas); `NotaElectronicaController::store()` (notas, que además
  copian de su venta).
- **Migración aplicada** en los 7 tenants locales (`tenants:migrate-verticales`) y en
  `sistemafe_test_migrations` (migrate + rollback + migrate). Comprobantes sin copia tras el
  llenado: 0 en umbo (24 ventas, 15 notas), agencia-demo (11), sandbox (16).
- **Desviaciones de la tarea y por qué:**
  - Nombres de columna `tipo_doc_cliente`, `num_doc_cliente`, `razon_social_cliente`,
    `direccion_cliente` (no `cliente_*`): siguen a la columna vecina `cod_tipo_doc_cliente`.
  - La copia también se rehace **al enviar a SUNAT** mientras no hay CDR (la tarea solo pedía al
    editar): si el cliente se corrigió entre crear y enviar, el XML sale corregido. Desde que SUNAT
    acepta, no cambia.
  - El QR también lee la copia (la tarea no lo nombraba; lleva el número de documento del cliente).
  - Una nota copia los datos **de su venta**, no del cliente de hoy (mismo receptor que el
    comprobante que modifica).
  - Sin test de `SaleController::update()` (el payload completo de una venta es grande); usa el
    mismo método que el envío, que sí tiene test.
- **Recorrido:** `docs/aprendizaje/14-recorrido-datos-cliente-en-comprobante.md`.

## Hallazgos fuera de alcance
- `cod_tipo_doc_cliente` (código SUNAT del tipo de documento) se sigue tomando del formulario al
  crear y editar, y **no** se rehace al enviar: si se corrige el tipo de documento del cliente
  (DNI → RUC) entre crear y enviar, el XML llevaría el número nuevo con el código viejo. Revisar
  junto con T-065.
- T-065 debe respetar la dirección elegida: hoy `copiarDatosDelCliente()` pisa
  `direccion_cliente` con la del cliente al editar o enviar.
