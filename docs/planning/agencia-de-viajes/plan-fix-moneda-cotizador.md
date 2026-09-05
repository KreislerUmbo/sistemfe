# Fix — Manejo de moneda en el Cotizador (2 monedas, PEN/USD)

> **Estado: DIAGNOSTICADO, DESBLOQUEADO (01-sep-2026).** El tema que
> obligaba a esperar era la auditoría de multi-destino/mayoristas — ya
> cerrada, y su §13 (`auditoria-arquitectonica-agencia-viajes.md`)
> confirma que la moneda sigue a nivel de `Alternativa` completa, no de
> destino/tramo. Ninguno de los 5 puntos de este plan cambia por eso.
> Puede ejecutarse ya — falta escribir el brief de sesión (no tiene
> número asignado en `plan-hoja-de-ruta-ejecucion.md` todavía).

## 1. Diagnóstico (con evidencia reproducida en vivo)

La arquitectura de conversión de moneda del cotizador **no es ingenua** —
no suma montos crudos de monedas distintas. Cada `alternativa_items` guarda
su propia `moneda_costo` + `precio_convertido` (ya en la moneda de la
`alternativa`), y la conversión pasa siempre por
`PriceEngineService::convertirMoneda()`
(`api-sistema-fe/app/Services/AgenciaViajes/PriceEngineService.php:193-202`),
que asume el par **USD↔PEN** (`tipo_cambio_agencia.valor` = cuántos PEN
equivalen a 1 USD). Todos los totales reales (`recalcularTotalAlternativa()`,
PDF, facturación) usan `total_convertido`, nunca el monto crudo — eso está
bien hecho.

**El problema real es que nada valida ni comunica el tipo de cambio que se
está aplicando.** Evidencia concreta:

- En la base real de `agencia-demo` existen 4 registros de
  `tipo_cambio_agencia` con `valor = 1.0000` (ids 1, 8, 9, 10), mezclados
  con otros de `3.45`/`3.50`. El sistema acepta sin validar un "tipo de
  cambio USD→PEN" con paridad 1:1, que es imposible en la realidad.
- **Reproducido en vivo** (cotización real CDKM-0826-0000003, alternativa
  55, borrador, `tipo_cambio_aplicado = 1.0000` heredado de ese último
  valor): se agregó un ítem manual real de USD 100 × 2 = USD 200. El total
  de la alternativa subió de PEN 240.00 a **PEN 440.00** — es decir, USD
  200 se sumó como PEN 200 (conversión 1:1) en vez de ~PEN 700 con un tipo
  de cambio real. Se perdió silenciosamente el equivalente a ~USD 143 de
  esa línea. Ítem de prueba revertido después de confirmar el bug — la
  base quedó exactamente como estaba.
- El formulario de "Ítem manual" muestra un preview correcto en su propia
  moneda ("Total al cliente: USD 100.00") pero **sin ningún aviso** de que
  se va a convertir, ni a qué tasa.
- Una vez guardado, la fila del ítem en el lienzo muestra el precio de
  venta editable con la etiqueta de moneda de la **alternativa** siempre
  ("PEN 100"), no la moneda original del ítem (USD) — el origen en USD
  desaparece visualmente. Si alguien vuelve a editar ese campo pensando
  que sigue en USD, en realidad toca el monto ya convertido a PEN.

### Causas raíz concretas

| # | Causa | Evidencia |
|---|---|---|
| A | `resolverTipoCambio()` acepta cualquier valor numérico sin validar que sea razonable para USD/PEN | `AlternativaController.php:634-655` |
| B | El form de "Nueva alternativa" no muestra el tipo de cambio vigente (valor + fecha) antes de crear — el campo "Valor nuevo" queda vacío y "opcional" sin contexto de qué va a reusar | `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue:174-184` |
| C | `tipo_cambio_aplicado` es un snapshot fijado solo en `store()`, nunca se refresca en `update()` — una alternativa abierta días y con ítems agregados después sigue usando la tasa vieja sin aviso | `AlternativaController::store()` |
| D | Ningún ítem con `moneda_costo` distinta a `moneda_cotizacion` se marca visualmente, ni en el drawer de alta ni en la fila ya guardada | `editar.vue` (confirmado en vivo, punto de arriba) |
| E | `AlternativaItem::total` (sin convertir) sigue expuesto en `$appends` junto a `total_convertido` — verificado que **nada lo consume hoy** (backend ni frontend, grep completo), pero es una trampa lista para que un futuro reporte lo sume ingenuamente | `AlternativaItem.php:73,139-148` |

## 2. Propuesta de solución — 5 puntos, orden de ejecución acordado

Los 5 puntos no tienen el mismo riesgo: 2, 3 y 5 son aditivos/visuales
(baratos de probar y revertir); 1 y 4 tocan validación de flujo y
**recálculo de montos ya persistidos**, y merecen su propia verificación
con tests antes de mezclarlos con lo visual — mismo criterio que se usó con
`ReservaController::reprogramar()` (fecha_origen `'auto'` vs `'manual'`,
solo se toca lo que corresponde).

**Orden acordado: 5 → 2 → 3 → 1 → 4**

1. **(orden de ejecución 1°) Retirar `AlternativaItem::total` de `$appends`**
   — ya verificado que no lo usa nadie. Limpieza segura, sin riesgo.
2. **(2°) Mostrar el tipo de cambio vigente antes de crear/editar** — en el
   form de "Nueva alternativa" (y cualquier punto donde se vaya a reusar el
   último valor), mostrar "Se va a usar: 3.5000 (registrado 12-ago, origen:
   agencia)" en vez de un campo vacío sin contexto. Requiere endpoint o
   reuso de uno existente para consultar el último `tipo_cambio_agencia`
   por `origen` en vivo desde el frontend.
3. **(3°) Badge de moneda distinta en cada ítem** — si
   `item.moneda_costo !== alternativa.moneda_cotizacion`, mostrar junto al
   precio algo como "USD 100 → PEN 350 (TC 3.50)" en vez de solo "PEN 350",
   tanto en el drawer de alta como en la fila ya guardada del lienzo.
   Verificar primero que la API ya devuelve `moneda_costo` por ítem en el
   payload que consume `editar.vue` (todo indica que sí, confirmar antes de
   escribir el frontend).
4. **(4°) Validación de sanidad al registrar tipo de cambio** —
   `resolverTipoCambio()`: si `valorNuevo` está fuera de un rango
   configurable (ej. 2.0–6.0 para USD/PEN), devolver 422 pidiendo
   confirmación explícita en vez de guardarlo silencioso. Necesita diseño
   de UX de confirmación en el frontend (reusa el contexto ya mostrado en
   el punto 2) — no es solo un cambio de backend.
5. **(5°, al cierre, con tests dedicados) Refrescar tasa en alternativas
   `borrador`** — endpoint nuevo tipo "actualizar tipo de cambio" que
   recalcula `total_convertido` de los ítems en moneda distinta a la de la
   alternativa, solo si `estado === 'borrador'` (o eventualmente también
   'enviada', a decidir). Es el punto que más se parece a
   `reprogramar()`: toca dinero ya persistido, así que necesita su propia
   suite de tests antes de considerarse cerrado (casos: alternativa con
   ítems mixtos PEN+USD, con y sin ítems ya en 0, verificar que el ítem que
   ya estaba en la misma moneda no se recalcula de más).

## 3. Preguntas abiertas / a confirmar con el usuario antes de codear

- Punto 4: ¿el rango de sanidad (2.0–6.0) debe ser configurable por tenant
  o hardcodeado? Ningún otro tenant real usa USD hoy (confirmado:
  `agencia-demo` es 100% PEN en `proveedor_tarifas`/`alternativa_items`
  reales), así que no hay urgencia de por sí — pero cualquier tenant nuevo
  de agencia de viajes con proveedores en dólares (ej. turismo receptivo)
  choca con este bug el primer día.
- Punto 5: ¿alcanza con "borrador", o también debe permitirse en
  'enviada' (cotización ya mandada al cliente pero aún no aceptada)?
  Revisar `estado` de `Alternativa` antes de decidir el guard exacto.
- Pendiente confirmar con el usuario si el tema que quiere analizar antes
  (mencionado en la sesión del 29-ago, sin detalle aún) modifica el alcance
  de alguno de estos 5 puntos — no asumir que este plan queda intacto sin
  releerlo contra ese análisis primero.

## 4. Cómo se verificó (para repetir si hace falta)

Todo se probó en vivo contra el tenant `agencia-demo`
(`admin@agencia-demo.test` / `123456`, Playwright MCP,
`http://agencia-demo.sistemafe.test:5173`), sobre la cotización real
CDKM-0826-0000003 (id 35), alternativa 55 (`estado='borrador'`, segura para
experimentar). El ítem de prueba se creó y se eliminó en la misma sesión —
confirmado por consulta directa a Postgres (vía `tenancy()->initialize()`
en `tinker`) que la alternativa quedó con el mismo `total` y sin filas
nuevas en `alternativa_items`.
