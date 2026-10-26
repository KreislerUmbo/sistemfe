# T-017 — El cliente como primer pasajero al aceptar la cotización

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 4 |
| **Rama** | `feat/T-017-cliente-primer-pasajero` |

## Contexto
Al aceptar, los pasajeros de la reserva nacen vacíos. Si el cliente que cotiza es una persona,
casi siempre viaja: se carga en el primer pasajero adulto (editable), y queda vinculado a su ficha
del catálogo de pasajeros, que T-018 también usa.

## Alcance
- En `ReservaController::aceptar()` (no en `crearReservaDesdeAlternativa()`, que también usa
  Venta Directa): resolver el cliente de la cotización y pasar a la creación un "pasajero por
  defecto" (nombre, tipo y n.º de documento, `pasajero_catalogo_id`). Ajustar la firma de
  `crearReservaDesdeAlternativa()` con un parámetro opcional, sin cambiar su comportamiento para
  Venta Directa.
- Se carga en el **primer pasajero adulto** (`tipo_pax` adulto, por orden), **salvo** que:
  el cliente sea RUC; no tenga documento real (`00000000` o vacío, ver `ClienteRequest`); el
  vendedor ya haya elegido a alguien del catálogo para ese lugar (`pasajero_catalogo_ids`); o no
  haya adultos.
- Ficha del catálogo: buscar en `pasajeros_catalogo` por `cliente_id`; si no, por documento
  (`pasajero_documentos`); si no, crearla con `cliente_id`. Si se encuentra por documento sin
  `cliente_id`, completarlo. Mismo criterio que use T-018 (extraer a un método o servicio
  compartido, por ejemplo `PasajeroCatalogoService::desdeCliente()`).
- Traducir el tipo de documento del cliente (`cod_tipo_doc_sunat` / `type_document`) a
  `dni`/`carne_extranjeria`/`pasaporte`/`otro`. Verificar los valores reales en la base.
- Nombre: `full_name` o `name` + `surname` (verificar cuál se llena en clientes persona).

## Fuera de alcance
- Venta Directa (su diseño está sin afinar).
- Reservas ya aceptadas.
- Cambiar el frontend: los datos ya se muestran y editan en Pasajeros.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php` (`aceptar()`, `crearReservaDesdeAlternativa()`)
- `api-sistema-fe/app/Services/AgenciaViajes/PasajeroCatalogoService.php` (nuevo o existente)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php` (cómo sincroniza hoy el catálogo en `update()`)

## Criterios de aceptación
- [ ] **CA1** — Cliente con DNI, 2 adultos → el primero nace con nombre y DNI del cliente y
  `pasajero_catalogo_id`; el segundo vacío. → test nuevo
- [ ] **CA2** — Cliente RUC → ningún pasajero con datos. → test
- [ ] **CA3** — Cliente con documento `00000000` → nada. → test
- [ ] **CA4** — Primer pasajero elegido del catálogo por el vendedor → se respeta lo elegido. → test
- [ ] **CA5** — Pasajeros solo niños/infantes → nada. → test
- [ ] **CA6** — Cliente ya existente en el catálogo por documento (sin `cliente_id`) → se reusa la
  ficha y se completa `cliente_id`; no se duplica. → test
- [ ] **CA7** — Venta Directa crea su reserva igual que antes (sin pasajero por defecto). → test existente en verde

## Seguridad y correctitud
- [ ] Permisos: no aplica (sin rutas nuevas)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: no aplica
- [ ] Concurrencia: dentro de la transacción ya existente de `aceptar()`
- [ ] Dinero: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=Aceptar
php artisan test   # suite completa, contra PostgreSQL
```
Prueba funcional en `agencia-demo`: aceptar una cotización de un cliente con DNI y ver Pasajeros.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
