# Recorrido: el cotizador partido en composables

18-nov-2026, tarea **T-094** (refactor puro: la pantalla hace lo mismo que antes). Recorridos previos que
este supone: 64 (actualización parcial sin recargas, H13).

Archivos:
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`: template sin tocar; el script bajó de
  ~3.430 a 323 líneas.
- `admin-start-kit/src/composables/agencia-viajes/cotizador/` (20 composables y 4 tests).
- `admin-start-kit/src/helpers/agencia-viajes/cotizador/` (`totales`, `bloquesLienzo`, `presentacionItem`,
  `grupoOpcion`, cada uno con su test) y `src/helpers/descargarArchivo.ts` (con test).
- `admin-start-kit/e2e/recorrido-cotizador.mjs`: recorrido de Playwright que compara la pantalla antes y
  después de un cambio.

**Al desplegar:** nada (solo frontend, sin cambios de API).

---

## 1. La idea

Antes, todo el estado del cotizador vivía en un solo `<script setup>` de ~3.430 líneas: para tocar los
precios había que leer también los mayoristas, las guías y la modificación de reserva. Además, el núcleo
llamaba a mano a cada bloque después de recargar, así que no se podía separar nada.

Ahora cada bloque es un composable con sus dependencias **por parámetro**, y la página solo los conecta:

```
editar.vue (script)
  ├─ useCotizacionEditor(cotizacionId)        ← núcleo: cotización, alternativa activa, refrescos
  ├─ useEdicionPrecios(núcleo)                ← precios, descuentos, piso, totales
  ├─ pasajeros · cabecera · resultado · alternativas
  ├─ lienzo: destinos → días → línea de tour → bloques
  ├─ useDrawerBiblioteca → hoteles locales → biblioteca
  ├─ mayoristas: opciones → hoteles → opcionales
  ├─ modificación de reserva → revisión antes de enviar
  ├─ guía · ítems sueltos
  ├─ watchers que cruzan bloques
  ├─ alRefrescar(...)                         ← qué se reinicia tras recargar, en orden
  └─ onMounted / onUnmounted
```

Tres reglas sostienen esto:
1. **Ningún composable importa a otro del cotizador** (la única excepción es `useModificacionReserva`, de la
   reserva). Lo que un bloque necesita de otro se lo pasa la página.
2. **El núcleo no llama a los bloques: avisa** (§2).
3. **La página desestructura** lo que devuelve cada composable (`const { totalLocal, … } = useEdicionPrecios(…)`).
   El template solo desenvuelve refs de primer nivel: si escribiera `precios.totalLocal`, vería el objeto
   ref y no el número.

## 2. El núcleo y `alRefrescar`: `useCotizacionEditor.ts`

Tiene `cotizacion`, `alternativaActivaId`/`alternativaActiva`, `itemsSinAjuste`/`itemsAjuste`,
`configAgencia`, `modificacionInfo`, `alternativaSoloLectura` y `versionCotizacion`, más tres maneras de
actualizar:

| Función | Cuándo | Aviso |
|---|---|---|
| `cargarCotizacion()` | pide la cotización entera (red de seguridad) | `'completo'` |
| `aplicarItemActualizado(item, alt?)` | el backend devolvió el ítem que cambió (recorrido 64) | `'parcial'` |
| `aplicarAlternativaActualizada(alt)` | el backend devolvió la alternativa (con o sin `items`) | `'parcial'` |

Las dos parciales devuelven `false` si no pudieron aplicar (respuesta incompleta, alternativa que ya no
está); entonces la acción llama a `cargarCotizacion()`.

Después de cada actualización, el núcleo recorre la lista de quienes se registraron con `alRefrescar(fn)`.
La página registra **una sola** función, al final del script, y ahí se ve el orden completo:

```ts
alRefrescar((tipo) => {
    inicializarEdicionItems();                 // precios
    if (tipo === 'completo') {
        resolverDestinoActivo();               // destinos
        cargarTemporadasTours();               // línea de tour
    }
    inicializarDias();                         // días
    if (modificacionInfo.value?.estado === 'abierta') void modificacion.cargar();
});
```

**Si agregas un bloque que debe reiniciarse al recargar**, va en esta función, no dentro del núcleo.

## 3. El drawer de la biblioteca: `useDrawerBiblioteca.ts`

Es un segundo ciclo con el mismo patrón. Volver al grid del drawer reinicia estado de otros bloques (la
matriz de hotel y el modo de precio de los hoteles, el formulario del ítem manual). El drawer no los
conoce: cada bloque registra su reinicio con `alVolverAGrid(fn)`.

Por eso el orden de creación importa: **drawer → hoteles locales → biblioteca**. Los hoteles registran su
reinicio en el drawer, y la biblioteca recibe de los hoteles `clicBibliotecaItem`. `pasoDrawerDe()` (paso y
título del drawer) se crea al final, después de `useItemsSueltos`, porque necesita estado de los hoteles y
del ítem manual.

`onServicioSueltoAgregado` también vive acá: vuelve al grid, avisa y aplica el ítem. La usan los hoteles,
los mayoristas, la guía y los ítems sueltos.

## 4. Dónde está cada cosa

| Quiero cambiar… | Archivo |
|---|---|
| Totales de vista previa, conversión de moneda | `helpers/.../totales.ts` (replica `AlternativaItem::calcularTotalEfectivo()`) |
| Agrupar el lienzo por tour, filas de un bloque | `helpers/.../bloquesLienzo.ts` |
| Ícono, etiqueta, badge tributario o desglose de pasajeros de un ítem | `helpers/.../presentacionItem.ts` |
| Nombre de archivo de una descarga | `helpers/descargarArchivo.ts` |
| Editar precio o descuento (debounce, no pisar lo que se escribe) | `useEdicionPrecios.ts` |
| Chips de destino y su formulario | `useDestinosAlternativa.ts` |
| Días y sus tabs | `useDiasLienzo.ts` |
| Cargas de tour, temporadas desactualizadas | `useLineaTour.ts` |
| Mover, reasignar o eliminar bloques del lienzo; panel de precio | `useBloquesLienzo.ts` |
| Chips, filtros y búsqueda de la biblioteca | `useBibliotecaCotizador.ts` |
| Hotel Local/Nacional: matriz, modo de precio, hotel no registrado | `useHotelesLocales.ts` |
| Comparador de mayoristas | `useOpcionesMayorista.ts`, `useHotelesMayorista.ts`, `useOpcionalesMayorista.ts` |
| Guía como ítem | `useGuiaCotizador.ts` |
| Ítem manual, pasaje aéreo, promover proveedor, eliminar | `useItemsSueltos.ts` |
| Cliente, destino y fechas de la cabecera | `useCabeceraCotizacion.ts` |
| Pasajeros | `usePasajerosCotizacion.ts` |
| Alternativas: nueva, moneda, duplicar, PDF, aceptar, eliminar | `useAlternativas.ts` |
| Perdida/reabrir, vigencia, envío | `useResultadoCotizacion.ts` |
| Modificación de reserva: penalidad, aplicar, descartar | `useModificacionEnCotizador.ts` |
| Revisión antes de enviar (T-022) | `useRevisionAlternativa.ts` |

La navegación llega por parámetro (`navegar: (ruta) => router.push(ruta)`): ningún composable llama a
`useRoute()`/`useRouter()`. Así se pueden probar sin montar la pantalla.

## 5. El orden de los watchers

Vue ejecuta los watchers en el orden en que se crearon. Los de un solo bloque viajan con su composable
(los tres del formulario «+ Nueva» en `useAlternativas`, el de `configAgencia` en `useGuiaCotizador`, el
de apertura del drawer en la biblioteca). Los que cruzan bloques se quedan en la página, al final:
`alternativaDestinos` → `modo` → `destinoActivoId` → `alternativaActivaId`.

**Si mueves un composable de lugar en la página**, revisa si crea watchers: cambiarlo de lugar cambia su
orden. Por eso `useModificacionEnCotizador` y `useRevisionAlternativa` se pudieron crear más abajo que
donde estaba su código: no crean ninguno.

## 6. Cómo se prueba

- **vitest** (`npm run test`): los helpers puros; el núcleo (orden de los refrescos, actualización parcial
  con y sin `items`, alternativa que ya no está → `false`); los precios (caso H13: no pisar un ítem que se
  está editando); el lienzo y el drawer. Los servicios se simulan con `vi.mock` y los watchers se prueban
  dentro de `effectScope()`, sin `@vue/test-utils`.
- **Playwright** (`e2e/recorrido-cotizador.mjs`): crea una cotización en agencia-demo, recorre 28 pasos
  (biblioteca, precios, días, destinos, mayoristas, guía, pasaje aéreo, pasajeros, cabecera, PDF y
  modificación de reserva) y guarda una huella por paso. Con `--comparar resultados/base.json` dice qué
  cambió. Las credenciales van por `RECORRIDO_USUARIO`/`RECORRIDO_CLAVE`, nunca en el repo.

```
cd admin-start-kit/e2e
RECORRIDO_USUARIO=… RECORRIDO_CLAVE=… node recorrido-cotizador.mjs --comparar resultados/base.json
```

## 7. Lo que sigue

El template (1.490 líneas) sigue entero en `editar.vue`. Partirlo en componentes es **T-105**: van a
recibir el núcleo por `provide/inject` con una `InjectionKey` tipada, y se verifican con el mismo
recorrido de Playwright.
