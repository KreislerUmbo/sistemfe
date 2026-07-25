# Índice — Proyecto Multitenant Umbosystem SaaS

> Este documento es el punto de entrada de TODO el proyecto. Léelo primero
> en cualquier sesión nueva antes de buscar cualquier otro archivo — te
> dice exactamente en qué subcarpeta está cada plan, para no tener que
> adivinar ni buscar por todo el Drive.
> Última actualización: 24-jul-2026 (v2 — agregado módulo de tours/destinos)

---

## 📁 Raíz — documentos transversales (aplican a TODOS los rubros/verticales)

| Documento | Qué cubre |
|---|---|
| `CLAUDE.md` | Plan maestro / instrucciones generales del proyecto completo |
| `arquitectura-multitenant-backend_1.md` | Cómo funciona el multi-tenancy (stancl/tenancy, BD central vs. BD por tenant, patrón core/verticals) — base para cualquier rubro |
| `plan-modulo-infraestructura-multitenant.md` | Ejecución concreta de la separación `core/` vs `verticals/` en migraciones — mecanismo técnico, no de negocio |

## 📁 Agencia de Viajes

Vertical específico. Todo lo referente a cotizaciones, proveedores,
reservas, itinerarios, destinos y tours de agencias de viajes vive acá.

| Documento | Qué cubre |
|---|---|
| `plan-general-vertical-agencia-viajes.md` | Documento raíz del vertical — objetivo, fases, mapa de módulos |
| `plan-modulo-proveedores.md` | Catálogo de proveedores, tarifas, temporadas, costo/margen/piso de descuento — **completo en modelo de datos** |
| `plan-modulo-cotizaciones-reservas.md` | Cotizaciones, alternativas, reservas, itinerarios, integración con el core de ventas |
| `plan-modulo-tours-catalogo.md` | Catálogo de destinos (árbol 3 niveles zona/lugar/atractivo), servicios, y confirmación de que "tour" = `paquetes_plantilla` — resuelve el bloqueante del módulo 2, validado con tours reales |
| `plan-modulo-maestros-iniciales.md` | Árbol de dependencias de datos maestros — qué se carga primero, en qué orden (bloqueante del módulo 2 ya resuelto) |
| `plan-modulo-planes-acceso.md` | Feature gating por plan contratado (económico/estándar/pro) + add-ons — Módulo 11 |
| "TOURS LAMAS NATIVO" (Google Doc) | Documento original de tour real de la agencia, usado para validar el modelo de `plan-modulo-tours-catalogo.md` |
| "FULL DAY ALTO MAYO" (Google Doc) | Ídem — segundo tour real usado como validación |

**Pendiente real de este vertical:** ninguno bloqueante. Quedan pendientes
puntuales menores (`temporada_ocurrencias` automática vs. manual,
`configuracion_agencia` como pantalla única) — ver
`plan-modulo-maestros-iniciales.md` sección 6. El siguiente paso natural
es empezar la construcción real (migraciones + CRUDs).

## 📁 Retail - Facturación Core

Rubro original del sistema (facturación electrónica / POS). No confundir
con "core" en el sentido de multi-tenancy (que son las migraciones que
corren en TODOS los tenants sin importar el rubro) — estos documentos son
específicamente del negocio retail.

| Documento | Qué cubre |
|---|---|
| `plan-modulo-series-comprobantes.md` | Series y numeración de comprobantes SUNAT |
| `plan-modulo-caja.md` | Manejo de caja |
| `plan-modulo-amortizaciones.md` | Amortizaciones — ventas a crédito |
| `plan-multitenant-umbo.md` | Módulo de amortizaciones (ventas a crédito) — cuotas, mora, recibos de pago |

## 📁 Panel Superadmin

Proyecto aparte — UI central de gestión de tenants (creación, backups,
suscripciones). Consume el catálogo de planes/módulos definido en
`Agencia de Viajes/plan-modulo-planes-acceso.md` pero no es parte de ese
vertical, aplica a todos.

| Documento | Qué cubre |
|---|---|
| `plan-panel-superadmin.md` | UI de gestión de tenants: creación, Company, SunatConfig, backups, suscripciones |

---

## Cómo agregar un rubro/vertical nuevo más adelante

1. Crear subcarpeta nueva con el nombre del rubro (ej. "Restaurantes")
2. Empezar ese vertical con un `plan-general-vertical-{rubro}.md` igual
   que se hizo con agencia de viajes
3. Agregar la fila correspondiente a este índice — sin esto, cualquier
   sesión nueva no sabrá que existe

## Historial de actualizaciones

| Fecha | Cambio |
|---|---|
| 24-jul-2026 | Primera versión: se reorganiza la carpeta raíz (que mezclaba infraestructura base, agencia de viajes, retail y panel superadmin sin separación) en subcarpetas por rubro/proyecto. Se crea este índice como punto de entrada obligatorio para no perder el hilo entre sesiones. |
| 24-jul-2026 | v2: se agrega `plan-modulo-tours-catalogo.md` (nuevo, módulo 2 resuelto) y los dos documentos originales de tours (Lamas Nativo, Alto Mayo) usados como validación real. Se actualiza el estado de "Agencia de Viajes" — ya no hay bloqueante pendiente. |
