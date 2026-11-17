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
- **Commits:** `08da7caa` (backend: servicio `ReporteComercial`, `ReporteComercialController`,
  `ReporteComercialExport`, permiso, rol, menú, tests), `0d15a156` (pantalla
  `reportes-comerciales/index.vue`, `reporteComercialService.ts`, ruta), más el de este resultado.
- **Tests:** 14 nuevos en `ReportesComercialesTest` (77 aserciones): CA1 embudo 4/3/3; día de Perú
  en el borde del rango; venta directa y modificación fuera; anulada; reserva modificada (monto de
  la versión nueva); CA2 origen y vendedor suman lo del embudo (incluye inactivo, origen
  desactivado, sin origen y sin vendedor); filtros; CA3 montos PEN/USD; CA4 mismas consultas con 5
  que con 25 cotizaciones (≤ 4); motivos con «Sin motivo»; mediana; sin `ver_todas` solo lo propio;
  rango > 12 meses, invertido o mal escrito → 422; permiso en la ruta y 404 en retail; Excel
  (secciones, ceros escritos, negritas). `MenuGirosTest` actualizado con el ítem nuevo.
  Suite completa: 2240 tests, 2236 en verde; fallan solo los 4 conocidos (3 de T-103 y el
  intermitente del combo). `type-check`: los mismos 47 errores previos, ninguno en archivos tocados.
- **Prueba funcional (CA5):** agencia-demo con Playwright como «Admin Agencia Demo»: ítem en
  VENTAS, pantalla con datos de noviembre (13 consultas: 4 ganadas, 9 abiertas; coincide con
  `estadoResumen()` del listado), filtro por origen y Excel descargado y leído (ahí salieron los dos
  defectos del Excel que se corrigieron antes del commit: ceros vacíos y negritas corridas).
- **Decisiones tomadas con el usuario (16-nov-2026), antes de codificar:**
  1. Ganada con la reserva cancelada = **«Anuladas»**, fila propia del embudo; no suma al monto.
     La conversión es ganadas / consultas (sin anuladas).
  2. Sin `cotizaciones.ver_todas`, el reporte muestra solo las cotizaciones propias
     (`Cotizacion::propias()`), también en la lista de vendedores del filtro.
  3. Toda ganada (o anulada) cuenta como **enviada**, aunque se haya aceptado desde borrador. El
     tiempo de respuesta usa solo la primera `fecha_envio` real.
  4. % de motivos sobre el total del tipo, con fila «Sin motivo». Los motivos de ganancia cuentan
     las ganadas y las anuladas (las dos se ganaron).
- **Desviaciones de la tarea y por qué:**
  - El embudo tiene una etapa más (Anuladas): decisión 1, para que las etapas sumen las consultas.
  - Monto de una reserva modificada: el total de la alternativa a la que apunta **hoy** la reserva
    (la versión nueva), no el de la alternativa original (`reemplazada`); es lo que se vendió.
  - El ítem de menú lo agrega `MenuItemsSeeder` (como Seguimiento en T-051), no una migración: la
    única migración es la del permiso.
  - Rango por defecto: el mes en curso. Filtros `vendedor_id` / `origen_contacto_id` aceptan `sin`.
- **Al desplegar:** 1 migración en Agencia → `tenants:migrate-verticales` (revisar la salida);
  luego `php artisan roles:sync-permisos-nuevos "Database\Seeders\AgenciaViajesRolesSeeder"`
  (`deploy.sh` no lo corre; el Propietario lo recibe solo con `roles:sync-propietario`). Los
  usuarios tienen que volver a iniciar sesión para ver la pantalla (ver hallazgo).
- **Datos en agencia-demo:** ninguno creado; se aplicó la migración, el permiso al rol y el menú.

## Hallazgos fuera de alcance
- PLA: el router del frontend toma los permisos guardados al iniciar sesión y, si falta uno, manda
  a `/auth/error-500` («Error 500») en vez de un aviso de permiso. Tras un deploy con permiso
  nuevo, quien entra por el menú sin volver a iniciar sesión ve «Error 500»
  (`admin-start-kit/src/router/index.ts`, línea ~80).
