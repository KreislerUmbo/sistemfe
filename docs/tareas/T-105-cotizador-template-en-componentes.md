# T-105 — Partir el template de `cotizador/editar.vue` en componentes que reciben los composables por `provide/inject`

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-094 |
| **Reglas** | no cambia ninguna (refactor puro) |
| **Decisiones** | ninguna |
| **Rama** | `refactor/T-105-cotizador-componentes` |

## Contexto
T-094 partió el script de `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue` en 20
composables (recorrido `docs/aprendizaje/69-recorrido-cotizador-en-composables.md`, **leerlo
entero antes de empezar**: §1 reglas, §3 orden del drawer, §5 orden de los watchers). El template
(líneas 1–1489) sigue entero en la página: para tocar el comparador de mayoristas hay que abrir un
archivo de 1.849 líneas. Esta tarea lo parte en componentes **sin cambiar nada de lo que se ve ni
de cómo se comporta**. Meta: `editar.vue` ≤ 500 líneas.

## Alcance
- `composables/agencia-viajes/cotizador/claves.ts` (nuevo):
  - Una `InjectionKey` por composable, tipada con `ReturnType`:
    `export const CLAVE_PRECIOS: InjectionKey<ReturnType<typeof useEdicionPrecios>> = Symbol('precios')`.
    Claves: núcleo (`useCotizacionEditor`), precios, pasajeros, cabecera, resultado, alternativas,
    destinos, días, línea de tour, bloques, drawer, hoteles locales, biblioteca, opciones de
    mayorista, hoteles de mayorista, opcionales de mayorista, modificación, revisión, guía, ítems
    sueltos.
  - `CLAVE_PAGINA` para lo que vive en la página: `modo`, `seleccionarAlternativa`,
    `tipoMayoristaId`, `tipoHotelId`, `desglosePasajerosTexto`, `pasoDrawer`, `tituloDrawer`
    (`cargandoPagina` no: lo usa solo la página). Tipo explícito con `interface ContextoPagina`.
  - `inyectar<T>(clave: InjectionKey<T>): T`: llama a `inject` y lanza
    `Error('… usado fuera de la pantalla del cotizador')` si no hay `provide`. Con test de vitest.
- `editar.vue`: cada composable se guarda entero (`const precios = useEdicionPrecios(…)`), se
  publica con `provide(CLAVE_PRECIOS, precios)` y se desestructura **solo** lo que la página sigue
  usando (watchers, `alRefrescar`, `onMounted`, argumentos de otros composables, los dos modales
  de Promover). **El orden de creación de los composables y de los watchers no cambia.**
- Componentes nuevos en `admin-start-kit/src/components/AgenciaViajes/cotizador/` (tabla de
  «Dónde tocar»), en el orden de «Pasos».
- Mover a cada componente las reglas de `<style scoped>` que usa (ver «Trampas»).

## Fuera de alcance
- Cambiar textos, clases, estructura del DOM o comportamiento (incluido el «Elegí la habitación»
  de `pasoDrawerDe`, que está en «Por analizar»).
- Tocar los composables o helpers de T-094 (salvo que un tipo exportado haga falta para
  `claves.ts`: anotarlo en Resultado).
- Una fachada que agrupe los composables de mayoristas: se evaluó (18-nov) y se descartó por
  ganancia chica; los componentes de mayoristas inyectan las claves de siempre.
- Store de Pinia o estado dentro de los componentes.
- Los 47 errores previos de `type-check` (T-101).
- Los temporizadores sin limpiar y la lectura distinta de errores (están en «Por analizar»).

## Reglas para los componentes
1. **No llaman a composables ni crean watchers.** Todo el estado sigue naciendo en la página, en
   el mismo orden (recorrido 69 §5). Un componente que crea un watcher cambia el orden: no se hace.
2. **Reciben por `inyectar(CLAVE_…)` y desestructuran** lo que usan
   (`const { totalLocal, onEditarPrecio } = inyectar(CLAVE_PRECIOS)`): el template solo desenvuelve
   refs de primer nivel (recorrido 69 §1, regla 3).
3. **Por prop, solo el dato de un bucle** (`bloque`, `fila`, `item`, `op`). Nada de `v-model`
   sobre una prop: `v-model` solo sobre refs inyectadas.
4. **Sin `emit`**: las acciones llegan por inject.
5. Los helpers puros (`formatFecha`, `etiquetaItem`, `textoPlano`, `filasDelBloque`…) se importan
   directo en el componente que los usa, no se inyectan.
6. **Sin `div` envoltorio nuevo**: la raíz del componente es el elemento que hoy ocupa ese lugar
   (o varias raíces si el rango tiene varios hermanos). Mismas clases, atributos `data-*` y orden.

## Dónde tocar
Líneas de `editar.vue` al empezar (commit `c9666a7c`). La columna «inject» es el punto de partida
según la estructura: confirmarla leyendo el rango.

| Componente | Líneas | Prop | Inject (aprox.) |
|---|---|---|---|
| `CabeceraCotizacion.vue` (título, estado, perdida, botones, `CerrarComoPerdidaModal`, `AceptarAlternativaModal`) | 8–60 | — | núcleo, resultado, alternativas, pasajeros, cabecera |
| `AvisosModificacion.vue` (edad estimada, estado no abierta, `ModificacionDiferenciaPanel`, `PenalidadPasajeroModal`) | 61–82 | — | núcleo, modificación |
| `EdicionPasajeros.vue` | 83–121 | — | pasajeros |
| `EdicionCabecera.vue` (con el modal de cliente rápido `ClientFormQuick`) | 122–193 | — | cabecera |
| `PestanasAlternativas.vue` (`CalculadoraTipoCambioModal`, pestañas, form «+ Nueva») | 194–287 | — | núcleo, alternativas, resultado, página |
| `ChipsDestino.vue` (chips y form de destino) | 288–334 | — | núcleo, destinos |
| `LienzoCotizador.vue` (`div.col-lg-8`: barra, solo lectura, pasaje aéreo, tabs de día, vista previa combo, temporadas, avisos de vacío, `v-for` de bloques) | 337–674 | — | núcleo, días, línea de tour, bloques, biblioteca, ítems sueltos, drawer, modificación |
| ↳ `CargaTourLienzo.vue` (`template v-if="bloque.carga"` y cabecera de tour 488–499) | 432–499 | `bloque` | línea de tour, bloques, precios |
| ↳ `GrupoOpcionesLienzo.vue` (`fila.esGrupo`) | 504–543 | `fila` | bloques, ítems sueltos, modificación, pasajeros, página |
| ↳ `ItemLienzo.vue` (`v-else` de la fila) | 544–672 | `item` | precios, ítems sueltos, modificación, días, núcleo, pasajeros, página |
| `PanelPrecio.vue` (`div.col-lg-4`: resumen, descuento global, totales, botones, vigencia, revisión, `AccionesPendientes`, `HistorialActividadesCotizacion`) | 676–875 | — | núcleo, precios, bloques, pasajeros, alternativas, resultado, revisión, modificación |
| `DrawerBiblioteca.vue` (`Teleport`, encabezado, selector de modo, pasos `matrizHotel` 1401–1445, `modoPrecio` 1446–1457, `manual` 1458–1474) | 883–1474 | — | drawer, hoteles locales, ítems sueltos, días, núcleo, página |
| ↳ `BibliotecaLocal.vue` (chips, filtros, grid, paginado, hotel no registrado) | 906–1101 | — | biblioteca, hoteles locales, destinos, núcleo, página |
| ↳ `BibliotecaGuia.vue` | 1102–1145 | — | guía, núcleo |
| ↳ `ComparadorMayoristas.vue` (lista + form de nueva opción 1394–1399) | 1146–1400 | — | opciones de mayorista, núcleo, página |
| ↳↳ `OpcionMayoristaTarjeta.vue` (cabecera, botones, form de edición, tours incluidos 1369–1393) | 1147–1393 | `op` | opciones y opcionales de mayorista, núcleo, destinos, página |
| ↳↳ `HotelesOpcionMayorista.vue` | 1189–1286 | `op` | hoteles y opciones de mayorista, pasajeros, núcleo, página |
| ↳↳ `OpcionalesOpcionMayorista.vue` | 1287–1368 | `op` | opcionales de mayorista, núcleo |

Se quedan en `editar.vue`: `DefaultLayout`, spinner de carga (3–7), el «sin alternativa»
(876–881), `PromoverProveedorModal` (1475–1482) y el `Teleport` de `PromoverHotelProveedorModal`
(1483–1487). `PanelPrecio` queda entero (decisión del usuario, 18-nov).

## Pasos (un commit cada uno; Playwright `--comparar` al final de cada uno)
1. `claves.ts` + `inyectar` + test. `editar.vue` pasa a guardar los objetos y hacer `provide`;
   el template no se toca.
2. Arriba de la página: `CabeceraCotizacion`, `AvisosModificacion`, `EdicionPasajeros`,
   `EdicionCabecera`, `PestanasAlternativas`, `ChipsDestino`.
3. `PanelPrecio`.
4. `LienzoCotizador`, `CargaTourLienzo`, `GrupoOpcionesLienzo`, `ItemLienzo`.
5. `DrawerBiblioteca`, `BibliotecaLocal`, `BibliotecaGuia`.
6. `ComparadorMayoristas`, `OpcionMayoristaTarjeta`, `HotelesOpcionMayorista`,
   `OpcionalesOpcionMayorista`.
7. Limpieza: imports y estilos que ya no usa la página; medir líneas.

## Trampas
- **`<style scoped>` no llega a los hijos.** Cada regla viaja con el componente que tiene el
  elemento: `.alt-pill*` → `PestanasAlternativas`; `.destino-chip*` → `ChipsDestino`;
  `.price-panel` → `PanelPrecio`; `.canvas-item-nombre` → donde esté ese elemento (ítem y/o grupo);
  `.resaltado-item` (lo devuelve `claseResaltado`) → copiada en `ItemLienzo` y
  `GrupoOpcionesLienzo`; `.lib-item` y `.biblioteca-grid` → `BibliotecaLocal` (y `BibliotecaGuia`
  si las usa). Buscar cada clase con grep antes de mover.
- **La huella de Playwright usa selectores**: `.alt-pill`, `.destino-chip`,
  `.col-lg-8 ul.nav-tabs .nav-link`, el panel de precio, el `h6` «Diferencia con lo vendido» y
  `.alert` visibles (`admin-start-kit/e2e/recorrido-cotizador.mjs`, función `huella`, ~línea 70).
  Cambiar una clase o el anidamiento rompe la comparación aunque la pantalla funcione.
- **`irAItem`** (`useModificacionEnCotizador.ts` ~línea 69) busca `[data-item-id~="…"]` con
  `document.querySelector`: los atributos `data-item-id` del lienzo se conservan tal cual.
- **Template refs en un hijo**: `ref="accionesRef"` y `ref="historialRef"` pasan a `PanelPrecio`
  atados a refs inyectadas y desestructuradas. `proximaAccion` (computed sobre `accionesRef`) y
  `onResultadoCambiado` (que llama a `recargar()` de los dos) tienen que seguir funcionando:
  comprobarlo a mano (CA5).
- **`modificacion`** se usa sin desestructurar (`modificacion.diferencia.value`): en el componente
  se conserva igual.
- **Teleport**: el drawer sigue en `Teleport to="body"` dentro de `DrawerBiblioteca`.
- **`type-check`** arranca con 47 errores (T-101): contar antes y después; ninguno nuevo.

## Criterios de aceptación
- [ ] **CA1** — Recorrido de Playwright igual a la base en los 28 pasos, después de cada paso de
  la tarea → `node recorrido-cotizador.mjs --comparar resultados/base.json` sin diferencias
  (grabar la base desde `main` antes del primer commit si la de T-094 ya no sirve).
- [ ] **CA2** — `editar.vue` ≤ 500 líneas → `wc -l`.
- [ ] **CA3** — Ningún componente nuevo llama a `use…` del cotizador, `watch`, `watchEffect`,
  `defineEmits` ni `useRoute`/`useRouter` → grep sobre `components/AgenciaViajes/cotizador/`.
- [ ] **CA4** — `inyectar` lanza el error si falta el `provide` y devuelve el objeto si está →
  test `claves.test.ts`.
- [ ] **CA5** — Cambiar el resultado de la cotización (perdida → reabrir) recarga historial y
  acciones pendientes, y la próxima acción se ve en la cabecera → paso manual en agencia-demo.
- [ ] **CA6** — El resaltado de ítems para revisar en una modificación de reserva se ve (borde
  amarillo) y «ir al ítem» hace scroll → paso manual con una reserva en modificación.
- [ ] **CA7** — vitest en verde (305 + los nuevos); `type-check` con los mismos 47 errores;
  `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin backend).
- [ ] Aislamiento de tenant: no aplica.
- [ ] Validación de entrada / archivos: no aplica.
- [ ] Concurrencia (`lockForUpdate`): no aplica.
- [ ] Dinero en centavos: no aplica (no se toca cálculo).
- [ ] Fechas con `HoraPeru`: no aplica.
- [ ] Tributario sin fallback silencioso: no aplica (el badge tributario solo se mueve).
- [ ] Sin `v-html` sin sanitizar: si el rango movido tiene `v-html`, se mueve igual, sin agregar otros.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica.

## Cómo verificar
Desde `C:/` en mayúscula (vitest falla con `c:` minúscula):
```
cd C:/xampp/htdocs/sistemfe/admin-start-kit
npm run test
npm run type-check 2>&1 | grep -c "error TS"
npm run build
wc -l src/views/agencia-viajes/cotizador/editar.vue
grep -rnE "use[A-Z]\w*\(|watch(Effect)?\(|defineEmits|useRout" src/components/AgenciaViajes/cotizador/
cd e2e
RECORRIDO_USUARIO=… RECORRIDO_CLAVE=… node recorrido-cotizador.mjs --comparar resultados/base.json
```
Tenant `agencia-demo` (`http://agencia-demo.sistemafe.test:5173`), backend en Apache. CA5 y CA6 a
mano en la misma pantalla. **Al desplegar:** nada (solo frontend).

---

## Resultado
<!-- Lo completa quien construye, al terminar. -->
- **Commits** (rama `refactor/T-105-cotizador-componentes`, uno por paso): `21381460` claves e
  `inyectar` · `eb2bc2b0` cabecera, avisos, pasajeros, alternativas y destinos · `c55dcf65`
  `PanelPrecio` · `293d5415` lienzo (`LienzoCotizador`, `CargaTourLienzo`, `GrupoOpcionesLienzo`,
  `ItemLienzo`) · `b14c6bfa` drawer (`DrawerBiblioteca`, `BibliotecaLocal`, `BibliotecaGuia`) ·
  `815df6c2` mayoristas (`ComparadorMayoristas`, `OpcionMayoristaTarjeta`,
  `HotelesOpcionMayorista`, `OpcionalesOpcionMayorista`) · `0808b89a` limpieza · un commit de
  la revisión: `DrawerBiblioteca` dejaba de usar, desde el paso 6, las inyecciones de mayoristas
  (opciones, hoteles, opcionales), `cantidadAdultosCotizacion`, `destinoAtractivoIdActivo`,
  `tipoHotelId`/`tipoMayoristaId` y 5 imports; se quitaron (vitest, `type-check` y `build` iguales).
- **Tests:**
  - CA1: Playwright `--comparar resultados/base.json` igual a la base en los 28 pasos después de
    cada paso (la base de T-094 sirvió: corrida sobre `main` igual). En el paso 5 una corrida
    falló entera por el 500 intermitente de `/api/proveedor-tipos` al montar; repetida sin
    cambios, igual. Huellas en `e2e/resultados/t105-*.json`.
  - CA2: `editar.vue` 1.849 → **317 líneas**.
  - CA3: el grep sobre `components/AgenciaViajes/cotizador/` no encuentra nada.
  - CA4: `claves.test.ts` (2 tests: devuelve el objeto; lanza «pagina usado fuera de la pantalla
    del cotizador»).
  - CA5 (script de Playwright sobre una cotización de prueba, borrada al final): «Agendar acción»
    en el panel → la cabecera muestra «Próxima acción: llamar el vie 20/11 (Admin)»; cerrar como
    perdida → historial y acciones se recargan (el backend cierra la acción pendiente, el panel
    pasa a 0 y la cabecera la deja de mostrar); reabrir → el historial suma «Cotización
    reabierta». Sin errores de consola.
  - CA6: la única modificación abierta de agencia-demo (#89) no tiene ítems para revisar. Se
    simuló **solo en el navegador** un bloqueo `para_revisar` sobre el ítem 520: borde amarillo
    en el lienzo, «Ver en el lienzo» del panel hace scroll (ítem centrado), aplica
    `.resaltado-item` (sombra amarilla, regla `scoped` del componente) y la quita a los 3 s.
    También se llamó a `irAItem` del objeto publicado con `provide`. Sin errores de consola.
  - CA7: vitest 307/307 (305 + 2); `type-check` 47 errores, los mismos de T-101, ninguno en
    archivos del cotizador; `build` sin errores.
- **Desviaciones de la tarea y por qué:**
  - **`v-if="alternativaActiva"` repetido en la raíz** de `PanelPrecio`, `LienzoCotizador`,
    `ItemLienzo` y `GrupoOpcionesLienzo` (y `<template v-if>` envolviendo las dos raíces de
    `CargaTourLienzo`). Dentro de un componente se pierde el estrechamiento de tipo del `v-if` del
    `div.row` de la página (`type-check` subía a 63). No cambia el DOM. Decisión del usuario.
  - **`ItemLienzo` recibe `bloque` además de `item`**: el selector de día lee
    `bloque.tourOrigenId`. Es dato del bucle (regla 3) y el template queda igual.
  - **Las cadenas `v-if`/`v-else` y los `v-for` quedaron en los tags**: `GrupoOpcionesLienzo
    v-if` / `ItemLienzo v-else`; `BibliotecaLocal v-if` / `BibliotecaGuia v-else-if` /
    `ComparadorMayoristas v-else` (su `<template v-if>` envoltorio se descartó dentro del
    componente); `OpcionMayoristaTarjeta v-for`. Mismo DOM.
  - **`.resaltado-item` también en `LienzoCotizador`**: además del ítem y del grupo, la usa el
    `div` de cada bloque de tour (`claseResaltado` sobre la carga). `.canvas-item-nombre` solo
    está en `ItemLienzo` (el grupo no la usa).
  - Rangos reales distintos de la tabla: `PanelPrecio` 675–873 (874 cierra el `div.row`, que se
    queda en la página); el `div v-else` del ítem empezaba en 229.
  - En la página, los objetos se llaman `opcionesDeMayorista` (choca con la ref
    `opcionesMayorista`), `destinosAlternativa` y `modificacionCotizador` (`modificacion` es lo
    que devuelve el composable).
  - Los componentes que usan a otros del cotizador los importan explícitos, aunque
    `unplugin-vue-components` ya los resuelve solo; `components.d.ts` lo regenera ese plugin y va
    commiteado.
  - No se tocó ningún composable ni helper: `claves.ts` solo necesitó `PasoDrawer`, que ya se
    exportaba.

## Hallazgos fuera de alcance
<!-- Problemas vistos y NO arreglados. Se convierten en tareas o en "Por analizar". -->
- agencia-demo no tiene ninguna modificación de reserva con ítems «para revisar»: CA6 tuvo que
  simularse en el navegador y el recorrido de Playwright no cubre ese resaltado. Un dato de prueba
  fijo (o un paso del recorrido que lo cree) lo dejaría comprobable.
- `admin-start-kit` no tiene configuración de ESLint para la versión instalada (ESLint 10 pide
  `eslint.config.*`): `npx eslint` no corre.
- `unplugin-vue-components` resuelve cualquier componente de `src/components` sin import, así que
  un import faltante no se nota en `type-check` ni en `build`.
