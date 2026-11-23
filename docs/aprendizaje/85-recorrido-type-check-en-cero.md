# Recorrido: `npm run type-check` en 0 errores

23-nov-2026, tarea **T-101**. Desde el 10-nov el frontend tenía 47 errores de TypeScript (`vue-tsc`), así que
«type-check y build sin errores» de la Definición de Hecho (`docs/proceso.md` §3) no se podía cumplir.

**Al desplegar:** solo frontend (`npm run build`). En dev, si Vite pide `/src/stores/ui.js` (el nombre
viejo), recargar la pestaña o reiniciar `npm run dev`.

---

## 1. Qué es el type-check y por qué importa

`npm run type-check` corre `vue-tsc --build --force`: revisa los tipos de todos los `.ts` y `.vue` **sin**
ejecutar nada. `npm run build` corre `vue-tsc` antes de `vite build`, así que con un solo error el build falla.
Con 47 errores viejos, un error nuevo se perdía entre los demás.

## 2. Las cuatro clases de error que había

| Clase | Ejemplo | Arreglo |
|---|---|---|
| Lista sin tipo (`ref([])` = `never[]`) | `recursos/manual.vue`: `recurso.titulo` «no existe en never» | `ref<RecursoListado[]>([])` (tipo nuevo en `recursoService.ts`) |
| Valor que puede faltar | `company/index.vue`: `company_selected.value.ubigeo_provincia` con `company_selected` opcional | `?.` y un valor por defecto (`?? ''`) |
| Tipo que no dice lo que llega | `FileReader.result` es `string \| ArrayBuffer`, pero `readAsDataURL` siempre da texto | `ref<string \| null>` y `reader.result as string` |
| **Código que de verdad está roto** | `Favoritos.vue` usa `favoritosStore`, que estaba comentado | arreglar la pantalla (sección 3) |

La cuarta clase es la que importa: el type-check encontró pantallas que fallaban y nadie lo sabía.

## 3. Pantallas que estaban rotas (decididas con el usuario)

| Pantalla | Qué pasaba | Qué se hizo |
|---|---|---|
| Recursos → Nuevo recurso | El selector «Sistema» no tenía lista ni campo; el backend exige `sistema_id` → **422** al guardar. El tipo se mandaba como `tipo` y la columna es `tipo_recurso`. Al terminar iba a `/recursos` (404) | Carga `GET systems` (todas las páginas), manda `tipo_recurso`, vuelve a `recursos.index` |
| Portal → Favoritos | `<script>` comentado: la página fallaba al abrir | Store en `stores/favoritos.ts` (antes un archivo **sin extensión**) y script de vuelta |
| Portal → Sistemas (anual) | «Facturado S/ undefined al año» | `precioAnual × 12` (79 × 12 = 948) |
| Portal → Mi cuenta → Mis direcciones | Ruta sin componente | `redirect` al panel de Mi cuenta |

Lo que quedó a medias (listado de recursos, favoritos que nadie agrega, cupón de solo pantalla, URL de imágenes
del portal, lápiz del tour ad-hoc) está en «Por analizar» del backlog.

## 4. Dos técnicas que conviene conocer

**`Pick` para lo que manda la API.** `Sale.client` (`types/sales.ts`) es `Pick<Client, 'id' | 'full_name' | …>`:
dice exactamente qué campos manda `SaleResource`, sin inventar los que no llegan.

**`Omit` para un formulario.** En `paquetes/form.vue`, `destino_atractivo_id` es `number` en el tour guardado,
pero en el formulario arranca vacío. `Omit<Partial<PaquetePlantilla>, 'destino_atractivo_id'> & { destino_atractivo_id: number | null }`
lo dice sin aflojar el tipo del modelo.

## 5. Cómo se probó

`npm run type-check` → 0 errores; `npm run build` OK; Vitest 357 tests; `e2e/verif-t101-pantallas.mjs`
(agencia-demo, el POST del recurso se intercepta): 6 sistemas en el selector, el POST lleva `sistema_id` y
`tipo_recurso`, Favoritos carga y Sistemas dice «Facturado S/ 948 al año», sin errores de JavaScript.

## 6. Ejercicios

1. Cambia `ref<RecursoListado[]>([])` por `ref([])` en `manual.vue` y corre `npm run type-check`. ¿Qué tipo
   infiere TypeScript y por qué da error en la plantilla?
2. ¿Por qué `filter(Boolean)` no alcanza para pasar de `(string | undefined)[]` a `string[]`, y
   `filter((c): c is string => !!c)` sí?
3. Busca en `routes.ts` otra ruta del portal y explica qué pasaría si le quitaras `component` sin poner
   `redirect` ni `children`.
