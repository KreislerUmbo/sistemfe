# Recorrido: markup, margen y ganancia a la vista, con un solo mínimo

06-nov-2026, tarea **T-087**. Requerimiento: `docs/requerimientos/agencia-precio-y-margen.md` (P4,
la parte que no espera al contador).

---

## 1. El problema

El sistema llamaba «margen» a algo que en realidad es **markup**:

| Concepto | Fórmula | Costo 100, venta 125 |
|---|---|---|
| **Markup** (sobre el costo) | (venta − costo) ÷ costo | **25 %** |
| **Margen** (sobre la venta) | (venta − costo) ÷ venta | **20 %** |
| **Ganancia** | venta − costo | **S/ 25** |

Con el mismo dato, «25 %» y «20 %» son los dos correctos: miden cosas distintas. El riesgo era
leer «margen 25 %» pensando en la venta y creer que se gana más de lo que se gana.

Además había **dos mínimos**: la tarifa de proveedor pintaba rojo con un **20 % escrito en el
código** (`MARGEN_MINIMO_ACEPTABLE_PCT = 20`), y el tour usaba el mínimo de Configuración. Si la
agencia ponía 30 % en Configuración, la tarifa seguía comparando con 20.

**Qué no cambió:** el backend. `PriceEngineService` siempre aplicó markup (costo × (1 + %)), y las
columnas siguen llamándose `margen_tipo`, `margen_valor`, `margen_minimo_pct`,
`margen_minimo_aceptable_pct`. Solo cambiaron las etiquetas y lo que se muestra.

## 2. Archivos

| Archivo | Qué cambió |
|---|---|
| `admin-start-kit/src/utils/margenes.ts` (nuevo) | Las tres fórmulas, en un solo lugar |
| `admin-start-kit/src/utils/margenes.test.ts` (nuevo) | 7 tests de Vitest |
| `admin-start-kit/src/components/AgenciaViajes/MargenEnVivo.vue` | Muestra los tres números; props nuevas |
| `views/agencia-viajes/proveedores/detalle.vue` | Modal y tabla de tarifas usan el componente; fuera el 20 fijo |
| `views/agencia-viajes/paquetes/detalle.vue` | Combo (vista previa y panel lateral) usa el componente |
| `components/AgenciaViajes/PromoverProveedorModal.vue` | Usa el componente |
| `configuracion/index.vue`, `guias/detalle.vue`, `paquetes/form.vue`, `proveedores/form.vue`, `TourPrecioVentaCampos.vue` | Etiquetas «Margen …» → «Markup …» |

Tablas: ninguna. Backend: ninguno.

## 3. El recorrido

### 3.1 El helper: una sola fórmula para todo

Antes, cada pantalla hacía su propia cuenta (`calcularMargenPct` en proveedores,
`margenResultante` en el combo, `margen` en Promover proveedor). Ahora todas llaman a
`utils/margenes.ts`:

```ts
export const ganancia  = (costo, precio) => precio − costo
export const markupPct = (costo, precio) => ganancia ÷ costo × 100   // null si costo = 0
export const margenPct = (costo, precio) => ganancia ÷ precio × 100  // null si precio = 0
```

Tres detalles para entenderlo:

- **`aNumero()`** convierte lo que llega: la API manda los decimales como texto (`"125.00"`), y un
  campo vacío (`""`) cuenta como «falta el dato» (`null`), no como 0.
- **`null` en vez de 0** cuando no se puede dividir. Antes, una tarifa con costo 0 mostraba «0 %»,
  que es falso: ahora el markup sale «—» y el margen 100 %.
- **No redondea.** El redondeo está solo en `formatPct()` (1 decimal) y `formatMonto()`
  (2 decimales), al mostrar. Si se redondeara en el cálculo, sumar o comparar números ya
  redondeados acumula errores.

### 3.2 El componente: `MargenEnVivo.vue`

Ya existía para el tour (fila 13). Ahora es el único lugar que pinta estos números:

```
[Markup 25.0 % · Margen 20.0 % · Ganancia PEN 25.00]  · markup mínimo 30.0% (de la agencia) ⚠
```

**De dónde sale el mínimo (`piso`):** `margenMinimoTour ?? margenMinimoAgencia ?? null`. El del
tour o combo manda; si no tiene, el de la agencia; si tampoco hay, no hay aviso.

**Colores (`claseBadge`)**, en este orden:
1. **Rojo** si la ganancia es negativa (se vende bajo el costo).
2. **Amarillo** si el markup está debajo del mínimo (`bajoMinimo`).
3. **Verde** en otro caso.

El aviso compara contra el **markup**, no contra el margen: los mínimos que las agencias ya
configuraron se escribieron pensando en markup, y el backend
(`ComboValidationService::validarMargenMinimo()`) también compara contra markup.

**Props nuevas** (todas opcionales, para que las pantallas viejas sigan igual):

| Prop | Para qué |
|---|---|
| `detalle` (true) | `false` oculta «precio … − costo …» (en la tabla y los modales sobra) |
| `verMinimo` (true) | `false` no escribe «· markup mínimo X %»: queda solo el ícono ⚠ con el dato en el `title`. Se usa en la tabla de tarifas, para no repetir el texto en cada fila |
| `origenMinimo` («del tour») | El combo pasa «del combo» |
| `etiqueta` | Ahora opcional: sin etiqueta no se pinta el «X:» |

### 3.3 Un solo mínimo en la tarifa de proveedor

En `proveedores/detalle.vue` se borraron `MARGEN_MINIMO_ACEPTABLE_PCT`, `calcularMargenPct`,
`claseMargen` y `margenTarifa`. En su lugar:

```ts
const margenMinimoAgencia = computed(() => configAgencia.value?.margen_minimo_aceptable_pct ?? null) // (con Number())
```

`configAgencia` ya se cargaba en esa pantalla (para edades y tributario), así que no hubo llamada
nueva a la API.

### 3.4 El combo

En `paquetes/detalle.vue` se quitaron `preview.margenResultante` y `margenOk`, que calculaban el
markup a mano. La vista previa y el panel lateral pasan `preview.ventaNeta` y `preview.costoTotal`
al componente. El mínimo es el del combo (`margenMinimoLocal`) o, si no tiene, el de la agencia.

Ojo: el backend solo **bloquea** el guardado con el mínimo del combo. El de la agencia en el combo
es solo un **aviso** en pantalla.

En la revisión también se quitó de esta pantalla la carga de `configAgencia`: ya no la usaba nadie
(el mínimo de la agencia llega en `show()` como `margenMinimoAgencia`), y era una petición de más.

## 4. Lo que quedó afuera

- **Restar el IGV** a la ganancia (P4b): espera la respuesta del contador sobre si el costo se
  registra con o sin IGV.
- **Los números del combo están mal desde antes** (hallazgo de la prueba): el combo suma las
  tarifas del Incluye de cada tour, no el precio del tour. Combo 15 de agencia-demo: tours de
  S/ 375, el combo muestra S/ 205. T-087 lo muestra fielmente; el arreglo es **T-090**.

## 5. Para probarlo

`http://agencia-demo.sistemafe.test:5173`:
1. Proveedores → una tarifa con costo 100 y venta 125 → «Markup 25.0 % · Margen 20.0 % · Ganancia 25.00».
2. Configuración → «Markup mínimo aceptable» 30 → la tarifa en amarillo con ⚠; 20 → verde; vacío → verde sin mínimo.
3. Venta 80 → rojo (pérdida).
4. Tour, combo y «Promover proveedor»: el mismo formato.

```
cd admin-start-kit && npx vitest run src/utils/margenes.test.ts
```
