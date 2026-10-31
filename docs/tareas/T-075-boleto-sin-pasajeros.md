# T-075 — Un pasaje aéreo nunca se queda sin pasajeros

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-007 |
| **Reglas** | — |
| **Decisiones** | Opción 1 elegida por el usuario el 31-oct-2026: impedir borrar al pasajero (descartadas: borrar el boleto con su último pasajero, que lo hace desaparecer en silencio; dejarlo "de nadie", que deja un servicio huérfano y obliga a cambiar qué significa `[]` en varios servicios) |
| **Rama** | `fix/T-075-boleto-sin-pasajeros` |

## Contexto
Desde T-007 el pasaje aéreo guarda siempre su lista de pasajeros (`pax_incluidos`). Si se borran
todos los de esa lista, la lista queda vacía `[]`, y varios servicios leen `[]` como "todos":
el boleto pasa a cubrir a todos con el precio que tenía. Pasa en dos lugares (revisión de T-007):
1. **Cotización:** `CotizacionController::limpiarPaxIncluidosEliminados()` deja `[]` al borrar
   al último pasajero de un boleto.
2. **Modificación de reserva:** `ModificacionReservaService::copiarAlternativa()` (~línea 553)
   deja `[]` en la copia si los pasajeros del boleto ya se quitaron de la reserva. Además los
   servicios no coinciden: `paxDelItem()` lo lee como "todos" y
   `ModificacionDiferenciaService::composicion()` como "nadie".

## Alcance
- **Cotización:** en `actualizarPasajeros()`, antes de borrar, si un pasaje aéreo se quedaría sin
  pasajeros → 422 sin tocar nada, con un mensaje que nombre el boleto, por ejemplo: «El pasajero
  Adulto 2 tiene el pasaje LATAM (alternativa X): primero pasa el boleto a otro pasajero o
  quítalo». El frontend ya muestra `error.response.data.message`.
- **Modificación:** en la copia, un pasaje aéreo cuyos pasajeros ya no están en la reserva no
  queda con `[]`. Propuesta: no copiarlo, igual que `ReservaController::soloParaPasajerosQuitados()`
  hace al crear `reserva_items`. Confirmar con el usuario antes de implementarlo: el boleto ya
  vendido sale de la versión nueva, y eso cambia la diferencia.
- Un pasaje aéreo nunca se guarda con `[]`: `validarPasajeAereo()` ya cambia vacío por todos
  los pasajeros. Verificarlo con un test.

## Fuera de alcance
- Los otros ítems con lista (manual, proveedor): siguen como hoy (quedan "para revisar" en
  `RecalculoPorPersonaService::motivoSinRepreciar()`). Si se quiere el mismo bloqueo para ellos,
  se pide como tarea aparte.
- T-017 (cliente como primer pasajero): llena datos de la persona, no toca `pax_incluidos`.

## Dónde tocar
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionController.php` → `actualizarPasajeros()` (~línea 380-425), `limpiarPaxIncluidosEliminados()` (~446)
- `api-sistema-fe/app/Services/AgenciaViajes/ModificacionReservaService.php` → `copiarAlternativa()` (~535-560)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php` → `soloParaPasajerosQuitados()` (~651), como referencia

## Criterios de aceptación
- [ ] **CA1** — Cotización con 2 adultos y un pasaje solo para el Adulto 2; al borrar al Adulto 2 → 422 con el nombre del boleto; pasajeros e ítems sin cambios. → test
- [ ] **CA2** — Mismo caso pero el pasaje cubre a los dos; al borrar al Adulto 2 → se guarda y el boleto queda con `[Adulto 1]`. → test
- [ ] **CA3** — Modificación de una reserva cuyo pasaje cubría solo a un pasajero ya quitado → la copia no tiene ese pasaje con `[]` (según lo que se confirme). → test
- [ ] **CA4** — Crear o editar un pasaje aéreo sin lista → se guarda con todos los pasajeros, nunca `[]`. → test
- [ ] **CA5** — Prueba a mano en `agencia-demo`: borrar al pasajero de un boleto en el cotizador muestra el mensaje.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas nuevas)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: el bloqueo va antes de borrar, dentro de la transacción existente
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: el mensaje se muestra como texto (`Swal.fire(título, texto)`), no como html
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=PasajeAereo
php artisan test --filter=Modificacion
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
