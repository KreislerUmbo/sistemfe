# T-031 — Servicio de tour sin precio de niño: el niño paga como adulto (hoteles no cambian)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | Misma regla que ya usan los tours: "precio de niño vacío = precio de adulto" (`TourPrecioVentaCampos.vue`, `TourLineaService`) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión del flujo de cotizaciones", H7 (decisión del usuario, 26-oct-2026: **en servicios de tour, si la tarifa no tiene precio de niño, el niño paga como adulto; en hoteles no, porque tienen sus propias tarifas**) |
| **Rama** | `fix/T-031-nino-paga-como-adulto` |

## Contexto
En las tarifas de proveedor, `precio_venta_nino` vacío se toma hoy como **0**: el niño sale gratis,
pero su costo sí se cuenta → margen negativo sin aviso. En los tours, el mismo campo vacío
significa "igual al adulto". El usuario decidió: **en servicios de tour** (entradas, transporte,
actividades: tarifas sin `tipo_habitacion`), vacío = paga como adulto. **Las tarifas de hotel
(`tipo_habitacion` no nulo) no cambian**: tienen sus propias tarifas por habitación y cama
adicional. El infante no cambia en ningún caso (vacío = sin costo).

Lugares donde hoy vacío vale 0 (verificados el 12-nov-2026, después de T-045):
- `PriceEngineService::ventaYCostoDeTarifa()` (alta por persona; reemplaza a `crearItemProveedor`)
- `AlternativaItemController::crearItemClasicoDesdeEntrada()` (tour clásico desde plantilla; usa el
  precio de niño de la entrada de `ComboExplosionService`)
- `RecalculoPorPersonaService::recalcular()` (recálculo al cambiar pasajeros; reemplaza a
  `CotizacionController::recalcularItemsPorPersona`)
- `ComboExplosionService` (arma `precio_venta_nino` de la entrada con `?? 0`)
- `VentaDirectaController::desglose()` y `totalTourClasico()` (vista previa y total de Venta directa)

## Alcance
- Un solo punto que resuelva el precio de venta de niño de una tarifa (por ejemplo
  `ProveedorTarifa::precioVentaNinoEfectivo()`): si es tarifa de hotel (`tipo_habitacion` no nulo),
  `precio_venta_nino ?? 0` (igual que hoy); si no, `precio_venta_nino ?? precio_venta_adulto`.
  Usarlo en los 6 puntos (ver lista). Infante sin cambios.
- Aplica igual si el servicio de tour se agrega suelto o dentro de un tour cargado desde plantilla.
- `ComboExplosionService`: la entrada lleva el precio de niño efectivo.
- Pantalla de tarifas del proveedor (`proveedores/detalle.vue`): placeholder "Vacío = adulto" en el
  precio de niño, igual que en tours, **solo en tarifas que no son de hotel**. Hoy el formulario lo autocalcula con el costo × factor si no
  se toca: confirmar que ese comportamiento sigue igual.
- Cotizaciones ya armadas: **no se cambian** (precio guardado). Solo al recalcular por cambio de
  pasajeros (alternativas en borrador/enviada) se aplica la regla nueva: anotarlo en "Resultado".

## Fuera de alcance
- Pasaje aéreo (`tarifa_base_nino` vacío = 0): es otra cosa (tarifa de aerolínea que el vendedor
  tipea); dejarlo como está y anotarlo en "Hallazgos" si con datos reales conviene revisarlo.
- Precio de infante.

## Archivos probables
- `api-sistema-fe/app/Models/AgenciaViajes/ProveedorTarifa.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php`, `CotizacionController.php`
- `api-sistema-fe/app/Services/AgenciaViajes/ComboExplosionService.php`
- `admin-start-kit/src/views/agencia-viajes/proveedores/detalle.vue`

## Criterios de aceptación
- [ ] **CA1** — Tarifa por persona con adulto S/ 100 y niño vacío; cotización con 2 adultos y 1
  niño → precio de la línea S/ 300. → test (falla antes del fix)
- [ ] **CA2** — Con niño S/ 70 → S/ 270 (sin cambio). → test
- [ ] **CA3** — Infante con precio vacío sigue en 0. → test
- [ ] **CA3b** — Tarifa de hotel (`tipo_habitacion` = doble) por persona, sin precio de niño → el
  niño sigue en 0, igual que hoy. → test
- [ ] **CA4** — Tour clásico desde plantilla con un servicio compartido sin precio de niño → el niño
  paga como adulto. → test
- [ ] **CA5** — Cambiar pasajeros en una alternativa en borrador recalcula con la regla nueva. → test
- [ ] **CA6** — Suite completa en verde (actualizar a propósito los tests que asumían niño = 0 y
  explicarlo en "Resultado").

## Seguridad y correctitud
- [ ] Permisos: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: no aplica
- [ ] Concurrencia: no aplica
- [ ] Dinero: **objetivo** — una sola regla, en un solo lugar
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter="PorPersona|DesdePlantilla|ActualizarPasajeros"
php artisan test   # suite completa, contra PostgreSQL
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:** ver `git log fix/T-031-nino-paga-como-adulto`.
- **Tests:** `NinoSinPrecioPagaComoAdultoTest` (8 tests: CA1, CA2, CA2b niño en 0 explícito, CA3, CA3b,
  CA4, CA5 y Venta directa vista previa = total guardado). Antes del fix fallaban CA1, CA3 (porque
  usa niño vacío = 300), CA4, CA5 y Venta directa; pasaban CA2, CA2b y CA3b. Después: carpeta
  `tests/Feature/AgenciaViajes` 1203 pasan; suite completa 2137 pasan (PostgreSQL). Front: `build` OK;
  `type-check` da los mismos 47 errores de `fake-backend.ts` que hay sin mis cambios (ninguno en `detalle.vue`).
  Ningún test existente asumía niño = 0, así que no hubo que actualizar ninguno.
- **Qué se hizo:** `ProveedorTarifa::precioVentaNinoEfectivo()` (hotel → `?? 0`; resto → `?? adulto`;
  un 0 escrito a propósito sigue siendo gratis). Se usa en `PriceEngineService`,
  `RecalculoPorPersonaService`, `ComboExplosionService` y `VentaDirectaController::desglose()`.
  `crearItemClasicoDesdeEntrada()` y `totalTourClasico()` leen el precio de niño de la entrada, que
  ahora ya viene efectivo desde `ComboExplosionService`; se quitó su `?? 0`. `grep` sobre `app/`:
  no queda ningún `precio_venta_nino ?? 0`. Front: placeholder «Vacío = adulto» en el precio de niño
  de `proveedores/detalle.vue` solo si el proveedor no es hotel; el autocálculo costo × factor no se tocó.
- **Cotizaciones ya armadas:** no cambian (precio guardado). Solo se reprecian al cambiar pasajeros
  (alternativas en borrador/enviada), y ahí aplica la regla nueva: un servicio de tour sin precio de
  niño pasa de 0 a precio de adulto para ese niño.
- **Desviaciones de la tarea y por qué:**
  - La lista «Lugares donde hoy vacío vale 0» estaba desactualizada (T-045 movió la fórmula a
    servicios): corregida arriba, ahora con 6 puntos.
  - **Venta directa entra en el alcance** (`desglose()` y `totalTourClasico()`): la vista previa y el
    total tienen que coincidir con el cotizador; se agregó su test.

## Hallazgos fuera de alcance
- `RecalculoPorPersonaService::recalcular()` repite la fórmula de `PriceEngineService::ventaYCostoDeTarifa()`
  en vez de llamarla; hoy dan lo mismo, pero son dos copias que pueden volver a divergir.
- Pasaje aéreo (`tarifa_base_nino` vacío = 0): sin tocar, como pedía la tarea; revisar con datos reales.
