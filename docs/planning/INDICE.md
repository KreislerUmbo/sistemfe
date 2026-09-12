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
> Última actualización: 11-sep-2026 (v4 — verificación exhaustiva contra `git log`, no solo
> contra lo que decían los documentos; ver la entrada de esa fecha en "Historial de
> actualizaciones" más abajo para el detalle completo de qué se corrigió y por qué).

---

## 📁 Raíz — documentos transversales (aplican a TODOS los rubros/verticales)

| Documento | Qué cubre |
|---|---|
| `CLAUDE.md` (raíz del repo, no de `docs/planning/`) | Plan maestro / bitácora completa del proyecto — el documento más grande y más actualizado, con la narrativa sesión por sesión de todo lo construido |
| `arquitectura-multitenant-backend_1.md` | Cómo funciona el multi-tenancy (stancl/tenancy, BD central vs. BD por tenant, patrón core/verticals), estado del panel superadmin, y el modelo de 3 capas para el menú lateral (giro + plan + roles) |
| `plan-modulo-menus-y-roles.md` | **Menú dinámico por tenant/giro/rol + control de acceso real.** Fase 0-2 completas y mergeadas a `main` (11-sep-2026): gate de permisos Bucket A/B (Bucket B en modo sombra, sin bloquear todavía), `menu_items`/`MenuResolver`/`GET /me/menu`, catálogo real de roles/permisos de `agencia_viajes`, sidebar dinámico + módulo Roles/Usuarios reforzado en `admin-start-kit`. Detalle fase por fase en su propio §7. Único pendiente: Fase 3 (retail al mismo modelo de roles). |
| `claude/` (subcarpeta) | Documentos de evidencia/diseño que respaldan `plan-modulo-menus-y-roles.md` — ver tabla propia más abajo |
| `historial-archivo.md` | 4 secciones archivadas: guía de despliegue a producción OVH (ya ejecutada, 16/17-ago-2026 — no confiar en ella como estado actual); plan de Sesión 0 (infraestructura core/verticals, cerrado 27-jul-2026); URL de API dinámica en dev + storage tenant-aware (cerrado 31-jul-2026); spinners de carga + editor enriquecido + cache de preflight CORS (10/11-ago-2026) |

### 📁 `claude/` — respaldo de `plan-modulo-menus-y-roles.md`

| Documento | Qué cubre |
|---|---|
| `auditoria-menu-admin-start-kit.md` | Fase 0 — inventario puro (menú hardcodeado viejo + 69 rutas backend mutables sin gate), sin ejecutar nada. Fuente de verdad que migró `MenuItemsSeeder` (Fase 1a). |
| `gate-bucket-a-fase0b.md` | Fase 0b — clasificación completa 69→Bucket A (38 rutas, gateadas)/Bucket B (31 rutas). **Mergeado a `main`** (`938b606`/`ee008a2`). Backfill de `umbo`/`negocio2` (permisos nuevos, no toca asignaciones) sigue sin ejecutar, requiere autorización. |
| `shadow-mode-bucket-b-fase0c.md` | Fase 0c Parte 1 — modo sombra de Bucket B, diseño + mapeo de 31 rutas a permiso. **Mergeado a `main`** (`fecf4d1`). Partes 2/3 (backfill + gate real) siguen sin ejecutar. |
| `plan-modulo-centro-ayuda.md` | Diseño CERRADO, nada implementado — reemplazo real del módulo "Recursos" (hoy es catálogo de marketing pre-venta, no ayuda operativa in-app). Sin brief de ejecución todavía. |

## 📁 Agencia de Viajes

Vertical específico, **el más avanzado del proyecto** — cotizaciones,
proveedores, reservas, itinerarios, destinos, tours y facturación de
reservas, todo construido y en producción.

| Documento | Qué cubre |
|---|---|
| `plan-hoja-de-ruta-ejecucion.md` | **Punto de entrada real para trabajar en este vertical — fuente de verdad del estado, no este índice.** Todas las sesiones de construcción en orden (0 a 12h, M1-M5, C1, UX1), con checklist de avance, convención de ramas/commits, e historial resumido. Consultar siempre antes de abrir una sesión nueva. |
| `auditoria-arquitectonica-agencia-viajes.md` | **Auditoría profunda del modelo de mayoristas/multi-destino (01-sep-2026)** — §7 `alternativa_destinos`, §9/§9.1/§9.2/§9.3 mayoristas/`contenido_tour`/reasignación en vivo/leak del PDF, §13 moneda, §23 brechas, §24 mantenibilidad. Documento de referencia ya consumido — todas las sesiones que tradujo (12a-12h) ya se ejecutaron, ver la fila de abajo. |
| `plan-ejecucion-multidestino-mayoristas.md` | **12a-12f y 12h ejecutados y mergeados a `origin/main`** (confirmado 11-sep-2026 contra `git log`, con commit de cada uno). Solo **12g** (limpieza final, dropear columnas deprecadas) sigue pendiente — bloqueado a propósito hasta que 12f corra un ciclo completo de reservas en producción. Queda como referencia de diseño/dependencias, no como tracker de avance (esa función la cumple `plan-hoja-de-ruta-ejecucion.md`). |
| `plan-refactor-mayoristas-tramos.md` | **CERRADO 01-sep-2026 — superado por `alternativa_destinos`.** Diagnóstico original (caso real Cusco→Tarapoto+México) que disparó todo el trabajo de mayoristas/multi-destino. Queda como registro histórico. Único pendiente huérfano sin resolver en ningún otro lado: **C3** (margen automático por mayorista sin conectar — `Proveedor.margen_default_tipo/valor` nunca se usa al cargar tarifa de hotel), sin brief propio todavía. |
| `plan-matriz-hoteles-cotizador.md` | **Diseño CERRADO (29-ago-2026).** Matriz de opciones de hotel dentro de una Alternativa. Se tradujo en `plan-ejecucion-matriz-hoteles-cotizador.md` — este documento ya no se edita salvo caso nuevo que obligue a revisar una decisión cerrada. |
| `plan-ejecucion-matriz-hoteles-cotizador.md` | **M1-M5 completas y cerradas (02-sep-2026)** — el propio documento ya tiene su nota de cierre con los 5 commits reales. Sin cambios necesarios. |
| `plan-fix-moneda-cotizador.md` | Bug real confirmado (tipo de cambio 1:1 aceptado sin validar). **El punto 4 de 5 (validación de rango de sanidad) ya se resolvió, pero por otra vía** — como parte del módulo "Tipo de Cambio SUNAT/SBS" (`TipoCambioSanityService`, commit `e30d7a0`), no de este plan. **Puntos 1, 2, 3 y 5 siguen sin construir**, sin brief de sesión todavía. |
| `plan-mejora-pdf-cotizacion-cliente.md` | Diseño del PDF de cotización mejorado con marca personalizable — **✅ EJECUTADO y MERGEADO (07-sep-2026, commit `5b00fdd`)**. Queda activo como referencia técnica del diseño acordado. |
| `fase1b-roles-permisos.md` | Detalle completo de Fase 1b de `docs/planning/plan-modulo-menus-y-roles.md` (catálogo real de roles/permisos + scope de fila para el giro `agencia_viajes`) — **✅ CERRADO y MERGEADO** (`abf71bc`, 10-sep-2026). Vive acá porque es específico del vertical, aunque el plan "madre" está en la raíz. |
| `plan-modulo-cotizaciones-reservas.md` | Cotizaciones, alternativas, reservas, itinerarios, facturación (simple y múltiple por grupo de pasajeros), integración con el core de ventas. Todavía activo — las filas abiertas (11f/11g) referencian sus secciones §4.6/§8bis directamente. |
| `plan-modulo-codigos-numeracion.md` | Módulo 12 — prefijo editable por agencia + correlativo configurable para tours/paquetes/cotizaciones/reservas. **Construido y mergeado (26-ago-2026)** — fila 12 de `plan-hoja-de-ruta-ejecucion.md`. |
| `plan-modulo-planes-acceso.md` | Feature gating por plan contratado (económico/estándar/pro) + add-ons. **Confirmado sin cambios desde 23-jul-2026** (11-sep-2026, sin ningún commit relacionado en el log) — sigue teniendo trabajo real pendiente (mecanismo de tenant demo/real, feature-gating de módulos), ver su propia sección "Recomendación". |
| `sincronizacion.md` | Protocolo de por qué `plan-hoja-de-ruta-ejecucion.md` puede desactualizarse frente al repo real y cómo resincronizarlo |
| `PEGAR-EN-CLAUDE-CODE-temporada-plantilla.md` | Brief sin ejecutar, confirmado (11-sep-2026, sin commits relacionados) — auditoría/fix de resolución de tarifa por temporada al cargar una cotización desde `paquete_plantilla` |
| `historial-archivo.md` | Todo lo demás: las ~20 sesiones ya cerradas con detalle completo, documentos de diseño fundacional archivados, y (11-sep-2026) 9 briefs `PEGAR-EN-CLAUDE-CODE-*.md` de sesiones 12a-12h/M1-M3/C1 ya ejecutadas + 3 documentos de auditoría cruda ya consumidos por `auditoria-arquitectonica-agencia-viajes.md` + un duplicado viejo de la hoja de ruta — todos borrados por superados, detalle de cada uno en la entrada de esa fecha |

**Pendiente real de este vertical (actualizado 11-sep-2026, verificado contra `git log` — no
confiar en la fecha "01-sep-2026" de versiones anteriores de este índice, quedó
desactualizada).** El frente de multi-destino/mayoristas y el de matriz de hoteles que este
índice describía como "en progreso" desde el 01-sep **ya se cerraron por completo entre el
01 y el 09-sep-2026**. Lo que sigue realmente abierto hoy:

- **Frente histórico (sin cambios desde 26-ago):** 11f (motor de
  recordatorios), 11g (controllers de pago a proveedor), 11t (bug
  colateral de `VentaDirectaController`, sin brief propio todavía).
- **12g** (limpieza final de columnas deprecadas) — bloqueado a propósito, esperando un
  ciclo completo de reservas en producción con 12f ya estable.
- **C3** (margen automático por mayorista, huérfano de `plan-refactor-mayoristas-tramos.md`)
  y **`plan-fix-moneda-cotizador.md` puntos 1/2/3/5** (el punto 4 ya se resolvió por otra
  vía) — ninguno de los dos tiene brief de sesión todavía.
- `plan-modulo-planes-acceso.md` (feature-gating por plan) — sin cambios desde jul-2026,
  sigue siendo trabajo real pendiente, no solo reconciliación.

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
| 11-sep-2026 | **v4 — verificación exhaustiva contra `git log` (no solo contra lo que decían los documentos) + segunda ronda de archivado real, a pedido explícito del usuario ("actualiza todos los planes, archiva lo terminado, corrige comentarios viejos").** Se agrega `plan-modulo-menus-y-roles.md` (transversal, Fase 0-2 completas y mergeadas a `main`) y la subcarpeta `claude/` (respaldo de ese plan) a la sección raíz — ninguno de los dos aparecía acá pese a estar activos desde el 10-sep. En Agencia de Viajes: confirmado que 12a-12f+12h y M1-M5 (que el índice describía como "en progreso" desde el 01-sep) ya estaban 100% cerrados desde el 02/03-sep — se corrigen las filas correspondientes y se archivan 9 briefs de sesión ya ejecutados + 3 documentos de auditoría cruda ya consumidos + 1 duplicado viejo de la hoja de ruta (detalle completo en `agencia-de-viajes/historial-archivo.md`, entrada de esta fecha); se agregan filas para `fase1b-roles-permisos.md` y `plan-mejora-pdf-cotizacion-cliente.md`, huérfanos pese a estar cerrados. En Retail: se corrige `plan-modulo-caja.md` (2 de sus 3 puntos de "deuda técnica" ya estaban resueltos, quedaba solo 1 vigente). En la raíz: se actualiza `arquitectura-multitenant-backend_1.md` (su nota "pendiente de definir" sobre roles por vertical ya se resolvió, con matiz: solo para `agencia_viajes`, `retail` sigue sin catálogo propio). Panel Superadmin y `plan-modulo-amortizaciones.md`/`plan-modulo-planes-acceso.md`/`PEGAR-EN-CLAUDE-CODE-temporada-plantilla.md`: verificados, sin cambios, el índice ya acertaba. |
| 01-sep-2026 | **Se agrega el frente nuevo de Agencia de Viajes** (multi-destino/mayoristas + matriz de hoteles + fixes puntuales), que venía corriendo en dos líneas paralelas sin cruzarse con este índice: `auditoria-arquitectonica-agencia-viajes.md` + `plan-ejecucion-multidestino-mayoristas.md` (Línea 2, sesiones 12a-12h) y `plan-refactor-mayoristas-tramos.md` + `plan-matriz-hoteles-cotizador.md`/`plan-ejecucion-matriz-hoteles-cotizador.md` (Línea 1, sesiones M1-M5). Ambas líneas ya se habían reconciliado solas (misma conclusión: `alternativa_destinos`, no `alternativa_tramos`) pero no estaban reflejadas acá. Se corrige también una referencia de archivo repetida en varios documentos (`auditoria-arquitectonica-profunda-sintesis.md`, nombre que no existe en este repo → `auditoria-arquitectonica-agencia-viajes.md`, el real). `plan-fix-moneda-cotizador.md` queda desbloqueado (la duda que lo pausaba ya se resolvió en la auditoría §13). Único pendiente sin dueño: C3 (margen automático por mayorista). |
| 29-ago-2026 | Sesión larga de ajustes cortos en Agencia de Viajes, sin fila propia en la hoja de ruta (no mueve 11f/11g/11t) — paridad de tarifas de guía, filtros de destinos, catálogo de servicios, sesión JWT, capitalización de nombres, fix de logo en PDF de cotización. Detalle completo en `plan-hoja-de-ruta-ejecucion.md` (changelog) y `CLAUDE.md`. |
| 26-ago-2026 | Actualización de estado (no reescritura): fila del módulo 12 corregida (estaba "diseñado, sin construir" — ya está construido y mergeado, fila 12 propia en la hoja de ruta), pendiente real del vertical Agencia de Viajes recortado a 11f/11g/11t (11e/11d y el módulo 12 ya cerrados). |
| 20-ago-2026 | **v3 — reescritura completa + primera ronda de archivado real.** Se archivan/borran 15 documentos (~7500 líneas): en Agencia de Viajes, 4 planes de diseño fundacional ya cerrados y 4 briefs de sesiones ya mergeadas/pusheadas (11r/11s/11u-guardia/11v); en Retail, `plan-modulo-series-comprobantes.md` (cerrado) y `plan-multitenant-umbo.md` (duplicado descartado); en Panel Superadmin, `plan-panel-superadmin.md` (1518→34 líneas, queda como stub con los pendientes reales) más 3 documentos sueltos sobre el selector de giro ya consolidados; en la raíz, la guía de despliegue OVH (ya ejecutada) y el plan de infraestructura de Sesión 0 (cerrado). Cada carpeta con archivado ahora tiene su propio `historial-archivo.md`. Se aplican también los 2 bloques pendientes de `panel-superadmin/PEGAR-EN-REPO.md` (fila de `plan-modulo-codigos-numeracion.md` acá, sección "Menú lateral y control de acceso" en `arquitectura-multitenant-backend_1.md`) antes de archivar ese documento junto con los demás. |
| 24-jul-2026 | v2: se agrega `plan-modulo-tours-catalogo.md` (nuevo, módulo 2 resuelto) y los dos documentos originales de tours (Lamas Nativo, Alto Mayo) usados como validación real. Se actualiza el estado de "Agencia de Viajes" — ya no hay bloqueante pendiente. |
| 24-jul-2026 | Primera versión: se reorganiza la carpeta raíz (que mezclaba infraestructura base, agencia de viajes, retail y panel superadmin sin separación) en subcarpetas por rubro/proyecto. Se crea este índice como punto de entrada obligatorio para no perder el hilo entre sesiones. |
