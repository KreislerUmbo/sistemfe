# Recorrido: ganancia estimada por salida operativa

07-nov-2026, tarea **T-089**. Requerimiento: `docs/requerimientos/agencia-precio-y-margen.md`
(P8 a, respuesta 7 del 05-nov).

---

## 1. El problema

Los tours son compartidos: una salida junta pasajeros de varias reservas, y el vehículo y el guía
se pagan igual vayan 2 o 10. Hasta ahora la salida solo avisaba «bajo el mínimo»; no decía si ese
día se gana o se pierde. T-089 lo **estima** con las tarifas del catálogo. La ganancia **real**
(con las facturas) llega con Compras (P8 b), en la misma pantalla.

## 2. Archivos

| Archivo | Qué cambió |
|---|---|
| `api-sistema-fe/app/Services/AgenciaViajes/TourCostoEstimadoService.php` | `estimarSalida()` (nuevo); `tarifaDeTemporada()` recibe la fecha y delega en `tarifasDeTemporada()` (por lote) |
| `.../Services/AgenciaViajes/SalidaOperacionService.php` | `reservasActivas()` y `ocupan()` pasan a públicos, para reusarlos |
| `.../Controllers/AgenciaViajes/SalidaOperativaController.php` | `resumenSalida($s, true)` agrega `ganancia` solo con `reservas.ver_todas` |
| `.../Services/Dashboard/DashboardService.php` | `proximos()` agrega `{moneda, ganancia, completo}` a cada salida, con el mismo permiso |
| `admin-start-kit/src/views/agencia-viajes/salidas/index.vue` | Bloque «Ganancia estimada» en el detalle de la salida |
| `admin-start-kit/src/views/dashboards/analytics/index.vue` | Badge «Ganancia est. S/ …» en Inicio |
| `admin-start-kit/src/types/agencia-viajes.ts`, `types/dashboard.ts` | Tipo `GananciaSalida` |
| `tests/Feature/AgenciaViajes/GananciaSalidaTest.php` (nuevo) | 9 tests |

Tablas: ninguna nueva. Migraciones: ninguna.

## 3. El recorrido

### 3.1 La cuenta (`TourCostoEstimadoService::estimarSalida()`)

```
cobrado       = Σ total_convertido del alternativa_item de cada reserva_item de la salida
                (reservas no canceladas, solo los de la moneda del tour)
pasajeros     = los que ocupan asiento (el infante no cuenta)
costo fijo    = tarifas 'privado' × vehículos de la salida (mínimo 1)
              + guía: costo_diario × días del tour
costo variable= (tarifas 'compartido' + costos escritos a mano) × pasajeros
ganancia      = cobrado − costo fijo − costo variable
```

- **Mismo criterio que el tablero.** Las reservas y los pasajeros salen de
  `SalidaOperacionService::reservasActivas()` y `ocupan()`, las mismas que calculan «bajo el
  mínimo». No hay una copia: si una cambia, cambian las dos.
- **Mismas reglas que la calculadora** (`sugerirItem()`): una tarifa de hotel o en otra moneda no
  se usa; en ese caso se toma el `costo_estimado_persona` del ítem, y si tampoco hay, el
  servicio queda «sin costo».
- **Temporada a la fecha de la salida.** `tarifasDeTemporada()` busca, en **una** consulta para
  todo el Incluye, la tarifa de la temporada vigente **ese día** (antes `tarifaDeTemporada()`
  miraba «hoy»; la calculadora sigue igual porque le pasa `null`).
- **Centavos enteros.** Todo se suma en centavos (`centavos()`) y se divide entre 100 al final.
- **Sin IGV** (P4b, espera al contador).

### 3.2 `completo` y los avisos

`completo = false` (y un aviso) cuando:

| Caso | Aviso |
|---|---|
| Un servicio del Incluye no tiene costo | «"Desayuno" no tiene costo: no está sumado.» |
| El tour no tiene servicios en el Incluye | «El tour no tiene servicios en el Incluye: el costo no está sumado.» |

Una reserva en otra moneda **no** vuelve incompleto el cálculo, pero se avisa («1 reserva en USD
no está sumada.»). Su cobrado no se suma, pero sus pasajeros sí cuentan para el costo: la
ganancia sale más pesimista.

El caso del Incluye vacío apareció en la prueba en pantalla: «Catarata de Ahuashiyacu» salía con
ganancia = lo cobrado, en verde. Ahora sale con asterisco.

### 3.3 Quién la ve

Solo quien tiene `reservas.ver_todas`. El backend **no manda** la clave `ganancia` a los demás
(no se oculta en la pantalla: no llega). Lo prueba `test_sin_permiso_no_ve_montos`.

### 3.4 Pantalla

| Dónde | Qué se ve |
|---|---|
| Detalle de la salida | Cobrado · Costo fijo · Costo variable («S/ 65.00 por persona × 2 pax») · Ganancia, con markup y margen (`utils/margenes.ts`), avisos y «Estimada con las tarifas; la real llega con Compras». Ganancia en rojo si es negativa |
| Inicio → Próximos 7 días → Salidas operativas | Badge «Ganancia est. S/ …» junto a «Bajo el mínimo»: verde o rojo; `*` si falta algún costo |

## 4. Lo que queda fuera

- El costo del extranjero (`costo_estimado_persona_ext`): se aplica el nacional a todos.
- Los guías por vehículo (`salida_vehiculos.guia_id`) no suman: el guía se cuenta una vez.
- El costo del vehículo escrito a mano por salida (`salida_vehiculos` no tiene monto).
- En Inicio son ~4 consultas por salida (máx. 8 salidas): no está agrupado por lote.
- La ganancia real con facturas (Compras).
