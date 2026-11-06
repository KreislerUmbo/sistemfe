# Recorrido: «mínimo para salir» obligatorio en tours con servicios por grupo

06-nov-2026, tarea **T-088**. Requerimiento: `docs/requerimientos/agencia-precio-y-margen.md`
(P9, respuestas 3 y 4 del 05-nov).

---

## 1. El problema

El costo por persona de un tour **reparte** lo que se cobra por grupo (el vehículo, el guía) entre
`pax_minimo_salida` (`TourCostoEstimadoService::sugerirItem()`). Sin ese número la calculadora no
puede sugerir el costo, el costo queda escrito a mano y el margen del tour no tiene base. En
agencia-demo, 12 de 13 tours no tenían mínimo. La ganancia por salida (T-089) también lo usa.

## 2. Archivos

| Archivo | Qué cambió |
|---|---|
| `api-sistema-fe/app/Models/AgenciaViajes/PaquetePlantilla.php` | `serviciosPorGrupo()` y `faltaMinimo()`: la regla en un solo lugar |
| `.../Controllers/AgenciaViajes/PaquetePlantillaController.php` | `update()` exige el mínimo (422); `show()` devuelve `servicios_por_grupo`; `index()` devuelve `falta_minimo` |
| `.../Controllers/AgenciaViajes/PaquetePlantillaItemController.php` | `store()`/`update()` devuelven `falta_minimo` (avisa, no bloquea) |
| `.../Services/AgenciaViajes/DiagnosticoToursService.php`, `Console/Commands/DiagnosticarTours.php` | Columna «Falta mínimo» |
| `admin-start-kit/src/components/AgenciaViajes/TourPrecioVentaCampos.vue` | Asterisco y ayuda en «Mínimo para salir» |
| `admin-start-kit/src/components/AgenciaViajes/TourIncluyeCostos.vue` | Aviso amarillo en el Incluye |
| `admin-start-kit/src/views/agencia-viajes/paquetes/index.vue` | Badge «Falta mínimo» |
| `paquetes/detalle.vue`, `paquetes/form.vue`, `types/agencia-viajes.ts` | Pasan `servicios_por_grupo` a los componentes |
| `tests/Feature/AgenciaViajes/PaquetePlantillaMinimoSalidaTest.php` (nuevo) | 8 tests |

Tablas: ninguna. Migraciones: ninguna (la columna `pax_minimo_salida` ya existía).

## 3. El recorrido

### 3.1 La regla: ¿qué es un servicio «por grupo»?

`PaquetePlantilla::serviciosPorGrupo()` devuelve los **nombres** de los servicios del Incluye que
se cobran por grupo:

- una tarifa de proveedor con `modalidad = 'privado'` **y** sin `tipo_habitacion` (un hotel
  privado no cuenta: se cobra por habitación, no se reparte entre el grupo), o
- un guía (`guia_tarifa_id`), que se muestra como «Guía».

Si el tour es **combo** o **solo itinerario**, devuelve vacío: la regla no aplica.
`faltaMinimo()` = no tiene `pax_minimo_salida` **y** la lista no está vacía.

Truco del listado: si el Incluye ya viene precargado (`relationLoaded('items')`), lo usa sin
volver a consultar. Así `index()` carga el Incluye de toda la página en una consulta y no una por tour.

### 3.2 Guardar el tour (pestaña Datos)

`PaquetePlantillaController::update()`:

```
estado final = lo guardado + lo que llega   ((clone $paquete)->fill($validado))
si el tour queda activo y faltaMinimo(estado final) → 422
    «Este tour tiene servicios que se cobran por grupo (Movilidad privada, Guía):
     indica el mínimo de pasajeros para salir.»
```

**Desactivar no lo exige** (`activo = false`): un tour que no se vende no necesita mínimo, y así
se puede apagar un tour viejo. Reactivarlo sí lo exige.

### 3.3 Agregar un servicio al Incluye

No se bloquea: primero se arma el Incluye y después se pone el mínimo. La respuesta trae
`falta_minimo`, y la pantalla recarga el tour (`cargarPaquete()`), con lo que el asterisco y el
aviso aparecen solos.

### 3.4 Pantalla

| Dónde | Qué se ve | De dónde sale |
|---|---|---|
| Listado de tours | Badge «Falta mínimo» | `falta_minimo` de `index()` |
| Datos → «Mínimo para salir» | Asterisco + «Se usa para repartir el vehículo y el guía…» | `servicios_por_grupo` de `show()` |
| Incluye | Aviso amarillo con los servicios por grupo | `servicios_por_grupo` + `pax_minimo_salida` guardado |

### 3.5 Datos existentes

Nada se cambia solo. `php artisan agencia-viajes:diagnosticar-tours` muestra la columna «Falta
mínimo». En agencia-demo solo el #17 «Tours a la Encañada» dio «sí».

## 4. Lo que queda fuera

- El cotizador **no se bloquea** por falta de mínimo; las cotizaciones hechas no cambian.
- Costo real de un tour privado (P1, diferido) y ganancia por salida (T-089).
