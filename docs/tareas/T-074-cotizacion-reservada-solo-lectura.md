# T-074 — Cotización reservada: ver qué alternativa se eligió y el lienzo en solo lectura

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-007 (misma pantalla `cotizador/editar.vue`; empezar después de unirla) |
| **Reglas** | — |
| **Decisiones** | — |
| **Rama** | `feat/T-074-cotizacion-reservada-solo-lectura` |

## Contexto
Prueba del usuario sobre la cotización 1 (31-oct-2026), ya reservada:
1. No se ve qué alternativa se eligió. En el lienzo solo hay un ✓ chico en la pestaña; en el
   listado, solo «Reservada».
2. Después de reservar no hay forma de ver cómo se armó un pasaje aéreo (ni un ítem manual):
   el enlace «Editar» pasa a «No editable — ya tiene una reserva generada» y no muestra nada.
3. El precio de venta o el descuento de cada ítem y el descuento global siguen editables. El
   backend ya los rechaza (422 «ya fue aceptada…»), pero el valor tipeado queda en pantalla
   como si se hubiera guardado.

Regla del usuario: una alternativa reservada solo cambia por **Modificar reserva** (T-007),
que trabaja sobre una versión nueva en borrador. Esta tarea es solo de pantalla; el backend
ya bloquea.

## Alcance
- **Alternativa elegida a la vista:**
  - En la pestaña del lienzo: etiqueta «Reservada» con el código de la reserva y enlace a
    ella, también para el estado `reemplazada`, que hoy no muestra ningún ícono. En este
    caso el enlace va a la reserva que la reemplazó: `modificacionAplicada.reserva`.
  - En el listado de cotizaciones: nombre de la alternativa reservada y código de la reserva,
    junto al estado.
- **Lienzo en solo lectura** cuando la alternativa activa está `aceptada` o `reemplazada` (un
  único computed en `editar.vue`, que no aplica en la versión borrador de una modificación):
  - precio de venta y descuento por ítem;
  - descuento global;
  - ✕ de eliminar ítem y de grupo;
  - «Agregar servicio», «Pasaje aéreo suelto» y «Agregar otro día»;
  - mover de día y «Marcar como elegida».
  - Mostrar un aviso corto: «Alternativa reservada: para cambiarla usa Modificar reserva»,
    con enlace a la reserva.
- **Ver detalle en solo lectura:** el pasaje aéreo y el ítem manual reservados muestran
  «Ver detalle» en lugar de «No editable». Ese enlace abre el mismo formulario
  (`PasajeAereoForm.vue`, `ItemManualForm.vue`) con una prop `soloLectura`: los campos van en
  un `<fieldset disabled>` y no se muestra el botón de guardar.

## Fuera de alcance
- Cambiar las guardas del backend: ya existen, salvo lo que diga «Hallazgos».
- Bloquear la cabecera o los pasajeros de la cotización: ya están cubiertos por
  `hayAlternativaAceptada`.
- El PDF de la cotización.

## Dónde tocar
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
  - pestañas de alternativas (~línea 173-190);
  - botones del lienzo (~286-300);
  - inputs de precio y descuento por ítem (~553-580);
  - enlaces «No editable» (~520-547);
  - descuento global (~722-733);
  - `lineaTourBloqueada()` (~2340), para unificar con el computed nuevo;
  - `abrirEdicionPasajeAereo()` / `abrirEdicionManual()` (~3763-3816).
- `admin-start-kit/src/components/AgenciaViajes/PasajeAereoForm.vue` e `ItemManualForm.vue`:
  prop `soloLectura`.
- `admin-start-kit/src/views/agencia-viajes/cotizador/index.vue` → columna de estado (~84).
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionController.php` → `index()`:
  devolver el nombre de la alternativa reservada y el código de la reserva (ya se precargan
  `Cotizacion::RELACIONES_ESTADO_RESUMEN`, sin consultas extra).
- `admin-start-kit/src/types/agencia-viajes.ts` → agregar `'reemplazada'` al tipo `estado` de
  la alternativa (~línea 338).

## Criterios de aceptación
- [ ] **CA1** — Dada una cotización con alternativa aceptada, en el lienzo su pestaña dice
  «Reservada · <código>» y enlaza a la reserva → manual.
- [ ] **CA2** — Dada una alternativa `reemplazada` por una modificación, su pestaña lo indica
  y enlaza a la reserva → manual.
- [ ] **CA3** — En el listado, la fila de una cotización reservada muestra el nombre de la
  alternativa y el código de la reserva, sin consultas extra por fila → test:
  `CotizacionEstadoResumenTest` (o uno nuevo del listado).
- [ ] **CA4** — Con la alternativa reservada no se puede escribir precio, descuento por ítem ni
  descuento global, ni agregar, eliminar o mover servicios → manual.
- [ ] **CA5** — En la versión borrador de «Modificar reserva», todo lo anterior sigue
  editable → manual.
- [ ] **CA6** — El pasaje aéreo y el ítem manual reservados muestran «Ver detalle»; el
  formulario abre con los datos cargados, todo deshabilitado y sin botón de guardar → manual.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas nuevas)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: verificar en las etiquetas nuevas (nombre de alternativa)
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=CotizacionEstadoResumen
```
Tenant `agencia-demo` (o el local donde está la cotización 1): abrir la cotización reservada,
probar cada CA; luego abrir «Modificar reserva» desde la reserva y comprobar CA5.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
