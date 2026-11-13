# Recorrido: revisión antes de enviar («¿lista para el cliente?»)

13-nov-2026, tarea **T-022**. Origen: punto 8 de las pruebas del usuario
(`docs/requerimientos/observaciones-pruebas-usuario.md`, respuestas 1-6, D2, «Diseño — solo lo crítico»).
Archivos:
- `api-sistema-fe/app/Services/AgenciaViajes/RevisionAlternativaService.php` (nuevo: las 6 revisiones)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/RevisionAlternativaController.php` (nuevo: `GET revision`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionEnvioController.php` y `CotizacionActividadController.php` (el bloqueo al enviar)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` (`actualizarTarifa`)
- `api-sistema-fe/app/Services/AgenciaViajes/PriceEngineService.php` y `app/Models/AgenciaViajes/ProveedorTarifa.php`
- `admin-start-kit/src/components/AgenciaViajes/RevisionAlternativa.vue` (nuevo), `EnviarAlternativa.vue`,
  `EnviarCorreoCotizacion.vue`, `views/agencia-viajes/cotizador/editar.vue`

Sin migraciones.

---

## 1. La idea

Antes de mandar una propuesta, el vendedor necesita saber rápido si ya está lista. La regla de
fondo del usuario: **la revisión acelera el envío, no lo frena**. Solo puntos críticos, y silencio
cuando todo está bien.

Junto a los botones de envío aparece una de dos cosas:
- **✓ Lista para el cliente**, si no hay nada;
- una insignia **«N pendientes»** que despliega una lista corta, con una línea por tipo de problema.

Hay **exactamente 6 revisiones**, de dos niveles:

| # | Revisión | Nivel | Código |
|---|---|---|---|
| 1 | La propuesta no tiene servicios | **bloquea** | `sin_servicios` |
| 2 | Un servicio con precio de venta 0 o vacío | **bloquea** | `precio_cero` |
| 3 | Venta bajo el costo, o markup bajo el mínimo de la agencia | aviso | `markup_bajo` |
| 4 | La tarifa tiene precio nuevo, o no vale para la fecha del viaje | aviso | `tarifa_precio_nuevo`, `tarifa_no_vigente` |
| 5 | Pasaje aéreo con la tarifa vencida o que vence antes que la propuesta | aviso | `pasaje_tarifa_vencida` |
| 6 | Propuesta enviada que ya venció | aviso | `propuesta_vencida` |

- **Bloquea:** no se puede enviar por ningún medio (correo, WhatsApp ni «ya la envié por otro medio»).
- **Aviso:** se ve, y el botón dice **«Enviar igual»**.
- **Ver PDF nunca se bloquea.**

Si se te ocurre una séptima revisión, la regla es no agregarla: anotarla en el backlog y decidirla
con el usuario.

## 2. El recorrido

### 2.1 Las revisiones (`RevisionAlternativaService::revisar()`, ~línea 41)

Recibe una alternativa y devuelve una lista. Cada entrada tiene:

```
codigo, nivel ('bloquea' | 'aviso'), mensaje (texto corto en español), cantidad,
destino: { item_ids: [...], seccion: 'lienzo' | 'datos_cotizacion' | 'pasaje_aereo' | null },
detalle: [...]   ← datos extra; en «precio nuevo», el precio actual y el nuevo por servicio
```

Se **agrupa por código**: tres servicios con el mismo problema son **una** entrada con
`cantidad = 3` y los tres ids en `item_ids`, no tres líneas.

Detalles de cada revisión:
- **2 (precio 0):** mira `precio_convertido`, que ya incluye el descuento. Un servicio con 100 %
  de descuento también bloquea: hoy nada distingue una cortesía de un olvido (decisión del usuario,
  13-nov). En un grupo de hoteles se revisa cada fila.
- **3 (markup, `markupBajo()`, ~línea 98):** compara el **markup** = (venta − costo) ÷ costo,
  igual que `utils/margenes.ts::markupPct` en el front, contra
  `configuracion_agencia.margen_minimo_aceptable_pct`: es el mismo mínimo de T-087, no hay otro. La cuenta
  se hace en **centavos** (enteros) y el costo se convierte a la moneda de la alternativa. Con
  mínimo 0 solo avisa la venta bajo el costo. Sin costo no se evalúa. Un servicio que ya bloquea
  por precio 0 no se repite acá.
- **4 (tarifas, `tarifas()`, ~línea 137):** dos avisos distintos.
  - *Precio nuevo:* `ProveedorTarifa::siguienteVersionVigente()` sigue la cadena
    `version_anterior_id` (v1 → v2 → v3, la cadena que dejó T-028) hasta la versión vigente hoy.
  - *No vale para el viaje:* la tarifa está desactivada, su `vigente_hasta` es anterior a
    `fecha_viaje_desde`, o su temporada no tiene una ocurrencia que cubra esa fecha. Las ocurrencias
    están en la base **central** (`TemporadaOcurrencia` usa `CentralConnection`) y se leen en una
    sola consulta.
  - Si las dos aplican, solo se avisa el precio nuevo, que es el aviso útil.
  - Sin fecha de viaje, la parte de vigencia no se evalúa.
- **5 (pasaje aéreo, `pasajesAereos()`, ~línea 205):** `tarifa_valida_hasta` ya pasó, o vence
  antes que la propuesta. Solo se evalúa con fecha de viaje.
- **6 (propuesta vencida, `propuestaVencida()`, ~línea 237):** estado `enviada` y
  `fecha_vencimiento` pasada.

Los ítems «Ajuste — …» de una modificación de reserva (T-072) no se revisan: son de la reserva.

### 2.2 El bloqueo al enviar (`bloqueoDeEnvio()`, ~línea 80)

Una **sola** función decide «¿se puede enviar?». Devuelve `null` (sí) o la respuesta 422 lista,
con el mensaje y la revisión completa. La llaman los dos caminos de envío:

- `CotizacionEnvioController::enviar()` (~línea 126): el correo;
- `CotizacionActividadController::marcarEnviada()` (~línea 67): WhatsApp y «otro medio».
  Se chequea después de validar y de revisar el estado, y **antes** de registrar la actividad CRM:
  con bloqueo no queda nada escrito.

Así el bloqueo vale aunque alguien llame a la API directo, sin pasar por la pantalla.
`preparar()` (el formulario del correo) también devuelve la revisión, para mostrarla en el modal.

### 2.3 «Actualizar al precio nuevo» (`AlternativaItemController::actualizarTarifa()`, ~línea 192)

`PUT alternativa-items/{id}/actualizar-tarifa`, con permiso `cotizaciones.editar`. Pasos:

1. Bloquea la alternativa con `lockForUpdate()`, para que dos vendedores no se pisen el total.
2. 422 si la alternativa está **aceptada** (ya generó su reserva) o si la tarifa no tiene versión nueva.
3. Busca la versión nueva **en el servidor**: el front no manda ningún id de tarifa.
4. Arma el precio con `PriceEngineService::precioDeItemConTarifa()` (~línea 261), con las mismas
   piezas que el alta del ítem (`ventaYCostoDeTarifa()` + `convertirMoneda()`). Conserva el
   descuento y la cantidad.
5. Copia el tratamiento tributario de la versión nueva, igual que el alta, y recalcula el total
   de la alternativa.

**La cama adicional (corregido en la revisión):** en un hotel por habitación, el alta puede sumar
camas adicionales. Ese número no se guarda en el ítem, y el **precio** de la cama vive en la tarifa
(`precio_venta_cama_adicional`). Por eso `precioDeItemConTarifa()` deduce cuántas camas había (lo
que el snapshot tenía por encima del precio de la habitación vieja ÷ precio de cama viejo) y las
cobra al precio de la versión nueva. Ejemplo: habitación 200 → 220 y cama 50 → 60, con 1 cama: el
ítem pasa de 250 a **280**, no a 270.

### 2.4 La pantalla (`RevisionAlternativa.vue` y `editar.vue`, ~línea 852)

- El componente pide `GET alternativas/{id}/revision`. Vuelve a pedirla cuando cambia la
  `claveRevision` (~línea 1606): la cotización recargada, la alternativa activa o el total local.
  Espera **500 ms** desde el último cambio para no llamar en cada tecla, y descarta respuestas
  viejas si llegan tarde.
- **Clic en una línea** (`irARevision`, ~línea 1611): con servicios, va al primero, lo abre y lo
  resalta; cada clic siguiente va al siguiente servicio. Sin servicios, abre «Agregar servicio»
  (`lienzo`) o el formulario de vigencia (`datos_cotizacion`).
- **«Actualizar»**, en la línea de precio nuevo: confirmación «Precio actual X → nuevo Y.
  ¿Actualizar?», uno por uno, sin «actualizar todos».
- `EnviarAlternativa.vue` recibe la revisión: con bloqueo muestra el motivo y deshabilita los tres
  medios; con solo avisos, el botón dice «Enviar igual». El modal del correo hace lo mismo.
- En una **modificación de reserva** no se muestra ni la revisión ni el envío (`v-if="!modificacionInfo"`).
- Los mensajes se muestran como texto, nunca con `v-html`.

## 3. Cómo probarlo

```
php artisan test tests/Feature/AgenciaViajes/RevisionAlternativaServicioTest.php \
  tests/Feature/AgenciaViajes/RevisionAlternativaEnvioTest.php
```

Los tests llevan el número del criterio en el nombre (`test_ca3_se_compara_el_markup_no_el_margen`,
`test_ca1b_marcar_enviada_sin_items_da_422_en_whatsapp_y_otro_sin_efectos`, …). El de la cama
adicional es `test_ca5_actualizar_tarifa_de_hotel_con_cama_adicional_usa_la_cama_de_la_version_nueva`.

En pantalla (agencia-demo, 13-nov):
- **Cotización 90:** ✓ «Lista para el cliente».
- **Cotización 92 (sin servicios):** «1 pendiente», envío bloqueado, y el clic abre «Agregar servicio».
- **Versión nueva de la tarifa 58 a S/ 300:** «S/ 1,020.00 → S/ 900.00». Al actualizar, el total
  queda en 900 y aparece el aviso de markup (7 % contra el mínimo de 20 %).

## 4. Si algo falla

- **No aparece «precio nuevo» después de editar una tarifa:** ¿la versión nueva tiene
  `version_anterior_id` apuntando a la vieja? Las versiones anteriores a T-028 no lo tienen. ¿La
  nueva es vigente hoy? Una versión programada a futuro todavía no avisa.
- **Aviso de markup que no se entiende:** se compara el markup (sobre el costo), no el margen (sobre
  la venta). Con costo 100 y venta 125, el markup es 25 %, el margen 20 %, y con mínimo 20 **no** avisa.
- **Una cortesía (100 % de descuento) no deja enviar:** es a propósito (decisión del 13-nov).
- **Un tour del día 3 con tarifa vigente hasta el día 2 no avisa:** la vigencia se compara con la
  fecha de inicio del viaje, no con el día de cada servicio. Anotado en «Por analizar».
- **El envío por la API responde 422 con `revision`:** es el bloqueo de `bloqueoDeEnvio()`. La
  lista viene en la respuesta.
