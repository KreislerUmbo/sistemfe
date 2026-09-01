# Índice — Proyecto Multitenant Umbosystem SaaS

> Este documento es el punto de entrada de TODO el proyecto. Léelo primero
> en cualquier sesión nueva antes de buscar cualquier otro archivo — te
> dice exactamente qué documento activo cubre qué, para no tener que
> adivinar ni abrir archivos que ya no aplican.
> **Convención de esta carpeta (desde 20-ago-2026): cada subcarpeta puede
> tener su propio `historial-archivo.md`** — ahí viven, comprimidos, los
> planes/briefs ya cerrados que se archivaron para no tener que releerlos
> completos en cada sesión nueva. No hace falta abrirlos para trabajar,
> solo para auditoría o memoria histórica. Si un documento que buscás no
> aparece en la tabla de abajo, probablemente está ahí — revisá el
> `historial-archivo.md` de la carpeta correspondiente antes de asumir
> que se perdió.
> Última actualización: 20-ago-2026 (v3 — reescrito de punta a punta; la
> v2 databa de 24-jul-2026 y describía el vertical como "sin construir
> todavía", completamente desactualizado a esta altura).

---

## 📁 Raíz — documentos transversales (aplican a TODOS los rubros/verticales)

| Documento | Qué cubre |
|---|---|
| `CLAUDE.md` (raíz del repo, no de `docs/planning/`) | Plan maestro / bitácora completa del proyecto — el documento más grande y más actualizado, con la narrativa sesión por sesión de todo lo construido |
| `arquitectura-multitenant-backend_1.md` | Cómo funciona el multi-tenancy (stancl/tenancy, BD central vs. BD por tenant, patrón core/verticals), estado del panel superadmin, y el modelo de 3 capas para el menú lateral (giro + plan + roles) |
| `historial-archivo.md` | Guía de despliegue a producción OVH (ya ejecutada, 16/17-ago-2026 — no confiar en ella como estado actual) y el plan de Sesión 0 (infraestructura core/verticals, cerrado 27-jul-2026), ambos archivados |

## 📁 Agencia de Viajes

Vertical específico, **el más avanzado del proyecto** — cotizaciones,
proveedores, reservas, itinerarios, destinos, tours y facturación de
reservas, todo construido y en producción.

| Documento | Qué cubre |
|---|---|
| `plan-hoja-de-ruta-ejecucion.md` | **Punto de entrada real para trabajar en este vertical.** Todas las sesiones de construcción en orden (0 a 12h, M1-M5, más C1), con checklist de avance, convención de ramas/commits, e historial resumido. Consultar siempre antes de abrir una sesión nueva. |
| `auditoria-arquitectonica-agencia-viajes.md` | **Auditoría profunda del modelo de mayoristas/multi-destino (Línea 2, 01-sep-2026)** — §7 `alternativa_destinos`, §9/§9.1/§9.2/§9.3 mayoristas/`contenido_tour`/reasignación en vivo/leak del PDF, §13 moneda (confirma que sigue a nivel de `Alternativa`, no de destino), §23 brechas, §24 mantenibilidad/escalabilidad. Documento de referencia — no se ejecuta directo, se traduce en `plan-ejecucion-multidestino-mayoristas.md`. |
| `plan-ejecucion-multidestino-mayoristas.md` | Traduce la auditoría a sesiones concretas 12a-12h (`alternativa_destinos`, reubicación de `OpcionMayorista`, `contenido_tour`, reasignación de mayorista en vivo). 12a listo para ejecutar ya, 12b-12g se redactan al llegar cada una, 12h ya tiene brief. |
| `plan-refactor-mayoristas-tramos.md` | **CERRADO 01-sep-2026 — superado por `alternativa_destinos`.** Diagnóstico original (caso real Cusco→Tarapoto+México) que disparó todo el trabajo de mayoristas/multi-destino. Queda como registro histórico — no se sigue diseñando ahí. Único pendiente huérfano que dejó sin resolver en ningún otro lado: **C3** (margen automático por mayorista sin conectar — `Proveedor.margen_default_tipo/valor` nunca se usa al cargar tarifa de hotel), sin brief propio todavía. |
| `plan-matriz-hoteles-cotizador.md` | **Diseño CERRADO (29-ago-2026).** Matriz de opciones de hotel dentro de una Alternativa (agrupador `grupo_opcion_id`, hotel ad-hoc sin depender de Proveedor registrado). Se traduce en `plan-ejecucion-matriz-hoteles-cotizador.md` — este documento ya no se edita salvo caso nuevo que obligue a revisar una decisión cerrada. |
| `plan-ejecucion-matriz-hoteles-cotizador.md` | Sesiones concretas M1-M5 del diseño de arriba. M1 (núcleo: agrupador + guard + precio en vivo) listo para ejecutar ya, sin dependencias. M2-M5 se redactan al llegar cada una. |
| `plan-fix-moneda-cotizador.md` | Bug real confirmado (tipo de cambio 1:1 aceptado sin validar, pérdida silenciosa de ~USD 143 reproducida en vivo). **Estaba pausado esperando confirmar si el análisis de multi-destino lo afectaba — ya resuelto: la auditoría (§13) confirma que la moneda sigue a nivel de `Alternativa`, no de destino, así que este plan queda desbloqueado y puede ejecutarse.** 5 puntos, orden acordado 5→2→3→1→4, sin brief de sesión todavía. |
| `PEGAR-EN-CLAUDE-CODE-fix-leak-mayorista-pdf.md` | Brief listo — fix C1 (el PDF comercial imprimía la razón social legal del mayorista al cliente). Chico, autocontenido, sin dependencias — se puede ejecutar en cualquier momento. |
| `PEGAR-EN-CLAUDE-CODE-fase0-gaps-mayoristas-multidestino.md` | Brief listo — sesión 12a. |
| `PEGAR-EN-CLAUDE-CODE-matriz-hoteles-m1-nucleo.md` | Brief listo — sesión M1. |
| `PEGAR-EN-CLAUDE-CODE-reasignar-mayorista-vivo.md` | Brief listo — sesión 12h. |
| `plan-modulo-cotizaciones-reservas.md` | Cotizaciones, alternativas, reservas, itinerarios, facturación (simple y múltiple por grupo de pasajeros), integración con el core de ventas. Todavía activo — las filas abiertas (11f/11g) referencian sus secciones §4.6/§8bis directamente. |
| `plan-modulo-codigos-numeracion.md` | Módulo 12 — prefijo editable por agencia + correlativo configurable para tours/paquetes/cotizaciones/reservas. **Construido y mergeado (26-ago-2026)** — fila 12 de `plan-hoja-de-ruta-ejecucion.md`. |
| `plan-modulo-planes-acceso.md` | Feature gating por plan contratado (económico/estándar/pro) + add-ons. Mayormente reconciliado con lo ya construido en Panel Superadmin, pero **tiene trabajo real marcado como pendiente** (mecanismo de tenant demo/real, feature-gating de módulos) — ver su propia sección "Recomendación" antes de asumir que está cerrado. |
| `sincronizacion.md` | Protocolo de por qué `plan-hoja-de-ruta-ejecucion.md` puede desactualizarse frente al repo real y cómo resincronizarlo |
| `PEGAR-EN-CLAUDE-CODE-temporada-plantilla.md` | Brief sin ejecutar — auditoría/fix de resolución de tarifa por temporada al cargar una cotización desde `paquete_plantilla` |
| `historial-archivo.md` | Todo lo demás: las ~20 sesiones ya cerradas con detalle completo, 8 documentos de diseño fundacional + briefs de sesiones cerradas que se borraron por redundantes (proveedores, tours-catálogo, maestros-iniciales, guardia tributario, fix de fechas, facturación múltiple), e ítem manual/mover-fusionar servicio/split de facturación/facturación externa por tenant (11w — brief `PEGAR-EN-CLAUDE-CODE-facturacion-externa-tenant.md` ya ejecutado y mergeado, borrado el 25-ago-2026) |

**Pendiente real de este vertical (actualizado 01-sep-2026).** Dos líneas
de trabajo activas en paralelo, sin bloqueantes entre ellas — el usuario
puede intercalarlas según prioridad de negocio:

- **Frente histórico (sin cambios desde 26-ago):** 11f (motor de
  recordatorios), 11g (controllers de pago a proveedor), 11t (bug
  colateral de `VentaDirectaController`, sin brief propio todavía).
- **Frente nuevo (multi-destino/mayoristas, abierto 31-ago/01-sep):**
  12a (Fase 0, listo), M1 (núcleo matriz hoteles, listo), C1 (fix leak
  PDF, listo) — estas 3 son independientes entre sí y pueden ejecutarse
  ya, en cualquier orden. Después de 12a vienen 12b-12g en cadena; después
  de M1 vienen M2/M3 en paralelo y luego M4/M5; 12h y el fix de moneda
  (`plan-fix-moneda-cotizador.md`, ahora desbloqueado) son independientes
  y se pueden intercalar en cualquier momento. Único pendiente sin brief
  ni sesión asignada todavía: **C3** (margen automático por mayorista).

Ver `plan-hoja-de-ruta-ejecucion.md` §1 para el detalle exacto de cada
fila y sus dependencias.

## 📁 Retail - Facturación Core

Rubro original del sistema (facturación electrónica / POS). No confundir
con "core" en el sentido de multi-tenancy (que son las migraciones que
corren en TODOS los tenants sin importar el rubro) — estos documentos son
específicamente del negocio retail.

| Documento | Qué cubre |
|---|---|
| `plan-modulo-caja.md` | Manejo de caja — **Fases 0-6 de 7 cerradas y verificadas**, Fase 7 (multi-caja simultánea) sigue genuinamente pendiente, esperando que el negocio abra una segunda caja real. Checklist de activación en su propia sección. |
| `plan-modulo-amortizaciones.md` | Amortizaciones / ventas a crédito — **módulo cerrado** (Fases 1-9 de 9), documento sigue siendo referencia técnica viva por si se retoma `credit_type='libre'` o UI de anular/refund/replace. |
| `historial-archivo.md` | Series de comprobantes (cerrado, con 4 pendientes reales documentados — Catálogo 01 SUNAT completo, migrar NC/ND al módulo nuevo, CRUD de `branches`, reporte PLE) y un duplicado descartado (`plan-multitenant-umbo.md`, borrador viejo superado por `plan-modulo-amortizaciones.md`) |

## 📁 Panel Superadmin

Proyecto aparte — UI central de gestión de tenants (creación, `Company`,
`SunatConfig`, backups, suscripciones, verificación de emisión). Consume
el catálogo de planes/módulos definido en
`Agencia de Viajes/plan-modulo-planes-acceso.md` pero no es parte de ese
vertical, aplica a todos los giros.

| Documento | Qué cubre |
|---|---|
| `plan-panel-superadmin.md` | **Stub corto — el panel está cerrado en su alcance actual** (Fases 0/A/B/B.0.5/B.2/C/D/E). Solo quedan 3 pendientes reales, listados ahí mismo: decisión de negocio sobre `test-emission` como gate obligatorio, corregir el `giro` real de `market.umbosystem.com` (requiere aprobación explícita), y un mismatch no bloqueante de manejo de errores en 2 vistas de `admin-start-kit`. |
| `historial-archivo.md` | Detalle completo fase por fase (hallazgos reales, bugs encontrados y corregidos) de las ~1500 líneas originales del plan, más el historial del selector de giro/editar tenant/reset de password (3 documentos sueltos que cubrían esa misma pieza, ya consolidados acá) |

---

## Cómo agregar un rubro/vertical nuevo más adelante

1. Crear subcarpeta nueva con el nombre del rubro (ej. "Restaurantes")
2. Empezar ese vertical con un `plan-general-vertical-{rubro}.md`
3. Agregar la fila correspondiente a este índice — sin esto, cualquier
   sesión nueva no sabrá que existe

## Cómo archivar un documento cuando ya no se usa

1. Confirmá primero que está genuinamente cerrado — sin ninguna decisión
   real pendiente (no basta con que "ya no cambie seguido"). Si tiene
   pendientes reales, preservalos explícitamente en el paso 2 antes de
   borrar el original.
2. Creá (o extendé) el `historial-archivo.md` de esa carpeta, con un
   resumen comprimido por fecha/tema — no un volcado literal. Mismo
   estilo que los `historial-archivo.md` ya existentes.
3. Si el documento es muy grande y todavía puede servir de referencia
   técnica puntual (no solo bitácora), dejá un stub corto en su lugar en
   vez de borrarlo del todo — ver `plan-panel-superadmin.md` como
   ejemplo. Si es puramente bitácora ya superada por otro documento
   (ej. la hoja de ruta de ejecución), borralo directo.
4. Actualizá este índice.

## Historial de actualizaciones

| Fecha | Cambio |
|---|---|
| 01-sep-2026 | **Se agrega el frente nuevo de Agencia de Viajes** (multi-destino/mayoristas + matriz de hoteles + fixes puntuales), que venía corriendo en dos líneas paralelas sin cruzarse con este índice: `auditoria-arquitectonica-agencia-viajes.md` + `plan-ejecucion-multidestino-mayoristas.md` (Línea 2, sesiones 12a-12h) y `plan-refactor-mayoristas-tramos.md` + `plan-matriz-hoteles-cotizador.md`/`plan-ejecucion-matriz-hoteles-cotizador.md` (Línea 1, sesiones M1-M5). Ambas líneas ya se habían reconciliado solas (misma conclusión: `alternativa_destinos`, no `alternativa_tramos`) pero no estaban reflejadas acá. Se corrige también una referencia de archivo repetida en varios documentos (`auditoria-arquitectonica-profunda-sintesis.md`, nombre que no existe en este repo → `auditoria-arquitectonica-agencia-viajes.md`, el real). `plan-fix-moneda-cotizador.md` queda desbloqueado (la duda que lo pausaba ya se resolvió en la auditoría §13). Único pendiente sin dueño: C3 (margen automático por mayorista). |
| 29-ago-2026 | Sesión larga de ajustes cortos en Agencia de Viajes, sin fila propia en la hoja de ruta (no mueve 11f/11g/11t) — paridad de tarifas de guía, filtros de destinos, catálogo de servicios, sesión JWT, capitalización de nombres, fix de logo en PDF de cotización. Detalle completo en `plan-hoja-de-ruta-ejecucion.md` (changelog) y `CLAUDE.md`. |
| 26-ago-2026 | Actualización de estado (no reescritura): fila del módulo 12 corregida (estaba "diseñado, sin construir" — ya está construido y mergeado, fila 12 propia en la hoja de ruta), pendiente real del vertical Agencia de Viajes recortado a 11f/11g/11t (11e/11d y el módulo 12 ya cerrados). |
| 20-ago-2026 | **v3 — reescritura completa + primera ronda de archivado real.** Se archivan/borran 15 documentos (~7500 líneas): en Agencia de Viajes, 4 planes de diseño fundacional ya cerrados y 4 briefs de sesiones ya mergeadas/pusheadas (11r/11s/11u-guardia/11v); en Retail, `plan-modulo-series-comprobantes.md` (cerrado) y `plan-multitenant-umbo.md` (duplicado descartado); en Panel Superadmin, `plan-panel-superadmin.md` (1518→34 líneas, queda como stub con los pendientes reales) más 3 documentos sueltos sobre el selector de giro ya consolidados; en la raíz, la guía de despliegue OVH (ya ejecutada) y el plan de infraestructura de Sesión 0 (cerrado). Cada carpeta con archivado ahora tiene su propio `historial-archivo.md`. Se aplican también los 2 bloques pendientes de `panel-superadmin/PEGAR-EN-REPO.md` (fila de `plan-modulo-codigos-numeracion.md` acá, sección "Menú lateral y control de acceso" en `arquitectura-multitenant-backend_1.md`) antes de archivar ese documento junto con los demás. |
| 24-jul-2026 | v2: se agrega `plan-modulo-tours-catalogo.md` (nuevo, módulo 2 resuelto) y los dos documentos originales de tours (Lamas Nativo, Alto Mayo) usados como validación real. Se actualiza el estado de "Agencia de Viajes" — ya no hay bloqueante pendiente. |
| 24-jul-2026 | Primera versión: se reorganiza la carpeta raíz (que mezclaba infraestructura base, agencia de viajes, retail y panel superadmin sin separación) en subcarpetas por rubro/proyecto. Se crea este índice como punto de entrada obligatorio para no perder el hilo entre sesiones. |
