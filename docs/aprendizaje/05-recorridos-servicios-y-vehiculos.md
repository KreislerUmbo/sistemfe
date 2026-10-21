# Recorridos de la fila 13, fase 2b: servicios a reservar y vehículos de la salida

Mismo formato que `02-mapa-del-sistema.md`. Continúa la guía 04 (la salida agrupa los tours).

Rutas de archivos abreviadas:
- `views/…`, `components/…`, `services/admin/…`, `types/…` → dentro de `admin-start-kit/src/`
- `Controllers/…`, `Services/…`, `Models/…` → dentro de `api-sistema-fe/app/`
- `routes/api.php`, `database/…`, `tests/…` → dentro de `api-sistema-fe/`

## 0. La idea

Una salida ("Laguna Azul del 15-nov") ahora sabe **qué hay que reservar** y **en qué va la gente**:

| Tabla | Qué guarda | Modelo |
|---|---|---|
| `salida_servicios` | un servicio a reservar: nombre, proveedor, cantidad a mano (opcional) y estado `por_reservar` / `reservado` | `Models/AgenciaViajes/SalidaServicio.php` |
| `salida_vehiculos` | un vehículo: transportista (o propio), descripción, chofer, capacidad, guía del vehículo | `Models/AgenciaViajes/SalidaVehiculo.php` |
| `salida_vehiculo_reservas` | qué reservas lleva cada vehículo (tabla puente) | relación `SalidaVehiculo::reservas()` |

Migración: `database/migrations/tenant/verticals/agencia-viajes/2026_10_21_200000_create_salida_servicios_y_vehiculos_tables.php`.
También pasa el texto "Vehículo" y el "Cupo máximo" de antes a un primer vehículo, con todas las
reservas de su salida (D13).

## 1. La lista de servicios se arma sola (D10)

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Aceptar la reserva | `ReservaController::engancharSalidaOperativa()` | después de enganchar una línea de tour, llama al servicio de abajo |
| Armar la lista | `Services/AgenciaViajes/SalidaServiciosService.php` → `sincronizarDesdeTour()` | por cada ítem del Incluye del tour que todavía no esté en la lista, crea un `SalidaServicio` con el proveedor sugerido (o el de su tarifa). **No incluye el guía**: el guía va en la salida o en el vehículo |
| Botón "Actualizar desde el tour" | `SalidaServicioController::actualizarDesdeTour()` → la misma función | trae lo nuevo del Incluye |

`sincronizarDesdeTour()` es **idempotente**: llamarla dos veces da lo mismo que una. Solo agrega lo
que falta, nunca borra ni cambia lo que el operador ya asignó. Hay además un índice único en la
base (salida + ítem del tour) que impide duplicados.

**Cantidad:** si `cantidad_manual` está vacía, la cantidad es **adultos + niños** de la salida (el
infante no cuenta). La calcula `SalidaOperativaController::resumenSalida()`; no se guarda, así que
sigue sola a los pasajeros.

## 2. Editar un servicio

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Pantalla | `components/AgenciaViajes/SalidaServiciosVehiculos.vue` → `guardarServicio()`, `guardarCantidad()`, `agregarServicio()`, `quitarServicio()` | dentro del detalle de la salida (`views/agencia-viajes/salidas/index.vue`) |
| Proveedor | `components/AgenciaViajes/ProveedorBuscador.vue` | buscador: escribes 2 letras y consulta `GET /proveedores?search=` |
| Servicio frontend | `salidaOperativaService.actualizarServicio()` / `agregarServicio()` / `quitarServicio()` / `actualizarServiciosDesdeTour()` | `PUT /salida-servicios/{id}`, `POST /salidas-operativas/{id}/servicios`, `DELETE /salida-servicios/{id}`, `POST …/servicios/actualizar` |
| Ruta | `routes/api.php` | permiso `reservas.editar` |
| Controlador | `Controllers/AgenciaViajes/SalidaServicioController.php` | valida. **No deja marcar "Reservado" sin proveedor.** No toca nada si la salida está cancelada |

## 3. Vehículos

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Pantalla | `SalidaServiciosVehiculos.vue` → `agregarVehiculo()`, `guardarVehiculo()`, `alternarReserva()`, `quitarVehiculo()` | — |
| Servicio frontend | `salidaOperativaService.agregarVehiculo()` / `actualizarVehiculo()` / `asignarReservasVehiculo()` / `quitarVehiculo()` | `POST /salidas-operativas/{id}/vehiculos`, `PUT /salida-vehiculos/{id}`, `PUT /salida-vehiculos/{id}/reservas`, `DELETE /salida-vehiculos/{id}` |
| Controlador | `Controllers/AgenciaViajes/SalidaVehiculoController.php` | ver abajo |

Reglas de `SalidaVehiculoController`, en palabras:
- **`store()`**: el **primer** vehículo de una salida se lleva de entrada a todas sus reservas (lo
  normal es uno solo). Desde el segundo, se reparten a mano.
- **`asignarReservas()`**: una reserva va en **un solo** vehículo de la salida; marcarla en uno la
  saca del otro. Solo acepta reservas de esa salida. Si se pasa de la capacidad, **avisa pero
  guarda** (el operador puede saber algo que el sistema no). El infante no ocupa asiento.

## 4. Quién guía a cada reserva (D12)

`Services/AgenciaViajes/AsignacionOperativa.php` → `guiaEfectivo()`:
1. si la reserva va en un vehículo con su propio guía → ese guía;
2. si no → el guía principal de la salida.

`SalidaOperativa::vehiculoDeReserva()` busca el vehículo de una reserva. La asignación es
**explícita**: la pantalla, el Reporte Operativo y el guía efectivo leen lo mismo.

## 5. Dónde se ven

| Lugar | Archivo | Qué muestra |
|---|---|---|
| Tablero, columna "Operación" | `views/agencia-viajes/salidas/index.vue` | vehículos y asientos, servicios reservados, avisos "sin proveedor" y "reserva sin vehículo" |
| Detalle de la salida | `SalidaServiciosVehiculos.vue` | la lista de servicios (solo si la salida tiene tours del modo nuevo) y los vehículos |
| Reporte Operativo | `ReporteOperativoController::textoVehiculo()` | "Van 14 · ABC-123 · chofer Juan (999…)" del vehículo de cada reserva |
| Inicio | `DashboardService::proximos()` | el cupo = suma de las capacidades de los vehículos |
| "Sin asignar" | `AsignacionOperativa::sinAsignar()` | un tour queda pendiente si un servicio de su salida no tiene proveedor. Que falte un vehículo es solo un aviso: hay tours sin transporte |

## 6. Tests

Archivo: `tests/Feature/AgenciaViajes/Fila13Fase2bServiciosVehiculosTest.php`. Los fixtures
compartidos de la fase 2 están en `tests/Feature/AgenciaViajes/Concerns/SalidasFixtures.php`, un
**trait**: un bloque de código que varias clases de test reutilizan con `use SalidasFixtures;`.

| Regla | Test |
|---|---|
| La lista sale del Incluye, sin guía ni duplicados; cantidad = adultos + niños | `test_la_salida_arma_sus_servicios_desde_el_incluye_sin_guia_ni_duplicados` |
| "Actualizar" no pisa lo asignado | `test_actualizar_desde_el_tour_agrega_lo_nuevo_sin_tocar_lo_asignado` |
| Cantidad a mano; reservado exige proveedor | `test_cantidad_a_mano_y_reservado_exige_proveedor` |
| Servicio sin proveedor → tour sin asignar | `test_un_servicio_sin_proveedor_deja_el_tour_sin_asignar` |
| Primer vehículo lleva a todos; una reserva en un solo vehículo; aviso de capacidad | `test_varios_vehiculos_una_reserva_va_en_uno_solo_y_avisa_capacidad` |
| Guía del vehículo | `test_el_guia_del_vehiculo_es_el_guia_de_sus_reservas` |

---

## Ejercicios

### Nivel 1
¿Qué pasa en la base de datos si quitas un vehículo que llevaba dos reservas? Pista: mira el
`cascadeOnDelete()` de `salida_vehiculo_reservas` en la migración.

### Nivel 2
Un operador cambia el proveedor del almuerzo y después alguien agrega "Entrada" al Incluye del
tour y presiona "Actualizar desde el tour". ¿Se pierde el proveedor del almuerzo? Explica por qué
mirando `sincronizarDesdeTour()`.

### Nivel 3
En tinker, con `agencia-demo`, lista los vehículos con sus reservas:
```php
tenancy()->initialize(App\Models\Tenant::find('agencia-demo'));
App\Models\AgenciaViajes\SalidaVehiculo::with('reservas:id')->get()
    ->map(fn ($v) => [$v->salida_operativa_id, $v->descripcion, $v->reservas->pluck('id')]);
```
¿De dónde salieron esos vehículos si nadie los creó desde la pantalla?

## Soluciones

<details>
<summary>Nivel 1</summary>

Se borran las dos filas de `salida_vehiculo_reservas` de ese vehículo (`cascadeOnDelete()`). Las
reservas no se tocan: quedan en la salida, pero sin vehículo, y el tablero avisa "2 reserva(s) sin
vehículo".

</details>

<details>
<summary>Nivel 2</summary>

No. `sincronizarDesdeTour()` busca primero los ítems del tour que **ya están** en la lista
(`$yaEstan`) y solo crea los que faltan (`whereNotIn('id', $yaEstan)`). El almuerzo ya estaba, así
que no se toca: solo se agrega "Entrada".

</details>

<details>
<summary>Nivel 3</summary>

Los creó la migración (decisión D13): por cada salida que tenía texto en "Vehículo" o un "Cupo
máximo", hizo un vehículo con esos datos y le asignó todas las reservas de esa salida. Al 21-oct-2026
eran dos: "combi 14 px" (salida 7) y "mini van" (salida 3).

</details>
