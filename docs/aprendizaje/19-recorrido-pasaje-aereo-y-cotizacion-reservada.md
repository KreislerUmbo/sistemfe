# Recorrido: pasaje aéreo por pasajero y cotización reservada en solo lectura (ola 4b)

01-nov-2026, tareas **T-076**, **T-075** y **T-074** (unidas a main en `f546b57`, `2792202` y el
merge de `feat/T-074-cotizacion-reservada-solo-lectura`; las tareas se borraron al cerrar, git las
conserva). Salieron de la prueba de «Modificar reserva» (T-007).

Las tres tocan la misma pantalla, el **cotizador** (`cotizador/editar.vue`), y dos de ellas el
**pasaje aéreo suelto** (`PasajeAereoForm.vue`). Se leen en orden: primero cuánto cuesta un pasaje
(T-076), después a quién cubre (T-075), y al final qué se puede tocar una vez reservado (T-074).

---

## 1. Los problemas

1. **El precio del pasaje salía mal con 2 o más pasajeros (T-076).** La tarifa base se
   multiplicaba por pasajero, pero los cargos (impuestos, TUA) y el fee de agencia se sumaban una
   sola vez por boleto. Si marcabas 2 pasajeros en vez de 1, la tarifa se duplicaba y los cargos
   no. La aerolínea da los impuestos por pasajero.
2. **Un boleto podía quedarse sin pasajeros (T-075).** Al borrar al único pasajero de un pasaje,
   su lista `pax_incluidos` quedaba vacía `[]`, y varios servicios leen `[]` como «todos»: el
   boleto pasaba a cubrir a todos con el precio que tenía.
3. **La cotización reservada parecía editable (T-074).** No se veía qué alternativa se eligió, el
   pasaje y el ítem manual decían «No editable» sin dejar ver su detalle, y el precio o los
   descuentos se podían tipear: el backend los rechazaba, pero el valor quedaba en pantalla como si
   se hubiera guardado.

## 2. Archivos

| Archivo | Tarea | Qué cambió |
|---|---|---|
| `database/migrations/tenant/verticals/agencia-viajes/2026_11_01_100000_add_montos_por_pasajero_to_cotizacion_pasaje_aereo.php` | T-076 | **nuevo**: columna `montos_por_pasajero` (false por defecto) |
| `app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` | T-076 | `calcularPasajeAereo()`, `validarPasajeAereo()`, `actualizarPasajeAereo()`, `crearItemPasajeAereo()` |
| `app/Models/AgenciaViajes/CotizacionPasajeAereo.php` | T-076 | la marca en `$fillable` y `$casts` |
| `app/Services/AgenciaViajes/AlternativaCopiaService.php` | T-076 | copia la marca al duplicar o modificar |
| `app/Http/Controllers/AgenciaViajes/CotizacionController.php` | T-075, T-074 | `pasajesQueQuedanSinPasajeros()`; `index()` y `show()` traen la reserva |
| `app/Models/AgenciaViajes/Cotizacion.php` | T-074 | `alternativaReservada()` |
| `admin-start-kit/src/components/AgenciaViajes/PasajeAereoForm.vue` | T-076, T-074 | cargos por pasajero, «ya tiene pasaje», prop `soloLectura` |
| `admin-start-kit/src/components/AgenciaViajes/ItemManualForm.vue` | T-074 | prop `soloLectura` |
| `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue` | T-076, T-074 | pasa los ítems al formulario; `alternativaSoloLectura` |
| `admin-start-kit/src/views/agencia-viajes/cotizador/index.vue` | T-074 | alternativa y código de reserva en el listado |
| `admin-start-kit/src/types/agencia-viajes.ts` | T-076, T-074 | tipos nuevos |

## 3. El recorrido

### 3.1 Cuánto cuesta un pasaje aéreo (T-076)

```
Cotizador → «Pasaje aéreo suelto» → PasajeAereoForm.vue
   │  cada cambio (400 ms de espera) ──► POST alternativas/{id}/pasaje-aereo/preview
   │                                       └─ previewPasajeAereo() → calcularPasajeAereo()   (no guarda)
   │
   └─ «Agregar» ──► POST alternativa-items (store) → crearItemPasajeAereo()
                       ├─ validarPasajeAereo()          ← pax_incluidos vacío = todos (nunca [])
                       ├─ calcularPasajeAereo()         ← la fórmula
                       └─ cotizacion_pasaje_aereo.montos_por_pasajero = true
```

**La fórmula** (`AlternativaItemController::calcularPasajeAereo()`, ~línea 1514):

```
tarifa = adultos × tarifa_adulto + niños × tarifa_niño + infantes × tarifa_infante

si montos_por_pasajero:
    cada cargo = monto × (adultos + niños)            si «no aplica a infantes»
               = monto × (adultos + niños + infantes)  si no
    fee        = fee × (adultos + niños + infantes)    ← el infante sí paga fee
si no (pasaje anterior a T-076):
    cargos y fee se suman una vez, como antes

costo = tarifa + cargos          venta = costo + fee     (PriceEngineService, margen 'fijo')
```

Ejemplo (CA1): 2 adultos, tarifa 100, TUA 20, fee 10 → costo 200 + 40 = **240**, venta 240 + 20 = **260**.

**¿Por qué una marca y no cambiar la fórmula para todos?** Los pasajes ya guardados tenían sus
montos cargados «para el grupo». Con la fórmula nueva, un TUA de 40 (ya totalizado para 2) pasaría a
80 y la cotización enviada al cliente cambiaría de precio sola. Por eso la columna nace en `false`
para todo lo existente, y solo los pasajes nuevos llevan `true`.

**Editar un pasaje viejo** (`actualizarPasajeAereo()`): si el formulario no manda la marca, se
conserva la del pasaje (`??=`). El formulario muestra el check «Calcular por pasajero» **solo** en un
pasaje viejo: al marcarlo hay que volver a cargar los montos, porque los guardados son del grupo.

**«Ya tiene pasaje»** (`PasajeAereoForm.vue`, `aerolineasPorPasajero`, ~línea 257): recorre los
otros pasajes de la alternativa y arma un mapa pasajero → aerolíneas. Un pasaje con lista vacía
cubre a todos. `paxPorDefecto()` marca, en un pasaje nuevo, solo a quienes aún no tienen pasaje (y
si todos tienen, a todos). Solo se muestra: repetir un pasajero no se bloquea (ida y vuelta en dos
aerolíneas es normal).

### 3.2 Un boleto nunca se queda sin pasajeros (T-075)

```
Cotizador → Pasajeros → quitar a un pasajero → «Guardar»
   └─► PUT cotizaciones/{id}/pasajeros → CotizacionController::actualizarPasajeros()
          ├─ ¿la cotización ya tiene alternativa aceptada? → 422 (de antes)
          ├─ idsEliminados = existentes − los que vienen
          ├─ pasajesQueQuedanSinPasajeros()   ← NUEVO, antes de escribir nada
          │     busca pasajes aéreos (alternativas no descartadas ni reemplazadas)
          │     cuya lista quedaría vacía → 422 «el pasaje Latam de «Alternativa 1» es de pasajero 2 (adulto)…»
          └─ transacción: actualiza/borra pasajeros, limpiarPaxIncluidosEliminados(), recalcula
```

La condición es `array_diff($item->pax_incluidos, $idsEliminados) === []`: si después de sacar a
los borrados no queda nadie, se bloquea. Un pasaje que todavía conserva a otro pasajero se guarda
sin el borrado (CA2). Los pasajes viejos con `[]` (que ya cubren a todos) no bloquean.

Como «Modificar reserva» edita los pasajeros de su copia por la misma ruta, el bloqueo vale también
ahí: para quitar a un pasajero con boleto propio, primero se quita o reasigna el boleto.

### 3.3 La cotización reservada en solo lectura (T-074)

```
Cotizador (editar.vue) ──► GET cotizaciones/{id} → show()
                              └─ precarga RELACIONES_ESTADO_RESUMEN:
                                 alternativas.reserva, alternativas.modificacionAplicada.reserva

alternativaSoloLectura = alternativa activa 'aceptada' o 'reemplazada'
                         Y la pantalla no es el borrador de una modificación abierta
   ├─ oculta: Agregar servicio, Pasaje aéreo suelto, Agregar otro día, ✕ eliminar, Marcar como elegida, mover de día
   ├─ deshabilita: precio, descuento por ítem, descuento global
   ├─ aviso: «Alternativa reservada: para cambiarla usa Modificar reserva — RDKM-…»
   └─ «Ver detalle» en el pasaje y el ítem manual → mismo formulario con soloLectura
                                                     (<fieldset disabled>, sin botón de guardar)
```

**¿A qué reserva enlaza la pestaña?** `reservaDeAlternativa()`: si está `aceptada`, a su reserva;
si quedó `reemplazada` por una modificación, a la reserva de la modificación que la reemplazó
(`modificacion_aplicada.reserva`).

**¿Por qué no bloquea el borrador de una modificación?** `modificacionInfo` solo existe cuando abres
la cotización borrador de una modificación (que es otra cotización). Ahí se trabaja precisamente
para cambiar la reserva. En la cotización original, `modificacionInfo` es null y la alternativa
reservada sigue bloqueada aunque haya una modificación abierta.

**Un solo computed.** Antes `lineaTourBloqueada()` miraba solo `estado === 'aceptada'`; ahora usa
`alternativaSoloLectura`, así que un tour de una alternativa reemplazada también queda bloqueado.
Un único lugar decide «¿se puede tocar?», y el resto lo consulta.

**El listado** (`index.vue`): `Cotizacion::alternativaReservada()` toma la alternativa aceptada (o,
si no hay, la reemplazada) de las relaciones ya cargadas para `estadoResumen()`: sin consultas extra
por fila.

**Importante:** todo esto es pantalla. El backend ya rechazaba los cambios sobre una alternativa
aceptada; T-074 evita que el usuario crea que algo se guardó.

## 4. Tests

| Test | Criterio |
|---|---|
| `PasajeAereoPorPasajeroTest::test_ca1_cargos_y_fee_se_multiplican_por_los_pasajeros_marcados` | T-076 CA1 |
| `…::test_ca2_cargo_que_no_aplica_a_infantes_no_se_cobra_por_el_infante` | T-076 CA2 |
| `…::test_cargo_sin_la_marca_se_cobra_tambien_al_infante` | T-076 |
| `…::test_ca3_pasaje_anterior_conserva_su_formula_al_editarlo` | T-076 CA3 |
| `…::test_pasaje_anterior_pasa_a_la_formula_nueva_solo_si_se_pide` | T-076 |
| `…::test_ca4_duplicar_la_alternativa_copia_la_marca` | T-076 CA4 |
| `…::test_ca6_pasaje_viejo_con_cargo_fee_agencia_se_guarda_sin_error` | T-076 CA6 |
| `AlternativaItemPasajeAereoTest::test_t075_borrar_al_unico_pasajero_de_un_pasaje_responde_422_y_no_toca_nada` | T-075 CA1 |
| `…::test_t075_si_al_pasaje_le_queda_otro_pasajero_se_guarda_y_lo_conserva` | T-075 CA2 |
| `…::test_t075_un_pasaje_de_una_alternativa_descartada_no_bloquea` | T-075 |
| `…::test_editar_sin_pax_incluidos_cubre_a_todos_nunca_queda_vacio` | T-075 CA4 |
| `CotizacionEstadoResumenTest::test_alternativa_reservada_trae_nombre_y_codigo_sin_consultas_extra` | T-074 CA3 |
| `…::test_alternativa_reservada_es_null_sin_reserva` | T-074 |

El resto de T-074 (pestaña, solo lectura, «Ver detalle», borrador editable) se probó en pantalla:
Playwright en agencia-demo y prueba del usuario.

## 5. Para desplegar

T-076 trae una migración: correr `php artisan tenants:migrate-verticales` y revisar su salida
(`deploy.sh` sigue aunque falle). T-075 y T-074 no tienen migraciones.

## 6. Ejercicios

1. Un pasaje de 2 adultos y 1 infante: tarifa adulto 100, infante 10, TUA 20 con «no aplica a
   infantes», impuesto 5 sin la marca, fee 10. Calcula costo y venta con la fórmula de 3.1, y
   compruébalo copiando `test_ca2_…` con estos números.
2. ¿Qué pasaría con las cotizaciones ya enviadas si la migración hubiera puesto
   `montos_por_pasajero` en `true` por defecto? Busca un pasaje viejo en agencia-demo y haz la cuenta.
3. En `pasajesQueQuedanSinPasajeros()`, ¿por qué se excluyen las alternativas `descartada` y
   `reemplazada`? Piensa qué pasaría al quitar un pasajero en una cotización con una alternativa
   descartada que tenía su boleto.
4. En `editar.vue`, busca `alternativaSoloLectura` y cuenta en cuántos lugares se usa. ¿Qué
   ventaja tiene sobre repetir `alternativaActiva.estado === 'aceptada'` en cada botón?
