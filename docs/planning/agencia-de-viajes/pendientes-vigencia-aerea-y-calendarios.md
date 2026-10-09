# Pendientes — vigencia de tarifas aéreas y calendarios Rizz (09-oct-2026)

Dos pendientes anotados a pedido del usuario, **sin implementar todavía**. Antes de
construir, confirmar las dudas abiertas de cada uno.

Contexto del mismo día (ya hecho, sin commitear al momento de escribir esto):
el PDF de cotización no imprimía el **itinerario del pasaje aéreo suelto**
(`cotizacion_pasaje_aereo.itinerario`) — la sección "Vuelo" solo leía los datos de vuelo
del mayorista. Corregido en `AlternativaPdfService::generar()`; además el pasaje aéreo ya
no se repite en "Incluye" (mismo criterio que la matriz de hoteles, que tiene su propia
sección). Verificado con el PDF real de CDKM-0926-0000011 (`agencia-demo`).

---

## 1. Vigencia de la cotización cuando incluye pasajes aéreos

### Problema
El PDF muestra `Vigencia: 15 días desde la emisión` (sale de
`configuracion_agencia.dias_vigencia_cotizacion`, un número fijo para toda la agencia).
Para un boleto aéreo eso es falso: la aerolínea sostiene la tarifa solo hasta una fecha
**y hora** límite (a veces el mismo día) y después varía.

### Lo que ya existe
- `alternativas.fecha_vencimiento` (datetime) — columna creada desde el diseño original
  (`= fecha_envio + dias_vigencia_cotizacion`), **pero ningún endpoint ni pantalla la
  escribe**. El blade (`alternativa.blade.php`, bloque "Vigencia") ya le da prioridad sobre
  los días configurados — hoy esa rama nunca se ejecuta.

### Propuesta (pendiente de aprobar)
1. **Campo "Tarifa válida hasta" (fecha + hora) en `PasajeAereoForm.vue`** — columna nueva
   en `cotizacion_pasaje_aereo` (p. ej. `tarifa_valida_hasta`, instante en UTC, se muestra
   en hora de Perú — regla de fechas de `CLAUDE.md`). Validación en
   `AlternativaItemController::validarPasajeAereo()`.
2. **PDF:**
   - Si la alternativa tiene pasajes con límite → `Vigencia: hasta dd/mm/aaaa hh:mm`
     usando el límite **más temprano** entre todos sus pasajes.
   - Siempre que haya un pasaje aéreo → nota fija debajo: *"Tarifa aérea sujeta a
     disponibilidad y variación hasta la emisión del boleto."*
   - Sin pasaje aéreo → sin cambios (días configurados).
3. **Cotizador (`editar.vue`):** aviso visible en el ítem/alternativa si el límite ya pasó,
   para no reenviar un precio vencido.

Razón de poner el límite en el pasaje y no solo en la alternativa: es un dato que la
aerolínea entrega junto con la tarifa, y en una cotización mixta (pasaje + hotel/tour) es
el que vence primero.

### Dudas abiertas para el usuario
- ¿El texto de la nota sirve así, o debe ser editable en Configuración de Agencia?
- ¿Agregar también una "Vigencia hasta" manual por alternativa (para cualquier cotización,
  con o sin pasaje)? Es poco trabajo extra: la columna `alternativas.fecha_vencimiento` ya
  existe y el PDF ya la lee.

---

## 2. Calendarios del vertical Agencia de Viajes → `CampoFecha.vue` (Rizz)

### Problema
El módulo usa `<input type="date">` / `<input type="time">` nativos del navegador. El
estándar del sistema (Créditos, y la regla de memoria `feedback_estilo_ui_como_agencia_y_ventas`)
es `src/components/CampoFecha.vue`: flatpickr con el tema Rizz, en español, lunes primero,
muestra `dd/mm/aaaa`, mismo calendario en el celular, `v-model` en `YYYY-MM-DD`, props
`min`/`max`/`estatico` (este último obligatorio dentro de modales).

### Inventario (revisado 09-oct-2026, `admin-start-kit/src/views/agencia-viajes/`)
**Fechas (`type="date"`, 33 campos) — reemplazo directo por `<CampoFecha>`:**

| Pantalla | Campos |
|---|---|
| `cotizador/index.vue` | filtro viaje desde/hasta (2) |
| `cotizador/nueva.vue` | fecha de viaje desde/hasta (2) |
| `cotizador/editar.vue` | cabecera desde/hasta (2), nuevo destino inicio/fin (2) |
| `reservas/index.vue` | filtro viaje desde/hasta (2) |
| `reservas/detalle.vue` | vuelo ida/vuelta del pasajero (2), vuelo de agencia ida/vuelta (2), fecha del ítem (1), modal reprogramar desde/hasta (2), facturación externa (1) — 8 |
| `salidas/index.vue` | filtro desde/hasta (2) |
| `reporte-operativo/index.vue` | filtro desde/hasta (2) |
| `venta-directa.vue` | fecha de servicio (1) |
| `paquetes/form.vue` | vigencia desde/hasta (2) |
| `paquetes/detalle.vue` | vigencia desde/hasta (2, `b-form-input`) |
| `proveedores/detalle.vue` | tarifa vigente desde/hasta (2) |
| `guias/detalle.vue` | tarifa vigente desde/hasta (2) |
| `temporadas/index.vue` | ocurrencia desde/hasta (2) |

**Horas (`type="time"`, 13 campos) — `CampoFecha` NO cubre horas:**
`reservas/detalle.vue` (vuelo ida/vuelta ×2 bloques + hora del ítem = 5),
`paquetes/form.vue` (salida/retorno = 2), `paquetes/detalle.vue` (salida/retorno + hora de
paso alta/edición = 4), `proveedores/form.vue` (check-in/check-out = 2).

### Notas para la implementación
- Para las horas hace falta un componente hermano (p. ej. `CampoHora.vue`, flatpickr con
  `enableTime + noCalendar`, `time_24hr`, `v-model` en `HH:mm`). Ojo: el backend valida
  `H:i` sin segundos (comentarios en `paquetes/form.vue:251` y `paquetes/detalle.vue:913/1221`).
- El campo "Tarifa válida hasta" del punto 1 necesita fecha + hora → resolverlo con
  `CampoFecha` + `CampoHora` juntos (o un modo fecha-hora), decidir al construir.
- Filtros con `@change="list"`/`@change="buscar"` y el ítem de reserva con
  `@change="guardarItem(it)"`: `CampoFecha` emite solo `update:modelValue` → pasar a
  `watch` o `@update:model-value`, revisar cada caso para no disparar guardados dobles.
- Campos dentro de modales (reprogramar en `reservas/detalle.vue`, etc.) → `estatico`.
- Mantener las validaciones de rango existentes (desde ≤ hasta) usando `min`/`max`.
- Verificar en vivo con Playwright contra `agencia-demo` (ver
  `reference_playwright_browser_testing_windows` en memoria).
