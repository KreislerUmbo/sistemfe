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
- Se carga en el **primer pasajero adulto** (`tipo_pax === 'adulto'`, por orden `id`), **salvo**
  que: el cliente no sea persona con documento (ver abajo); el vendedor ya haya elegido a alguien
  del catálogo para **ese** lugar (`pasajero_catalogo_ids[$index]` no nulo); o no haya adultos.
  Si el vendedor eligió a alguien para *otro* lugar, el primer adulto libre sí recibe al cliente,
  salvo que el cliente ya sea uno de los elegidos (no repetir a la misma persona).
- Se copian a `reserva_pasajeros`: `nombre`, `documento` y `pasajero_catalogo_id`.
  **`reserva_pasajeros` no tiene tipo de documento**: el tipo solo va a `pasajero_documentos`.
- Ficha del catálogo: buscar en `pasajeros_catalogo` por `cliente_id`; si no, por documento
  (`pasajero_documentos.numero_documento`); si no, crearla con `cliente_id` y su documento. Si se
  encuentra por documento sin `cliente_id`, completarlo. Si ya tiene **otro** `cliente_id` (dos clientes
  con el mismo documento), usarla sin cambiar su `cliente_id` y anotarlo en Hallazgos. Servicio nuevo `PasajeroCatalogoService::desdeCliente(Client $c): ?PasajeroCatalogo`
  (devuelve null si el cliente no califica), que T-018 va a reusar.

### Datos verificados en agencia-demo (04-nov-2026)
- **"Persona con documento" = `clients.type_document` en `DNI`, `CE`, `PAS`.** `type_document`
  es confiable (lo normaliza `ClienteRequest`, valores `DNI/RUC/CE/PAS/TM/SND`).
  **No usar `type_client`** (empresas con RUC tienen `type_client = 1`) **ni `cod_tipo_doc_sunat`**
  (viene `null` en algunas filas). `SND` queda fuera aunque traiga número (hay 2 así). `TM`
  (tarjeta militar) queda fuera. Además: `n_document` vacío o `00000000` → no califica.
- **Nombre:** en los clientes DNI, `full_name` está siempre lleno (= `name` + `surname`). Usar
  `full_name`; si viniera vacío, `trim(name . ' ' . surname)`; si sigue vacío, no califica.
- **Tipo en `pasajero_documentos`:** los 9 registros reales usan `'DNI'` en mayúscula
  (`ReservaPasajeroController::sincronizarCatalogo()` escribe `'DNI'`), aunque el comentario de
  la migración `2026_07_28_150100` diga `'dni' | 'pasaporte' | …`. **Se usa el mismo código del
  cliente: `DNI`, `CE`, `PAS`.** No corregir el comentario de la migración (nunca editar una
  migración); anotarlo en Hallazgos.
- Hoy ningún pasajero del catálogo tiene `cliente_id` (0 de 9).

### Riesgo que esta tarea vuelve común: pisar la ficha del cliente
`sincronizarCatalogo()` (en `ReservaPasajeroController::update()`) busca la ficha **por
`pasajero_catalogo_id`** y le copia el nombre y el documento del pasajero. El frontend
(`reservas/detalle.vue`, autocompletado por RENIEC o escritura a mano) cambia `nombre`/`documento`
pero **deja el `pasajero_catalogo_id` anterior**. Con T-017 el primer pasajero nace con la ficha
del cliente: si el cliente no viaja y el vendedor escribe a otra persona, al guardar se
sobrescribe la ficha del cliente con los datos de otra persona, que queda con el `cliente_id`
del cliente. Después, Facturar (`ReservaFacturacionController`, `cliente_sugerido`) ofrece al
cliente equivocado.
- **Corrección (backend):** en `sincronizarCatalogo()`, si el pasajero trae `pasajero_catalogo_id`
  pero su `documento` es distinto del documento de esa ficha, es **otra persona**: no tocar esa
  ficha; buscar por documento o crear una nueva (sin `cliente_id`) y apuntar el pasajero a ella.
  Si el documento es el mismo, se actualiza como hoy (corregir el nombre de la misma persona).
- Sin cambios de frontend.

## Fuera de alcance
- Venta Directa (su diseño está sin afinar).
- Reservas ya aceptadas.
- Cambiar el frontend: los datos ya se muestran y editan en Pasajeros.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php` (`aceptar()`, `crearReservaDesdeAlternativa()`)
- `api-sistema-fe/app/Services/AgenciaViajes/PasajeroCatalogoService.php` (nuevo o existente)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php` (`sincronizarCatalogo()`, CA10)
- Tests nuevos: `tests/Feature/AgenciaViajes/T017ClientePrimerPasajeroTest.php` (ver `ReservaAceptarConcurrencyTest` para armar cotización/alternativa y aceptar)

## Criterios de aceptación
- [ ] **CA1** — Cliente con DNI, 2 adultos → el primero nace con nombre y DNI del cliente y
  `pasajero_catalogo_id`; el segundo vacío. → test nuevo
- [ ] **CA2** — Cliente RUC → ningún pasajero con datos. → test
- [ ] **CA3** — Cliente con documento `00000000` → nada. → test
- [ ] **CA4** — Primer pasajero elegido del catálogo por el vendedor → se respeta lo elegido. → test
- [ ] **CA5** — Pasajeros solo niños/infantes → nada. → test
- [ ] **CA6** — Cliente ya existente en el catálogo por documento (sin `cliente_id`) → se reusa la
  ficha y se completa `cliente_id`; no se duplica. → test
- [ ] **CA7** — Venta Directa crea su reserva igual que antes (sin pasajero por defecto). → `VentaDirectaBloque1Test` y `VentaDirectaReservaFechaTest` en verde
- [ ] **CA8** — Cliente con `type_document = 'SND'` y un número cualquiera, o `TM` → nada. Cliente `CE` o `PAS` → sí, y la ficha nace con `tipo_documento` `CE`/`PAS`. → test
- [ ] **CA9** — El vendedor eligió del catálogo al propio cliente para el 2.º lugar → el 1.º queda vacío (no se repite). → test
- [ ] **CA10** — Primer pasajero con la ficha del cliente; se guarda con otro nombre y **otro** documento → la ficha del cliente no cambia (nombre, documento ni `cliente_id`) y el pasajero queda apuntando a una ficha nueva o a la del otro documento. Guardar con el **mismo** documento y nombre corregido → se actualiza la misma ficha. → test
- [ ] **CA11** — Aceptar dos cotizaciones del mismo cliente → una sola ficha con ese `cliente_id`. → test

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
