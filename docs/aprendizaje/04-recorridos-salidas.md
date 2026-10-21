# Recorridos de la fila 13, fase 2a: la salida agrupa los tours

Mismo formato que `02-mapa-del-sistema.md`: para cada acción, el camino de la pantalla a la tabla
y qué hace cada paso **en palabras**.

Rutas de archivos abreviadas:
- `views/…`, `services/admin/…`, `types/…` → dentro de `admin-start-kit/src/`
- `Controllers/…`, `Services/…`, `Models/…` → dentro de `api-sistema-fe/app/`
- `routes/api.php`, `database/…`, `tests/…` → dentro de `api-sistema-fe/`

## 0. La idea en una frase

Una **salida operativa** es "el tour Laguna Azul del 15 de noviembre". Junta a todas las reservas
que hacen ese tour ese día, para operarlas juntas: un guía, un vehículo, un estado. Desde la 2a,
las **líneas de tour** del modo nuevo también se juntan solas en su salida.

| Tour | Qué salida le toca |
|---|---|
| `compartido` (lo normal) | la del tour y la fecha, **compartida** con otras reservas |
| `privado` | una salida **propia de la reserva** (`privada_reserva_id`), aunque otra reserva haga el mismo tour ese día |

La modalidad sale del tour: `paquetes_plantilla.modalidad`.

## 1. Al aceptar una reserva, cada tour cae en su salida

No hay botón: pasa solo al aceptar la alternativa.

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Aceptar | `Controllers/AgenciaViajes/ReservaController.php` → `crearReservaDesdeAlternativa()` | crea la reserva, sus pasajeros (con `residencia`) y sus ítems |
| Por cada ítem | `crearReservaItemDesdeAlternativaItem()` | copia el ítem y llama a `engancharSalidaOperativa()` |
| Enganche | `engancharSalidaOperativa()` | **línea de tour:** mira la modalidad del tour; si es privado, la clave lleva la reserva. **Ítem de proveedor compartido:** como antes. Cualquier otro ítem: no se engancha |
| Buscar o crear | `SalidaOperativa::firstOrCreate($clave, ['estado' => 'programada'])` | la clave es tour + fecha + reserva privada (o `null` si es compartida) |
| Tabla | `salidas_operativas`, y `reserva_items.salida_operativa_id` | — |

**Por qué no se duplican salidas:** hay dos índices únicos en Postgres (migración
`2026_10_21_100000_salidas_operativas_privadas_y_estados.php`):
- compartidas: un solo registro por (tour, fecha);
- privadas: un solo registro por (tour, fecha, reserva).

Si dos personas aceptan a la vez, la base rechaza la segunda inserción, y el `catch` de
`engancharSalidaOperativa()` busca la salida que ya existe.

Un tour con IGV mezclado son dos líneas (misma `carga_tour`). Las dos caen en la misma salida
porque comparten tour, fecha y reserva.

## 2. Reprogramar una reserva mueve su tour de salida (C4)

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Pantalla | `views/agencia-viajes/reservas/detalle.vue` → botón "Reprogramar viaje" | — |
| Ruta | `POST /reservas/{id}/reprogramar` → `ReservaController::reprogramar()` | — |
| Por cada ítem que cambia de fecha | desengancha de la salida vieja → `SalidaOperativa::eliminarSiQuedoVacia()` → `engancharSalidaOperativa()` con la fecha nueva | la salida vieja se borra solo si quedó sin nadie |

No hubo que escribir nada nuevo para esto: reprogramar ya usaba la misma función de enganche.

## 3. El tablero de salidas

Menú Agencia → **Salidas Operativas**.

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Pantalla | `views/agencia-viajes/salidas/index.vue` → `list()`, `abrirDetalle()` | lista por rango de fechas y estado; el detalle muestra pasajeros |
| Servicio frontend | `services/admin/salidaOperativaService.ts` → `listar()`, `ver()` | `GET /salidas-operativas`, `GET /salidas-operativas/{id}` |
| Ruta | `routes/api.php` | permiso `reservas.ver` o `reservas.ver_todas` |
| Controlador | `Controllers/AgenciaViajes/SalidaOperativaController.php` → `index()`, `show()` | carga las relaciones (`RELACIONES`) y arma el resumen |
| Resumen | `resumenSalida()` | ver abajo |

Qué calcula `resumenSalida()`, en palabras:
- toma las reservas enganchadas, **sin las canceladas**, una vez cada una;
- junta sus pasajeros y los cuenta por tipo (`adultos`, `ninos`, `infantes`, `extranjeros`);
- **mínimo para salir:** `pax_que_cuentan` = todos menos los infantes, comparado con
  `tourOrigen.pax_minimo_salida`. Si no llega, `bajo_minimo = true`, y el tablero lo pinta en rojo
  ("4 / 6 mín.");
- en el detalle agrega la lista de pasajeros (nombre, tipo, residencia, alimentación, reserva).

## 4. Estados de la salida (D9)

```
programada ──► confirmada ──► realizada
     │              │
     └──────────────┴──► cancelada   (endpoint propio, no vuelve atrás)
```

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Pantalla | `salidas/index.vue` → `avanzarEstado()`, `volverEstado()`, `confirmarCancelar()` | botones "Confirmar salida", "Marcar realizada", "Volver a…", "Cancelar salida" |
| Servicio frontend | `salidaOperativaService.cambiarEstado()` / `cancelar()` | `PUT /salidas-operativas/{id}/estado` / `PUT …/cancelar` |
| Ruta | `routes/api.php` | permiso `reservas.editar` |
| Controlador | `SalidaOperativaController::cambiarEstado()` | tabla `$permitidos`: desde cada estado, a cuáles puede pasar. "Realizada" exige que la fecha ya haya llegado (`HoraPeru::hoy()`) |
| Controlador | `SalidaOperativaController::cancelar()` | no cancela una salida realizada. **No toca las reservas**: cada una se resuelve a mano |

Las constantes de los estados están en `Models/AgenciaViajes/SalidaOperativa.php`
(`ESTADO_PROGRAMADA`…, `ESTADOS_EN_CURSO`, `scopeEnCurso()`).

## 5. Enganchar o soltar a mano

| Acción | Función | Reglas |
|---|---|---|
| Enganchar | `SalidaOperativaController::attachReservaItem()` | la salida tiene que estar en curso; una línea de tour solo va a una salida de **su** tour, y a una privada solo si es de su reserva; no se engancha nada de una reserva cancelada ni algo ya facturado |
| Soltar | `detachReservaItem()` | si la salida queda vacía, se borra |
| Ambas | `itemsDeLaMismaCarga()` | las líneas de un tour con IGV mezclado se mueven **juntas**: en la operación son un solo tour |

## 6. "Sin asignar" en el Reporte Operativo y en el Inicio

`Services/AgenciaViajes/AsignacionOperativa.php` → `sinAsignar()` decide si un servicio está
pendiente de asignar. Para una **línea de tour**:
- pendiente si no está en ninguna salida, o si su salida se canceló;
- pendiente si el tour **lleva guía** en su Incluye (`tourLlevaGuia()`) y la salida todavía no
  tiene guía;
- en cualquier otro caso, asignada. Los vehículos y proveedores por salida llegan en la fase 2b.

El **Inicio** (`Services/Dashboard/DashboardService.php` → `proximos()`) lista las salidas
**en curso** de los próximos días, con su estado y el aviso "Bajo el mínimo". Pantalla:
`views/dashboards/analytics/index.vue`.

## 7. Tests que describen estas reglas

Archivo: `tests/Feature/AgenciaViajes/Fila13Fase2aSalidasTest.php`.

| Regla | Test |
|---|---|
| Tour compartido: dos reservas, una salida; pasajeros y mínimo | `test_tour_compartido_dos_reservas_van_a_la_misma_salida_con_sus_pasajeros` |
| Tour privado: una salida por reserva | `test_tour_privado_cada_reserva_tiene_su_propia_salida` |
| Reprogramar mueve el tour de salida (C4) | `test_reprogramar_mueve_la_linea_de_tour_a_la_salida_de_la_nueva_fecha` |
| Estados de a un paso; realizada no puede ser futura | `test_estados_avanzan_un_paso_y_realizada_no_puede_ser_futura` |
| Cancelada no vuelve | `test_una_salida_cancelada_no_vuelve_por_cambiar_estado` |
| Un tour no va a la salida de otro | `test_una_linea_de_tour_no_se_engancha_a_la_salida_de_otro_tour` |
| Tour mezclado: se suelta entero | `test_las_lineas_de_un_tour_mezclado_se_desenganchan_juntas` |
| Sin asignar por falta de guía | `test_linea_de_tour_sin_asignar_si_el_tour_lleva_guia_y_la_salida_no_tiene` |

---

## Ejercicios

### Nivel 1: encontrar
1. ¿Qué permiso hace falta para confirmar una salida? Búscalo en `routes/api.php` (`/estado`).
2. ¿En qué columna de qué tabla se sabe a qué salida pertenece cada servicio de una reserva?

### Nivel 2: rastrear
Una agencia dice: "aceptamos dos reservas del mismo tour para el mismo día y salieron en **dos**
salidas distintas, no en una". Siguiendo §1, ¿qué dato del tour revisarías primero y por qué?

### Nivel 3: comprobar en tinker
Con `agencia-demo`, cuenta las salidas por estado:
```php
tenancy()->initialize(App\Models\Tenant::find('agencia-demo'));
App\Models\AgenciaViajes\SalidaOperativa::selectRaw('estado, count(*) n')->groupBy('estado')->get();
```
¿Por qué no aparece ninguna `activa`?

## Soluciones

<details>
<summary>Nivel 1</summary>

1. `reservas.editar` (`PUT salidas-operativas/{id}/estado`).
2. `reserva_items.salida_operativa_id`.

</details>

<details>
<summary>Nivel 2</summary>

La **modalidad** del tour (`paquetes_plantilla.modalidad`). Si está en `privado`,
`engancharSalidaOperativa()` pone la reserva en la clave (`privada_reserva_id`) y cada reserva
recibe su propia salida. Eso es correcto para un tour privado; si el tour en realidad es
compartido, hay que corregir su modalidad en el catálogo.

</details>

<details>
<summary>Nivel 3</summary>

Porque la migración `2026_10_21_100000_salidas_operativas_privadas_y_estados.php` cambió todas
las `activa` a `programada` (decisión D9), y la base ya no acepta `activa`: hay un `CHECK` con los
cuatro estados nuevos. Al 21-oct-2026 daba 11 programadas y 1 cancelada.

</details>
