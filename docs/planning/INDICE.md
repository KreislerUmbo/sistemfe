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
| `plan-hoja-de-ruta-ejecucion.md` | **Punto de entrada real para trabajar en este vertical.** Las 20 sesiones de construcción en orden, con checklist de avance, convención de ramas/commits, e historial resumido. Consultar siempre antes de abrir una sesión nueva — la primera fila con `[ ]` sin marcar es la próxima a construir (hoy: 11f, motor de recordatorios). |
| `plan-modulo-cotizaciones-reservas.md` | Cotizaciones, alternativas, reservas, itinerarios, facturación (simple y múltiple por grupo de pasajeros), integración con el core de ventas. Todavía activo — las filas abiertas (11f/11g) referencian sus secciones §4.6/§8bis directamente. |
| `plan-modulo-codigos-numeracion.md` | Módulo 12 — prefijo editable por agencia + correlativo configurable para tours/paquetes/cotizaciones/reservas. **Construido y mergeado (26-ago-2026)** — fila 12 de `plan-hoja-de-ruta-ejecucion.md`. |
| `plan-modulo-planes-acceso.md` | Feature gating por plan contratado (económico/estándar/pro) + add-ons. Mayormente reconciliado con lo ya construido en Panel Superadmin, pero **tiene trabajo real marcado como pendiente** (mecanismo de tenant demo/real, feature-gating de módulos) — ver su propia sección "Recomendación" antes de asumir que está cerrado. |
| `sincronizacion.md` | Protocolo de por qué `plan-hoja-de-ruta-ejecucion.md` puede desactualizarse frente al repo real y cómo resincronizarlo |
| `PEGAR-EN-CLAUDE-CODE-temporada-plantilla.md` | Brief sin ejecutar — auditoría/fix de resolución de tarifa por temporada al cargar una cotización desde `paquete_plantilla` |
| `historial-archivo.md` | Todo lo demás: las ~20 sesiones ya cerradas con detalle completo, 8 documentos de diseño fundacional + briefs de sesiones cerradas que se borraron por redundantes (proveedores, tours-catálogo, maestros-iniciales, guardia tributario, fix de fechas, facturación múltiple), e ítem manual/mover-fusionar servicio/split de facturación/facturación externa por tenant (11w — brief `PEGAR-EN-CLAUDE-CODE-facturacion-externa-tenant.md` ya ejecutado y mergeado, borrado el 25-ago-2026) |

**Pendiente real de este vertical (actualizado 26-ago-2026):** el reporte
operativo (11e backend + 11d pantalla) y el módulo 12 (códigos/
numeración) ya están cerrados y mergeados. Quedan filas 11f (motor de
recordatorios), 11g (controllers de pago a proveedor) y 11t (bug
colateral de `VentaDirectaController`, sin brief propio todavía) — ver
`plan-hoja-de-ruta-ejecucion.md` §1 para el detalle exacto de cada una.
Sin bloqueantes entre ellas, el orden queda a criterio del usuario.

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
| 29-ago-2026 | Sesión larga de ajustes cortos en Agencia de Viajes, sin fila propia en la hoja de ruta (no mueve 11f/11g/11t) — paridad de tarifas de guía, filtros de destinos, catálogo de servicios, sesión JWT, capitalización de nombres, fix de logo en PDF de cotización. Detalle completo en `plan-hoja-de-ruta-ejecucion.md` (changelog) y `CLAUDE.md`. |
| 26-ago-2026 | Actualización de estado (no reescritura): fila del módulo 12 corregida (estaba "diseñado, sin construir" — ya está construido y mergeado, fila 12 propia en la hoja de ruta), pendiente real del vertical Agencia de Viajes recortado a 11f/11g/11t (11e/11d y el módulo 12 ya cerrados). |
| 20-ago-2026 | **v3 — reescritura completa + primera ronda de archivado real.** Se archivan/borran 15 documentos (~7500 líneas): en Agencia de Viajes, 4 planes de diseño fundacional ya cerrados y 4 briefs de sesiones ya mergeadas/pusheadas (11r/11s/11u-guardia/11v); en Retail, `plan-modulo-series-comprobantes.md` (cerrado) y `plan-multitenant-umbo.md` (duplicado descartado); en Panel Superadmin, `plan-panel-superadmin.md` (1518→34 líneas, queda como stub con los pendientes reales) más 3 documentos sueltos sobre el selector de giro ya consolidados; en la raíz, la guía de despliegue OVH (ya ejecutada) y el plan de infraestructura de Sesión 0 (cerrado). Cada carpeta con archivado ahora tiene su propio `historial-archivo.md`. Se aplican también los 2 bloques pendientes de `panel-superadmin/PEGAR-EN-REPO.md` (fila de `plan-modulo-codigos-numeracion.md` acá, sección "Menú lateral y control de acceso" en `arquitectura-multitenant-backend_1.md`) antes de archivar ese documento junto con los demás. |
| 24-jul-2026 | v2: se agrega `plan-modulo-tours-catalogo.md` (nuevo, módulo 2 resuelto) y los dos documentos originales de tours (Lamas Nativo, Alto Mayo) usados como validación real. Se actualiza el estado de "Agencia de Viajes" — ya no hay bloqueante pendiente. |
| 24-jul-2026 | Primera versión: se reorganiza la carpeta raíz (que mezclaba infraestructura base, agencia de viajes, retail y panel superadmin sin separación) en subcarpetas por rubro/proyecto. Se crea este índice como punto de entrada obligatorio para no perder el hilo entre sesiones. |
