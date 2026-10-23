# Recorridos de la fila 13, fase 2c: órdenes de servicio, avisos y reprogramar la salida

Mismo formato que `02-mapa-del-sistema.md`. Continúa las guías 04 (la salida agrupa los tours) y
05 (servicios y vehículos).

Rutas de archivos abreviadas:
- `views/…`, `components/…`, `services/admin/…` → dentro de `admin-start-kit/src/`
- `Controllers/…`, `Services/…`, `Models/…` → dentro de `api-sistema-fe/app/`
- `routes/api.php`, `resources/…`, `tests/…` → dentro de `api-sistema-fe/`

## 0. La pieza central: `SalidaOperacionService`

`Services/AgenciaViajes/SalidaOperacionService.php` tiene tres funciones. Entenderlas explica casi
toda la 2c:

| Función | Qué hace en palabras |
|---|---|
| `foto($salidaId)` | saca una "foto" de la salida en este momento: cada servicio con su proveedor y su cantidad, cada vehículo con sus pasajeros. Si la salida ya no existe, devuelve `null` |
| `avisos($antes, $despues)` | compara dos fotos y escribe qué hay que decirle a cada proveedor: "El Mirador (Almuerzo): ahora son 2, no 4", "Van 14: queda vacío", "la salida ya no va", "queda por debajo del mínimo" |
| `ordenes($salida)` | arma la orden de servicio de cada proveedor, con el texto listo para WhatsApp (`mensaje`) y el enlace `wa.me` |

La técnica de "foto antes, foto después, comparar" sirve para cualquier cambio: se usa al cancelar
una reserva y, a su manera, al reprogramar la salida.

## 1. Órdenes de servicio (D14)

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Pantalla | `components/AgenciaViajes/SalidaOrdenesServicio.vue` → `cargar()`, `copiar()`, `pdf()` | dentro del detalle de la salida; botón "Preparar órdenes" |
| Servicio frontend | `salidaOperativaService.ordenes()` / `abrirOrdenPdf()` | `GET /salidas-operativas/{id}/ordenes` y `GET …/ordenes/{proveedorId}/pdf` (pedido como *blob* porque la ruta exige el token) |
| Ruta | `routes/api.php` | permiso `reservas.ver` o `reservas.ver_todas` |
| Controlador | `SalidaOperativaController::ordenes()` / `ordenPdf()` | solo salidas programadas o confirmadas |
| Servicio | `SalidaOperacionService::ordenes()` | junta los proveedores de los servicios y de los vehículos, uno por uno |
| PDF | `resources/views/pdf/agencia-viajes/orden-servicio.blade.php` | DomPDF; el logo con `StorageUrl::resolveParaPdf()` |

**Datos mínimos (Ley 29733):** el restaurante o las entradas reciben **cantidades** por tipo y la
alimentación especial ("1 vegetariano"), **sin nombres**. El transportista recibe, por vehículo,
las reservas con su titular y cuántos pasajeros van, **sin documentos**.

**Enlace de WhatsApp:** `enlaceWhatsapp()` deja solo los dígitos del teléfono del proveedor
(primero WhatsApp, si no el celular, si no el teléfono). Si es un celular peruano de 9 dígitos, le
pone el 51 delante, que es lo que exige `wa.me`.

## 2. Avisos al cancelar una reserva (D15)

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Pantalla | `views/agencia-viajes/reservas/detalle.vue` → `confirmarCancelacion()` | después de cancelar, si hay avisos, los muestra en una ventana "Avisa a los proveedores" |
| Ruta | `PUT /reservas/{id}/cancelar` → `ReservaController::cancelar()` | — |
| Antes | `$operacion->foto()` de cada salida de la reserva | — |
| Cancelar | igual que antes, más: **saca la reserva de los vehículos** (`salida_vehiculo_reservas`) | — |
| Después | `$operacion->avisos($antes, foto())` | la respuesta trae `avisos_salidas` |

Los avisos no se guardan en ninguna tabla. La bandeja de pendientes llega con la fila 11f (D7). Las
órdenes de servicio se arman en el momento, así que ya salen con la cantidad nueva.

## 3. Reprogramar la salida completa (D16, D17)

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Pantalla | `components/AgenciaViajes/SalidaReprogramar.vue` → `confirmar()` | nueva fecha, motivo y qué reservas se cancelan en vez de moverse |
| Servicio frontend | `salidaOperativaService.reprogramar()` | `POST /salidas-operativas/{id}/reprogramar` |
| Ruta | `routes/api.php` | permiso `reservas.editar` |
| Controlador | `SalidaOperativaController::reprogramar()` | ver abajo |

Qué hace `reprogramar()`, paso a paso:
1. Valida: la salida en curso, la fecha nueva desde hoy en adelante y distinta de la actual, y las
   reservas a cancelar tienen que estar en la salida.
2. Toma la `foto()` de la salida, para no perder lo configurado (la salida vieja se borra al quedar
   vacía, y con ella sus servicios y vehículos).
3. **Todo dentro de una transacción.** Si algo falla, no queda nada a medias. Por cada reserva:
   - si está marcada para cancelar → `ReservaController::cancelar()`;
   - si es **solo de ese día** → `ReservaController::reprogramar()`, que mueve la reserva completa;
   - si es **un viaje de varios días** → `moverServiciosDeLaSalida()`, que mueve solo sus servicios
     de esta salida, con fecha manual, y los vuelve a enganchar con `SalidaEngancheService`.
4. `llevarConfiguracion()` pasa a la salida nueva el guía, los proveedores y cantidades a mano de
   los servicios (que quedan "Por reservar", porque cambió la fecha) y los vehículos con sus
   reservas, si la salida nueva todavía no tenía vehículos.
5. Responde con los avisos para los proveedores ("pasa al 20/11/2026. Hay que volver a reservar").

`exigirOk()` es una ayuda: si `cancelar()` o `reprogramar()` de la reserva responde con error, corta
la transacción con ese mismo mensaje.

**¿Por qué existe `SalidaEngancheService`?** Antes, la regla de "a qué salida va un ítem" era una
función privada de `ReservaController`. Para usarla también desde `reprogramar()` de la salida, se
pasó a un servicio. El código es el mismo; solo cambió de lugar (guía 04, §1).

## 4. Hora y lugar de recojo (22-oct-2026)

El tour ya tenía su hora (`paquetes_plantilla.hora_salida`) y su lugar de recojo
(`paquetes_plantilla.lugar_recojo`), pero nadie los copiaba. Ahora:

| Dato | Dónde vive | Quién lo pone |
|---|---|---|
| Hora del tour | `salidas_operativas.hora` | `SalidaEngancheService::enganchar()`, al crear la salida (`firstOrCreate(..., ['hora' => $tour->hora_salida, ...])`); se cambia en el detalle de la salida (`salidas/index.vue` → `guardarDatosSalida()`) |
| Lugar de recojo | `salidas_operativas.lugar_recojo` | igual, desde `$tour->lugar_recojo` |
| Hora de recojo de una reserva | `reserva_items.hora` | a mano, en la pestaña Servicios de la reserva, solo si es distinta |

- **Reporte Operativo** (`ReporteOperativoController::armarFila()`): `$item->hora ?? hora de la salida`.
- **Orden de servicio** (`SalidaOperacionService::mensaje()` y el PDF): "a las 07:30" y "Recojo: …".
- **Reprogramar la salida** (`llevarConfiguracion()`): la hora y el lugar que el operador había
  dejado pasan a la salida nueva.
- Migración `2026_10_22_110000_add_lugar_recojo_y_hora_a_salidas.php`: agrega la columna y completa
  las salidas que ya existían, solo donde estaba vacío.

## 5. Una tarjeta por tour en la reserva

Un tour con IGV mezclado son varias líneas con la misma `carga_tour`. En la pestaña Servicios de
la reserva (`reservas/detalle.vue`) se ven como **una sola tarjeta**:
- `itemsPorTarjeta`: la lista sin repetir cargas;
- `lineasDeTarjeta(it)`: las líneas de esa carga;
- `guardarHoraDeTarjeta()`: la hora de recojo se guarda en todas las líneas;
- `quitarItem()`: quita todas las líneas del tour.

## 6. En Agencia, "exonerado" es "Amazonía"

Para SUNAT solo existe el código **20, exonerado**. El sistema guarda además un dato interno,
`destino_tributario` (amazonia, nacional, extranjero), que decide la leyenda de la Ley 27037 y la
confirmación antes de facturar.

**Regla de Agencia** (decidida con el usuario el 22-oct-2026): un servicio turístico solo está
exonerado porque se presta en la Amazonía, así que **20 ⇒ amazonia**.

| Dónde | Cómo |
|---|---|
| Modelos de Agencia | trait `Models/AgenciaViajes/Concerns/ExoneradoEsAmazonia.php`: antes de guardar, si el código es 20, pone `amazonia` |
| Pantallas de Agencia | `helpers/tratamientoTributarioAgencia.ts` → `destinoParaIgvAgencia()`: al elegir "Exonerado", el destino pasa a Amazonía y se bloquea |
| Datos viejos | migración `2026_10_22_100000_corregir_exonerado_nacional_a_amazonia.php` (registra cada fila en `ajustes_exonerado_amazonia`; su `down()` las devuelve) |
| Al cargar un tour | `TourLineaService::gruposDeIgv()` agrupa **solo por código SUNAT**: dos servicios exonerados son una línea |

**Retail no cambia.** En productos y ventas, "exonerado + nacional" **sí** es válido (por ejemplo,
alimentos del Apéndice I del IGV), así que la regla no se aplica a `products`, `sales` ni
`sale_details`, ni a `resolverTipAfeIgv()`.

## 7. Tests

Archivo: `tests/Feature/AgenciaViajes/Fila13Fase2cOperacionTest.php` (fixtures en
`Concerns/SalidasFixtures.php`).

| Regla | Test |
|---|---|
| Una orden por proveedor, sin nombres para el restaurante, enlace de WhatsApp | `test_una_orden_por_proveedor_con_datos_minimos_y_enlace_de_whatsapp` |
| El PDF se genera | `test_el_pdf_de_la_orden_se_genera` |
| Cancelar avisa y saca la reserva del vehículo | `test_cancelar_una_reserva_avisa_que_ajustar_y_la_saca_del_vehiculo` |
| Cancelar la última: "ya no va" | `test_cancelar_la_ultima_reserva_avisa_que_la_salida_ya_no_va` |
| Reprogramar lleva reservas y configuración | `test_reprogramar_la_salida_lleva_reservas_y_configuracion_a_la_nueva_fecha` |
| Reprogramar cancelando algunas | `test_reprogramar_puede_cancelar_algunas_reservas` |
| Viaje de varios días: solo se mueve el tour | `test_en_un_viaje_de_varios_dias_solo_se_mueve_el_tour` |
| No a una fecha pasada | `test_no_se_reprograma_a_una_fecha_pasada` |
| En Agencia, exonerado queda como Amazonía | `test_en_agencia_exonerado_siempre_queda_como_amazonia` |
| Dos servicios exonerados son una sola línea | `test_un_tour_con_dos_servicios_exonerados_es_una_sola_linea` |
| La salida nace con la hora y el lugar del tour | `test_la_salida_nace_con_la_hora_y_el_lugar_de_recojo_del_tour` |
| Reprogramar conserva hora y lugar | `test_reprogramar_conserva_la_hora_y_el_lugar_que_puso_el_operador` |

---

## Ejercicios

### Nivel 1
En `SalidaOperacionService::avisos()`, ¿qué texto sale cuando un vehículo se queda sin pasajeros?
Busca la palabra "vacío".

### Nivel 2
Una familia hace un viaje del 15 al 19 de noviembre, con el tour de Laguna Azul el 16. Llueve y la
agencia reprograma la salida del 16 al 18. ¿Qué pasa con la fecha de inicio del viaje de la
familia? ¿Por qué? Pista: mira la variable `$soloEseDia` en `reprogramar()`.

### Nivel 3
Corre el test de las órdenes y luego abre el test. Explica por qué existe esta línea:
```php
$this->assertStringNotContainsString('Juan Secreto', $mirador['mensaje']);
```

## Soluciones

<details>
<summary>Nivel 1</summary>

`"{$quien}: queda vacío."`. Por ejemplo: "Turismo Selva (Van 14): queda vacío.". Sale cuando antes
llevaba pasajeros y ahora lleva 0.

</details>

<details>
<summary>Nivel 2</summary>

El viaje sigue empezando el 15. Como la reserva no es solo de ese día (empieza el 15, no el 16),
`$soloEseDia` es falso y se usa `moverServiciosDeLaSalida()`: solo el tour pasa al 18, con fecha
manual. Si se hubiera usado `reprogramar()` de la reserva, se habrían corrido los cinco días del
viaje, y eso no es lo que pasó (solo se suspendió un tour).

</details>

<details>
<summary>Nivel 3</summary>

Comprueba la regla de datos mínimos (Ley 29733): el restaurante solo necesita cuántos van y qué
comen, no quiénes son. Si un cambio futuro metiera los nombres en el mensaje del restaurante, este
test fallaría y lo avisaría.

</details>
