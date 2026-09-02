# Brief para Claude Code — Sesión M3: hotel ad-hoc local + tributario

> Referencia: `plan-matriz-hoteles-cotizador.md` Ronda 4 (P11-P12) y
> Ronda 6 (P16), `plan-ejecucion-matriz-hoteles-cotizador.md` fila M3.
> Depende de M1 (`origin/main` `1861890`), recomendado sobre M2
> (`origin/main` `9b1f835`) pero no estructural. **Backend puro, sin
> UI todavía** — el selector tributario en `HabitacionMatrixPicker.vue`
> y el atajo "+ Agregar hotel no registrado" en la pestaña Local son
> M4. Para probar esta sesión, llamar los endpoints nuevos directo
> (tests/Tinker), no hace falta pantalla.

---

## 0. Contexto (ya investigado, no repetir)

`OpcionHotel.opcion_mayorista_id` ya es nullable a nivel de schema (y
`paquete_plantilla_id` ya se dropeó en la consolidación de hoteles) —
**no hace falta migración para permitir un `OpcionHotel` standalone**,
el schema ya lo soporta. Confirmado leyendo las migraciones reales
antes de escribir este brief.

Por diseño (Ronda 4, ya cerrado): un `OpcionHotel` ad-hoc **no necesita
FK propia hacia la alternativa** — nace suelto, se consume de inmediato
vía `alternativa_items.opcion_hotel_tarifa_id` al agregarlo, mismo
criterio que cualquier `ProveedorTarifa` real (standalone, referenciado
solo hacia adelante).

## 1. Migración — tributario en `opciones_hotel_tarifas` (Ronda 6/P16)

```php
Schema::table('opciones_hotel_tarifas', function (Blueprint $table) {
    $table->string('tip_afe_igv', 2)->nullable()->after('precio_venta_cama_adicional');
    $table->string('destino_tributario')->nullable()->after('tip_afe_igv');
});
```

Aplica a las 3 formas de uso de esta tabla (mayorista, standalone
nuevo — paquete_plantilla ya no existe). Reusar
`AlternativaItemController::resolverTratamientoTributario()` (ya
existe, privado) para prellenar con el default de agencia cuando no
venga explícito — mismo patrón "snapshot en creación" que
`costo_snapshot`.

## 2. Endpoint nuevo — alta de hotel ad-hoc standalone

Controller nuevo: `App\Http\Controllers\AgenciaViajes\OpcionHotelController`.
`POST opciones-hotel` (sin `{id}` de owner — es standalone):

- Payload: mismo shape que `OpcionMayoristaController::hoteles()`
  (`nombre_hotel`, `categoria_estrellas`, `proveedor_id` nullable,
  `moneda`, `tarifas[]` con `tipo_habitacion`/`precio_costo`/
  `precio_venta`/`tip_afe_igv`/`destino_tributario` opcionales por
  fila).
- Crea `OpcionHotel` con `opcion_mayorista_id=null` + sus
  `OpcionHotelTarifa` (tributario resuelto con
  `resolverTratamientoTributario()` por fila si no vino explícito).
- Devuelve el `OpcionHotel` con `opcionesHotelTarifas` cargadas —
  mismo shape que ya consume `HabitacionMatrixPicker.vue` (M4 lo
  cablea a la pestaña Local, esta sesión no toca el componente).

## 3. Consumir el hotel ad-hoc como `AlternativaItem`

Extender `AlternativaItemController::crearItemProveedor()` (NO crear
un método paralelo — es el mismo `origen_tipo=proveedor` de siempre,
solo un tercer origen de precio además de `proveedor_tarifa_id` y el
"precio de referencia" manual ya existentes):

- Nuevo campo opcional `opcion_hotel_tarifa_id` (`nullable|integer|
  exists:opciones_hotel_tarifas,id`).
- Si viene: deriva `costo_snapshot`/`precio_venta_snapshot`/
  `moneda_costo` de esa tarifa (mismo patrón que
  `crearItemMayorista()`), tratamiento tributario de la tarifa misma
  (ya resuelto en el punto 2, no default de agencia de nuevo), setea
  `opcion_hotel_tarifa_id` en el `AlternativaItem` creado — **nunca**
  `proveedor_tarifa_id` (mutuamente excluyentes, igual que la
  validación ya existente `required_without:proveedor_tarifa_id`
  extendida a tres vías, no dos).
- Sin `opcion_hotel_tarifa_id` ni `proveedor_tarifa_id`: sigue el
  camino "precio de referencia" ya existente, sin cambios.

## 4. Promover matriz completa a Proveedor (Ronda 4/P12)

`POST opciones-hotel/{id}/promover` — **no reusa**
`AlternativaItemController::promoverAProveedor()` tal cual (esa
promueve una sola línea desde un ítem manual); función nueva en
`OpcionHotelController`:

- Payload: `destino_servicio_id` (required, mismo criterio que
  `promoverAProveedor()` — de ahí sale `tipo_proveedor_id`, validado
  habilitado igual que el existente), `razon_social`,
  `tipo_documento`/`numero_documento` opcionales.
- Crea 1 `Proveedor` + 1 `ProveedorServicio` + **N `ProveedorTarifa`**
  (una por cada `OpcionHotelTarifa` del hotel — `tipo_tarifa='publica'`,
  `modalidad='privado'` default para hotel, tributario copiado de cada
  tarifa).
- **Sin relink retroactivo**: los `AlternativaItem`/`OpcionHotelTarifa`
  ya existentes siguen exactamente igual — el `Proveedor` nuevo queda
  disponible recién para la próxima cotización. Mismo criterio ya
  usado por la promoción de ítem manual.
- Bloquea con 422 si el hotel ya fue promovido antes (agregar
  `proveedor_promovido_id` a `opciones_hotel`, mismo patrón que
  `alternativa_items.proveedor_promovido_id`).

## 5. Explícitamente fuera de alcance (M4)

- `HabitacionMatrixPicker.vue`: selector tributario por fila, checkbox
  múltiple + "Agregar N opciones como grupo".
- Pestaña Local del cotizador: formulario inline "+ Agregar hotel no
  registrado".
- Cualquier UI de "promover".

## 6. Verificación esperada

- Regresión: `crearItemProveedor()` con `proveedor_tarifa_id` o sin
  ninguno de los tres (manual referencia) se comporta exactamente
  igual que antes.
- Test: alta de hotel ad-hoc standalone (sin `opcion_mayorista_id`),
  con tarifas y tributario resuelto por fila.
- Test: `AlternativaItem` creado desde `opcion_hotel_tarifa_id` ad-hoc
  — `origen_tipo=proveedor`, `opcion_hotel_tarifa_id` seteado,
  `proveedor_tarifa_id` null, costo/precio/tributario copiados
  correctos.
- Test: promoción crea Proveedor+ProveedorServicio+N ProveedorTarifa
  (una por tipo_habitacion), no relinkea el `AlternativaItem` ya
  creado en el punto anterior (sigue apuntando a `opcion_hotel_tarifa_id`,
  no al `proveedor_tarifa_id` nuevo).
- Test: promover dos veces el mismo hotel → 422.
- Suite completa en verde. Migración corrida contra `agencia-demo`.
- Actualizar `plan-ejecucion-matriz-hoteles-cotizador.md` (fila M3) y
  `plan-hoja-de-ruta-ejecucion.md`.
