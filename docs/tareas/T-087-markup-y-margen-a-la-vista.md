# T-087 — Markup, margen y ganancia a la vista, con un solo mínimo (parte sin IGV)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/agencia-precio-y-margen.md` → P4 (parte que no espera al contador) y pregunta 1 (se escribe el markup y se muestran los dos) |
| **Rama** | `feat/T-087-markup-y-margen` |

## Contexto
Lo que el sistema llama "margen" es **markup**, es decir, (venta − costo) ÷ costo. Con costo 100 y
venta 125 muestra "25 %", pero el margen sobre la venta es 20 %. Además hay **dos mínimos
distintos**: la tarifa de proveedor pinta rojo con un 20 % fijo en el código, y el tour usa el
mínimo de Configuración. Esta tarea pone los nombres correctos, muestra siempre los tres números
y usa un solo mínimo. Restar el IGV queda para después, porque espera al contador (P4b del
requerimiento).

## Alcance
- **Un helper puro** `admin-start-kit/src/utils/margenes.ts`:
  - `markupPct(costo, precio)` = (precio − costo) ÷ costo × 100;
  - `margenPct(costo, precio)` = (precio − costo) ÷ precio × 100;
  - `ganancia(costo, precio)` = precio − costo.
  - Devuelve `null` si el divisor es 0 o falta un dato. Redondeo solo al mostrar.
  - Tests en Vitest.
- **Un solo componente para mostrarlo:** ampliar `MargenEnVivo.vue` para que muestre
  `Markup X % · Margen Y % · Ganancia S/ Z`. El aviso de "debajo del mínimo" sigue comparando contra
  el **markup**, para no cambiar el sentido de los mínimos ya configurados.
- **Usarlo en todos los lugares que hoy calculan su propio "margen":**
  - tarifa de proveedor: modal y badge de la tabla (`proveedores/detalle.vue`, `calcularMargenPct`,
    `margenModalPct`, `margenTarifa`);
  - tour: `TourPrecioVentaCampos.vue`, `TourIncluyeCostos.vue`, `paquetes/detalle.vue:122`;
  - vista previa del combo: `paquetes/detalle.vue:315` y `:733` (`preview.margenResultante`);
    revisar de dónde sale ese número y mostrar los tres;
  - `PromoverProveedorModal.vue:61`.
- **Nombres:**
  - "Margen tipo" / "Margen valor" → "Markup (tipo)" / "Markup (valor)" en la tarifa de proveedor
    y en la tarifa de guía (`guias/detalle.vue:39`);
  - "Margen automático (mayorista)" → "Markup automático (mayorista)" (`proveedores/form.vue:142`);
  - "Margen mínimo aceptable (%)" → "Markup mínimo aceptable (%)" en Configuración, con la ayuda
    corregida (`configuracion/index.vue:112-114`);
  - "Margen mínimo protegido (%)" del combo y del tour → "Markup mínimo protegido (%)".
- **Un solo mínimo:**
  - quitar `MARGEN_MINIMO_ACEPTABLE_PCT = 20` de `proveedores/detalle.vue:434` y usar
    `configAgencia.margen_minimo_aceptable_pct`, que la pantalla ya carga;
  - si es `null`, no se colorea (mismo criterio que `MargenEnVivo`: sin piso no hay rojo).

## Fuera de alcance
- **Restar el IGV** al calcular la ganancia (P4b del requerimiento): espera la pregunta del
  contador sobre el costo con o sin IGV.
- Cambiar la fórmula del backend (`PriceEngineService` sigue aplicando markup, que está bien) o
  los nombres de las columnas (`margen_tipo`, `margen_valor`, `margen_minimo_pct`): solo cambian las
  etiquetas.
- Markup por defecto por tipo de servicio (P5), tarifa comisionable (P6), redondeo (P7).
- El cotizador hoy no muestra margen por línea: si hace falta, va como hallazgo.

## Dónde tocar
- `admin-start-kit/src/utils/margenes.ts` (nuevo) + `margenes.test.ts`
- `admin-start-kit/src/components/AgenciaViajes/MargenEnVivo.vue` (~líneas 12-43)
- `admin-start-kit/src/views/agencia-viajes/proveedores/detalle.vue` → bloque de margen del modal (~286-306), badge de la tabla (~132-148), funciones (~433-442, 545-575)
- `admin-start-kit/src/components/AgenciaViajes/TourPrecioVentaCampos.vue` (~59-111)
- `admin-start-kit/src/components/AgenciaViajes/TourIncluyeCostos.vue` (~142-157)
- `admin-start-kit/src/views/agencia-viajes/paquetes/detalle.vue` (~122, ~305-320, ~733)
- `admin-start-kit/src/views/agencia-viajes/paquetes/form.vue` (~143-162)
- `admin-start-kit/src/components/AgenciaViajes/PromoverProveedorModal.vue` (~61, ~127)
- `admin-start-kit/src/views/agencia-viajes/guias/detalle.vue` (~39-45)
- `admin-start-kit/src/views/agencia-viajes/proveedores/form.vue` (~142-156)
- `admin-start-kit/src/views/agencia-viajes/configuracion/index.vue` (~112-114)

## Criterios de aceptación
- [ ] **CA1** — Helper: costo 100, precio 125 → markup 25, margen 20, ganancia 25. Costo 0 o
  precio 0 → `null` en el porcentaje que corresponda. → `margenes.test.ts`
- [ ] **CA2** — Tarifa de proveedor con costo 100 y venta 125: el modal y la tabla muestran
  "Markup 25.0 % · Margen 20.0 % · Ganancia S/ 25.00". → prueba en pantalla
- [ ] **CA3** — Configuración con mínimo 30: esa tarifa (markup 25) sale en rojo. Con mínimo 20,
  en verde. Con el mínimo vacío, sin color. Ya no existe el 20 fijo. → prueba en pantalla
- [ ] **CA4** — Tour, combo y "Promover proveedor" muestran los mismos tres números con el mismo
  componente. → prueba en pantalla
- [ ] **CA5** — No queda ninguna etiqueta "Margen tipo", "Margen valor", "Margen automático" ni
  "Margen mínimo" que en realidad sea markup. → `grep -rn "Margen" admin-start-kit/src/views/agencia-viajes admin-start-kit/src/components/AgenciaViajes`
- [ ] **CA6** — `type-check` sin errores nuevos y `build` en verde.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas nuevas)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: el helper solo muestra; redondeo en un solo punto (al formatear)
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica (el IGV queda fuera)
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
cd admin-start-kit && npx vitest run src/utils/margenes.test.ts && npm run type-check && npm run build
```
Prueba funcional en `http://agencia-demo.sistemafe.test:5173`: Proveedores → una tarifa (costo
100, venta 125); Configuración → mínimo 30 / 20 / vacío; un tour con costo; un combo.

---

## Resultado
- **Commits:** rama `feat/T-087-markup-y-margen` (1 commit, ver `git log`).
- **Tests:** `margenes.test.ts` 7/7 en verde; `build` en verde; `type-check` sin errores nuevos
  en los archivos tocados (los que quedan ya existían: portal, fake-backend, `paquetes/form.vue:228`,
  `TourIncluidoForm.vue`). **No hecha** la prueba en pantalla (CA2-CA4) ni `/code-review`.
- **Desviaciones de la tarea y por qué:**
  - `MargenEnVivo` ganó props opcionales `etiqueta` y `detalle` para usarlo también en la tabla y
    los modales (sin la línea "precio − costo").
  - En `PromoverProveedorModal` no hay piso (el modal no carga la configuración): solo se pinta
    rojo si la ganancia es negativa.
  - En el combo se quitó `preview.margenResultante` y `margenOk` (reemplazados por el componente);
    el piso es el mínimo del combo o, si no tiene, el de la agencia.
  - **CA3, color:** debajo del mínimo la tarifa sale **amarilla** (`MargenEnVivo`: rojo = pérdida,
    amarillo = bajo el mínimo, mismo criterio que ya tenía el tour), no roja como decía CA3.
  - Revisión (Opus, 06-nov): `MargenEnVivo` ganó `verMinimo` (la tabla de tarifas no repite
    "· markup mínimo X %" en cada fila: queda el ícono con el dato en el `title`) y `origenMinimo`
    (el combo dice "del combo", no "del tour"). Se quitó de `paquetes/detalle.vue` la carga de
    `configAgencia` y `margenMinimoAceptablePct`, que ya no tenían uso (el mínimo llega en
    `show()`). Texto de `paquetes/form.vue:146` pasado a "markup mínimo protegido".
  - En el combo sin mínimo propio, el aviso usa el de la agencia, pero el backend
    (`ComboValidationService::validarMargenMinimo`) solo bloquea con el del combo: es aviso, no bloqueo.

## Hallazgos fuera de alcance
- Costo 0 en la tarifa: antes mostraba "0 %", ahora el markup sale "—" (no se puede dividir) y el
  margen 100 %.
