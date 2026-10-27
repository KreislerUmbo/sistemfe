# T-043 — Tablero de salidas: "Siguiente" cambia de página de verdad (H36)

| | |
|---|---|
| **Módulo** | AGE (frontend) |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión de salidas operativas", H36 (26-oct-2026) |
| **Rama** | `fix/T-043-salidas-paginacion` |

## Contexto
`SalidaOperativaController::index()` pagina de a 20, pero `salidas/index.vue` → `list()` no manda
`page`: "Siguiente" muestra "Página 2" con las mismas 20 salidas. Con más de 20 en el rango
(7 días por defecto) el resto no se ve.

## Alcance
- `salidaOperativaService.listar()` acepta `page`; `list()` manda `currentPage`.
- Cambiar un filtro (fechas, estado) o "Limpiar" vuelve a la página 1.
- "Siguiente" se deshabilita con `currentPage * 20 >= total` (hoy mira `salidas.length < 20`).
- Mostrar "Página N de M".

## Fuera de alcance
- Cambiar el tamaño de página o el orden.

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/salidas/index.vue`
- `admin-start-kit/src/services/admin/salidaOperativaService.ts`

## Criterios de aceptación
- [ ] **CA1** — Con 25 salidas en el rango, la página 2 muestra las 5 restantes. → prueba funcional (o con datos creados para la prueba en agencia-demo, borrados después)
- [ ] **CA2** — Cambiar el filtro desde la página 2 vuelve a la 1. → prueba funcional
- [ ] **CA3** — `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:** rama `fix/T-043-salidas-paginacion` (ver `git log`).
- **Tests:** solo frontend; `type-check` con los mismos 46 errores previos y `build` correcto.
- **Prueba funcional (agencia-demo, 31 salidas temporales en el rango por defecto, borradas al
  terminar; ninguna quedó enganchada):** 36 salidas en el rango → página 1 con 20, "Página 1 de
  2"; "Siguiente" → página 2 con las 16 restantes y "Siguiente" deshabilitado; cambiar el
  filtro de estado desde la página 2 vuelve a la página 1.
- **Cómo quedó:** `salidaOperativaService.listar()` acepta `page`; `list()` manda
  `currentPage`; los filtros (fechas y estado) llaman a `buscar()`, que vuelve a la página 1;
  "Siguiente" se deshabilita con `currentPage >= totalPaginas` (antes miraba
  `salidas.length < 20`); se muestra "Página N de M".
- **Desviaciones de la tarea y por qué:** ninguna.

## Hallazgos fuera de alcance
- Ninguno.
