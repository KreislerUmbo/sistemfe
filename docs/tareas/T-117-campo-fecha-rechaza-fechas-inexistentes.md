# T-117 — Campo de fecha: rechazar una fecha escrita que no existe, en vez de cambiarla por otra

| | |
|---|---|
| **Módulo** | UI |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | Opción «rechazar y avisar» (usuario, 21-nov-2026, análisis en T-116) |
| **Rama** | `fix/T-117-campo-fecha-inexistente` |

## Contexto
En la venta 41 de umbo el usuario quiso poner el vencimiento de una cuota el «31/11/2026» y el campo lo
guardó como 01/12/2026 sin avisar: flatpickr arma `new Date(2026, 10, 31)` y JavaScript pasa el día que
sobra al mes siguiente. Pasa en todos los campos de fecha (`CampoFecha.vue` se usa en ~35 archivos:
ventas, cuotas, reservas, viajes, caja, reportes). En dinero y en fechas de viaje, una fecha cambiada sin
avisar es un error que nadie nota.

Opciones analizadas el 21-nov: (a) rechazar y avisar, (b) llevar al último día del mes, (c) solo
calendario, (d) `input type="date"` del navegador. Elegida **(a)**: es la única que no decide por el
usuario. (b) adivina, (c) frena a quien escribe fechas, (d) pierde el estilo de la plantilla.

## Alcance
1. Función pura `parsearFechaEscrita(texto)` en `helpers/fecha.ts`: acepta `dd/mm/aaaa` (y `d/m/aaaa`) y
   devuelve `YYYY-MM-DD` solo si la fecha existe (día dentro del mes, febrero bisiesto); si no, `null`.
2. `CampoFecha.vue` le pasa a flatpickr un `parseDate` que usa esa función para lo escrito a mano
   (`altFormat` `d/m/Y`); el valor del modelo (`Y-m-d`) se sigue leyendo como hoy.
3. Fecha escrita que no existe: el campo **conserva la fecha anterior** (o queda vacío si no había), se
   pone en rojo y muestra debajo «31/11/2026 no existe». El aviso se va al escribir o elegir una fecha
   válida. Sin ventanas emergentes (ayudas sin fricción).
4. Respetar `min`/`max` como hoy (flatpickr ya los aplica): esta tarea no los cambia.

## Fuera de alcance
- Formatos de año con 2 dígitos u otros separadores, si hoy no se aceptan.
- Validar fechas en el backend (cada endpoint ya valida `date`; `2026-11-31` ya lo rechaza Laravel).
- Cambiar el aspecto del calendario.

## Dónde tocar
- `admin-start-kit/src/helpers/fecha.ts` → nueva `parsearFechaEscrita()` (junto a `soloFechaISO()`)
- `admin-start-kit/src/components/CampoFecha.vue` → opciones de `flatpickr(...)` en `onMounted()`
  (`parseDate`, `onClose`), `sincronizarAlterno()` (clase `is-invalid`) y el template (texto del aviso)
- Ojo: varios formularios pasan `:invalido` propio (p. ej. `CronogramaCuotasCampos` con cuota sin fecha):
  el rojo de esta tarea se suma al del padre, no lo pisa.

## Criterios de aceptación
- [ ] **CA1** — «31/11/2026», «30/02/2026», «29/02/2026» y «31/04/2026» dan `null`; «29/02/2028»,
  «30/11/2026» y «1/3/2026» dan la fecha ISO → Vitest `fecha.test.ts`
- [ ] **CA2** — En la edición de una venta a crédito, escribir «31/11/2026» en una cuota que decía
  31/12/2026: el campo sigue en 31/12/2026, rojo, con «31/11/2026 no existe»; el PATCH lleva `2026-12-31`
  → navegador (`e2e/verif-t116-fecha-cuota.mjs` adaptado)
- [ ] **CA3** — Escribir luego «30/11/2026» quita el aviso y el modelo queda en `2026-11-30` → navegador
- [ ] **CA4** — Elegir con el calendario y borrar el campo siguen igual que hoy (vacío limpia el modelo)
  → navegador, en una pantalla de Agencia (fecha de viaje) y en Caja
- [ ] **CA5** — Dentro de un modal (`estatico`) el aviso se ve y no rompe el diseño → navegador

## Seguridad y correctitud
- Permisos: no aplica. Tenancy: no aplica. Entrada: es la validación de lo escrito. Concurrencia: no aplica.
  Dinero: no aplica. Fechas: no usa `new Date()` para validar «hoy» ni zona horaria; solo día/mes/año
  escritos (sin `toISOString()`). Tributario: no aplica. `v-html`: no aplica. Migraciones: no aplica.

## Cómo verificar
```
cd C:/xampp/htdocs/sistemfe/admin-start-kit   # con C: mayúscula (vitest)
npx vitest run src/helpers/fecha.test.ts
npx vitest run
npx vite build
```
Navegador en agencia-demo: edición de venta (cuotas), fecha de viaje de una reserva, filtro de fechas de Caja.

---

## Resultado
- **Commits:** ver la rama `fix/T-117-campo-fecha-inexistente`.
- **Tests:** `parsearFechaEscrita()` en `fecha.test.ts` (4 nuevos, CA1). Vitest 357 y `vite build` en verde;
  `vue-tsc` sin errores en los archivos tocados.
- **Navegador** (`e2e/verif-t117-campo-fecha.mjs`, agencia-demo, PATCH interceptado):
  CA2 la cuota en 31/12/2026, escribir «31/11/2026» → sigue 31/12/2026, rojo, «31/11/2026 no existe.»; el PATCH lleva
  `2026-12-31`. CA3 «30/11/2026» → sin aviso y el PATCH lleva `2026-11-30`. CA4 elegir el 25 con el calendario
  quita el aviso; borrar el campo lo deja vacío (y la cuota única desaparece, como antes). Reportes comerciales:
  «30/02/2026» deja «01/11/2026» con aviso. CA5 modal «Nueva consulta» (estático): «31/04/2026» con el campo vacío
  → sigue vacío con aviso; el aviso se ve bajo el campo y el botón del calendario conserva su borde.
- **Desviaciones de la tarea y por qué:**
  - Caja no usa `CampoFecha`: en su lugar se probó el filtro de fechas de Reportes comerciales de Agencia.
  - Se aceptan «-» y «.» como separador, además de «/»: el parser de flatpickr los aceptaba (sus separadores son
    comodines) y quitarlos sería un cambio que nadie pidió.
  - Texto que no es una fecha («mañana») también se rechaza con aviso («no es una fecha: escríbela como
    dd/mm/aaaa»); antes vaciaba el campo sin avisar.
  - `parseDate` lleva un cast: los tipos de flatpickr piden `Date`, pero su parser descarta un `undefined`.

## Hallazgos fuera de alcance
- Ninguno.
