# T-049 — Venta directa: varias líneas en la misma venta y destino del árbol (H46, H47)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-047 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 12, H46 y H47 (aprobadas 27-oct-2026) |
| **Rama** | `feat/T-049-venta-directa-lineas` |

## Contexto
Una venta directa admite un solo servicio: tour + traslado son dos ventas, dos reservas y dos
comprobantes. El destino es texto libre y la biblioteca de servicios empieza vacía hasta escribir.

## Alcance
- `lineas[]` en `VentaDirectaController::store()`: cada línea es tour, tarifa de proveedor o
  concepto libre, con su fecha (por defecto la de la venta). Todas en la misma alternativa y la
  misma reserva. El payload de una sola línea sigue funcionando.
- Moneda: todas las líneas en la moneda de la primera; una tarifa en otra moneda se convierte con
  el tipo de cambio del día, como en el cotizador (`tipo_cambio_aplicado`).
- Pantalla: "Agregar otro servicio", cada línea con su total y el total general en vivo; quitar
  una línea.
- Destino con `DestinoTreeSelect`; si la primera línea es un tour, se toma de él. La biblioteca de
  servicios se filtra por el destino y muestra resultados sin escribir.

## Fuera de alcance
- Paquetes de varios días y combos (pregunta 3 de la obs. 12, sin decidir).
- Mayorista y pasaje aéreo en venta directa.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php`
- `admin-start-kit/src/views/agencia-viajes/venta-directa.vue`
- `admin-start-kit/src/components/AgenciaViajes/DestinoTreeSelect.vue`

## Criterios de aceptación
- [ ] **CA1** — Tour + traslado → una reserva con las dos líneas, un solo comprobante al facturar. → test
- [ ] **CA2** — Tarifa en USD con venta en soles → convertida con el tipo de cambio del día. → test
- [ ] **CA3** — Payload antiguo de una línea → igual que hoy. → test
- [ ] **CA4** — Elegir un destino muestra sus servicios sin escribir. → prueba funcional
- [ ] **CA5** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: `lineas` mínimo 1, máximo 20
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: conversión redondeada como en el cotizador
- [ ] Fechas con `HoraPeru`: fecha por línea, por defecto la de la venta
- [ ] Tributario sin fallback silencioso: si las líneas mezclan tratamiento tributario, el guardia de facturación ya lo bloquea; avisarlo al armar la venta
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
