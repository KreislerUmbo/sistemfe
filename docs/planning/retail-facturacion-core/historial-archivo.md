# Historial archivado — Retail / Facturación Core

> Documentos de esta carpeta retirados por estar cerrados y/o
> superados. `plan-modulo-amortizaciones.md` y `plan-modulo-caja.md`
> **NO** están acá — siguen activos en la carpeta (cada uno con su propio
> banner de estado al inicio: Amortizaciones cerrado pero con detalle
> técnico todavía útil; Caja con la Fase 7 real pendiente de activación).

---

## `plan-multitenant-umbo.md` (600 líneas, borrado 20-ago-2026 — no archivado, duplicado sin valor)

Era un borrador **anterior y superado** de `plan-modulo-amortizaciones.md`
— mismo documento ("Plan: Módulo de Amortizaciones — Ventas a Crédito"),
~90% de contenido idéntico, sin el banner "✅ MÓDULO CERRADO" ni las
correcciones de Fase 6-9 que sí tiene la versión vigente. No aportaba
nada que no esté ya en `plan-modulo-amortizaciones.md` (que sigue activo
en esta misma carpeta) — se borró directo, sin resumir, por ser
puramente redundante.

## `plan-modulo-series-comprobantes.md` (224 líneas, archivado 20-ago-2026)

Diseño de series/numeración de comprobantes SUNAT + Nota de Venta interna
(documento no-fiscal, terminal). **Completo — construido, verificado y
migrado a `umbo` real el 19-jul-2026.** 6 pasos originales cerrados, con
2 gaps reales encontrados y cerrados el mismo día: `AdvanceController`
desconectado del módulo (todo adelanto nuevo caía al mecanismo legado de
numeración) y preview de serie faltante en el formulario.

**Pendientes reales que quedaron explícitamente documentados, sin
resolver — no bloquean el módulo, no retomar sin que el usuario lo pida
aparte:**
- Completar el Catálogo 01 SUNAT del código `15` en adelante
  (espectáculos públicos, retenciones, etc.) — no adivinado a propósito,
  es tabla de referencia legal.
- Migrar NC/ND (`07`/`08`) de `note_series`/`SerieNotaResolver` a este
  módulo — el propio comentario de `note_series` ya anticipaba este
  reemplazo.
- CRUD administrable completo de `branches` (hoy solo listado de solo
  lectura, heredado de Caja Fase 5).
- Reporte Registro de Ventas SUNAT/PLE (no construido) — cuando se
  construya, usar siempre `Sale::scopeSoloDocumentosFiscales()`, nunca
  inferir por prefijo de `serie`.
