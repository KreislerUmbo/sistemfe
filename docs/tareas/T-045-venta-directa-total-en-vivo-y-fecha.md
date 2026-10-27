# T-045 — Venta directa: total en vivo, modo de precio claro y fecha por defecto hoy (H42, H44)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 12, H42 y H44 (aprobadas 27-oct-2026) |
| **Rama** | `feat/T-045-venta-directa-total-fecha` |

## Contexto
En Venta directa el vendedor no ve el monto hasta llegar a la reserva, y el modo de precio viene en
"Tarifa fija": una entrada por persona para 4 pasajeros con cantidad 1 cobra una sola entrada. La
fecha es opcional y, si queda vacía, el servicio no sale en el Reporte operativo.

## Alcance
- **Total en vivo** en `venta-directa.vue`: se recalcula al cambiar servicio, modo, cantidad o
  pasajeros. Mismo cálculo que el backend (`AlternativaItemController::store()`); si no se puede
  replicar sin riesgo, un endpoint de vista previa que use ese mismo cálculo sin guardar nada.
- **Modo de precio sin valor por defecto** para una tarifa de proveedor: dos opciones visibles,
  "Por persona (N pasajeros): S/ X" y "Por unidad (cantidad C): S/ Y", con el total de cada una.
  La cantidad solo se muestra en "Por unidad".
- **Fecha obligatoria**, por defecto `hoyPeru()`, con acceso rápido "Mañana" (`sumarDiasISO()`).
  Backend: `fecha_servicio` pasa a `required|date`.
- El total se muestra también en el botón: "Crear venta directa · S/ X".

## Fuera de alcance
- Tours (T-047), pasajeros con residencia (T-046), cobrar en el mismo paso (T-048).
- Elegir el modo por la unidad de la tarifa: el vendedor elige, viendo los dos totales.

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/venta-directa.vue`
- `admin-start-kit/src/services/admin/ventaDirectaService.ts`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php`
- `api-sistema-fe/tests/Feature/AgenciaViajes/VentaDirectaBloque1Test.php`

## Criterios de aceptación
- [ ] **CA1** — Tarifa de S/ 30 con 4 pasajeros: las opciones muestran S/ 120 (por persona) y S/ 30 (por unidad); sin elegir una, no se puede crear. → prueba funcional en agencia-demo
- [ ] **CA2** — El total mostrado coincide con el total de la reserva creada (por persona, por unidad y concepto libre). → test (vista previa vs. creado) o prueba funcional
- [ ] **CA3** — Sin fecha → 422. → test
- [ ] **CA4** — La pantalla abre con la fecha de hoy en hora de Perú; "Mañana" pone el día siguiente. → prueba funcional
- [ ] **CA5** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: si hay endpoint de vista previa, mismo permiso que crear la venta directa
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: `fecha_servicio` obligatoria
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: el total en vivo redondea igual que el backend
- [ ] Fechas con `HoraPeru`: hoy = `hoyPeru()`, nunca `toISOString()`
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=VentaDirecta
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
