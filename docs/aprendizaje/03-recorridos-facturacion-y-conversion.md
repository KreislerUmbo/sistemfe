# Recorridos de la fila 13, fase 1c: facturar tours y convertir tours

Esta guía sigue el formato del **mapa del sistema** (`02-mapa-del-sistema.md`): para cada acción,
el camino completo desde la pantalla hasta la tabla, y qué hace cada paso **en palabras**. Sirve
para rastrear un problema o entender el código sin leerlo entero.

Rutas de archivos abreviadas:
- `views/…`, `services/admin/…`, `components/…` → dentro de `admin-start-kit/src/`
- `Controllers/…`, `Services/…`, `Models/…` → dentro de `api-sistema-fe/app/`
- `routes/api.php`, `database/…`, `tests/…` → dentro de `api-sistema-fe/`

---

## 1. Vista previa de la factura de una reserva

Cuando el vendedor abre "Facturar" o "Facturación especial" en una reserva, **antes** de emitir
el sistema calcula qué entra en el comprobante y cuánto suma. No guarda nada.

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Pantalla | `views/agencia-viajes/reservas/detalle.vue` → `refrescarPreviewFacturaEspecial()` (o `refrescarPreviewFacturaSimple()`) | junta los pasajeros marcados y los ítems sueltos elegidos |
| Servicio frontend | `services/admin/reservaFacturacionService.ts` → `prepararFactura()` | `GET /reservas/{id}/preparar-factura` |
| Ruta | `routes/api.php` | `ReservaFacturacionController::prepararFactura`, permiso `reservas.ver` o `reservas.ver_todas` |
| Controlador | `Controllers/AgenciaViajes/ReservaFacturacionController.php` → `prepararFactura()` | valida y llama, en orden, a las funciones de abajo |
| ↳ | `resolverSeleccion()` | decide **qué ítems** van en esta factura (ver §3) |
| ↳ | `detectarMezclaTributaria()` | revisa el IGV: ¿se puede emitir así? (ver §2) |
| ↳ | `agruparPorCategoria()` | arma los grupos: TOUR, HOTEL, VUELO… y dentro de cada uno, uno por tipo de IGV |
| ↳ | `construirLineas()` | calcula subtotal, IGV y total de cada línea del comprobante |
| Respuesta | — | `grupos_propuestos`, `total`, `partes_tour`, `bloqueado_tributario`… La pantalla los muestra |

## 2. El guardia de IGV: ¿se puede emitir con Amazonía y nacional juntos?

Todo vive en `ReservaFacturacionController.php`:

| Función | Qué hace en palabras |
|---|---|
| `detectarMezclaTributaria()` | 1) si algún ítem es `extranjero` → bloquea. 2) si hay más de un destino (Amazonía y nacional) y **no** es el caso permitido → bloquea. 3) si algún ítem Amazonía no fue confirmado a mano → bloquea y lista cuáles (`items_sin_confirmar_ids`). Si nada de eso pasa, devuelve `null` = "se puede" |
| `mezclaDentroDeUnaCargaTour()` | el caso permitido (decisión D1): la mezcla está toda dentro de **un mismo tour** (misma `carga_tour`) y todo lo demás tiene un solo destino. Agrupa por `carga_tour`, cuenta cuántos tours mezclan destinos (tiene que ser exactamente 1) y revisa que el resto sea homogéneo |
| `resolverDestinoVenta()` | qué se guarda en `sales.destino`: `amazonia` si alguna línea lo es. Ese campo solo decide si el PDF imprime la leyenda de la Ley 27037 |
| `resolverDestinoTributario()` / `resolverTipAfeIgvItem()` | de dónde sale el destino y el IGV de un ítem: primero el del propio ítem de la reserva, si no el de su tarifa, si no el default |

**Confirmar un ítem Amazonía** (lo que pide el bloqueo 3): `detalle.vue` → botón de confirmar
tratamiento → `POST /reservas/{id}/override-tratamiento-tributario` →
`ReservaController::overrideTratamientoTributario()` → guarda `motivo_override_tributario` en
`reserva_items`.

## 3. Qué ítems entran en la factura de un grupo de pasajeros

`ReservaFacturacionController.php` → `resolverSeleccion()` recorre todos los ítems de la reserva
que todavía no se facturaron y separa tres tipos:

| Tipo de ítem | Cómo se reconoce | Qué hace |
|---|---|---|
| **Línea de tour** (modo nuevo) | `alternativaItem.origen_tipo === 'tour'` | va a `$itemsTour` y luego a `partesDeTour()` (§4): entra **sola**, con la parte de los pasajeros elegidos |
| Ítem con pasajeros asignados | tiene filas en `reserva_item_pasajero` | entra solo si **todos** sus pasajeros están en esta factura; si falta alguno, queda en `items_pendientes_por_pasajero_faltante` |
| Ítem sin pasajeros asignados | ninguna fila en `reserva_item_pasajero` | va a `items_sin_asignar_disponibles`; entra solo si el vendedor lo marca (`reserva_item_ids_manual`) |

Funciones de apoyo:
- `itemsYaFacturadosIds()`: los ítems que ya están en alguna factura (`reserva_ventas.reserva_item_ids`).
- `pasajerosYaFacturadosIds()`: los pasajeros que ya están en alguna factura. **Un pasajero solo
  puede estar en una factura**: esta es la protección contra cobrar dos veces.
- `ReservaVenta::itemsDeTourAMedias()` (`Models/AgenciaViajes/ReservaVenta.php`): líneas de tour
  cobradas solo en parte. Se descuentan de "ya facturados" para que sigan disponibles para el
  grupo siguiente.

## 4. Cuánto paga cada grupo de un tour

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Orquesta | `ReservaFacturacionController.php` → `partesDeTour()` | calcula `$pendientes` (los que pagan y no tienen factura), `$delGrupo` (los elegidos ahora) y `$cierra` (¿son todos los que faltaban?) |
| Lista de un grupo | `Services/AgenciaViajes/TourLineaService.php` → `precioDeLista()` | suma el precio de cada pasajero: adulto o niño, nacional o extranjero. El infante paga 0 |
| Parte del grupo | `TourLineaService.php` → `parteDelGrupo()` | vendido × lista del grupo ÷ lista de todos (regla de tres) |
| Último grupo | `partesDeTour()`, rama `if ($cierra)` | cobra "vendido − lo ya cobrado", para que la suma dé exacta |
| Suma en la línea | `construirLineas()` | usa la parte del grupo en vez del total de la línea (`$montoTour`) |
| Se guarda | `store()` → `ReservaVenta::create([... 'partes_tour' => ...])` | tabla `reserva_ventas`, columna `partes_tour`: `[{reserva_item_id, monto, cierra}]` |

De dónde salen los datos:
- precios por pasajero: `alternativa_items.tour_linea` → `precios` (lo guardó la 1b al cargar el tour);
- tipo y residencia de cada pasajero: `reserva_pasajeros.tipo_pax` y `reserva_pasajeros.residencia`.
  La residencia se copia de la cotización al aceptar: `ReservaController::crearReservaDesdeAlternativa()`.

## 5. Emitir la factura

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Pantalla | `detalle.vue` → `confirmarFacturacionEspecial()` (o `confirmarFacturacionSimple()`) | manda pasajeros, ítems sueltos, cliente y tipo de comprobante |
| Servicio frontend | `reservaFacturacionService.ts` → `facturar()` | `POST /reservas/{id}/facturar` |
| Ruta | `routes/api.php` | `ReservaFacturacionController::store`, permiso `reservas.editar` |
| Controlador | `store()` | repite todo lo de la vista previa **dentro de una transacción y con la reserva bloqueada** (`lockForUpdate()`), para que dos personas no facturen lo mismo a la vez |
| Modelos | `Sale::create()`, `SaleDetail::create()`, `SaleDetailItem::create()`, `ReservaVenta::create()` | la venta, sus líneas, qué ítem de la reserva va en cada línea, y el registro de la factura en la reserva |
| Tablas | `sales`, `sale_details`, `sale_detail_items`, `reserva_ventas` | — |

**Estado "parcial / total" de la reserva:** `ReservaController::calcularEstadoFacturacion()`.
Cuenta los ítems de facturas **aceptadas por SUNAT** y descuenta las líneas de tour cobradas a
medias.

## 5b. Dos comprobantes a clientes distintos por tipo de servicio (F1, 23-oct-2026)

Caso: los mismos pasajeros, pero los vuelos a un RUC y el resto de servicios a otro.

1. **Primera factura:** "Facturación especial" → todos los pasajeros, el cliente con RUC 20 y
   marcar solo los vuelos.
2. **Segunda factura:** como ya no quedan pasajeros pendientes, el modal entra en **modo "solo
   servicios"** (`soloServiciosPendientes` en `reservas/detalle.vue`): un aviso en lugar de la lista
   de pasajeros, el cliente con RUC 10 y marcar el resto. Por detrás manda todos los pasajeros
   (`pasajeroIdsParaFacturarSimple()`).

En el backend (`resolverSeleccion()`): si **todos** los pasajeros pedidos ya están facturados, se
acepta aunque no venga ningún ítem. Así la vista previa puede listar lo que queda. Lo que se
rechaza: **mezclar** pasajeros ya facturados con pendientes. Y emitir una factura sin ningún ítem
lo bloquea `store()`.

**A nombre de quién sale** (`components/AgenciaViajes/ClienteComprobanteSelector.vue`): en
"Facturación especial" el cliente está arriba y siempre visible, aunque haya un bloqueo
tributario, y el pie dice "Se emitirá a: …". Los pasajeros marcados deciden **qué** entra; el
cliente decide **a nombre de quién**. En "Facturar" (simple) siempre es el cliente de la
cotización, y el modal lo dice arriba.

**Qué entra y cuánto** (`components/AgenciaViajes/DetalleComprobantePreview.vue`): la vista previa
trae `detalle`, armado por `ReservaFacturacionController::detallePreview()` **antes** del guardia
tributario, así que se ve aunque haya un bloqueo. Una fila por servicio (un tour con varias líneas
de IGV es una fila); un tour cobrado por grupo dice "Parte de 2 de 4 pasajeros — del total del
tour S/ 522.00". Debajo: cómo sale en la factura (`grupos_propuestos`, con su tipo de IGV) y, en
"Facturación especial", los anticipos marcados y el saldo a cobrar.

**Anticipo a nombre de otro cliente:** `ReservaAnticipoController::store()` acepta `client_id`
opcional (vacío = el cliente de la cotización; la moneda sigue saliendo de la reserva). Importa
porque un anticipo **solo se descuenta en una factura del mismo cliente**
(`AdvanceApplicationService`): si la reserva se facturará a una empresa, el anticipo tiene que ir
a su RUC. Pantalla: "Cobrar anticipo" en `reservas/detalle.vue`.

Tests: `test_f1_segunda_factura_con_los_mismos_pasajeros_para_el_resto_de_servicios`,
`ReservaAnticipoTest::test_store_permite_elegir_el_cliente_del_anticipo` y
`test_f1_pasajeros_ya_facturados_mezclados_con_pendientes_siguen_rechazados`
(`tests/Feature/AgenciaViajes/Fila13Fase1cFacturacionTest.php`).

## 6. Convertir tours al modelo nuevo

Pantalla nueva: Paquetes / Tours → botón **"Convertir tours"**.

| Paso | Archivo → función | Qué pasa |
|---|---|---|
| Pantalla | `views/agencia-viajes/paquetes/conversion.vue` → `cargarLista()`, `seleccionar()`, `confirmar()` | lista de tours, propuesta del elegido, botón Confirmar |
| Servicio frontend | `services/admin/paquetePlantillaService.ts` → `conversionToursService.listar()` / `propuesta()` / `confirmar()` | `GET /tours-conversion`, `GET /tours-conversion/{id}`, `POST /tours-conversion/{id}/confirmar` |
| Ruta | `routes/api.php` | `ConversionToursController@index/show/confirmar`, permiso `agencia.paquetes` |
| Controlador | `Controllers/AgenciaViajes/ConversionToursController.php` | valida (precio > 0, IGV válido) y llama al servicio |
| Servicio | `Services/AgenciaViajes/ConversionToursService.php` | ver abajo |
| Modelos | `PaquetePlantilla`, `PaquetePlantillaItem` | — |
| Tablas | `paquetes_plantilla` (precio, `conversion_confirmada_en`/`_por`), `paquete_plantilla_items` (costo e IGV de cada servicio) | — |

Las funciones de `ConversionToursService.php`, en palabras:

| Función | Qué hace |
|---|---|
| `listar()` | reutiliza el diagnóstico de la 1a (`DiagnosticoToursService::diagnosticar()`), deja solo los tours simples de venta y agrega si ya se confirmaron. Cuenta aparte los combos y los "solo itinerario" |
| `propuesta()` | **solo lee.** Precio: el del tour, o la suma de ventas de sus tarifas (+ ajuste de redondeo). Costo de cada servicio: el guardado, o el que sugiere la calculadora (`TourCostoEstimadoService::calcular()`). IGV de cada servicio: el guardado, o **el de su tarifa**. Arma los avisos |
| `confirmar()` | **lo único que escribe.** Calcula el IGV final de cada servicio; si el tour mezcla IGV y falta algún costo → error 422 sin guardar nada. Si todo está bien, guarda en una transacción y marca `conversion_confirmada_en` |
| `exigirConvertible()` | rechaza combos y tours "solo itinerario" |

**Ojo con el IGV vacío:** en "Incluye y costos", la opción "Del tour (…)" se guarda como **vacío**.
La conversión no puede distinguir un vacío elegido a propósito de uno que nadie revisó, así que
propone el IGV de la tarifa. Para fijar un IGV, elige la opción explícita (por ejemplo
"Exonerado (Amazonía)").

## 7. Tests que describen estas reglas

Archivo: `tests/Feature/AgenciaViajes/Fila13Fase1cFacturacionTest.php`. Cada test es una regla
escrita como ejemplo. Para correr uno: `php artisan test --filter=nombre_del_test`.

| Regla | Test |
|---|---|
| Tour mezclado en una factura, una línea por IGV | `test_tour_con_igv_mezclado_sale_en_un_comprobante_con_una_linea_por_igv` |
| Sueltos de distinto destino siguen bloqueados | `test_servicios_sueltos_de_distinto_destino_siguen_bloqueados` |
| Un combo no cuenta como un solo tour (D5) | `test_combo_de_un_tour_exonerado_y_otro_gravado_no_cuenta_como_una_carga` |
| Rojas S/ 270 y Pérez S/ 252 | `test_familias_rojas_y_perez_facturan_aparte_su_parte_del_tour_con_precio_a_mano` |
| Centavos al último grupo | `test_el_ultimo_grupo_se_lleva_los_centavos_del_redondeo` |
| Reserva "parcial" con un tour a medias | `test_reserva_con_un_tour_cobrado_a_medias_queda_parcial` |
| La propuesta de conversión no escribe nada | `test_la_propuesta_no_escribe_nada_y_toma_el_igv_y_el_costo_de_la_tarifa` |
| Tour mezclado sin costo no se convierte | `test_confirmar_un_tour_mezclado_con_un_servicio_sin_costo_no_guarda_nada` |

---

## Ejercicios

### Nivel 1: encontrar
1. ¿Qué permiso hace falta para **emitir** la factura de una reserva, y cuál para solo **ver la
   vista previa**? Búscalo en `routes/api.php`.
2. ¿En qué tabla y columna queda guardado cuánto pagó cada familia de un tour?

### Nivel 2: rastrear
Un vendedor dice: "elegí a dos pasajeros y la factura no trae el tour". Siguiendo §3 y §4, ¿en qué
función mirarías primero y qué condición haría que la línea de tour no entre?

### Nivel 3: comprobar con un test
Corre `php artisan test --filter=test_el_ultimo_grupo_se_lleva_los_centavos_del_redondeo`. Abre el
test y explica por qué la tercera factura da 33.34 y no 33.33.

## Soluciones

<details>
<summary>Nivel 1</summary>

1. Emitir: `reservas.editar` (`POST reservas/{id}/facturar`). Vista previa: `reservas.ver` o
   `reservas.ver_todas` (`GET reservas/{id}/preparar-factura`).
2. Tabla `reserva_ventas`, columna `partes_tour` (una fila por factura; dentro, el monto por línea
   de tour).

</details>

<details>
<summary>Nivel 2</summary>

En `partesDeTour()`. La línea no entra si `$delGrupo` queda vacío, o sea, si ninguno de los
pasajeros elegidos está pendiente de pago: todos son infantes, o ya fueron facturados antes. La
línea tampoco aparece si ya se cobró completa, porque sale de `itemsYaFacturadosIds()` y no está en
`itemsDeTourAMedias()`.

</details>

<details>
<summary>Nivel 3</summary>

Son 3 adultos con lista S/ 150, vendidos en S/ 100. Cada uno de los dos primeros paga
`parteDelGrupo(150, 450, 100)` = 33.333… → **33.33**. El tercero cierra la línea (`$cierra = true`)
y paga `100 − (33.33 + 33.33)` = **33.34**. Si los tres pagaran 33.33, faltaría un centavo.

</details>
