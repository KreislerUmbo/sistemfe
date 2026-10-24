# Recorrido: céntimos del tour, confirmar encima del modal y elegir servicios con casillas

24-oct-2026. Tres cosas que el usuario encontró probando la facturación de la reserva
RDKM-1026-0000012 (2 pasajeros, tours Alto Mayo y Laguna Azul de S/ 300 cada uno).

**Archivos nuevos:** ninguno. Todo son funciones nuevas o cambiadas en archivos que ya existían.

| Archivo | Qué cambió |
|---|---|
| `api-sistema-fe/app/Services/AgenciaViajes/TourLineaService.php` | nuevas `parteExacta()` y `repartirCentavos()` |
| `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php` | `partesDeTour()` redondea por tour; nuevas `separarExcluidos()` y `filasDetalle()`; parámetro `reserva_item_ids_excluidos` |
| `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue` | el modal de confirmar va encima; avisa al modal de facturar que recalcule |
| `admin-start-kit/src/components/AgenciaViajes/FacturarReservaSimpleModal.vue` y `FacturarReservaEspecialModal.vue` | `defineExpose({ refrescarPreview })`; en el especial, `alternarServicio()` y `hayServiciosQueEntran` |
| `admin-start-kit/src/components/AgenciaViajes/DetalleComprobantePreview.vue` | opción `seleccionable`: una casilla por servicio |
| `admin-start-kit/src/services/admin/reservaFacturacionService.ts` | `excluido` en el detalle; `reserva_item_ids_excluidos` al pedir la vista previa y al emitir |
| `api-sistema-fe/tests/Feature/AgenciaViajes/Fila13Fase1cFacturacionTest.php` | 4 tests nuevos |
| `api-sistema-fe/tests/Feature/AgenciaViajes/ReservaAceptarConcurrencyTest.php` | limpia también permisos y auditoría (ver el final de la sección 4) |

---

## 1. El céntimo de más (150.01)

### El problema

El tour Alto Mayo mezcla IGV, así que en la reserva son **dos líneas**: 203.23 (exonerado) y
96.77 (gravado). Para un pasajero de dos, cada línea se partía y redondeaba por separado:

```
203.23 ÷ 2 = 101.615 → 101.62
 96.77 ÷ 2 =  48.385 →  48.39
                        ──────
                        150.01   ← las dos subieron un céntimo
```

El otro pasajero pagaba 149.99 (la última factura cobra "lo que falta"): el total cuadraba, pero
se veía mal.

### La corrección: redondear el tour, no cada línea

```
101.615 + 48.385 = 150.000   → total redondeado: 150.00
primera línea: 101.62
última línea:  150.00 − 101.62 = 48.38      (se lleva la diferencia)
```

### Las funciones

- **`TourLineaService::parteExacta($listaGrupo, $listaTotal, $vendido)`**: la parte del grupo
  **sin redondear** (`vendido × lista del grupo ÷ lista de todos`). `parteDelGrupo()` ahora es
  `round(parteExacta(...), 2)`.
- **`TourLineaService::repartirCentavos([id => parte exacta, ...])`**: recibe las partes sin
  redondear de las líneas de un mismo tour y las devuelve redondeadas sin ganar ni perder
  céntimos. Redondea el total, redondea cada parte menos la última, y la última es
  `total − suma de las demás`. Es una **función pura** (no toca la base de datos): siempre
  devuelve lo mismo para lo mismo, y se prueba sola.
- **`partesDeTour()`** (en el controlador): antes calculaba el monto redondeado línea por
  línea. Ahora guarda las partes exactas en `$exactasPorCarga[carga_tour][id]` y, al final, pasa
  cada tour por `repartirCentavos()`. Las líneas que **cierran** el tour no cambian: ya cobran
  exacto "lo vendido − lo ya cobrado".

---

## 2. "Confirmar tratamiento tributario" quedaba detrás

### El problema

Los modales de esta pantalla son `div.modal` con la misma capa (`z-index` 1055 de Bootstrap).
Con capas iguales, el navegador dibuja encima **el que está más abajo en el HTML**. El modal de
confirmar está más arriba que los de facturar, así que quedaba tapado. Ya pasaba antes de
separar los modales en componentes.

Además, después de confirmar, la facturación abierta no se enteraba: seguía mostrando el bloqueo.

### La corrección

- El modal de confirmar tiene `z-index:1065`: siempre por encima de los de facturar.
- **`defineExpose({ refrescarPreview })`** en los dos modales de facturar: un componente puede
  "mostrar hacia afuera" funciones suyas. La pantalla guarda una referencia a cada modal
  (`ref="modalFacturarSimple"`, `ref="modalFacturarEspecial"`) y, cuando el usuario confirma,
  llama `modalFacturarEspecial.value?.refrescarPreview()`. El `?.` hace que no pase nada si ese
  modal no está abierto.

> **¿Por qué no "observar" la reserva desde el modal?** Porque después de emitir una factura la
> reserva también cambia, y el modal pediría una vista previa con pasajeros que ya quedaron
> facturados, lo que mostraría un error falso. El aviso explícito solo ocurre cuando de verdad
> hace falta.

---

## 3. Elegir con casillas qué servicios entran

### Qué hace

En **Facturación especial**, la tabla "Qué entra en este comprobante" tiene una casilla por
servicio. Desmarcarlo lo deja **pendiente** para otro comprobante: se ve tachado, con
"Queda pendiente" y su monto, pero no suma. Ejemplo: Kreisler paga ahora solo Alto Mayo, y su
Laguna Azul la factura después otra empresa. ("Facturar", el modal simple, no tiene casillas:
factura todo lo pendiente.)

### El recorrido

```
 DetalleComprobantePreview (casilla)  → emit('alternar', ids, entra)
     ▼
 FacturarReservaEspecialModal::alternarServicio()
     ¿era un servicio "sin asignar" marcado a mano? → lo quita de reserva_item_ids_manual
     si no → lo agrega o lo quita de reserva_item_ids_excluidos
     ▼ (el watch ve el cambio y pide la vista previa otra vez)
 GET /reservas/{id}/preparar-factura?reserva_item_ids_excluidos[]=…
     ▼
 ReservaFacturacionController::resolverSeleccion(…, $idsExcluidos)
     arma la lista completa como siempre (pasajeros, partes de tour, sin asignar)
     separarExcluidos() → lo que entra / lo que queda pendiente
     ▼
 detallePreview(lo que entra, …, lo que queda pendiente, …)
     filasDetalle() por cada grupo; las pendientes con 'excluido' => true
```

Al emitir (`POST /reservas/{id}/facturar`) va el mismo parámetro, y se vuelve a revisar con la
reserva bloqueada, como todo lo demás de `store()`.

### Las funciones

- **`separarExcluidos($items, $partesTour, $idsExcluidos)`** (controlador): devuelve
  `items`/`partes` (lo que entra) e `items_excluidos`/`partes_excluidas` (lo que queda
  pendiente). Si se desmarca una línea de un tour con IGV mezclado, **sale el tour entero**
  (todas las líneas con la misma `carga_tour`): nunca media factura de un tour.
- **`filasDetalle($items, $partes, $excluido)`** (controlador): arma las filas de la tabla (una
  por servicio; las líneas de un tour son una fila). `detallePreview()` la llama dos veces (lo
  que entra y lo que no) y ordena todo como estaba.
- **`alternarServicio(ids, entra)`** (modal especial): decide qué lista cambia.
- **`hayServiciosQueEntran`** (modal especial): el botón "Emitir" se apaga si se desmarcó todo.
- **`seleccionable`** (`DetalleComprobantePreview`): muestra las casillas; el total solo suma
  las filas con `excluido` falso.

### ¿Por qué lo pendiente se puede facturar después?

Gracias a dos reglas que ya existían:
- **Partes de tour:** `partesDeTour()` pregunta "¿quién pagó **este** tour?" (auditoría F3, ver
  el manual 09). Kreisler ya tiene factura, pero no de Laguna Azul, así que su parte sigue
  pendiente.
- **Servicios con pasajeros asignados:** cuando todos sus pasajeros ya están facturados, el
  servicio aparece como "sin asignar" (el "ítem huérfano" de `resolverSeleccion()`).

**Límite que sigue:** un servicio compartido (por ejemplo, una habitación doble) solo entra cuando
están marcados todos sus pasajeros. Y "este pasajero no va al tour" no se resuelve aquí: el precio
es por persona, así que es un cambio de la reserva ("Modificar reserva", fila F4).

---

## 4. Tests

En `Fila13Fase1cFacturacionTest.php`:
- `test_tour_en_varias_lineas_reparte_sin_centavos_de_mas`: el caso real, 203.23 + 96.77, dos
  pasajeros; cada factura da **150.00**. Se escribió antes de corregir y dio 150.01.
- `test_repartir_centavos_no_gana_ni_pierde_centimos`: la función pura con tres casos.
- `test_servicio_desmarcado_queda_pendiente_y_se_factura_despues`: Kreisler sin Laguna Azul
  (150), Roxana con los dos (300), la Laguna de Kreisler después (150); nada más por facturar.
- `test_desmarcar_una_linea_de_un_tour_mezclado_saca_el_tour_entero`.

En el navegador (reserva 27, sin emitir): el modal de confirmar se ve encima (comprobado con el
elemento que hay en el centro de la pantalla); Alto Mayo da 150.00; desmarcar Laguna Azul la deja
"pendiente" y el total baja de 300.00 a 150.00.

### Un problema que apareció al correr toda la suite: basura en la base de pruebas

Fallaron 3 tests de Roles y Usuarios (`PermissionAlreadyExists` y "se esperaba 1 fila de
auditoría, hay 33"). El código de hoy no tenía nada que ver: el culpable era
`ReservaAceptarConcurrencyTest` (doble aceptación). Para que el bloqueo se vea entre dos
conexiones, ese test hace `DB::commit()`, y eso guarda **de verdad** todo lo que creó `setUp()`:
el usuario, su rol, **los permisos** `reservas.ver_todas`/`cotizaciones.ver_todas` y **las filas
de auditoría** (`role_audit_logs`) que se generan al darle esos permisos. Ayer se limpiaban el
usuario y el rol, pero no los permisos ni la auditoría.

Corrección, en el `finally` del test:
- antes del commit, la segunda conexión (que solo ve lo confirmado) anota qué permisos ya estaban
  guardados; al final se borran **solo los nuevos**;
- `borrarUsuarioDeSetUp()` también borra la auditoría de ese usuario.

Comprobado: después de correr el test, la base de pruebas queda con los mismos permisos que antes
y 0 filas de auditoría, 0 usuarios, 0 roles, 0 clientes. Se borraron a mano los restos de las
corridas anteriores (2 permisos, 32 filas de auditoría).

> **Regla para cualquier test que haga `DB::commit()`:** todo lo que se cree antes del commit
> (también lo que crean los ayudantes y los listeners, como la auditoría) queda guardado. Hay que
> borrarlo explícitamente, o el test ensucia la base para los demás.

## 5. Ejercicios

1. Con `repartirCentavos([1 => 10.005, 2 => 10.005, 3 => 10.005])`, ¿qué devuelve? ¿Suma 30.02?
2. ¿Por qué `repartirCentavos()` no se usa en las líneas que cierran el tour?
3. Quita mentalmente `z-index:1065` y mueve el modal de confirmar **debajo** de los modales de
   facturar en el HTML. ¿Se vería encima? ¿Por qué el `z-index` es mejor solución?
4. En `separarExcluidos()`, ¿qué pasaría si no se sacara el tour entero y se facturara solo la
   línea gravada de un tour mezclado?
5. Kreisler factura sin Laguna Azul. ¿Qué datos de `reserva_ventas` hacen que, en la siguiente
   factura, su parte de Laguna Azul siga pendiente?
6. ¿Por qué la lista de "permisos que ya estaban" se lee con la **segunda** conexión y no con la
   del test? (Pista: ¿qué ve la conexión del test antes del commit?)
