# T-031 — Tarifa de proveedor sin precio de niño: el niño paga como adulto

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | Misma regla que ya usan los tours: "precio de niño vacío = precio de adulto" (`TourPrecioVentaCampos.vue`, `TourLineaService`) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión del flujo de cotizaciones", H7 (decisión del usuario, 26-oct-2026: **si la tarifa no tiene precio de niño, el niño paga como adulto**) |
| **Rama** | `fix/T-031-nino-paga-como-adulto` |

## Contexto
En las tarifas de proveedor, `precio_venta_nino` vacío se toma hoy como **0**: el niño sale gratis,
pero su costo sí se cuenta → margen negativo sin aviso. En los tours, el mismo campo vacío
significa "igual al adulto". El usuario decidió unificar: **vacío = paga como adulto**. El infante
no cambia (vacío = sin costo).

Lugares donde hoy vacío vale 0:
- `AlternativaItemController::crearItemProveedor()` (alta por persona)
- `AlternativaItemController::crearItemClasicoDesdeEntrada()` (tour clásico desde plantilla, vía la
  entrada de `ComboExplosionService`)
- `CotizacionController::recalcularItemsPorPersona()` (recálculo al cambiar pasajeros)
- `ComboExplosionService` (arma `precio_venta_nino` de la entrada con `?? 0`)

## Alcance
- Un solo punto que resuelva el precio de venta de niño de una tarifa (por ejemplo
  `ProveedorTarifa::precioVentaNinoEfectivo()`: `precio_venta_nino ?? precio_venta_adulto`) y usarlo
  en los 4 lugares. Infante sin cambios.
- `ComboExplosionService`: la entrada lleva el precio de niño efectivo.
- Pantalla de tarifas del proveedor (`proveedores/detalle.vue`): placeholder "Vacío = adulto" en el
  precio de niño, igual que en tours. Hoy el formulario lo autocalcula con el costo × factor si no
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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
