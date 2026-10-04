# Créditos — Historial (solo consulta; no hace falta leerlo para trabajar)

## Proceso
- 2026-09-28/29 · Plan construido por conversación: reglas de negocio, casos (adelanto, atraso, pago parcial, excedente, liquidación, reprogramación, impago prolongado), garantías, módulos contratables, roles/cobradores, documentos, reportes, límites, migración, renovación, pago retroactivo, ficha de cobro. Revisión de coherencia (v11) y aclaraciones técnicas de Claude Code (sección 12, v21-v24). Mockups: canvas "Pantallas Módulo Créditos".
- Auditoría v1 del código (`docs/planning/plan-modulo-creditos-auditoria.md`): `Credit` ocupado por Amortizaciones, `giro` ya existía (se usa `giro='creditos'`), clientes reutilizables, caja con `reference_type`, centavos enteros como patrón, migraciones en `tenant/core/`.
- 2026-09-30 · Plan v24 + decisiones consolidados en `00-reglas-y-modelo.md` (este esquema de archivos).

## Fase 1 — Motor (commits `3d2aa59`, `96b7df0`)
7 clases puras (`GeneradorCronograma`, `CalendarioLaborable`, `CalculadoraInteres`, `CalculadoraMora`, `CalculadoraLiquidacion`, `AplicadorPagos`, `EvaluadorLimites`), 24 DTOs, 12 enums, 3 excepciones, `Redondeo`/`Fecha`/`Tasa`. 112 tests; ejemplos del plan exactos; prueba de mutaciones 5/5 tras agregar caso de abono al día siguiente del vencimiento. Ajustes aprobados: `PagoAAplicar::$esCierre`, `montoExigible`, `cierresInsuficientes`, `fechaForzadaASiguiente`, `esRecupero`, `ModoRedondeo`, `Redondeo::multiplicar()`, DTO `Abono`.

## Fase 2 — Datos (commit `675e320`)
Migraciones de todas las tablas + extras (`credito_auditoria`, `credito_correlativos`, `credito_castigos`, `credito_saldo_favor_movimientos`, `es_cierre`), 15 permisos, 26 modelos, 24 enums, `CorrelativoCreditoService`, giro `creditos` con roles y feriados, `menu_items.giros_excluidos`. `ReglasMora` con lista de `PeriodoCastigo`. 138 tests del módulo. Índice único de `numero_credito` normal (Postgres admite varios NULL).

## Fase 3 — API (rama `feat/creditos-fase3-api`)
37 rutas en `routes/creditos.php` (test que exige permiso en todas), servicios de activar, cobrar (retroactivo, saldo a favor, devolución), anular con reaplicación, liquidar, corregir, anular crédito/renovación, reprogramar, condonar, castigar/revertir, renovar, migrar, consultas y cobranza del día; job `creditos:escalamiento` 02:30 Lima; `credito_operaciones`; `creditos.ver_todos`. 1.118 tests backend (221 del módulo), incl. lock con dos conexiones. Bugs corregidos: mora congelada sin fecha (motor: `MoraCongelada`), ancla del cronograma (`fecha_primer_vencimiento` nullable = fecha pedida), defaults de BD en modelos recién creados. Contrato al activar → fase 4b.

## Pendientes fuera del módulo
- `TipoCambioSunatAplicadoSaleTest` falla de noche (UTC vs America/Lima) — tarea aparte.
- **Producción (antes de fase 5)**: confirmar que el servidor OVH ejecuta `php artisan schedule:run` cada minuto; sin eso no corren `creditos:escalamiento` ni otros jobs.
- Bases locales: backup → `tenants:migrate-verticales` → `migrate` → `MenuItemsSeeder`.

## Fases siguientes
| Fase | Contenido |
|---|---|
| 3 | API (ver `03-api.md`) |
| 4 | Frontend `views/creditos/` responsive + ítems de menú |
| 4b | Documentos: contrato con plantilla, cronograma, recibo 80mm/A4/WhatsApp, estado de cuenta, constancia |
| 4c | Reportes: panel, cobranza, cartera, morosidad, ingresos, castigados, cobrar al garante, control; foto diaria; conciliación |
| 5 | Alta del tenant del cliente + prueba end-to-end |
| 6 | Sistema de módulos + adicionales en factura |
| 7 | Garantes y prendas |
| 8 | Cobradores, cartera, caja del cobrador, gestiones |
| Posterior | Reprogramación con montos, amortización parcial, otros métodos, zonas, GPS, offline (transversal), WhatsApp automático |