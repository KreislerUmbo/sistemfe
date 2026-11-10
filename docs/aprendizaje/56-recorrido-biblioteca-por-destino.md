# Recorrido: la biblioteca del cotizador abre filtrada por el destino activo

10-nov-2026, tarea **T-035**. Origen: observación 9 de las pruebas del usuario, R3 (aprobada el 26-oct).
Archivos:
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/BibliotecaCotizadorController.php`
- `api-sistema-fe/tests/Feature/AgenciaViajes/BibliotecaCotizadorFiltrosTest.php`

---

## 1. La idea

En el cotizador, el botón **Agregar servicio** abre la biblioteca (pestaña «Servicios de la agencia»).
Antes abría **sin filtro**: en una cotización a Cusco aparecían Alto Mayo y Lamas, y la mitad de los
tours eran «solo itinerario», que no se pueden cargar.

Ahora:

1. al abrir, el filtro de destino se pone solo con el **destino activo** de la alternativa (el chip
   elegido, si hay varios). Si el destino se escribió a mano y no está en el catálogo, abre sin filtro;
2. encima de la lista aparece «Mostrando: Cusco y sus lugares ✕». La ✕ quita el filtro;
3. lo que el vendedor cambie se respeta mientras la biblioteca siga abierta; al cerrarla y abrirla
   otra vez, vuelve al destino activo;
4. los tours «solo itinerario» ya no se listan. Los **internacionales de venta sí** (decisión del 10-nov):
   es la única forma de vender un tour propio de la agencia en el extranjero.

## 2. El recorrido

### 2.1 Pantalla → qué destino usar (`editar.vue`, `watch(drawerBibliotecaAbierto)`, ~línea 3973)

```ts
watch(drawerBibliotecaAbierto, async (abierto) => {
    if (!abierto) return;
    const destino = alternativaDestinos.value.find((d) => d.id === destinoActivoId.value);
    const nuevoId = destino?.destino_atractivo_id ?? null;
    filtroDestinoTexto.value = nuevoId ? (destino?.destino_texto ?? '') : '';
    filtroDestinoId.value = nuevoId;
    await nextTick();
    clearTimeout(bibliotecaTimeout);
    cargarBiblioteca();
});
```

- **Por qué un `watch` y no código en el botón:** la biblioteca se abre desde varios lugares
  («Agregar servicio», «Editar» de un ítem manual). Mirar la variable que la abre cubre todos.
- **Dos ids distintos:** `destinoActivoId` es la fila de `alternativa_destinos` (el chip);
  `destino_atractivo_id` es el lugar del catálogo (`destinos_atractivos`). El filtro necesita el segundo.
- **El `nextTick` + `clearTimeout`:** cambiar `filtroDestinoId` dispara el `watch` de filtros
  (`watch([filtroDestinoId, filtroServicioId, filtroProveedorId], onBibliotecaSearch)`), que recarga con
  300 ms de espera, pensada para no pedir una vez por cada letra al escribir. Al abrir no hay nada que
  esperar: tras `nextTick()` ese watch ya agendó su recarga; se cancela y se carga en el acto. Sin esto, la
  banda decía «Cusco» sobre la lista vieja durante 300 ms (medido: ahora sale una sola petición a ~30 ms).

### 2.2 La banda (`editar.vue`, plantilla, `data-test="biblioteca-filtro-destino"`)

```html
<div v-if="filtroDestinoId" ...>
    Mostrando: {{ filtroDestinoTexto || 'destino elegido' }} y sus lugares
    <button class="btn-close" @click="filtroDestinoId = null"></button>
</div>
```

- Si el vendedor elige otro lugar en el árbol (`DestinoTreeSelect`), el evento `update:label` actualiza
  `filtroDestinoTexto`, así la banda siempre dice lo que filtra.
- La ✕ pone el filtro en `null`; el watch de filtros recarga sin `destino_atractivo_id`.

### 2.3 Pantalla → API → tabla (`BibliotecaCotizadorController::index()` / `buscarPaquetes()`)

`GET /biblioteca-cotizador?tipo=todos&destino_atractivo_id=1`

```php
$destinoIds = $request->filled('destino_atractivo_id')
    ? DestinoAtractivo::idsConDescendientes((int) $request->get('destino_atractivo_id'))
    : null;
...
$query->where(fn ($q) => $q->whereNull('uso')->orWhere('uso', '!=', PaquetePlantilla::USO_SOLO_ITINERARIO));
if ($destinoIds) {
    $query->whereIn('destino_atractivo_id', $destinoIds);
}
```

- **«y sus lugares»:** `idsConDescendientes()` devuelve Cusco y todo lo que cuelga de él en el árbol
  (p. ej. Mirador de Taray). Ya existía; T-035 solo lo aprovecha.
- **Por qué excluir en el backend y no en la pantalla:** la consulta tiene `limit(50)`. Si se quitaran
  en el navegador, de 50 resultados podrían quedar 30 y el vendedor no sabría que faltan más.
- Tabla: `paquetes_plantilla` (tours y paquetes de la agencia). `uso` = `venta` o `solo_itinerario`;
  `categoria` = `local`/`nacional`/`internacional` (no se filtra por ella).
- **Otro que usa este endpoint:** `venta-directa.vue`. Ya filtraba «solo itinerario» en el navegador, así
  que ahí no cambia nada visible.

## 3. Cómo probarlo

```
php artisan test --filter=BibliotecaCotizadorFiltros
```
`test_no_lista_tours_solo_itinerario_pero_si_internacionales_de_venta` crea tres tours en Cusco (uno de
venta, uno solo itinerario, uno internacional de venta) y comprueba, con `tipo=todos` y `tipo=tour`, que
salen el primero y el tercero.

En pantalla (agencia-demo): cotización 90 tiene dos destinos (Tarapoto y Lamas). Abrir la biblioteca con
cada chip activo muestra la banda con ese destino; la ✕ muestra todo.

## 4. Si algo falla

- **Abre sin filtro con un destino que sí está en el catálogo:** revisar que la fila de
  `alternativa_destinos` tenga `destino_atractivo_id` (si se escribió a mano queda `NULL`).
- **Falta un tour que debería salir:** ¿está `activo`? ¿es `solo_itinerario`? ¿su `destino_atractivo_id`
  cuelga del destino filtrado en el árbol?
