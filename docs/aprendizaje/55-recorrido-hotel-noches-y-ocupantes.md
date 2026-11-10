# Recorrido: el hotel trae las noches y los ocupantes ya puestos

09-nov-2026, tarea **T-034**. Origen: observación 9 de las pruebas del usuario, R2 (aprobada el 26-oct).
Solo frontend:
- `admin-start-kit/src/helpers/fecha.ts` (+ su test)
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `admin-start-kit/src/components/AgenciaViajes/HabitacionMatrixPicker.vue`

---

## 1. La idea

En el cotizador, flujo **Local/Nacional**, al elegir una habitación de hotel:

- **Noches** salía siempre en 1, aunque el viaje fuera del 10 al 13 (3 noches). Era fácil cotizar
  una sola noche por olvido.
- **Ningún pasajero** venía marcado como ocupante.

Ahora:

1. las noches se calculan solas. Primero se usan las fechas del **destino activo**, si tiene las
   dos; si no, las de la **cotización**; si tampoco hay, queda en **1**;
2. junto al selector aparece de dónde salió el número: «3 noches (10/12 – 13/12)»;
3. todos los pasajeros vienen **marcados**. Si el vendedor reparte al grupo en varias habitaciones,
   desmarca a los que no van en esa.

El número de noches sigue siendo editable con − / +. El flujo de mayorista (precio por adulto) no cambia.

## 2. El recorrido

### 2.1 Días entre dos fechas (`helpers/fecha.ts`)

```ts
export function diferenciaDiasISO(desde: string, hasta: string): number {
  const [a1, m1, d1] = desde.slice(0, 10).split('-').map(Number);
  const [a2, m2, d2] = hasta.slice(0, 10).split('-').map(Number);
  return Math.round((Date.UTC(a2, m2 - 1, d2) - Date.UTC(a1, m1 - 1, d1)) / 86400000);
}
```

- Es la pareja de `sumarDiasISO()`: aritmética de calendario pura, **sin zona horaria**. Una fecha
  de viaje es una fecha de negocio, no un instante. Por eso no se usa `new Date('2026-12-10')`, que
  el navegador interpreta en su propia zona y puede correrse un día.
- `slice(0, 10)` acepta tanto `2026-12-10` como lo que manda Laravel por el cast `date`
  (`2026-12-10T00:00:00.000000Z`).
- Test en `fecha.test.ts`: caso normal, cambio de mes, cambio de año, fechas iguales y fechas al revés.

### 2.2 Cuántas noches (`editar.vue`, computed `nochesHotelDefault`, ~línea 3096)

```ts
const nochesHotelDefault = computed(() => {
    const destino = alternativaDestinos.value.find((d) => d.id === destinoActivoId.value);
    const delDestino = !!(destino?.fecha_inicio && destino?.fecha_fin);
    const desde = delDestino ? destino!.fecha_inicio : cot?.fecha_viaje_desde;
    const hasta = delDestino ? destino!.fecha_fin : cot?.fecha_viaje_hasta;
    if (!desde || !hasta) return { noches: 1 };
    const noches = diferenciaDiasISO(desde, hasta);
    if (noches <= 0) return { noches: 1 };
    return { noches, texto: `${noches} noches (dd/mm – dd/mm)` };
});
```

- Como es un `computed`, al cambiar de pestaña de destino (`destinoActivoId`) las noches se
  recalculan solas: Tarapoto 10/12–13/12 da 3 y Lamas 14/12–16/12 da 2.
- Si las fechas vienen al revés (0 o negativo), se usa 1 en lugar de un número sin sentido.
- Sin fechas no hay `texto`: el picker no muestra nada en vez de mostrar «1 noche» como si fuera un dato.

### 2.3 Cómo llega al picker (`editar.vue`, ~línea 1394)

```html
:cantidad-default="matrizHotelActiva.grupoAbierto?.cantidad ?? nochesHotelDefault.noches"
:texto-noches="matrizHotelActiva.grupoAbierto ? undefined : nochesHotelDefault.texto"
marcar-todos-los-pasajeros
```

- Si ya hay una **comparación abierta** (un grupo de opciones de hotel al que se le suma otra), manda
  la cantidad del grupo: todas las opciones de un grupo deben tener las mismas noches. En ese caso
  no se muestra el texto, porque el número no salió de las fechas.
- `marcar-todos-los-pasajeros` solo lo pasa este caller (Local/Nacional). El picker de mayorista no lo
  pasa, así que sigue igual.

### 2.4 El picker (`HabitacionMatrixPicker.vue`)

Dos props nuevos, ambos opcionales:

```ts
textoNoches?: string;              // se muestra debajo del − / +
marcarTodosLosPasajeros?: boolean; // al elegir habitación, todos marcados
```

Y en `elegir()`, que corre al pulsar «Elegir»:

```ts
cantidad.value = props.cantidadDefault ?? 1;
paxSeleccionados.value = props.marcarTodosLosPasajeros ? (props.pasajeros ?? []).map((p) => p.id) : [];
```

En la fila, el `input-group` del − / + tiene `width:110px` y `mx-auto`, igual que el del modo
«Comparar varias opciones». Sin ese ancho, el texto de noches ensanchaba la celda y el selector se
estiraba (se corrigió en la revisión).

### 2.5 Qué cambia en lo que se guarda: `pax_incluidos`

Antes, si el vendedor no marcaba a nadie, el ítem se guardaba con `pax_incluidos = null`. Ahora se
guarda con la lista de ids (`[125, 126, 127]`).

- **El precio no cambia.** El hotel es `tarifa_fija` (precio por habitación × noches), y
  `AlternativaItemController` solo mira los pasajeros para la **cama adicional**. Ahora esa validación
  tiene con quién trabajar desde el inicio.
- **Diferencia real:** `null` se lee como «todos», incluso los pasajeros que se agreguen después. Una
  lista queda **fija**: si después se suma un pasajero a la cotización, no entra solo a esa
  habitación. Para una habitación eso es lo correcto, porque tiene ocupantes concretos.
- Si se **quita** un pasajero de la cotización, `CotizacionController::limpiarPaxIncluidosEliminados()`
  ya lo saca de la lista. Eso no cambió.

## 3. Cómo se probó

- Vitest: `fecha.test.ts` (CA4).
- `build` OK. `type-check` arrastra 47 errores que ya estaban en `main` (fake-backend, portal, rutas),
  ninguno en estos archivos.
- Playwright en agencia-demo:
  - CDKM-1126-0000011 (Tarapoto 10/12–13/12, 3 adultos), Hotel Rio Sol: 3 noches, el texto y los 3
    pasajeros marcados. Al agregar se guardó cantidad 3 con los 3 pasajeros, total PEN 1020 (340 × 3).
  - Segundo destino Lamas 14/12–16/12: 2 noches; al volver a Tarapoto, 3. El modo comparar muestra lo mismo.
  - CDKM-1126-0000007 (sin fechas): 1 noche, sin texto.

## 4. Al desplegar

Nada especial: solo cambia el frontend, sin migraciones.
