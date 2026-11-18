# Recorrido: el template del cotizador partido en componentes

18-nov-2026, tarea **T-105** (refactor puro: la pantalla se ve y se comporta igual que antes). Supone el
recorrido 69 (los 20 composables de T-094): este es su segunda mitad.

Archivos:
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`: de 1.849 a 317 líneas. Queda el
  script (crea los composables y los publica) y un template de ~45 líneas.
- `admin-start-kit/src/composables/agencia-viajes/cotizador/claves.ts` (con `claves.test.ts`): las
  `InjectionKey` y la función `inyectar`.
- `admin-start-kit/src/components/AgenciaViajes/cotizador/`: 18 componentes nuevos (§3).

**Al desplegar:** nada (solo frontend, sin cambios de API).

---

## 1. La idea

Después de T-094 el estado ya vivía en composables, pero el template seguía entero en la página: para
tocar el comparador de mayoristas había que abrir un archivo de 1.849 líneas. Ahora cada parte de la
pantalla es un componente. La regla que sostiene todo:

> **El estado nace en la página; los componentes solo lo muestran.**

```
editar.vue
  script:   const precios = useEdicionPrecios(…)      ← crea, en el mismo orden de T-094
            provide(CLAVE_PRECIOS, precios)           ← publica el objeto entero
            const { totalLocal, … } = precios         ← desestructura solo lo que usa la página
  template: <CabeceraCotizacion /> <LienzoCotizador /> <PanelPrecio /> <DrawerBiblioteca /> …

PanelPrecio.vue
  script:   const { totalLocal, filasResumen, … } = inyectar(CLAVE_PRECIOS)
  template: {{ totalLocal }} …                         ← el mismo HTML que estaba en la página
```

¿Por qué `provide/inject` y no props? Porque un componente como `ItemLienzo` usa cosas de ocho
composables distintos: con props habría que pasar decenas de valores por cada nivel (página → lienzo →
ítem), y para cambiar algo el hijo tendría que emitir un evento hacia arriba. Con `inject`, el hijo recibe
el mismo objeto que creó la página y llama directo a sus funciones (`onEditarPrecio(item)`).

## 2. Las claves: `claves.ts`

Una clave por composable, tipada con lo que devuelve:

```ts
export const CLAVE_PRECIOS: InjectionKey<ReturnType<typeof useEdicionPrecios>> = Symbol('precios');
```

Gracias al tipo, `inyectar(CLAVE_PRECIOS)` sabe que `totalLocal` es un `ComputedRef<number>`: si escribes
mal un nombre, `type-check` lo marca. Hay 20 claves (una por composable) y una más, **`CLAVE_PAGINA`**,
para lo que vive en la página y no en un composable: `modo` (local / mayorista / guía), `seleccionarAlternativa`,
`tipoMayoristaId`, `tipoHotelId`, `desglosePasajerosTexto`, `pasoDrawer` y `tituloDrawer`. Su tipo es la
interfaz `ContextoPagina`, escrita a mano.

`inyectar(clave)` es un `inject` que falla en voz alta: si alguien monta un componente del cotizador fuera
de su pantalla, en vez de recibir `undefined` y romperse más adelante, lanza
`Error('precios usado fuera de la pantalla del cotizador')`.

## 3. Dónde está cada parte de la pantalla

| En pantalla | Componente | Inyecta |
|---|---|---|
| Título, cliente, próxima acción, perdida, botones Reabrir / Cerrar / Volver, datos del viaje | `CabeceraCotizacion` | núcleo, pasajeros, cabecera, resultado, alternativas |
| Aviso de edad estimada, modificación no abierta, panel de diferencia, penalidad | `AvisosModificacion` | núcleo, modificación |
| Agregar o quitar pasajeros | `EdicionPasajeros` | pasajeros |
| Corregir cliente / destino / fechas (con el modal de cliente rápido) | `EdicionCabecera` | cabecera |
| Pestañas de alternativas, «+ Nueva», calculadora de tipo de cambio | `PestanasAlternativas` | núcleo, precios, resultado, alternativas, página |
| Chips de destino y su formulario | `ChipsDestino` | núcleo, destinos |
| Columna izquierda: barra, pasaje aéreo, tabs de día, avisos, bloques | `LienzoCotizador` | núcleo, días, línea de tour, bloques, biblioteca, ítems sueltos… |
| ↳ Línea de un tour (precio, mover, quitar, incluye) | `CargaTourLienzo` (prop `bloque`) | línea de tour, bloques, precios, días |
| ↳ Grupo «comparar opciones» | `GrupoOpcionesLienzo` (prop `fila`) | bloques, ítems sueltos, modificación |
| ↳ Un ítem suelto (precio, descuentos, día, acciones) | `ItemLienzo` (props `item`, `bloque`) | precios, ítems sueltos, modificación, días… |
| Columna derecha: resumen, descuento global, totales, botones, vigencia, revisión, acciones, historial | `PanelPrecio` | núcleo, precios, bloques, pasajeros, alternativas, resultado, revisión |
| Drawer de la biblioteca: encabezado, modo, matriz de habitación, modo de precio, ítem manual | `DrawerBiblioteca` | drawer, hoteles locales, ítems sueltos, días, destinos, núcleo, página |
| ↳ Modo local: chips, filtros, grid, paginado, hotel no registrado | `BibliotecaLocal` | biblioteca, hoteles locales, ítems sueltos, página |
| ↳ Modo guía | `BibliotecaGuia` | guía |
| ↳ Modo mayorista: lista y opción nueva | `ComparadorMayoristas` | opciones de mayorista, destinos, núcleo, página |
| ↳↳ Una opción de mayorista | `OpcionMayoristaTarjeta` (prop `op`) | opciones y opcionales de mayorista, destinos |
| ↳↳ Sus hoteles | `HotelesOpcionMayorista` (prop `op`) | hoteles y opciones de mayorista, pasajeros |
| ↳↳ Sus opcionales | `OpcionalesOpcionMayorista` (prop `op`) | opcionales de mayorista |

En la página quedan: el spinner de carga, el aviso «sin alternativa» y los dos modales de Promover
(proveedor y hotel).

## 4. Las reglas de un componente del cotizador

1. **No llama a composables ni crea `watch`, `ref` o `computed`.** Vue corre los watchers en el orden en
   que se crearon (recorrido 69 §5): uno creado dentro de un componente nacería cuando el componente se
   monta, en otro orden. Todo nace en la página.
2. **Desestructura lo que inyecta**: `const { totalLocal } = inyectar(CLAVE_PRECIOS)`. El template solo
   desenvuelve refs de primer nivel: `{{ precios.totalLocal }}` mostraría el objeto ref, no el número.
   Excepción conocida: `modificacion` (el objeto de `useModificacionReserva`) se usa como
   `modificacion.diferencia.value`, igual que antes.
3. **Por prop, solo el dato del bucle** (`bloque`, `fila`, `item`, `op`). Nunca `v-model` sobre una prop:
   `v-model` va sobre refs inyectadas.
4. **Sin `emit`.** Las acciones son funciones inyectadas.
5. **Los helpers puros se importan** (`formatFecha`, `etiquetaItem`, `textoPlano`…), no se inyectan.
6. **Sin `div` envoltorio nuevo**: la raíz del componente es el elemento que ocupaba ese lugar. Por eso
   las cadenas `v-if`/`v-else` quedaron en los tags de la página (`<BibliotecaLocal v-if="modo === 'local'" />`
   / `<BibliotecaGuia v-else-if…>` / `<ComparadorMayoristas v-else />`).

## 5. Tres detalles que parecen raros

**`v-if="alternativaActiva"` repetido.** `PanelPrecio`, `LienzoCotizador`, `ItemLienzo`,
`GrupoOpcionesLienzo` y `CargaTourLienzo` repiten en su raíz el `v-if` que la página ya pone en el
`div.row`. En la página, ese `v-if` le dice a TypeScript que `alternativaActiva` no es `null`; dentro de
otro componente esa garantía se pierde y `type-check` subía de 47 a 63 errores. Repetirlo no cambia lo que
se ve (siempre es verdadero ahí) y devuelve el tipo.

**Template refs en un hijo.** `PanelPrecio` tiene `ref="accionesRef"` y `ref="historialRef"`. Esos refs
nacen en `useResultadoCotizacion` (página) y llegan por inject: Vue los llena cuando monta el panel. Por
eso `proximaAccion` de la cabecera y `onResultadoCambiado` (que recarga historial y acciones) siguen
funcionando aunque los componentes vivan en otro archivo.

**`<style scoped>` no llega a los hijos.** Cada regla viaja con el componente que tiene el elemento:
`.alt-pill*` en `PestanasAlternativas`, `.destino-chip*` en `ChipsDestino`, `.price-panel` en
`PanelPrecio`, `.lib-item` y `.biblioteca-grid` en `BibliotecaLocal` (y `.lib-item` en `BibliotecaGuia`).
`.resaltado-item` (borde amarillo de «para revisar», lo devuelve `claseResaltado`) está copiada en
`LienzoCotizador` (bloques de tour), `GrupoOpcionesLienzo` e `ItemLienzo`. **Si agregas una clase con
estilo propio, su regla va en el componente que la usa**, no en `editar.vue`.

## 6. Cómo agregar algo a la pantalla

- **Un botón nuevo en el panel de precio** que llama a una función de `useAlternativas`: agrega la
  función al composable (ya se publica entera), desestructúrala en `PanelPrecio` desde
  `inyectar(CLAVE_ALTERNATIVAS)` y úsala en el template. La página no se toca.
- **Un dato nuevo que no es de ningún composable** (como `tipoHotelId`): créalo en la página, agrégalo a
  `ContextoPagina` y al `provide(CLAVE_PAGINA, …)`.
- **Un watcher nuevo**: en el composable si es de un solo bloque, en la página si cruza bloques. Nunca en
  un componente.

## 7. Cómo se prueba

- `npm run test`: `claves.test.ts` comprueba que `inyectar` devuelve el objeto publicado y que lanza el
  error si falta el `provide` (usa `app.runWithContext`, sin montar componentes).
- Que ningún componente cree estado:
  `grep -rnE "use[A-Z]\w*\(|watch(Effect)?\(|defineEmits|useRout" src/components/AgenciaViajes/cotizador/`
  no debe encontrar nada.
- Playwright (`e2e/recorrido-cotizador.mjs --comparar resultados/base.json`, recorrido 69 §6): los 28
  pasos dieron igual a la base después de cada paso de la tarea. La huella usa selectores
  (`.alt-pill`, `.destino-chip`, `.col-lg-8 ul.nav-tabs .nav-link`…): cambiar una clase o el anidamiento
  rompe la comparación aunque la pantalla funcione.
- En la revisión, además, se rearmó el template original poniendo cada componente en su lugar y se
  comparó con el de `main` línea por línea: idéntico salvo los `v-if` de §5 y `fila.item` → `item`.

## Ejercicios

1. Abre `ItemLienzo.vue` y busca de qué clave sale `onEditarPrecio`. Sigue esa función hasta
   `useEdicionPrecios.ts`: ¿qué pasa si el vendedor escribe mientras hay un guardado en vuelo? (recorrido 64, H13).
2. ¿Por qué `DrawerBiblioteca` no inyecta nada de mayoristas, si el comparador se ve dentro del drawer?
3. Si quitaras el `v-if="alternativaActiva"` de la raíz de `PanelPrecio`, ¿cambiaría algo en pantalla?
   ¿Y en `npm run type-check`?
