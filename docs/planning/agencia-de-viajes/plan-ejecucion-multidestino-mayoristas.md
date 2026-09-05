# Plan de ejecución — Multi-destino + Mayoristas + Contenido reutilizable

> Este documento traduce `auditoria-arquitectonica-agencia-viajes.md`
> (§7, §9, §9.1, §19, §23) a sesiones concretas de Claude Code, en el
> mismo formato que usa `plan-hoja-de-ruta-ejecucion.md`. No vuelve a
> diseñar nada — cada decisión de diseño ya se cerró en la auditoría o
> en la conversación de afinamiento posterior; acá solo se secuencia.
>
> **Alcance de esta ronda (decidido con el usuario, 01-sep-2026):**
> multi-destino (`alternativa_destinos`), reubicación de `OpcionMayorista`,
> y `contenido_tour`. **Quedan fuera a propósito**: pagos a proveedor
> (Obligación/Cuota/Pago/Aplicación, §12) y fidelización/liquidación
> (§23.3) — se retoman en una ronda posterior, sin que esta ronda cierre
> ninguna puerta para construirlas después.
>
> **Decisiones ya cerradas para esta ronda (no volver a discutir):**
> - Una reserva por viaje completo (todos los destinos juntos) — no una
>   reserva por destino.
> - `dia_referencial` se reinicia por destino (día 1-2 en Tarapoto,
>   día 1-10 en México) — no numeración corrida.
> - Facturación partida por destino (§23.1.5) queda fuera de esta ronda
>   — se factura la reserva como hoy, sin partir por destino.
> - El plan no compromete fechas/tiempos por sesión — cada fila es una
>   unidad de trabajo, no un estimado de duración.
>
> **Reconciliado 01-sep-2026 (bloqueo levantado):** `auditoria-
> arquitectonica-agencia-viajes.md` (la síntesis base de este plan)
> afirmó por error que
> `docs/planning/agencia-de-viajes/plan-refactor-mayoristas-tramos.md`
> no existía en el proyecto — sí existía, y proponía `alternativa_tramos`
> como diseño alternativo para el mismo problema que acá se resuelve con
> `alternativa_destinos`. **El usuario decidió explícitamente:
> `alternativa_destinos` es el camino — `alternativa_tramos` no se
> construye.** `plan-refactor-mayoristas-tramos.md` quedó cerrado y
> marcado como superado por este plan (ver su propio historial). 12a se
> corrigió también (ver su fila) para sacarle el punto que chocaba con
> `plan-matriz-hoteles-cotizador.md`. 12b puede avanzar sin más bloqueos
> de reconciliación de arquitectura.
>
> **C1 ya resuelto (01-sep-2026):** `AlternativaController::
> resolverNombreItemPdf()` (línea 610) imprimía
> `opcionMayorista?->proveedor?->razon_social` en el PDF que ve el
> cliente, contradiciendo la afirmación de §9 de esta misma síntesis
> ("el mayorista nunca se imprime") — corregido en §9.3 de la auditoría
> + brief `PEGAR-EN-CLAUDE-CODE-fix-leak-mayorista-pdf.md`, listo para
> ejecutar, sin dependencias de esta secuencia 12a-12h.

---

## 0. Cómo usar este documento

Es un documento de referencia, igual que `plan-modulo-cotizaciones-reservas.md`
lo es para las sesiones 7-11 de la hoja de ruta. Las filas nuevas de
`plan-hoja-de-ruta-ejecucion.md` (12a-12h) apuntan acá y a los briefs
`PEGAR-EN-CLAUDE-CODE-*.md` propios de cada sesión. El orden de la tabla
de la sección 2 es el orden real de dependencias — no saltar filas.

---

## 1. Por qué este orden (resumen de dependencias)

```
12a (gaps Fase 0, sin dependencias)
  │
  ▼
12b (crear alternativa_destinos + backfill, tabla aditiva)
  │
  ├──▶ 12c (AlternativaItem.alternativa_destino_id + dia_referencial por destino)
  │
  └──▶ 12d (OpcionMayorista.alternativa_destino_id, reemplaza alternativa_id)
              │
              ▼
        12e (contenido_tour — no depende estructuralmente de 12d,
             pero conviene después para no tocar OpcionMayorista
             dos veces en la misma ventana de cambios)
              │
  12c + 12d + 12e completas
              │
              ▼
        12f (UI multi-destino en el cotizador — la de mayor riesgo
             de adopción, ver §20 de la auditoría)
              │
              ▼
        12g (limpieza final — dropear columnas deprecadas)

12h (decisión pendiente, no bloquea nada de lo anterior — ver §3)
```

`12c` y `12d` pueden ejecutarse en cualquier orden entre sí una vez
cerrada `12b` (ambas leen de `alternativa_destinos`, no se pisan porque
tocan tablas distintas), pero **no en la misma sesión** — regla de oro
del proyecto: una sesión, un commit, un chat.

---

## 2. Sesiones

| # | Sesión | Qué construye | Depende de | Brief |
|---|---|---|---|---|
| 12a | Fase 0 — gaps de bajo riesgo | Guard `alternativa.estado==='aceptada'` en `AlternativaController::update()` para `descuento_global_pct/monto`; índice único parcial `(alternativa_id) WHERE estado='elegida'` en `opcion_mayorista` | Ninguna | `PEGAR-EN-CLAUDE-CODE-fase0-gaps-mayoristas-multidestino.md` (listo, corregido 01-sep-2026 — ver nota abajo) |
| 12b | Crear `alternativa_destinos` | Migración + modelo `AlternativaDestino` (`alternativa_id`, `destino_atractivo_id`, `orden`, `fecha_inicio/fin`), backfill 1 destino por alternativa existente desde `Cotizacion.destino`/`fecha_viaje_desde/hasta`. Sin tocar `AlternativaItem`/`OpcionMayorista` todavía | 12a | Pendiente de escribir — se redacta al empezar esta sesión |
| 12c | `AlternativaItem` → `alternativa_destinos` | `alternativa_items.alternativa_destino_id` (nullable, default = destino único de 12b), recalcular `dia_referencial` relativo al inicio del destino (decisión ya cerrada: reiniciar por destino), doble escritura de compatibilidad con el código que hoy asume un solo destino | 12b | Pendiente |
| 12d | `OpcionMayorista` → `alternativa_destinos` | `opcion_mayorista.alternativa_destino_id` reemplazando `alternativa_id`, índice único parcial `(alternativa_destino_id) WHERE estado='elegida'` (reemplaza al de 12a, que queda como paso intermedio de transición) | 12b | Pendiente |
| 12e | `contenido_tour` | Tabla nueva (§9.1: sin precio, sin moneda), FK opcional `contenido_tour_id` en `OpcionMayorista`/`OpcionMayoristaOpcional`, **snapshot de descripción/fotos al vincular** (no referencia viva — cierra el hallazgo §23.1.8, para no romper la disciplina de congelamiento del resto del sistema), scoping de tenant confirmado (§23.1.9), UI de biblioteca con buscador antes de crear (mitiga duplicados, §23.1.9) | 12d (recomendado, no estructural) | Pendiente |
| 12f | UI multi-destino en el cotizador | Todo lo de §7.1 de la auditoría: chips de destino sobre las pestañas de día, comparador de mayoristas acotado al destino activo, subtotal por destino en el panel de precio, botón "+ Agregar destino", PDF agrupado por destino con encabezado de sección | 12c + 12d + 12e | Pendiente — es la sesión más grande, probablemente se divide en 2-3 briefs al llegar (backend de agregación de subtotales, componente de chips, PDF) |
| 12g | Limpieza final | Dropear `alternativa_items.opcion_hotel_tarifa_id`/`paquete_plantilla_id`, dropear `opcion_mayorista.alternativa_id` una vez todo lea de `alternativa_destino_id` | 12f estable en producción un ciclo de reservas completo | Pendiente |

---

## 3. Sesión 12h — diseño listo, falta brief (no bloquea esta secuencia)

**Reasignación en vivo de `OpcionMayorista` en `ReservaItem`** (hallazgo §23.1.4): hoy `ReservaItem` mantiene `proveedor_tarifa_id`/`guia_id` vivos y reasignables después de aceptar, pero no hay un equivalente para cuando el mayorista elegido no puede honrar precio/cupo tras la aceptación — justo el caso de mayor impacto en el segmento internacional. El usuario confirmó (01-sep-2026) que es un caso real de operación y vale la pena construirlo. Diseño de datos y de UI cerrado en `auditoria-arquitectonica-agencia-viajes.md` §9.2 (columnas `opcion_mayorista_original_id`, `motivo_reasignacion_mayorista`, `fecha_reasignacion_mayorista`, `veces_reasignado_mayorista`; mismo patrón que `reprogramar()`) y mockup `ReasignarMayorista.dc.html` (Artifact "Cotizador Multidestino"). No bloquea 12a-12g (son cambios de agrupación, no de reasignación operativa). Brief de ejecución listo: `PEGAR-EN-CLAUDE-CODE-reasignar-mayorista-vivo.md` — se puede insertar como sesión `12h` en cualquier punto después de 12d.

---

## 4. Verificación transversal (aplica a 12b-12g, no repetir la lista en cada brief)

- Cero filas de producción perdidas en cada migración — confirmar con conteo antes/después, no solo "la migración corrió sin error".
- `PriceEngineService::convertirMoneda()`/`evaluarPiso()` sin cambios de comportamiento en cada sesión de 12b a 12e (la moneda no se mueve de nivel, §13 de la auditoría) — test de regresión explícito.
- Ningún reporte/PDF existente que lea `Cotizacion.destino`/`fecha_viaje_desde/hasta` se rompe mientras esos campos sigan siendo el resumen calculado (Fase 1 de la auditoría, §7) — confirmar contra `ReporteOperativoController` y los PDFs reales, no solo contra tests.
- El PDF comercial nunca imprime el proveedor mayorista, con o sin `alternativa_destinos` — test de regresión explícito en 12d y 12f (§15 de la auditoría).
