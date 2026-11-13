# Recorrido: empezar la comparación de hoteles con 1 sola habitación

12-nov-2026, tarea **T-019**. Origen: observación 6 de las pruebas del usuario
(`docs/requerimientos/observaciones-pruebas-usuario.md`).
Archivos:
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `admin-start-kit/src/components/AgenciaViajes/HabitacionMatrixPicker.vue`

Sin cambios de backend.

---

## 1. La idea

El caso real: comparar «matrimonial del Hotel A vs. matrimonial del Hotel B» en un viaje
Local/Nacional. Antes, si el Hotel A tenía más de un tipo de habitación cargado, el picker exigía
marcar **al menos 2** para crear la comparación. El vendedor marcaba una de más y la borraba después.

Ahora:

1. en el **primer** hotel alcanza con 1 habitación; el botón dice **«Empezar comparación»** y crea
   un grupo de 1 fila («1 opciones de hotel · Sin resolver»);
2. el **siguiente** hotel abre directamente en modo comparación, con el aviso «Sumando este hotel
   a la comparación en curso», y su botón dice «Sumar a la comparación»: queda en el mismo grupo;
3. el picker de **mayorista** no cambia: sigue pidiendo 2, porque ahí se marcan varios hoteles juntos.

Lo que viene después no cambió: el PDF y «Aceptado por cliente» siguen bloqueados (422) mientras
el grupo no tenga una opción elegida.

## 2. El recorrido

### 2.1 Qué es un «grupo» (tabla `alternativa_items`)

Una comparación de hoteles no tiene tabla propia: son varias filas de `alternativa_items` que
comparten el mismo **`grupo_opcion_id`** (un UUID). Cada fila tiene `opcion_elegida` (true/false).

- **Grupo abierto (sin resolver):** ninguna fila con `opcion_elegida = true`. El total de la
  alternativa toma la opción más barata y el lienzo dice «desde, sin resolver».
- **Grupo resuelto:** exactamente una fila elegida; el total usa esa.

El backend nunca exigió un mínimo de filas por grupo (`grupo_opcion_id` es `nullable|uuid` en
`AlternativaItemController`): el mínimo de 2 era solo del frontend. Por eso T-019 es solo de frontend.

### 2.2 El picker de Local/Nacional (`editar.vue`, ~línea 1401)

```vue
<HabitacionMatrixPicker ...
    :minimo-grupo="1"
    :iniciar-en-modo-grupo="!!matrizHotelActiva.grupoAbierto"
    @agregar-grupo="({ ids, cantidad }) => matrizHotelActiva!.adhoc
        ? agregarGrupoHotelAdhocLocal(ids, cantidad)
        : agregarGrupoProveedorHotel(ids, cantidad)" />
```

- **`minimo-grupo`:** antes era `grupoAbierto || tarifas.length <= 1 ? 1 : 2`. Es decir: 1 si ya
  había una comparación en curso o si el hotel tenía un solo tipo; 2 en el resto. Ahora es 1 siempre.
- **`iniciar-en-modo-grupo`:** si ya hay un grupo abierto en ese día, el picker arranca con las
  casillas de «Comparar varias opciones» visibles (paso 2 de la idea).

### 2.3 El texto del botón (`HabitacionMatrixPicker.vue`, ~línea 200)

```vue
{{ idsGrupo.length === 1
    ? (props.iniciarEnModoGrupo || (minimoGrupo ?? 2) > 1 ? 'Sumar a la comparación' : 'Empezar comparación')
    : `Agregar ${idsGrupo.length} opciones como grupo` }}
```

Sin prop nueva: el picker ya sabe lo necesario.

| Caso | `iniciarEnModoGrupo` | `minimoGrupo` | Texto con 1 marcada |
|---|---|---|---|
| Primer hotel (Local) | false | 1 | **Empezar comparación** |
| Hotel siguiente (Local) | true | 1 | Sumar a la comparación |
| Mayorista | false | sin pasar → 2 | Sumar a la comparación (deshabilitado) |

El botón se deshabilita con `idsGrupo.length < (minimoGrupo ?? 2)`, y `confirmarGrupo()`
(~línea 496) repite la misma condición antes de emitir `agregarGrupo`.

### 2.4 Cómo sabe que hay un grupo abierto (`grupoHotelAbiertoDiaActivo`, ~línea 3168)

```ts
const grupoHotelAbiertoDiaActivo = computed(() => {
    const itemsHotelDelDia = itemsSinAjuste.value.filter((item) =>
        item.origen_tipo === 'proveedor' && !!item.grupo_opcion_id
        && item.dia_referencial === diaActivoParaAgregar.value
        && (item.proveedor_tarifa?.tipo_habitacion || item.opcion_hotel_tarifa));
    // agrupa por grupo_opcion_id y devuelve el primero sin ninguna opcion_elegida
    ...
    return { id: grupoId, hoteles: hotelesIds.size, cantidad: items[0].cantidad };
});
```

No cuenta filas, así que un grupo de **1** fila también cuenta como abierto: aparece el banner
«Comparando 1 hotel(es) para este día». `hoteles` cuenta hoteles distintos (por
`proveedor_servicio_id`, o `opcion_hotel_id` si el hotel es sin catálogo), no habitaciones.
Al abrir otro hotel, este valor se guarda en `matrizHotelActiva.grupoAbierto` (~línea 3219).

### 2.5 Guardar el grupo (`agregarGrupoProveedorHotel`, ~línea 3404)

```ts
const grupoOpcionId = matrizHotelActiva.value?.grupoAbierto?.id ?? generarUuidGrupo();
for (const proveedorTarifaId of ids) {
    await alternativaItemService.agregarProveedor(alternativaActiva.value.id, {
        proveedor_tarifa_id: proveedorTarifaId, modo_precio: 'tarifa_fija', cantidad,
        dia_referencial: diaActivoParaAgregar.value, alternativa_destino_id: destinoActivoId.value,
        grupo_opcion_id: grupoOpcionId,
    });
}
```

- **Primer hotel:** no hay grupo abierto → UUID nuevo.
- **Hotel siguiente:** reusa el `id` del grupo abierto, y por eso queda en la misma comparación.
- `agregarGrupoHotelAdhocLocal` (hoteles sin catálogo) hace lo mismo con `opcion_hotel_tarifa_id`.

`POST /alternativas/{id}/items` → una fila de `alternativa_items` por habitación marcada.

## 3. Cómo probarlo

No hay test automático del picker: la tarea se verificó en pantalla. Los tests que protegen lo que
viene después siguen en verde:

```
php artisan test tests/Feature/AgenciaViajes/SesionM4ElegirGrupoTest.php \
  tests/Feature/AgenciaViajes/SesionM5OpcionesHotelesPdfTest.php \
  tests/Feature/AgenciaViajes/T024GrupoHotelesIndependienteTest.php
```

En pantalla (agencia-demo, 10-nov): Agregar servicio → buscar «Cumbaza» → Comparar varias
opciones → marcar solo Doble → «Empezar comparación». El lienzo muestra «1 opciones de hotel · Sin
resolver», con el total «desde» igual al de esa fila (PEN 150 × 2 noches = 300). Después buscar
«Rio Sol»: abre con el aviso, se marca Doble → «Sumar a la comparación» → la tarjeta pasa a 2
opciones. Ver PDF da 422 hasta marcar una como elegida; después sale la tabla «Opciones de hoteles»
con la elegida resaltada. En el picker de mayorista, con 1 marcada el botón sigue deshabilitado.

## 4. Si algo falla

- **El segundo hotel crea otro grupo en lugar de sumarse:** ¿está en el **mismo día** activo? El
  grupo abierto se busca por `dia_referencial`. ¿El grupo ya tenía una opción elegida? Entonces está
  resuelto y el hotel arranca uno nuevo.
- **No aparece el banner «Comparando…»:** revisar que las filas tengan `grupo_opcion_id` y
  `tipo_habitacion` (o `opcion_hotel_tarifa`); un servicio que no es hotel no cuenta.
- **El PDF dice «Incluye descuento de temporada» sin descuento** con un grupo resuelto: no es de
  esta tarea, es H10 → T-023.
- **«1 opciones de hotel»:** el plural está fijo en el lienzo (`editar.vue`, ~línea 505); anotado en
  «Por analizar» del backlog.
