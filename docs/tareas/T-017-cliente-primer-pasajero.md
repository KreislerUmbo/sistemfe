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
- **Commits:** ver `git log feat/T-017-cliente-primer-pasajero` (un commit). El trabajo nació mezclado con el reporte operativo y con las tarifas de guía (otra sesión en la misma carpeta); el 04-nov se separó en `fix/reporte-operativo-observaciones` y `fix/guia-tarifas-versionadas` (respaldo: `respaldo/T-017-mezclada`).
- **Tests:** `T017ClientePrimerPasajeroTest`, 14 tests (CA1-CA6, CA8-CA11, más «primer lugar elegido → siguiente
  adulto libre», «ficha con otro cliente_id sin cambiarlo» y Venta Directa sin pasajero por defecto). CA7: la
  suite completa pasa, incluidos `VentaDirectaBloque1Test` y `VentaDirectaReservaFechaTest`. Suite completa:
  1990 pasan; en las corridas con la base sucia aparecían fallos de otros archivos que pasan solos (ver Hallazgos).
  **No hice la prueba funcional en agencia-demo**: el MCP de Playwright no conectó en esta sesión.
- **Desviaciones de la tarea y por qué:**
  - `crearReservaDesdeAlternativa()` recibe el pasajero por defecto como **función** (`?\Closure`), no como
    arreglo ya resuelto: así la ficha del cliente se crea solo si hay un adulto libre donde ponerlo (con solo
    niños no queda una ficha sin uso). Venta Directa no la pasa y todo sigue igual.
  - Lugar del cliente: pregunté y el usuario confirmó «primer adulto **libre**» (si el vendedor eligió al
    primer adulto, el cliente va al siguiente adulto sin elegir; si no hay ninguno, no se carga).
  - El servicio tiene además `datosDePasajero(Client)` (nombre/tipo/documento o null); `desdeCliente()` lo usa.
    Bloquea la fila del cliente (`lockForUpdate`) para que dos aceptaciones del mismo cliente no creen dos fichas (CA11).
  - Tests ajenos tocados (solo su limpieza): `ReservaAceptarConcurrencyTest`, `ReservaModificacionAplicarTest` y
    `ReservaModificacionCrearTest` hacen commit real y borran el cliente a mano; ahora aceptar crea una ficha
    con FK a `clients`, así que borran antes la ficha y sus documentos. Sin eso fallaban y dejaban basura.
  - Efecto de la corrección de `sincronizarCatalogo()`: corregir el **número** de documento de un pasajero que
    ya tenía ficha ya no cambia el número de esa ficha, sino que apunta a otra ficha (nueva o la de ese número).
    Es lo que pide la tarea; la ficha vieja con el número mal escrito queda en el catálogo.

## Hallazgos fuera de alcance
- La migración `2026_07_28_150100` comenta `tipo_documento` como `'dni' | 'pasaporte' | …` pero el sistema usa
  `DNI`/`CE`/`PAS` en mayúscula. No se edita (regla de migraciones); corregir el comentario en `reglas.md`.
- Dos clientes con el mismo documento: la ficha queda con el primer `cliente_id` y el segundo cliente la reusa
  sin cambiarlo (comportamiento pedido). Hoy ningún dato real lo provoca.
- Los tests de lock (`DB::commit()` real) dejan en la base de pruebas usuarios, roles, permisos, proveedores y
  clientes si su `finally` falla a medias, y eso hace fallar a otros tests (`PermissionAlreadyExists`, conteos de
  `ProveedorFiltroDestinoTest`). Conviene una tarea para que limpien con un solo helper.
- Si se edita el documento de un cliente que ya tiene ficha, la siguiente aceptación encuentra la ficha por `cliente_id` (documento viejo) pero el pasajero nace con el documento nuevo; al primer guardado, `sincronizarCatalogo()` lo pasa a una ficha nueva sin `cliente_id`. Caso raro (revisión, 04-nov-2026).
- `sincronizarCatalogo()` compara contra el primer documento de la ficha y, en la rama de actualizar, pisa `documentos()->first()`. Hoy cada ficha tiene un solo documento; si T-018 permite varios, revisarlo.
