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

## Fase 4 — Frontend (rama `feat/creditos-fase4-frontend`)
Las 8 pantallas de `04-frontend.md` (listado, detalle, cobrar, nuevo/editar, cobranza del día, migrar, configuración) + menú, caja y Roles.

## Fase 4b — Documentos (01-oct, `feat/creditos-fase4b-documentos`)
Recibo 80mm/A4, contrato con plantilla versionada, cronograma, estado de cuenta, constancia, acuerdo de reprogramación; URLs firmadas. Ver `04b-documentos.md`.

## Fase 4c / 4c.1 — Ficha del cliente y ajustes (02-oct)
Ficha de cobro, cartera por asesor/cobrador, página del cliente para todos los giros, documento único. 4c.1: saldo a favor completo, cartera al crear, bloqueos de concurrencia, rol Asesor, castigo automático tolerante a errores, alta de tenant con caja, traspaso de cartera. Ver `04c-ficha-cliente.md`.

## Fase 4d — Panel, Agenda y reportes (02-oct, `00a1ec7`)
Panel de inicio (reemplaza al Dashboard en el giro), Agenda de cobranza, 6 reportes con PDF/Excel, foto diaria de cartera. Ver `04d-reportes.md`.

## Revisión de edición (03-oct, `1390e30`)
7 bugs reales corregidos al editar/corregir un crédito (ver 00 §1.9 y §1.12). `RevisionEdicionTest`.

## Merge y producción
- 04-oct · Merge a `main` en `1974b7c` (6 ramas de créditos + Inicio + revisión de edición).
- 05-oct · Desplegado en producción; tenant real `credishirley` creado.
- 07-oct · Tareas programadas en hora de Lima (`f9179b8`), descarga de backups, menú profesional, protección del Super-Admin (`8777c9f`), "Equivale a X% mensual" oculto (`613b1cf`).
- 08-oct · Renovación ampliada + revisión de octubre (`197efc8`, merge `85154d8`), junto con la auditoría de seguridad (`37452eb`). Desplegado el mismo día. Usuario del dueño creado en credishirley.

## Renovación ampliada y revisión de octubre (08-oct, `197efc8`)
- Renovar con neto 0 (sin dinero) o negativo (el cliente paga la diferencia: entrada `credito_pago` referida al pago de renovación). Recibo con desglose; Panel, Agenda y conciliación lo cuentan como cobrado (`CobradoAlCliente`, regla única). `RenovacionPagandoInteresTest`.
- Castigo revertido: el automático espera otra vez `dias_para_castigo` desde la reversión. Vista previa del pago con fecha anterior calculada a esa fecha. `evaluarParaOtorgar` bloquea al cliente al activar/renovar/subir capital. Anular una devolución de saldo a favor. `RevisionOctubreTest`. Suite del módulo: 203/203.

## Pendientes fuera del módulo
- `TipoCambioSunatAplicadoSaleTest` falla de noche (UTC vs America/Lima) — tarea aparte.
- ~~Producción: confirmar el cron `schedule:run`~~ — confirmado con `schedule:list` (07-oct).
- Bases locales: backup → `tenants:migrate-verticales` → `migrate` → `MenuItemsSeeder`.

## Fases
| Fase | Contenido | Estado |
|---|---|---|
| 1-3 | Motor, datos, API | ✅ en producción |
| 4 | Frontend `views/creditos/` responsive + ítems de menú | ✅ en producción |
| 4b | Documentos: contrato con plantilla, cronograma, recibo 80mm/A4/WhatsApp, estado de cuenta, constancia | ✅ en producción (QR de verificación pendiente) |
| 4c / 4c.1 | Ficha del cliente, cartera por asesor/cobrador, ajustes | ✅ en producción |
| 4d | Panel, Agenda de cobranza, reportes, foto diaria, conciliación | ✅ en producción |
| 5 | Alta del tenant del cliente + prueba end-to-end | ✅ tenant `credishirley` creado (05-oct); faltan tasa máxima (TEA) y contrato del abogado |
| 6 | Sistema de módulos + adicionales en factura | Sin fecha |
| 7 | Garantes y prendas | Sin fecha |
| 8 | Cobradores con caja propia, transferencias, gestiones y promesas | Sin fecha (cartera por cobrador ya existe desde 4c) |
| Adicional | Crédito "Solo interés" (cuotas de solo interés, capital al final), configurable — diseñado 08-oct, opción A del interés mínimo; ver `adicional-solo-interes.md` | Para más adelante; hoy se resuelve renovando cada mes |
| Posterior | Reprogramación con montos, amortización parcial, otros métodos, zonas, GPS, offline (transversal), WhatsApp automático | — |
