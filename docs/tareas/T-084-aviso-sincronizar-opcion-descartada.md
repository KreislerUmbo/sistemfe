# T-084 — El aviso "servicios sin reflejar" de la reserva usa el mismo criterio que el botón Sincronizar

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | — |
| **Rama** | `fix/T-084-aviso-sincronizar-opcion-descartada` |

## Contexto
Lo reportó el usuario el 04-nov con la reserva RDKM-1126-0000004 (agencia-demo). La reserva
avisaba "Hay 1 servicio(s) nuevo(s) en la cotización sin reflejar: Quechua Hotel Cusco · doble"
y, al pulsar **Sincronizar**, salía "No hay ítems pendientes de sincronizar". El aviso no se iba
nunca.

La causa es que el aviso (`respuestaDetalle()`) listaba todos los `alternativa_items` que no
estaban en la reserva, mientras que el botón (`sincronizarItems()`) descarta antes:
- las opciones no elegidas de un grupo (`filtrarItemsParaReserva()`, M2). En este caso el
  servicio era el `alternativa_item` 502, la opción descartada del grupo de hoteles en Cusco;
- los servicios que son solo para pasajeros ya quitados (`soloParaPasajerosQuitados()`, F4).

## Alcance
- Una sola función, `ReservaController::itemsPendientesDeSincronizar()`, que usan el aviso y el botón.

## Fuera de alcance
- Cambiar qué se sincroniza: el botón conserva exactamente el mismo criterio que antes.
- Frontend: no cambia.

## Dónde tocar
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php` →
  `itemsPendientesDeSincronizar()` (nueva), `sincronizarItems()`, `respuestaDetalle()`

## Criterios de aceptación
- [x] **CA1** — Si la reserva viene de un grupo de opciones, la opción no elegida no sale en el aviso →
  `ReservaSincronizarItemsTest::test_detalle_no_avisa_la_opcion_no_elegida_de_un_grupo`
- [x] **CA2** — Un servicio que es solo para pasajeros quitados no sale en el aviso →
  `test_detalle_no_avisa_un_servicio_solo_para_pasajeros_quitados`
- [x] **CA3** — Un servicio nuevo (también la opción elegida de un grupo) sigue saliendo en el aviso →
  `test_respuesta_detalle_incluye_items_pendientes_sincronizar`,
  `test_detalle_sigue_avisando_la_opcion_elegida_de_un_grupo`
- [x] **CA4** — RDKM-1126-0000004 en agencia-demo ya no muestra el aviso (comprobado con
  `respuestaDetalle()`: `items_pendientes_sincronizar = []`)

## Seguridad y correctitud
- [x] Permisos / Policy en rutas nuevas: no aplica (no hay rutas nuevas)
- [x] Aislamiento de tenant: no aplica
- [x] Validación de entrada / archivos: no aplica
- [x] Concurrencia (`lockForUpdate`): `sincronizarItems()` sigue calculando los pendientes dentro del lock
- [x] Dinero en centavos: no aplica
- [x] Fechas con `HoraPeru`: no aplica
- [x] Tributario sin fallback silencioso: no aplica
- [x] Sin `v-html` sin sanitizar: no aplica
- [x] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=ReservaSincronizarItemsTest
```
Pantalla: agencia-demo → Reservas → RDKM-1126-0000004: no aparece el aviso amarillo.

---

## Resultado
- **Commits:** ver `git log fix/T-084-aviso-sincronizar-opcion-descartada`
- **Tests:** 3 nuevos en `ReservaSincronizarItemsTest`. CA1 y CA2 fallaban sin el arreglo. Suite completa: 1976 pasan, 1 falla (`Fila13Fase1cFacturacionTest::descuento_del_combo…`, el intermitente conocido; pasa al repetirlo).
- **Desviaciones de la tarea y por qué:** ninguna

## Hallazgos fuera de alcance
- ninguno
