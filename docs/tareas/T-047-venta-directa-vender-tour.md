# T-047 — Venta directa: vender un tour del catálogo, enganchado a la salida de su día (H41)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-085 (líneas y fecha por línea), T-046 (residencia), T-086 (catálogo y tarjetas como la maqueta) |
| **Reglas** | D6-D18 (`plan-tours-venta-simple-costo-real.md` §6) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 12, H41 (aprobada 27-oct-2026); D-AGE-034 |
| **Rama** | `feat/T-047-venta-directa-tour` |

## Contexto
Venta directa solo vende una tarifa de proveedor o un concepto libre. El tour del catálogo, lo más
vendido en el día a día, obliga a pasar por el cotizador. Y como un servicio suelto no tiene tour,
la venta directa nunca entra en la salida operativa: no cuenta en el almuerzo ni en el vehículo.

## Alcance
- Seguir la **maqueta aprobada** (enlace en D-AGE-034): pestaña, tarjetas de tour y tarjeta de la
  línea de tour como ahí.
- Pestaña **Tours** (la primera) en el catálogo de la pantalla de T-085: tarjetas de los tours del
  destino elegido, sin escribir, con buscador y «Desde S/ X p/p». Un tour es una línea más
  (etiqueta Tour), con su selector de fecha y su detalle («2 adultos nac. × 45 + 1 adulto ext. ×
  60 + …»).
- Backend: línea con `origen_tipo='tour'` + `paquete_plantilla_id` dentro de `lineas[]`. Agrega el tour a la alternativa con el
  **mismo camino que el cotizador** (`AlternativaItemController::desdePlantilla()`), respetando
  `modo_tours`: en `nuevo`, una línea con precio por persona, residencia y temporada de la fecha
  (`TourLineaService`); en `clasico`, como hoy en el cotizador. Nada reimplementado.
- Si todavía no hay destino, se toma del primer tour (se llena solo y se puede cambiar).
- La temporada sale de la **fecha de la línea** (inicio + día − 1), no de la fecha de inicio.
- Al crear la reserva, los ítems del tour se enganchan a la salida **de su fecha** como al aceptar
  una cotización (`crearReservaDesdeAlternativa()` → `SalidaEngancheService`: confirmarlo con
  test). La respuesta trae el aviso de salida cancelada de T-042.
- Total en vivo (T-045) con el precio del tour: la vista previa de T-085 calcula la línea de tour
  con `TourLineaService::specDelTour()` + `calcular()`, sin guardar, y devuelve su afectación (un
  tour de IGV mezclado son varias líneas de la misma carga, que sí pueden ir en un comprobante).
- Combos (`paquete_combo`): en el catálogo se ven con el botón «Solo desde el cotizador»
  deshabilitado (D-AGE-034). Si igual llega uno por API: 422 «Los combos se venden desde el
  cotizador».

## Fuera de alcance
- Paquetes y combos (D-AGE-034).
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
