# Recorrido: mayorista con etiquetas, primera opción elegida sola y «solo adultos» a la vista

10-nov-2026, tarea **T-036**. Origen: observación 9 de las pruebas del usuario, R6 (aprobada el 26-oct)
y H22 (decisión del usuario: por ahora el paquete de mayorista se cotiza solo por adultos).
Archivos:
- `admin-start-kit/src/components/AgenciaViajes/OpcionMayoristaForm.vue`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/OpcionMayoristaController.php`
- `api-sistema-fe/tests/Feature/AgenciaViajes/OpcionMayoristaCrudTest.php`

---

## 1. La idea

Tres molestias medidas en las pruebas:

1. el formulario del mayorista no tenía etiquetas, solo texto gris dentro de cada campo;
2. con **una sola** opción de mayorista había que pulsar «Elegir mayorista» y después «Hoteles» antes
   de cargar una habitación;
3. con 2 adultos + 1 niño el paquete salió USD 1,900 (950 × 2) y el panel decía «Total (2 adultos +
   1 niño)», o sea, parecía que el niño estaba incluido.

Ahora:

1. cada campo tiene su etiqueta encima (Proveedor mayorista, Moneda, Aerolínea, Detalle del vuelo,
   Descripción para el cliente, Contenido reutilizable, Incluye, No incluye);
2. la **primera** opción que se crea en una alternativa nace **elegida** y con su sección de hoteles
   abierta. Con dos o más, el vendedor elige como antes;
3. el cálculo **no cambió** (sigue siendo por adulto), pero ahora se nota: la línea del paquete dice
   «× 2 adultos», la matriz avisa «precio por adulto…» y, si hay niños o infantes, el total dice
   «Total (el paquete de mayorista no incluye niños)». No bloquea nada.

## 2. El recorrido

### 2.1 Pantalla → API → tabla: crear la opción (`OpcionMayoristaController::store()`, ~línea 61)

`POST /alternativas/{id}/opciones-mayorista` → tabla `opcion_mayorista`.

```php
$opcion = DB::transaction(function () use (...) {
    Alternativa::whereKey($alternativa->id)->lockForUpdate()->first();
    $destinoId = $alternativaDestinoId ?? $alternativa->destinos()->value('id');

    $hayOtraEnElDestino = OpcionMayorista::where('alternativa_id', $alternativa->id)
        ->where('alternativa_destino_id', $destinoId)->exists();
    $hayElegida = OpcionMayorista::where('alternativa_id', $alternativa->id)->where('estado', 'elegida')->exists();

    return OpcionMayorista::create($validado + [
        ...
        'estado' => (! $hayOtraEnElDestino && ! $hayElegida) ? 'elegida' : 'candidata',
    ]);
});
```

- **Estados de una opción:** `candidata` (en comparación), `elegida` (la que se cotiza), `descartada`.
  Solo con `elegida` se pueden agregar sus habitaciones al lienzo (`AlternativaItemController::crearItemMayorista()`
  lo exige); por eso antes había que pulsar «Elegir».
- **Por qué el bloqueo (`lockForUpdate`):** si dos vendedores crean la primera opción al mismo tiempo,
  ambos verían «no hay elegida» y las dos nacerían elegidas. El bloqueo de la fila de la alternativa
  hace que el segundo espere a que termine el primero. Si igual se colara, el índice único
  `opcion_mayorista_alternativa_elegida_unique` (una elegida por alternativa) rechaza la segunda.
- **Por qué «por alternativa» y no «por destino»:** hay dos índices únicos que conviven,
  uno por alternativa y otro por destino (`..._alternativa_destino_elegida_unique`). Manda el más
  estricto: en una alternativa con dos destinos, si el destino 1 ya tiene elegida, la primera opción
  del destino 2 nace **candidata**. Cuando la limpieza 12g borre el índice por alternativa, `store()` y
  `elegir()` pasan a decidir por destino (anotado en el backlog).
- **Elegir directo no se salta nada:** `elegir()` solo cambia el estado (y baja a `candidata` la que
  estaba elegida); no tiene otros efectos. Lo que depende de la elegida (cupo de `salidas_mayorista` al
  reservar) funciona igual.

### 2.2 Abrir los hoteles solos (`editar.vue`, `onOpcionMayoristaGuardada`, ~línea 3779)

```ts
const onOpcionMayoristaGuardada = async (opcion?: OpcionMayorista) => {
    ...
    await cargarOpcionesMayorista();
    if (opcion?.estado === 'elegida') opcionHotelesActivaId.value = opcion.id;
};
```

`OpcionMayoristaForm.vue` emite `agregado` con la opción que devolvió la API. Si nació elegida, se pone
su id en `opcionHotelesActivaId`, la misma variable que cambia el botón «Hoteles».

### 2.3 «× N adultos» y la nota del total (`editar.vue`, ~líneas 1766-1775)

```ts
const esItemPaqueteMayorista = (item) => item.origen_tipo === 'mayorista' && !!item.opcion_hotel_tarifa_id;
const textoAdultosMayorista = (item) => {
    if (!esItemPaqueteMayorista(item)) return '';
    const n = Number(item.cantidad) || 1;
    return `× ${n} ${n === 1 ? 'adulto' : 'adultos'}`;
};
const notaMayoristaSinNinos = computed(() => {
    const hayPaquete = (alternativaActiva.value?.items ?? []).some(esItemPaqueteMayorista);
    const hayNinos = (cotizacion.value?.pasajeros ?? []).some((p) => p.tipo_pax !== 'adulto');
    return hayPaquete && hayNinos;
});
```

- **Qué es «paquete de mayorista»:** un ítem con `origen_tipo='mayorista'` **y** `opcion_hotel_tarifa_id`
  (una habitación de la matriz). Los opcionales del mayorista también son `origen_tipo='mayorista'`, pero
  no tienen tarifa de hotel, así que no llevan «× adultos».
- **`cantidad` = adultos:** la matriz (`HabitacionMatrixPicker`, `cantidad-label="Adultos"`) propone
  `cantidadAdultosCotizacion` (los pasajeros con `tipo_pax='adulto'`).
- Se usa en tres lugares: el grupo de habitaciones del lienzo, la fila suelta del lienzo (reemplaza
  el «× cantidad» genérico) y el panel de resumen. El total (~línea 795) muestra la nota en lugar de
  «(2 adultos + 1 niño)».
- El aviso «Adultos: precio por adulto…» es un párrafo encima de la matriz (~línea 1193);
  `HabitacionMatrixPicker.vue` no se tocó.

## 3. Cómo probarlo

```
php artisan test --filter=OpcionMayorista
```
- `test_la_primera_opcion_de_mayorista_queda_elegida_y_la_segunda_no`: crea dos opciones en la misma
  alternativa; la primera queda `elegida`, la segunda `candidata`, y hay una sola elegida.
- `test_con_varios_destinos_la_primera_del_segundo_destino_queda_candidata`: dos destinos; la del
  destino 1 queda elegida y la primera del destino 2, candidata.

En pantalla (agencia-demo): una cotización con 2 adultos + 1 niño, crear la primera opción de mayorista
(nace «Elegida» con Hoteles abierto), cargar una habitación: el lienzo y el panel dicen «× 2 adultos» y
el total dice «Total (el paquete de mayorista no incluye niños)». Sin el niño vuelve «Total (2 adultos)».

## 4. Si algo falla

- **La primera opción nace candidata:** ¿la alternativa ya tiene otra elegida (quizá en otro destino) o
  ya hay otra opción en ese destino, aunque esté descartada?
- **No aparece «× N adultos»:** ¿el ítem tiene `opcion_hotel_tarifa_id`? Los ítems de mayorista cargados
  a mano sin matriz no lo tienen.
- **Falta la nota con niños:** revisar `tipo_pax` de los pasajeros (`adulto`/`nino`/`infante`).
- **Precio de niño en el paquete:** no existe todavía; está en «Por analizar» del backlog.
