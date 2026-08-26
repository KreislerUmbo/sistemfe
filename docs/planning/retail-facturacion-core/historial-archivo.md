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

---

## Ventas — matriz de pruebas tributarias, Bloques A-E (cerrada 2026-07-19)

Matriz de trabajo original (nunca persistida como archivo, vivió solo en la conversación
de esa sesión) cerrada caso por caso, con hallazgos reales corregidos en el camino:
`resolverTipAfeIgv()` extraída a `admin-start-kit/src/utils/resolverTipAfeIgv.ts` y
testeada (15/15, primer uso de `vitest` en el frontend) — la matriz original tenía mal el
modelo de variables (`destino_venta` solo tiene 2 valores reales,
`'amazonia'`/`'nacional'`, "exterior" no existe como destino; no existe
`naturaleza_producto='exonerado_amazonia'`, la exoneración Amazonía es 100% dinámica por
destino de venta). `validarRegimenEspecial()` 14/14 (B1-B14) — es un chequeo ciego sobre
montos agregados, sin distinción por origen de la exoneración. Bloques C (SPOT mixto por
carrito) y E (condición especial simultánea) resueltos como no-aplicables al modelo de
datos actual (`codigo_detraccion`/`condicion_especial` son campos únicos a nivel de venta
completa). Bloque D (FormaPago contado/crédito) 5/5 contra Postgres real.

3 bugs reales corregidos al construir el entorno de prueba contra Postgres real (fuera del
alcance original): migración tenant duplicaba una columna que la central ya agregaba un
día antes (invisible en producción, rompía correr ambos conjuntos sobre una base de test);
`GreenterService::procesarRespuestaSunat()` vivía fuera del `try/catch` de `enviarSunat()`
— un fallo ahí quemaba el correlativo sin dejar `sunat_error_message`; `reservarCorrelativo()`
verificado con lock real entre dos conexiones Postgres. Infraestructura de testing nueva
para todo el proyecto: base `sistemafe_test_migrations` (76 migraciones reales).

**Pendiente real, sin resolver:** comunicación de baja SUNAT sin conectar a ningún flujo
(Greenter ya la soporta a nivel de librería); migración central `fix_detraction_codes_
rebuild_schema.php` sigue alterando `products` (tabla de tenant) — funciona solo porque
`sv_facturacion` conserva una tabla heredada de antes del split, pregunta arquitectónica
abierta; producto real en `umbo` (id=37) con `tip_afe_igv_default='20'` sin relación
evidente con Ley 27037, a confirmar con el contador; riesgo de diseño en
`reservarCorrelativo()` con una serie sin ninguna venta previa (dos requests concurrentes
podrían calcular ambos `correlativo=1`) — mitigado para series que ya usan el módulo de
series de comprobantes de arriba (fila seed `correlativo_actual=0`), sigue latente para el
mecanismo legado.

## Notas de Crédito/Débito (cerrado 2026-07-14/15)

Módulo construido de punta a punta: emisión, envío a SUNAT, PDF (A4/ticket 80mm), listado
con filtros, reposición de stock atada a aceptación real de SUNAT. Los 13 motivos del
catálogo SUNAT (09 NC/10 ND) habilitados y cada uno validado con un comprobante real
aceptado por SUNAT BETA (arrancó en 6/13) — total (clon 1:1), parcial modo cantidad
(NC07, único que reduce `quantity`), parcial modo monto (NC05/NC09/ND02, SUNAT exige
`LineExtensionAmount == precio×cantidad` sin excepción, confirmado con error 3271/3272 en
dos rondas reales — NC08 comparte el código pero no se probó en vivo), descuento global
(NC04), concepto libre (ND01/ND03). 3 bugs reales corregidos, descubiertos al probar
contra SUNAT BETA: `TotalesComprobanteCalculator::calcular()` no reducía el IGV al aplicar
un descuento global (afecta también a ventas normales, no solo notas); el mismo método
leía `$d->subtotal` como propiedad de objeto, rompía con notas totales clonadas (arrays
planos), IGV en 0 en silencio; el tope de cantidad acreditable contaba mal los motivos de
valor (04/05/08/09, que no reducen cantidad).

**Pendiente real, sin resolver:** ajuste automático de `debt`/`paid_out`/`state_payment`
en `sales` cuando se acepta una NC/ND (NC reduce deuda con piso en 0, ND la aumenta) —
decisión pendiente sobre si automatizar solo para ventas sin retención/detracción/
percepción. Tabla `client_credit_movements` (saldo a favor del cliente cuando una NC
supera lo que la venta debía) diseñada (columnas, estados
`pendiente`/`aplicado_a_venta`/`reembolsado_*`), sin construir.

## Módulo Adelantos — gaps de integridad, conexión con Reservas, selector fiscal y
## corrección post-SUNAT (cerrado 2026-08-24)

Auditoría propia (más una segunda pasada disparada por una pregunta real del usuario)
sobre el módulo Adelantos construido 2026-07-11/12 (ver `project_advances_module.md` en
memoria de proyecto para el detalle mecánico de SUNAT — `PrepaidPayment` del XML nunca
lleva la clasificación tributaria). Cuatro tandas, verificadas contra
`sistemafe_test_migrations`, sin tocar `sandbox`/`umbo`:
- **Tier 1 integridad**: `AdvanceApplicationService` nuevo (extrae el loop de aplicación
  de `SaleController::store()`) — valida moneda, bloquea aplicar a cotización, rechaza
  `advance_id` duplicado, `lockForUpdate()` real en `refund()` (antes sin lock).
  Editar/eliminar venta con adelantos aplicados bloqueado (422/405). `permission:` real
  agregado a las rutas de Adelantos (antes sin gate). `type='advance'` excluido del
  listado general de Ventas.
- **Tier 0 conexión con Agencia de Viajes**: `reserva_anticipos` (schema puente inerte
  desde antes) gana `ReservaAnticipoController` real — cobrar anticipo desde
  `reservas/detalle.vue`, neteado contra `ReservaFacturacionController::store()`. Bug real
  corregido: el filtro de anticipos disponibles no exigía que el `client_id` coincidiera
  con el cliente facturado (riesgo real con Facturación múltiple, Sesión 11v).
- **Tier 1 selector fiscal**: `AdvanceController::store()` ya no fuerza gravado 18%
  siempre — exige `tip_afe_igv` (`10`/`20`/`30`) explícito, resolviendo el hallazgo
  Amazonía que había quedado pendiente desde la construcción original.
- **Tier 2 corrección post-SUNAT**: `POST advances/{id}/corregir` anula con NC motivo `01`
  y reemite, preservando el mismo `Advance.id` (`corrected_from_sale_id`/
  `correction_reason` de auditoría, no historial completo) — alcance angosto a propósito,
  solo corrige tratamiento tributario, nunca cliente/monto/medio de pago.
- **Tier 3 UX**: `create.vue`/`index.vue`/`show.vue` rediseñadas — flujo de 2 pasos
  explicado, filtros/búsqueda, "Estado SUNAT" separado, motivo de rechazo persistente.

226/226 tests backend en verde, type-check frontend en baseline (45 preexistentes).

**Pendiente real:** validar contra SUNAT Beta un comprobante de adelanto exonerado/
inafecto y una corrección real vía NC motivo 01 (requiere credenciales de tenant real).
Retail (fuera de Agencia de Viajes) sigue sin un mecanismo estructurado de "adelanto →
venta futura" como `reserva_anticipos` — descartado de alcance en 2026-08-24, no
reconfirmado con el usuario si hace falta.

## Cotizaciones Comerciales — módulo nuevo (cerrado 2026-08-25)

Retira `state_sale` de `Sale` (la cotización comercial dejó de vivir como un estado de
venta más) y construye `commercial_quotes`/`commercial_quote_items`/
`commercial_quote_anticipos` como módulo propio (`CommercialQuoteController`,
`CommercialQuoteAnticipoController`), con PDF A4 dedicado
(`cotizacion_comercial_a4.blade.php`) y pantallas propias en
`admin-start-kit/src/views/commercial-quotes/`. Verificado en los 5 tenants de
producción. Ver `project_cotizaciones_comerciales_modulo.md` en memoria de proyecto para
el detalle de verificación.
