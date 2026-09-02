# Brief para Claude Code — Sesión M2: trazabilidad de la cotización a la reserva

> Referencia: `plan-matriz-hoteles-cotizador.md` Ronda 5 (P13-P15),
> `plan-ejecucion-matriz-hoteles-cotizador.md` fila M2. Depende de M1
> (`origin/main` `1861890`) y de C1 (`origin/main` `f6da2d0`, hecho antes
> a propósito — el propio plan de M2 documentaba que el resolver
> centralizado necesita el parámetro de audiencia que C1 introduce).

---

## 0. Hallazgo real encontrado al arrancar esta sesión, antes de escribir este brief

`ReservaController::crearReservaDesdeAlternativa()` recorre **todos**
los `alternativa_items` de la alternativa sin filtrar por
`opcion_elegida` (`AlternativaItem::where('alternativa_id', ...)->get()`,
línea ~276). Con un grupo de M1 ya resuelto (guard de `aceptar()` lo
exige), esto crea un `ReservaItem` **también para las opciones de hotel
RECHAZADAS del grupo**, no solo la elegida — el brief original de M2
(escrito antes de M1 existir) no lo contemplaba porque `grupo_opcion_id`
no existía todavía. Se agrega como punto 2 de esta sesión, no es
opcional.

También se confirmó, releyendo ambos resolvers completos antes de
diseñar el merge, una segunda divergencia real entre
`ReservaController::resolverNombreItem()` y
`AlternativaController::resolverNombreItemPdf()` que Ronda 5/P14 no
detalló pero que la centralización debe cerrar igual:
`resolverNombreItem()` (usado en reporte operativo, facturación,
`show()`) **no tiene rama para `origen_tipo=guia`** — un ítem de guía
cae al genérico `'Servicio'`, perdiendo el nombre del guía. La versión
del PDF sí la tiene. Al centralizar, la rama de guía se incorpora al
método único (sin distinción de audiencia — el nombre del guía no es
dato sensible).

---

## 1. Fix del gap heredado — `opcion_hotel_tarifa_id` nunca se escribía

`AlternativaItemController::crearItemMayorista()` exige
`opcion_hotel_tarifa_id` en el request y lo usa para derivar
`costo_snapshot`/`precio_venta_snapshot`, pero nunca lo persiste en el
`AlternativaItem` creado — la columna existe en la tabla desde antes
(retrofit viejo, ver docblock del modelo) pero ningún código la escribe.
Agregar `opcion_hotel_tarifa_id` a `AlternativaItem::$fillable` y al
payload de `AlternativaItem::create()` en `crearItemMayorista()`.

---

## 2. `crearReservaDesdeAlternativa()` — filtrar por grupo resuelto

Antes del `foreach ($alternativaItems as $alternativaItem)`, filtrar la
colección: un ítem con `grupo_opcion_id` no nulo y `opcion_elegida=false`
**no genera `ReservaItem`** — es una opción descartada del grupo, nunca
tuvo reserva. Ítems sin grupo, y la fila `opcion_elegida=true` de un
grupo resuelto, siguen creando su `ReservaItem` exactamente igual que
hoy. Reusar `AlternativaItem::agruparPorGrupoOpcion()` de M1 si queda
limpio, o un filtro directo — decidir al escribir el código.

Test de regresión explícito: una alternativa sin ningún grupo genera el
mismo número de `ReservaItem` que hoy (ninguno se pierde). Una con un
grupo resuelto de 3 opciones genera **1 solo** `ReservaItem` para ese
grupo (la elegida), no 3.

---

## 3. `reserva_items.opcion_hotel_tarifa_id` — columna espejo

Migración nueva (nullable, FK a `opciones_hotel_tarifas`, mismo patrón
que la de `alternativa_items`). `ReservaItem::$fillable` +
`opcionHotelTarifa()` (`belongsTo`). En
`crearReservaItemDesdeAlternativaItem()`, copiar
`alternativa_item.opcion_hotel_tarifa_id` junto con `proveedor_tarifa_id`
(mismo `'campo' => $alternativaItem->campo` de siempre, sin lógica
nueva — el filtro de grupo ya pasó en el punto 2, así que cuando este
método corre, el ítem que llega ya es el que corresponde).

`AlternativaItem::opcionHotelTarifa()` (`belongsTo`) también nueva —
no existe todavía en ningún modelo.

---

## 4. Resolver de nombre centralizado

Extender `ReservaController::resolverNombreItem()` (ya es
`public static`, ya acepta `?ReservaItem $reservaItem = null` desde
12h) con:

```php
public static function resolverNombreItem(
    AlternativaItem $item,
    ?ReservaItem $reservaItem = null,
    string $audiencia = 'interno'
): string
```

- Rama `ORIGEN_MAYORISTA`: si `$audiencia === 'cliente'`, usa
  `opcionMayorista->descripcion_publica ?? 'Paquete mayorista'` (fix C1,
  sin ningún fallback al proveedor). Si `'interno'` (default — no rompe
  ningún caller existente que no pase el parámetro), sigue como hoy
  (`nombre_comercial ?: razon_social` del proveedor real).
- Agregar la rama `ORIGEN_GUIA` (copiada de
  `resolverNombreItemPdf()`, ver §0).
- Al final, antes del fallback genérico `'Servicio'`, agregar el
  intento por `opcionHotelTarifa` (mismo criterio "reserva_item primero,
  alternativa_item si no" que ya usa la rama mayorista para
  `opcionMayorista`): `"{$hotel->nombre_hotel} · {$tarifa->tipo_habitacion}"`.
- La rama `proveedorTarifa->tipo_habitacion` ya existente sigue como
  está (usa `nombre_comercial ?: razon_social`, correcto para las dos
  audiencias — un hotel LOCAL real registrado como Proveedor no tiene el
  mismo problema de dato fiscal que un mayorista).

`AlternativaController::resolverNombreItemPdf()` se **elimina** —
`pdf()` pasa a llamar `ReservaController::resolverNombreItem($item, null, 'cliente')`
directo (mismo patrón cross-controller que ya usan
`ReporteOperativoController`/`ReservaFacturacionController`, no es un
precedente nuevo). Confirmar que los 6 tests de
`Sesion12f3PdfPorDestinoTest`/`FixC1LeakMayoristaPdfTest` que invocan
`resolverNombreItemPdf()` por reflexión se actualizan al nuevo método
(o se adaptan si el nombre del método destino cambia) — no dejarlos
rotos.

`ReservaController::show()` — agregar el nombre ya resuelto (audiencia
`'interno'`) a cada ítem del JSON, para que `reservas/detalle.vue` deje
de tener su propia copia en TS (`nombreItem()`, ~línea 1272) y lo lea
directo de la respuesta. Actualizar `nombreItem()` para usar el campo
del backend en vez de recalcularlo — sin borrar el fallback si el campo
no viene (reservas cacheadas del lado del store de Pinia, por las
dudas).

---

## 5. `itemSinAsignacionOperativa()`/`tieneAsignacionAplicable()`/`filtrosDisponibles()`

- `ReservaController::itemSinAsignacionOperativa()` (~línea 356) y su
  réplica en `reservas/detalle.vue::tieneAsignacionAplicable()`: pasan a
  considerar "asignado" tanto `proveedor_tarifa_id` como
  `opcion_hotel_tarifa_id` presentes — un hotel ad-hoc/mayorista ya
  elegido SÍ es una asignación real.
- `filtrosDisponibles()` del reporte operativo: el catálogo de
  "hoteles" suma también los nombres de `opciones_hotel.nombre_hotel`
  usados en reservas activas (vía `opcion_hotel_tarifa_id`), no solo los
  de `ProveedorTarifa`.

---

## 6. Explícitamente fuera de alcance (M3/M4/M5)

- Alta ad-hoc de hotel local sin proveedor (M3).
- `HabitacionMatrixPicker.vue`/lienzo del cotizador (M4) — esta sesión
  no toca UI de creación de ítems, solo trazabilidad backend + el
  campo de nombre expuesto en `show()`.
- Sección "Opciones de hoteles" del PDF (M5).
- Pagos a proveedor (fuera de alcance del plan completo, P15).

---

## 7. Verificación esperada

- Test de regresión: alternativa 100% sin grupos se comporta idéntico
  en `crearReservaDesdeAlternativa()` (mismo número de `ReservaItem`),
  `resolverNombreItem()` (mismos nombres que devolvía antes para
  manual/pasaje_aereo/mayorista-interno/proveedor-hotel/servicio
  genérico) y `itemSinAsignacionOperativa()`.
- Suite completa de backend en verde.
- `tenants:migrate-verticales` corrido contra `agencia-demo` después de
  mergear.
- Verificado con datos reales o fixture explícita: un ítem
  `origen_tipo=mayorista` con `opcion_hotel_tarifa_id` cargado por
  `crearItemMayorista()`, aceptado dentro de un grupo M1 resuelto,
  genera 1 `ReservaItem` con `opcion_hotel_tarifa_id` copiado, nombre
  resuelto correctamente vía `opcionHotelTarifa` en el reporte
  operativo (audiencia interno) y en el PDF (audiencia cliente, sin
  revelar el mayorista).
- Actualizar `plan-ejecucion-matriz-hoteles-cotizador.md` (fila M2
  `[x]`, fecha y commit) y `plan-hoja-de-ruta-ejecucion.md` si tiene
  fila propia.
