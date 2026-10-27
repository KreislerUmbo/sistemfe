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
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
