# T-118 — Venta a crédito: una cuota por defecto y «Pagar en varias cuotas»

| | |
|---|---|
| **Módulo** | VEN |
| **Depende de** | T-116 (hecha) |
| **Reglas** | — |
| **Decisiones** | Usuario, 21-nov-2026: la fecha de la cuota se elige con el calendario (sin atajos de 15/30/60 días: el plazo es variable) |
| **Rama** | `feat/T-118-credito-una-cuota` |

## Contexto
Lo más común en una venta a crédito es una sola cuota («pago a N días»), pero registrar y editar
abren el cronograma de varias cuotas (N°, periodicidad, generar). El modo «Una cuota» ya existe en
`CronogramaCuotasCampos` (`permitir-una-cuota`) y solo lo usa la facturación de reservas de Agencia.
El usuario lo vio en otro sistema (una fecha al costado; un check para varias cuotas) y pidió esto.

## Alcance
1. Registrar (`CobroCredito`) y editar (`CobroEdicion`) usan `permitir-una-cuota`: a crédito se ve
   «Vence el» con el calendario (mínimo: la fecha de emisión) y el monto a financiar.
2. El cambio de modo pasa a ser un interruptor con etiqueta **«Pagar en varias cuotas»** (también en
   Agencia, mismo significado que los botones «Una cuota / Varias cuotas»).
3. Al pasar de una a varias cuotas se conserva la cuota (N° = 1), y el aviso de desactualizado (T-116)
   invita a regenerar al cambiar el N°. De varias a una: una cuota con todo el saldo y la fecha de la
   primera cuota (o la ya elegida).
4. La edición abre en «una cuota» con su fecha si la venta tiene una cuota; con varias, en «varias».
5. En una cuota sin monto a financiar (todo pagado) no se arma cuota de 0.
6. `store()` de registrar y editar: sin cuotas, el mensaje dice «Elige la fecha de vencimiento de la
   cuota (o genera el cronograma)».

## Fuera de alcance
- Atajos de días (decisión del usuario).
- Unificar el cobro de la edición con el de registro (propuesta aparte, sin crear).
- Backend: el payload (`cronograma`) no cambia.

## Dónde tocar
- `admin-start-kit/src/components/CronogramaCuotasCampos.vue` → `modo`, `cambiarModo()`, `armarUnaCuota()`
- `admin-start-kit/src/helpers/cronogramaCuotas.ts` (nuevo) → `modoInicialCronograma()`
- `admin-start-kit/src/components/Sales/CobroCredito.vue`, `CobroEdicion.vue` → `permitir-una-cuota`
- `admin-start-kit/src/views/sale/register.vue`, `edit.vue` → mensaje de `store()`

## Criterios de aceptación
- [ ] **CA1** — Registrar a crédito: aparece «Vence el»; elegir una fecha arma 1 cuota con todo el monto → navegador
- [ ] **CA2** — Con «Pagar en varias cuotas»: N°, periodicidad y tabla; desmarcarlo vuelve a una cuota → navegador
- [ ] **CA3** — Editar una venta con 1 cuota: abre en una cuota con su fecha; cambiarla llega al PATCH → navegador
- [ ] **CA4** — Editar una venta con 2 cuotas: abre en «varias» con la tabla → navegador
- [ ] **CA5** — `modoInicialCronograma()`: 0 o 1 cuota → «una»; 2 o más → «varias»; sin permitir → «varias» → Vitest
- [ ] **CA6** — Facturar una reserva (Agencia) sigue funcionando con el interruptor → navegador

## Seguridad y correctitud
- Permisos, tenancy, concurrencia, migraciones, `v-html`: no aplica. Entrada: el backend sigue validando el
  cronograma (`CronogramaCreditoValidator`). Dinero: la cuota única se redondea a 2 decimales (como hoy).
  Fechas: `min` = fecha de emisión; sin `new Date()`. Tributario: no aplica.

## Cómo verificar
```
cd C:/xampp/htdocs/sistemfe/admin-start-kit
npx vitest run src/helpers/cronogramaCuotas.test.ts && npx vitest run && npx vite build
```

---

## Resultado
- **Commits:** ver la rama `feat/T-118-credito-una-cuota`.
- **Tests:** nuevo `helpers/cronogramaCuotas.test.ts` (3, CA5). Vitest 353 y `vite build` en verde;
  `vue-tsc` sin errores nuevos en los archivos tocados (el de `edit.vue` es el conocido, T-101).
- **Navegador** (`e2e/verif-t118-una-cuota.mjs`, agencia-demo, sin guardar nada):
  CA1 registrar a crédito abre con «Vence el» y el interruptor apagado; 15/12/2026 arma 1 cuota de PEN 50.00
  que calza. CA2 al encender «Pagar en varias cuotas» queda la cuota (N° 1); con N° 3 y Regenerar, 3 filas;
  al apagarlo vuelve a una cuota con la fecha 15/12/2026. CA3 la venta 35 simulada con 1 cuota al 31-12 abre en
  una cuota con esa fecha; cambiada a 30/11/2026 el PATCH lleva `[{1, 280, 2026-11-30}]`. CA4 con 2 cuotas abre
  en «varias» con 2 filas y N° 2.
- **Desviaciones de la tarea y por qué:** CA6 (facturar una reserva de Agencia) no se probó en navegador: no hay
  script de ese flujo y armarlo pide una reserva lista para facturar. En Agencia solo cambia el control (botones
  «Una cuota / Varias cuotas» → interruptor) con el mismo `cambiarModo()`; el modo inicial sigue siendo «una»
  (el cronograma llega vacío). Cambio de comportamiento: de una a varias ya no borra la cuota (antes sí).

## Hallazgos fuera de alcance
- En agencia-demo, «Servicio de Transporte» (precio variable) no deja escribir el precio desde Playwright con
  `fill()` + Tab (queda en 0). No se investigó: puede ser del script; anotar si alguien lo ve a mano.
