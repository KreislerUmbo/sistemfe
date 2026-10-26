# T-029 — Tour clásico desde plantilla: cada servicio nace con su tratamiento tributario (y costo sin infantes)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | `CLAUDE.md` → reglas tributarias; regla D18 de Agencia: **exonerado (20) = Amazonía** |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión del flujo de cotizaciones", H18 y H19 |
| **Rama** | `fix/T-029-tributario-tour-clasico` |

## Contexto
**H18.** `AlternativaItemController::crearItemClasicoDesdeEntrada()` (modo clásico, el que usan
todos los tenants) crea los servicios de un tour cargado desde plantilla **sin** `tip_afe_igv` ni
`destino_tributario`. Para los que tienen tarifa de proveedor, la facturación cae a la tarifa
(`ReservaFacturacionController::resolverTipAfeIgvItem()` / `resolverDestinoTributario()`): bien.
Pero el **guía** (sin tarifa de proveedor) y el ítem **"Ajuste de redondeo"** (manual, en
`desdePlantilla()`) caen al fijo `'10'` / `'nacional'`, no al default de la agencia
(`ConfiguracionAgencia::tratamientoTributarioDefault()`), que es lo que usa un guía agregado suelto
(`crearItemGuia()`). En una agencia de la Amazonía: el guía se factura gravado y la mezcla con
servicios exonerados puede bloquear la facturación (guardia de "mezcla tributaria").

**H19.** En el mismo método, un servicio compartido calcula el costo con
`adulto + niño + infante`; el alta normal y el recálculo al cambiar pasajeros
(`crearItemProveedor()`, `recalcularItemsPorPersona()`, decisión E2 del 14-oct) cuentan
`adulto + niño`. El margen cambia solo con editar pasajeros.

**Antes de tocar lógica tributaria:** revisar el flujo completo producto → destino → operación
(`CLAUDE.md`), y el trait `ExoneradoEsAmazonia`.

## Alcance
- **Reproducir H18** con un test: agencia con default exonerado/amazonía, tour con guía y con
  ajuste de redondeo → los ítems nacen con `tip_afe_igv` nulo y al facturar salen gravados.
- Todo servicio creado por `crearItemClasicoDesdeEntrada()` nace con tratamiento explícito:
  - con tarifa de proveedor: el de la tarifa (como `crearItemProveedor()`);
  - guía: `resolverTratamientoTributario(null, null)` (default de la agencia, como `crearItemGuia()`).
- **Ajuste de redondeo:** el tratamiento de los servicios del tour si todos coinciden; si están
  mezclados, el default de la agencia. Anotar en "Resultado" qué se hizo. (Si con datos reales se
  ve que el ajuste debería repartirse, no hacerlo acá: anotarlo en "Hallazgos".)
- **H19:** el costo del servicio compartido sin infantes, igual que `crearItemProveedor()`.
- **Datos ya existentes:** sin cambio automático. Comando de solo lectura
  `agencia-viajes:diagnosticar-items-sin-igv` que lista `alternativa_items` y `reserva_items` de
  alternativas/reservas activas con `tip_afe_igv` nulo y sin tarifa de proveedor (guías, ajustes),
  para revisarlos con el usuario. Correrlo en `agencia-demo` y anotar cuántos hay.
- No cambiar el fallback de la facturación (`?? '10'`) en esta tarea: eso afecta todo lo ya
  facturado y es decisión aparte (anotar en "Hallazgos" si con el diagnóstico se ve que conviene).

## Fuera de alcance
- Modo nuevo de tours (`TourLineaService`), que ya resuelve el IGV por grupo.
- Cambiar el guardia de mezcla tributaria.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` (`crearItemClasicoDesdeEntrada()`, `desdePlantilla()`)
- `api-sistema-fe/app/Console/Commands/DiagnosticarItemsSinIgv.php` (nuevo)
- Tests de `desdePlantilla` existentes (incluido `test_desde_plantilla_da_el_mismo_resultado_que_antes`: actualizar lo que cambie a propósito y explicarlo)

## Criterios de aceptación
- [ ] **CA1** — Agencia con default `20`/`amazonia`: el guía de un tour cargado desde plantilla nace
  con `20`/`amazonia`. → test (falla antes del fix)
- [ ] **CA2** — Servicios con tarifa: nacen con el tratamiento de su tarifa. → test
- [ ] **CA3** — Ajuste de redondeo: tratamiento de los servicios si coinciden; default si no. → test
- [ ] **CA4** — Reserva de un tour así en agencia Amazonía: se puede facturar sin "mezcla". → test
- [ ] **CA5 (H19)** — Servicio compartido con 2 adultos y 1 infante: costo = precio de costo × 2. → test
- [ ] **CA6** — El comando de diagnóstico lista los ítems sin IGV y no escribe nada. → test
- [ ] **CA7** — Suite completa en verde (incluidos los tests de facturación de reservas).

## Seguridad y correctitud
- [ ] Permisos: no aplica
- [ ] Aislamiento de tenant: el comando corre por tenant de Agencia
- [ ] Validación de entrada: no aplica
- [ ] Concurrencia: no aplica
- [ ] Dinero: costo sin infantes (E2)
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: **objetivo de la tarea** — sin fallback silencioso en ítems nuevos; D18 respetada
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan agencia-viajes:diagnosticar-items-sin-igv
php artisan test --filter="DesdePlantilla|ReservaFacturacion"
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
