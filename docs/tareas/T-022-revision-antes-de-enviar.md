# T-022 — Cotizador: revisión antes de enviar ("¿lista para el cliente?")

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | **T-028** y T-050 (el botón "Enviar" donde se muestra la revisión) (vínculo `version_anterior_id` y scope de "vigente"); si T-001 ya está hecha, "Actualizar al precio nuevo" respeta su bloqueo de alternativa aceptada |
| **Reglas** | Un solo mínimo de la agencia (T-087): `configuracion_agencia.margen_minimo_aceptable_pct`, que es un **markup** (sobre el costo) — `admin-start-kit/src/utils/margenes.ts`, `MargenEnVivo.vue` |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 8 (respuestas 1-6, D2, "Diseño — solo lo crítico") |
| **Rama** | `feat/T-022-revision-antes-de-enviar` |

## Contexto
Antes de mandar una propuesta al cliente, el vendedor necesita responder rápido "¿ya está lista?".
Hoy se envía lo que haya. **Regla de fondo del usuario:** la revisión tiene que acelerar el envío,
no frenarlo — solo puntos críticos, silencio cuando todo está bien, nada trivial. Si durante la
construcción aparece la tentación de agregar otra revisión, **no**: anotarla en "Hallazgos".

## Alcance

**Backend**
- Servicio `RevisionAlternativaService::revisar(Alternativa): array` con **exactamente estas 6
  revisiones** (ver la tabla del requerimiento):
  1. sin servicios → `bloquea`;
  2. servicio con precio de venta nulo o 0 → `bloquea` (en grupos de hoteles, cada fila);
  3. venta por debajo del costo (siempre) o **markup** ((venta − costo) ÷ costo, como
     `utils/margenes.ts::markupPct`) por debajo de `configuracion_agencia.margen_minimo_aceptable_pct`
     → `aviso`. Es el mismo mínimo que ya avisan las tarifas, tours y combos (T-087); no se crea
     otro. La columna no admite vacío (default 20): con mínimo **0** no se avisa markup bajo, solo
     venta bajo costo. Sin costo (0 o nulo) no se evalúa el markup;
  4. tarifa del servicio con **versión siguiente activa** (precio nuevo), o que **no vale para la
     fecha del viaje** (vencida por `vigente_hasta`, `activo=false`, o su `temporada_id` no tiene
     ocurrencia que cubra la fecha) → `aviso`;
  5. pasaje aéreo con `tarifa_valida_hasta` vencida o anterior a la vigencia de la propuesta → `aviso`;
  6. alternativa `enviada` con `fecha_vencimiento` pasada → `aviso`.
  - Sin fecha de viaje, 4 (parte de temporada/vigencia) y 5 no se evalúan.
  - Grupos de hoteles abiertos: revisar cada fila; no es un pendiente que el grupo esté abierto.
  - Cada resultado: `codigo`, `nivel`, `mensaje` corto en español, `cantidad`, `destino` para
    navegar (`item_ids[]`, o `seccion`: `lienzo` / `datos_cotizacion` / `pasaje_aereo`), y para el 4,
    los datos de la versión nueva (`tarifa_nueva_id`, precio actual y nuevo).
  - Agrupar por código (una entrada por tipo con `cantidad` e `item_ids`), no una por ítem.
- `GET alternativas/{id}/revision` (misma Policy/permiso que ver la cotización).
- `CotizacionEnvioController::enviar()`: correr la revisión y responder 422 con la lista si hay
  algún `bloquea` (aunque se llame a la API directo). `preparar()` devuelve la revisión para el formulario.
- `CotizacionActividadController::marcarEnviada()` (`POST alternativas/{id}/marcar-enviada`, el
  camino de WhatsApp y "ya la envié por otro medio" de T-050): **la misma regla**, 422 con la lista
  si hay algún `bloquea`, después de validar y del chequeo de `ESTADOS_SIN_ENVIO`, y sin registrar
  la actividad CRM ni cambiar el estado. Una sola función que decida «¿se puede enviar?» para los
  dos endpoints (no copiar el chequeo). Agregado el 12-nov-2026.
- **Vínculo de versiones:** lo construye **T-028** (`proveedor_tarifas.version_anterior_id` y el
  scope de "vigente"). Acá solo se usa: "precio nuevo" = existe una tarifa vigente cuyo
  `version_anterior_id` es la del servicio. Versiones de antes de T-028 no avisan "precio nuevo";
  sí "vencida".
- **"Actualizar al precio nuevo":** `PUT alternativa-items/{id}/actualizar-tarifa` — re-engancha el
  ítem a la versión siguiente activa (costo y precio de la versión nueva, convertidos a la moneda de
  la alternativa como al agregar un ítem; conserva `descuento_pct` y cantidad), recalcula el total
  de la alternativa (`recalcularTotalAlternativa()`). 422 si la alternativa está aceptada o si ya no
  hay versión siguiente. Reusar el armado de precios que ya usa el alta del ítem (no duplicarlo).
- Sin columnas nuevas ni migraciones: el mínimo es `margen_minimo_aceptable_pct` (corregido el
  12-nov-2026; la versión anterior de esta tarea pedía una columna `margen_minimo_alerta_pct`
  aparte, que T-087 dejó sin sentido al unificar el mínimo).

**Frontend (`cotizador/editar.vue` + componente nuevo `RevisionAlternativa.vue`)**
- Junto a los botones de envío: ✓ "Lista para el cliente" si no hay nada; si hay, insignia
  "N pendientes" que despliega la lista corta (una línea por tipo). Sin modal.
- Clic en una línea: lleva al primer servicio (abre su día/destino, hace scroll y lo resalta un
  momento); si hay varios, "siguiente". Secciones: abrir el formulario correspondiente.
- Línea de precio nuevo: botón "Actualizar" por servicio → confirmación "Precio actual X → nuevo Y.
  ¿Actualizar?" → aplica y recalcula. Uno por uno, sin "actualizar todos".
- Se recalcula tras cualquier cambio de la alternativa (mismo momento en que hoy se refresca el total;
  evitar llamadas en cada tecla).
- **Ver PDF:** nunca se bloquea.
- **Botón "Enviar" de T-050 (correo, WhatsApp, "ya la envié por otro medio"):** la revisión se
  muestra al elegir **cualquiera** de los tres caminos, no solo el correo; con `bloquea`, ese camino
  queda deshabilitado con el motivo; con solo avisos, el botón dice "Enviar igual". (Ajustado el
  27-oct-2026: T-050 reemplazó el envío solo por correo.)
- Configuración de Agencia: **sin cambios** (el campo del mínimo ya existe).
- El aviso de la revisión dice «markup», igual que `MargenEnVivo` (no «margen»).

## Fuera de alcance
- Cualquier revisión que no sea una de las 6 (ítem manual, referencial, edades, tipo de cambio…).
- Marca "por confirmar" en servicios (descartada, D2).
- "Actualizar todos".
- Revisión al aceptar la cotización.

## Archivos probables
- `api-sistema-fe/app/Services/AgenciaViajes/RevisionAlternativaService.php` (nuevo)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaController.php` o uno nuevo para `revision`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` (`actualizarTarifa`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionEnvioController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionActividadController.php` (`marcarEnviada`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ProveedorTarifaController.php`
- `api-sistema-fe/app/Models/AgenciaViajes/ConfiguracionAgencia.php` (solo lectura del mínimo)
- `admin-start-kit/src/components/AgenciaViajes/RevisionAlternativa.vue` (nuevo), `EnviarAlternativa.vue`
  (el botón "Enviar" de T-050, con los tres caminos; se usa en `editar.vue` ~línea 851),
  `EnviarCorreoCotizacion.vue`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`

## Criterios de aceptación
- [ ] **CA1** — Alternativa sin ítems → `bloquea`; `POST enviar-correo` → 422. → test
- [ ] **CA1b** — Alternativa sin ítems → `POST marcar-enviada` (canal WhatsApp y canal otro) → 422,
  sin `crm_actividades` nueva y con el estado de la alternativa sin cambiar; con solo avisos → 200
  y se registra como hoy. → test
- [ ] **CA2** — Ítem con precio 0 → `bloquea` con su `item_id`. → test
- [ ] **CA3** — Venta < costo → aviso aunque el mínimo sea 0; costo 100 y venta 105 (markup 5 %)
  con `margen_minimo_aceptable_pct` = 10 → aviso; con mínimo 0 → sin aviso de markup; costo 100 y
  venta 125 con mínimo 20 → sin aviso (lo que se compara es el markup 25 %, no el margen 20 %). → test
- [ ] **CA4** — Editar el precio de una tarifa usada crea la versión con `version_anterior_id`; la
  revisión de la alternativa muestra "precio nuevo" con precio actual y nuevo. → test
- [ ] **CA5** — `actualizar-tarifa` re-engancha a la versión nueva, conserva el descuento y el total
  de la alternativa cambia; en alternativa aceptada → 422. → test
- [ ] **CA6** — Tarifa con `vigente_hasta` anterior a la fecha de viaje, o desactivada → aviso. Tarifa
  de temporada sin ocurrencia que cubra la fecha → aviso. → test
- [ ] **CA7** — Pasaje aéreo con `tarifa_valida_hasta` vencida → aviso. → test
- [ ] **CA8** — Alternativa enviada y vencida → aviso. → test
- [ ] **CA9** — Alternativa correcta → lista vacía (silencio). → test
- [ ] **CA10** — Solo avisos → el correo se envía ("Enviar igual"). → test
- [ ] **CA11** — 3 ítems con el mismo problema → una sola entrada con `cantidad=3`. → test
- [ ] **CA12** — Prueba funcional en `agencia-demo`: ✓ con todo bien; insignia y navegación al
  servicio; actualizar precio con confirmación; Ver PDF sin bloqueo; correo con bloqueo y con aviso.
  Revertir datos de prueba.
- [ ] **CA13** — `type-check` y `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos: `revision` con el permiso de ver cotizaciones; `actualizar-tarifa` con el de editar
- [ ] Aislamiento de tenant: temporadas/ocurrencias se leen de la base central con su modelo
  (`CentralConnection`)
- [ ] Validación de entrada: el ítem debe ser de la alternativa; la versión nueva se busca en el
  servidor (nunca un id enviado por el frontend)
- [ ] Concurrencia: `actualizar-tarifa` bloquea la alternativa (`lockForUpdate()`) igual que los
  demás cambios de precio
- [ ] Dinero: comparar en centavos; conversión de moneda con la misma función que el alta del ítem
- [ ] Fechas con `HoraPeru`: "hoy" para vencimientos con `HoraPeru`; `tarifa_valida_hasta` y
  `fecha_vencimiento` son instantes en UTC
- [ ] Tributario: `actualizar-tarifa` copia `tip_afe_igv`/destino de la versión nueva como el alta
- [ ] `v-html`: no (mensajes como texto)
- [ ] Migraciones: no aplica (el mínimo ya existe)

## Cómo verificar
```
php artisan test --filter=Revision
php artisan test   # suite completa, contra PostgreSQL
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:** en la rama `feat/T-022-revision-antes-de-enviar` (ver `git log main..`): el de la tarea
  corregida (12-nov), el de backend con sus tests y el de frontend.
- **Tests:** 36 nuevos (`RevisionAlternativaServicioTest`: CA1, CA2–CA9, CA11, CA5; `RevisionAlternativaEnvioTest`:
  CA1, CA1b, CA10, `GET revision`, `preparar`). Se escribieron primero y se confirmó que fallaban (clase inexistente).
  Suite completa contra PostgreSQL: 2168 pasan; falló solo el intermitente conocido
  `Fila13Fase1cFacturacionTest::descuento_del_combo…` (pasa solo, 32/32). `type-check`: 47 errores, los mismos
  previos (T-101), ninguno en archivos tocados. `build`: sin errores.
- **CA12 (prueba funcional en `agencia-demo`, Opus con Playwright, 13-nov):**
  1. Cotización 90 (alternativa 124, hotel 3 × 340, costo 280): ✓ «Lista para el cliente», sin insignia.
  2. Cotización 92 (alternativa 126, sin servicios): «1 pendiente» → «La propuesta no tiene servicios»; «Enviar al
     cliente» muestra «No se puede enviar todavía…» y WhatsApp / otro medio deshabilitados. Clic en la línea → abre
     «Agregar servicio».
  3. Versión nueva de la tarifa 58 a S/ 300 (creada a mano con `version_anterior_id`): «1 servicio con precio nuevo
     en su tarifa · S/ 1,020.00 → S/ 900.00», botón «Enviar igual». «Actualizar» → confirmación «Precio actual
     S/ 1,020.00 → nuevo S/ 900.00» → total PEN 900, la línea desaparece y aparece el aviso de markup bajo el
     mínimo (20 %), porque 300 sobre 280 es 7 %. Clic en esa línea → va al hotel y lo resalta.
  4. «Ver PDF» descarga el PDF con el aviso activo. Con solo avisos, WhatsApp y otro medio quedan habilitados (no
     se envió nada, para no dejar actividad CRM).
  - **No probado en pantalla:** «Por correo», porque en local no hay Brevo configurado y el medio no aparece (igual
    que antes de esta tarea); lo cubren CA1 y CA10 en tests. La cotización 89 (alternativa 123) es una modificación
    de reserva: ahí la revisión y el envío no se muestran, a propósito.
  - **Datos revertidos:** ítem 522 de vuelta a la tarifa 58 con sus valores y su `updated_at` originales, versión 63
    borrada, total de la alternativa 124 en 1020.
- **Corrección en la revisión (Opus):** «Actualizar» en un hotel con cama adicional cobraba la cama al precio de la
  versión **vieja**. El precio de la cama vive en la tarifa (`precio_venta/costo_cama_adicional`), así que
  `precioDeItemConTarifa()` ahora deduce cuántas camas tenía el ítem y las cobra al precio nuevo. Test
  `test_ca5_actualizar_tarifa_de_hotel_con_cama_adicional_usa_la_cama_de_la_version_nueva` (daba 270 en vez de 280
  antes del fix).
- **Desviaciones de la tarea y por qué:**
  - `contarPasajerosPorTipo` pasó de `AlternativaItemController` (privado) a `PriceEngineService` (público): es la
    pieza que el alta del ítem y «Actualizar al precio nuevo» necesitan compartir; el controlador delega.
  - El armado del precio nuevo vive en `PriceEngineService::precioDeItemConTarifa()` (usa `ventaYCostoDeTarifa` y
    `convertirMoneda`, no las duplica). Las **camas adicionales** del alta no se guardan como dato: se infieren como
    lo que el snapshot tenía por encima del precio de la tarifa anterior y se suman a la nueva (solo `tarifa_fija`).
  - «Precio nuevo» sigue la cadena `version_anterior_id` (v1 → v2 → v3) hasta la versión vigente; la tarea decía la
    versión inmediata. Una versión programada a futuro todavía no avisa.
  - Si un servicio tiene precio nuevo **y** su versión vieja ya venció, solo se avisa el precio nuevo (el aviso útil).
  - Un servicio sin precio (bloquea) no se repite como «venta bajo el costo».
  - Los ítems «Ajuste — …» (T-072) no se revisan: son de la reserva.
  - Códigos de `revision`: `sin_servicios`, `precio_cero`, `markup_bajo`, `tarifa_precio_nuevo`, `tarifa_no_vigente`,
    `pasaje_tarifa_vencida`, `propuesta_vencida` (la revisión 4 produce dos códigos). La respuesta 422 de envío trae
    `message` y la lista completa en `revision`.
  - Los tests de envío existentes (`EnvioCorreoCotizacionTest`, `CrmActividadCotizacionTest`, `CrmAccionTest`) crean
    ahora una alternativa **con un ítem**: sin servicios la regla nueva (CA1) las bloquea.
  - `RevisionAlternativaServicioTest` crea las tablas centrales `temporadas`/`temporada_ocurrencias` en la base de
    pruebas si faltan (mismo recurso que `AerolineaCatalogoTest`).
  - `components.d.ts` cambió solo por el registro automático del componente nuevo.
  - La revisión del front se recalcula con un `watch` sobre la cotización recargada, la alternativa activa y el
    total local (con espera de 500 ms); backlog no tocado.

## Hallazgos fuera de alcance
- Un servicio con **descuento del 100 %** (cortesía a propósito) queda con precio 0 y **bloquea** el envío. Decidir si
  una cortesía debe poder enviarse.
- La vigencia/temporada de la tarifa se compara con `fecha_viaje_desde` para todos los servicios, no con el día en
  que cae cada uno (`dia_referencial`): un tour del día 3 con tarifa vigente hasta el día 2 no avisa.
- «Precio nuevo» solo cubre `proveedor_tarifas`. Las tarifas de guía (`guia_tarifas` también tiene
  `version_anterior_id`), las opciones de mayorista y los tours-línea no tienen este aviso.
- `preparar()` ya hacía una revisión; con `enviar()` y `marcar-enviada` son tres cálculos por envío (baratos, pero el
  de `preparar` + `enviar` podría compartirse si la lista crece).
