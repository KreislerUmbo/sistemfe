# Recorrido: venta a crédito con una cuota por defecto

21-nov-2026, tarea **T-118**. Sigue al recorrido 80 (T-116).
Archivos:
- `admin-start-kit/src/components/CronogramaCuotasCampos.vue` — el cronograma (registrar, editar, facturar reserva)
- `admin-start-kit/src/helpers/cronogramaCuotas.ts` — `modoInicialCronograma()` (con su test)
- `admin-start-kit/src/components/Sales/CobroCredito.vue` (registrar) y `CobroEdicion.vue` (editar): `permitir-una-cuota`

**Al desplegar:** solo frontend (`npm run build`). Sin cambios de backend: el payload `cronograma` es el mismo.

---

## 1. La idea

Lo más común a crédito es **pagar en una sola cuota** («a N días»). Antes, registrar y editar abrían el
cronograma de varias cuotas (N°, periodicidad, «Generar»). Ahora abren con:

```
☐ Pagar en varias cuotas
Vence el [dd/mm/aaaa 📅]    Monto [S/ 120.00]
```

Eliges la fecha con el calendario (decisión del usuario: sin atajos de 15/30/60 días, el plazo varía) y el
cronograma ya es una cuota con todo el saldo. El interruptor abre el cronograma de varias cuotas.

El modo «una cuota» **ya existía** en `CronogramaCuotasCampos` (`permitir-una-cuota`) para facturar reservas de
Agencia; T-118 lo activa en ventas y cambia los botones «Una cuota / Varias cuotas» por el interruptor.

## 2. Cómo decide el modo

| Momento | Qué pasa | Dónde |
|---|---|---|
| Al montar | 0 o 1 cuota → «una»; 2 o más → «varias» (la edición abre como se guardó) | `modoInicialCronograma()` |
| Una cuota | el cronograma es `[{1, saldo, fecha}]`; se rearma si cambia la fecha o el saldo; sin saldo, ninguna | `armarUnaCuota()` + `watch` |
| Una → varias | se conserva la cuota y el N° queda en 1; al cambiar el N° el aviso de T-116 pide regenerar | `cambiarModo('varias')` |
| Varias → una | todo el saldo en una cuota, con la fecha ya elegida o la de la primera cuota | `cambiarModo('una')` |

## 3. De la pantalla a la tabla

| En pantalla | Qué se manda | Qué escribe |
|---|---|---|
| Elegir «Vence el» | nada (arma `cronograma` en memoria) | — |
| Guardar | `POST sales` / `PATCH sales/{id}` con `cronograma` | `installments` (en la edición: anula las vigentes y crea las nuevas) |

## 4. Cómo se probó

`helpers/cronogramaCuotas.test.ts` (3) y `e2e/verif-t118-una-cuota.mjs` (registrar: una cuota, varias, vuelta a
una; editar con 1 y con 2 cuotas). Facturar una reserva no se probó en navegador (mismo `cambiarModo()`).

## 5. Ejercicios

1. Edita una venta con 3 cuotas: ¿en qué modo abre? ¿Y si apagas el interruptor, qué fecha queda?
2. En una cuota, cambia un producto para que suba el total. ¿Qué pasa con el monto de la cuota? (pista: el `watch`).
3. ¿Por qué `modoInicialCronograma()` está en un helper y no dentro del componente?
