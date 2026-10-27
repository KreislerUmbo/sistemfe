# T-047 — Venta directa: vender un tour del catálogo, enganchado a la salida del día (H41)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-045, T-046 |
| **Reglas** | D6-D18 (`plan-tours-venta-simple-costo-real.md` §6) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 12, H41 (aprobada 27-oct-2026) |
| **Rama** | `feat/T-047-venta-directa-tour` |

## Contexto
Venta directa solo vende una tarifa de proveedor o un concepto libre. El tour del catálogo, lo más
vendido en el día a día, obliga a pasar por el cotizador. Y como un servicio suelto no tiene tour,
la venta directa nunca entra en la salida operativa: no cuenta en el almuerzo ni en el vehículo.

## Alcance
- Tercera opción en Venta directa: **Tour**, con buscador de tours del catálogo.
- Backend: `origen_tipo='tour'` + `paquete_plantilla_id`. Agrega el tour a la alternativa con el
  **mismo camino que el cotizador** (`AlternativaItemController::desdePlantilla()`), respetando
  `modo_tours`: en `nuevo`, una línea con precio por persona, residencia y temporada de la fecha
  (`TourLineaService`); en `clasico`, como hoy en el cotizador. Nada reimplementado.
- El destino de la cotización se toma del tour (se llena solo y se puede cambiar).
- Al crear la reserva, los ítems del tour se enganchan a la salida del día como al aceptar una
  cotización (`crearReservaDesdeAlternativa()`: confirmarlo con test). Si T-042 ya está hecha, la
  respuesta trae su aviso de salida cancelada.
- Total en vivo (T-045) con el precio del tour: vista previa con `TourLineaService::specDelTour()`
  + `calcular()`, sin guardar.
- Combo (varios días): 422 "Los combos se venden desde el cotizador" (pregunta 3 de la obs. 12,
  sin decidir).

## Fuera de alcance
- Paquetes de varios días y combos (pregunta 3, sin decidir).
- Varias líneas en la misma venta (T-049).
- Precio a mano del tour: se usa el del catálogo.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` (`desdePlantilla()`)
- `api-sistema-fe/app/Services/AgenciaViajes/TourLineaService.php`
- `admin-start-kit/src/views/agencia-viajes/venta-directa.vue`

## Criterios de aceptación
- [ ] **CA1** — `modo_tours='nuevo'`: tour con precio nacional 100 / extranjero 150, 2 nacionales + 1 extranjero → total 350 y una línea de tour en la reserva. → test
- [ ] **CA2** — Fecha en otra temporada → precio de esa temporada. → test
- [ ] **CA3** — La reserva queda en la salida operativa del tour y la fecha; si ya existía (otra reserva), se suma a sus cantidades. → test
- [ ] **CA4** — `modo_tours='clasico'` → mismos ítems que agregar el tour en el cotizador. → test
- [ ] **CA5** — Combo → 422 con el mensaje. → test
- [ ] **CA6** — La reserva se factura igual que una del cotizador (línea de tour por grupo de pasajeros). → test o prueba funcional en agencia-demo
- [ ] **CA7** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: si hay vista previa, mismo permiso que crear la venta directa
- [ ] Aislamiento de tenant: `paquete_plantilla_id` validado con `exists` en la base del tenant
- [ ] Validación de entrada / archivos: `paquete_plantilla_id` obligatorio con `origen_tipo='tour'`, tour activo
- [ ] Concurrencia (`lockForUpdate`): el enganche usa el mismo servicio que aceptar
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: temporada con la fecha de servicio
- [ ] Tributario sin fallback silencioso: tratamiento tributario del tour como en el cotizador (D18)
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter="VentaDirecta|Salida|TourLinea"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
