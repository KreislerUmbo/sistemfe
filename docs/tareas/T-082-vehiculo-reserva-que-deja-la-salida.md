# T-082 — La reserva que deja una salida sale también de sus vehículos

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | D15 (`plan-tours-venta-simple-costo-real.md` §6) |
| **Decisiones** | Hallazgo de T-040 (03-nov-2026) |
| **Rama** | `feat/T-040-avisos-salidas` (tarea chica relacionada, `docs/proceso.md` §7.7) |

## Contexto
Cancelar una reserva la saca de los vehículos de sus salidas (`salida_vehiculo_reservas`).
Reprogramarla, cambiar la fecha de un servicio, quitarlo o «Modificar reserva» la sacan de la
salida pero dejan la fila del vehículo: si después vuelve a esa salida, reaparece sola en el
vehículo, sin que nadie la asigne.

## Alcance
- `SalidaOperativa::eliminarSiQuedoVacia()`, que se llama cada vez que un ítem deja una salida:
  antes de ver si la salida quedó vacía, borra de sus vehículos las reservas que ya no tienen
  ningún ítem en ella.

## Fuera de alcance
- Avisar del cambio de vehículo: ya lo hace T-040 (la foto solo cuenta reservas enganchadas).
- Una reserva con varias líneas de tour (IGV mezclado) que deja una sola línea: sigue en el
  vehículo mientras le quede otra en la salida.

## Dónde tocar
- `api-sistema-fe/app/Models/AgenciaViajes/SalidaOperativa.php` → `eliminarSiQuedoVacia()`

## Criterios de aceptación
- [x] **CA1** — Reprogramar una reserva que va en una van la saca de la van; al volver a la fecha
  original, no reaparece. → `T082VehiculoReservaQueSaleTest::test_reprogramar_…`
- [x] **CA2** — Cambiar la fecha del servicio enganchado la saca de la van. → `test_cambiar_la_fecha_…`
- [x] **CA3** — Quitar el servicio enganchado la saca de la van. → `test_quitar_el_servicio_…`
- [x] **CA4** — Con otra línea del mismo tour en la salida, sigue en la van. → `test_con_otra_linea_…`

## Seguridad y correctitud
- [x] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [x] Aislamiento de tenant: no aplica
- [x] Validación de entrada / archivos: no aplica
- [x] Concurrencia (`lockForUpdate`): corre dentro de la transacción de quien llama, como hasta ahora
- [x] Dinero en centavos: no aplica
- [x] Fechas con `HoraPeru`: no aplica
- [x] Tributario sin fallback silencioso: no aplica
- [x] Sin `v-html` sin sanitizar: no aplica
- [x] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter='T082VehiculoReservaQueSaleTest|T040AvisosSalidasTest|Fila13Fase2'
```

---

## Resultado
- **Commits:**
- **Tests:** nuevos: `T082VehiculoReservaQueSaleTest` (4; 3 fallaban antes del arreglo). Salidas,
  avisos y «Modificar reserva»: 77 ok. Suite completa (Postgres): 1933 ok / 2 fallas, las dos intermitentes conocidas
  (`Fila13Fase1cFacturacionTest::descuento del combo…`, `ProveedorFiltroDestinoTest::sin filtro…`);
  solas pasan (36 ok).
- **Desviaciones de la tarea y por qué:** ninguna. Sin prueba en pantalla: no cambia ninguna
  pantalla, solo qué reservas quedan en el vehículo.

## Hallazgos fuera de alcance
- 
