# Recorrido: el bloque Servicios de Venta directa, como la maqueta aprobada

05-nov-2026, tarea **T-086**. Decisión: **D-AGE-034** (tiene el enlace a la maqueta «Venta directa —
rediseño B»).

---

## 1. El problema

T-085 construyó el bloque **3 · Servicios** leyendo solo el texto de su tarea, y la pantalla quedó
distinta de la maqueta aprobada: catálogo en lista, concepto libre como una línea vacía para
llenar, fecha en un campo con título aparte, noches y cantidad como campos numéricos, y nada que
avisara cuando una tarifa todavía no tenía elegido cómo se cobra.

La lógica estaba bien (líneas, vista previa, precios, afectación). Lo que cambió en T-086 es **cómo
se ve y se usa**: solo frontend, sin tocar el payload ni el backend.

**Lección de proceso:** desde T-086, el enlace a la maqueta vive en D-AGE-034 y cada tarea de Venta
directa dice que hay que seguirla. Una pantalla aprobada en una maqueta no se describe solo con
texto en la tarea: se enlaza.

## 2. Archivos

| Archivo | Qué cambió |
|---|---|
| `admin-start-kit/src/views/agencia-viajes/venta-directa.vue` | Bloque 3 rehecho; moneda con dos botones en el bloque 1; estilos `linea-pendiente`, `opcion-cobro`, `opcion-elegida`, `catalogo-grilla`, `btn-agregar-servicio` |

Tablas: ninguna. Backend: ninguno.

## 3. El recorrido

### 3.1 El catálogo se abre solo

`catalogoAbierto` arranca en `true` y `quitarLinea()` lo vuelve a abrir si se quita el último
servicio. Sin servicios se ve «Todavía no agregaste servicios. Elige el primero:»; con servicios,
el catálogo se cierra al agregar (`agregarLinea()`) y queda el botón «+ Agregar servicio» a todo el
ancho.

Las tarifas se muestran en tarjetas con CSS grid:

```css
.catalogo-grilla { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); }
```

`auto-fill` + `minmax(230px, 1fr)` pone tantas columnas como entren, cada una de al menos 230 px. En
un teléfono queda una sola columna, sin código extra.

### 3.2 Concepto libre: la línea nace llena

Antes, «Agregar concepto libre» creaba una línea vacía y el vendedor la llenaba en la tarjeta. Ahora
el formulario está **dentro del catálogo**, con sus propios `ref`: `libreDesc`, `librePrecio`,
`libreCant`, `libreCosto`. El botón se habilita con `libreListo`:

```ts
libreDesc.trim() !== '' && librePrecio > 0 && cantidadValida(libreCant) && libreCosto >= 0
```

Al agregar, `agregarLibre()` crea la línea con esos valores y limpia el formulario. La tarjeta de la
línea solo muestra «2 × S/ 50.00»: para corregirla se quita y se vuelve a agregar, como en la maqueta.

### 3.3 La tarjeta de cada servicio

- `etiquetaTipo(l)`: PROVEEDOR / HOTEL / LIBRE (T-047 suma TOUR).
- `subLinea(l)`: el proveedor, «Hotel · doble» o «Concepto libre».
- Fecha compacta: sigue siendo un `<select>` de **día del viaje** (1 a 14), porque eso es lo que se
  guarda (`alternativa_items.dia_referencial`). En un hotel dice «Entra el …».
- `detalleLinea(l, i)`: una línea de texto según el tipo. «2 noches × S/ 280.00 por noche», «2 ×
  S/ 50.00», o el desglose de la vista previa («2 adultos × 30»).
- «✓ Agregado» aparece mientras dura `resaltadoUid` (2,5 s), el mismo mecanismo que ya marcaba la
  línea recién agregada.

### 3.4 Contadores y cobro pendiente

`cambiarCantidad(l, delta)` sirve para noches y unidades, y nunca baja de 1. Tocar el contador de
«Por unidad» también elige ese modo (`l.modoPrecio = 'tarifa_fija'`).

`esPendiente(l)` = tarifa que no es hotel y sin `modoPrecio`. Con eso:

- la tarjeta lleva la clase `linea-pendiente` (borde naranja) y el texto «¿Cómo se cobra? Elige una
  opción.»;
- `textoTotalLinea()` devuelve «—» en la tarjeta y «Por elegir» en el resumen;
- `faltante` (de T-085) ya impedía crear mientras hubiera una línea así.

`modoPrecio` nace en `null` a propósito (T-045): el vendedor elige viendo los dos totales, nunca hay
uno elegido por defecto.

### 3.5 Moneda con dos botones

Dos `<button>` con `aria-pressed` en vez de un `<select>`. La regla no cambia: una tarifa en la otra
moneda se ve con «Está en dólares» / «Está en soles» y deshabilitada.

## 4. Cómo probarlo

En agencia-demo, `/agencia-viajes/venta-directa`, con 2 adultos:

1. Sin servicios → catálogo abierto en tarjetas.
2. Agrega «Desayuno» (S/ 30) → tarjeta naranja, total «—», «Por elegir» en el resumen. Elige «Por
   persona» → S/ 60.00.
3. Agrega un hotel doble y súbele a 2 noches → S/ 560.00 y «Sale el …».
4. Concepto libre «Cena show», precio 50, cantidad 2 → S/ 100.00. Total: S/ 720.00.
5. Cambia a dólares → las tarifas en soles dicen «Está en soles».

## 5. Ejercicios

1. ¿Por qué la fecha de cada servicio es un `<select>` de días y no un `CampoFecha`? ¿Qué pasaría
   con un tour del día 3 si el vendedor después cambia la fecha de inicio?
2. Cambia `minmax(230px, 1fr)` por `minmax(300px, 1fr)` en `.catalogo-grilla`. ¿Cuántas columnas ves
   en tu pantalla? ¿Y con el navegador angosto?
3. Una tarifa por persona: el vendedor toca «+» en «Cantidad» de «Por unidad». ¿Qué modo queda
   elegido y por qué? Busca la línea del template que lo decide.
4. ¿Qué tendría que cambiar para que el concepto libre se pudiera editar después de agregado?
   ¿Qué parte de la maqueta dejaría de seguirse?
