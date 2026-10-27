# T-062 — CRM C6b: reportes de conversión, origen, vendedor y motivos

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-052, T-059 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 16, fase C6 (27-oct-2026) |
| **Rama** | `feat/T-062-crm-reportes` |

## Contexto
Con origen, vendedor, resultado y motivos registrados, la agencia puede saber qué canal trae
ventas, quién convierte más y por qué se pierde. Falta la pantalla que lo muestre.

## Alcance
- Pantalla **"Reportes comerciales"** (menú de Agencia), con rango de fechas (por fecha de
  creación de la cotización) y filtros por vendedor y origen:
  - **Embudo:** consultas → enviadas → ganadas → perdidas → abiertas, con % de conversión.
  - **Por origen:** consultas, ganadas, % de conversión y monto vendido por canal.
  - **Por vendedor:** lo mismo por vendedor (incluye usuarios inactivos, marcados).
  - **Motivos:** de pérdida y de ganancia, con cantidad y %.
  - **Tiempo de respuesta:** mediana de horas entre creación y primer envío.
- Monto vendido: total de la alternativa aceptada en su moneda (PEN y USD por separado, sin
  convertir).
- Exportar a Excel.

## Fuera de alcance
- Gráficos de tendencia mensual (si se piden, aparte).
- Datos anteriores a T-050/T-059: las cotizaciones viejas cuentan como "sin origen" y sin motivo.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/` (controller nuevo de reportes comerciales) + export
- `admin-start-kit/src/views/agencia-viajes/reportes-comerciales/index.vue`, menú (`menu_items`)

## Criterios de aceptación
- [ ] **CA1** — 10 cotizaciones (4 ganadas, 3 perdidas, 3 abiertas) → embudo y % correctos. → test
- [ ] **CA2** — Por origen y por vendedor suman lo mismo que el embudo. → test
- [ ] **CA3** — Montos por moneda, sin mezclar. → test
- [ ] **CA4** — Consultas en número fijo (agregados en SQL). → test (contar consultas)
- [ ] **CA5** — Prueba funcional en agencia-demo con Excel; suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: permiso nuevo `reportes_comerciales.ver` (sembrado para administradores; `roles:sync-permisos-nuevos`)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: rango validado, máximo 12 meses
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: sumas en SQL con `numeric`
- [ ] Fechas con `HoraPeru`: filtro por día con `HoraPeru::inicioDiaUtc()`/`finDiaUtc()`
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: solo la del permiso y el menú

## Cómo verificar
```
php artisan test --filter=ReportesComerciales
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
