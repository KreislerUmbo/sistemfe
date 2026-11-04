# T-018 — "Buscar pasajero" también busca en Clientes

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-017 (comparte la vinculación catálogo ↔ cliente) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 5 |
| **Rama** | `feat/T-018-buscar-pasajero-clientes` |

## Contexto
`ReservaPasajeroController::buscarCatalogo()` solo busca en `pasajeros_catalogo`, que tiene muchas
menos personas que `clients`. Desde T-017 el vínculo `pasajeros_catalogo.cliente_id` se llena al
aceptar una cotización (`PasajeroCatalogoService::desdeCliente()`); esta tarea lo reusa.

## Alcance
- `buscarCatalogo()` devuelve resultados de **pasajeros y clientes persona** a la vez, cada uno con
  `origen` (`pasajero`/`cliente`). Un cliente que ya tiene ficha vinculada sale una sola vez (como
  pasajero). Clientes RUC y sin documento real (`00000000`) no salen.
- Al elegir un resultado de origen `cliente`, se resuelve su ficha con el mismo método de T-017
  (`PasajeroCatalogoService::desdeCliente()`): se reusa o se crea vinculada, sin duplicar.
- Frontend (`reservas/detalle.vue`, buscador de pasajero): etiqueta "Pasajero" / "Cliente" en cada
  resultado. Si no aparece nada, sigue como hoy (se escribe a mano y queda en el catálogo).

### Verificado contra el código (04-nov-2026, después de T-017)
- **Buscador hoy:** `ReservaPasajeroController::buscarCatalogo()` (`GET pasajeros-catalogo?search=`,
  permiso `reservas.ver|reservas.ver_todas`): mínimo 2 caracteres, `ilike` sobre
  `pasajeros_catalogo.nombre` y `pasajero_documentos.numero_documento`, `limit(15)`, responde
  `{ pasajeros_catalogo: PasajeroCatalogo[] }` (con `documentos`). Solo lo usa `reservas/detalle.vue`
  (`onBuscarCatalogo()` ~1375, lista ~303, `autocompletarDesdeCatalogo()` ~1391).
- **Elegir no guarda nada:** `autocompletarDesdeCatalogo()` solo llena el formulario (`nombre`,
  `documento`, `nacionalidad`, `pasajero_catalogo_id`); se persiste al tocar Guardar
  (`guardarPax()` → `PUT reserva-pasajeros/{id}` → `update()` → `sincronizarCatalogo()`). Si no hay
  resultados y el texto es un DNI de 8 dígitos, cae a RENIEC.
- **Qué cliente califica:** el mismo criterio de T-017, ya escrito en
  `PasajeroCatalogoService::datosDePasajero()` (`type_document` DNI/CE/PAS, número no vacío ni
  `00000000`, con nombre). En SQL repetir ese filtro; no inventar otro.
- **El problema que la tarea no resolvía:** un resultado de origen `cliente` todavía no tiene ficha
  (`id` de catálogo). Crear la ficha **en la búsqueda** (un GET) no: cada tecla crearía fichas.

### Decisiones (cerradas para construir)
1. **Respuesta:** se mantiene la clave `pasajeros_catalogo` y la forma de cada elemento. Cada uno
   lleva `origen`: `'pasajero'` (las fichas, como hoy) o `'cliente'`. Un cliente sale con
   `id: null`, `cliente_id`, `nombre`, `nacionalidad: null` y
   `documentos: [{ tipo_documento, numero_documento }]` (del cliente). Primero las fichas, después
   los clientes, **15 en total**.
2. **Sin repetidos:** un cliente **no** sale si ya hay una ficha con su `cliente_id` **o** con su
   mismo número de documento (caso real: la ficha 9 de agencia-demo tenía el DNI del cliente sin
   `cliente_id`). Esa persona sale una vez, como `pasajero`.
3. **Búsqueda en clientes:** `full_name`, `name`, `surname` y `n_document` con `ilike`, igual que hoy
   en las fichas.
4. **Vincular al guardar, no al elegir:** elegir un cliente llena el formulario y guarda en el
   pasajero (solo en la pantalla) un `cliente_id`. `guardarPax()` lo manda. `update()` acepta
   `cliente_id` (`nullable|integer|exists:clients,id`): si viene, y el `documento` que se guarda es
   **el mismo** `n_document` del cliente, resuelve la ficha con
   `PasajeroCatalogoService::desdeCliente()` y la pone en `pasajero_catalogo_id` **antes** de
   `sincronizarCatalogo()`. Si el documento no coincide (el vendedor eligió al cliente y después
   escribió a otra persona), se ignora el `cliente_id`. `desdeCliente()` bloquea la fila del
   cliente: `update()` tiene que envolver esa parte en `DB::transaction` (hoy no abre ninguna).
   `reserva_pasajeros` **no** tiene columna `cliente_id`: no se guarda ahí.
5. **Pantalla:** badge «Pasajero» / «Cliente» en cada resultado (clases `-subtle` como el resto de
   la pantalla). El `:key="c.id"` de la lista no sirve (los clientes vienen con `id: null`): usar
   `` `${c.origen}-${c.id ?? c.cliente_id}` ``. Agregar al tipo `PasajeroCatalogo` `origen?` y `cliente_id?`.
   De paso, el texto de ayuda dice «Si escribís…»: pasarlo a «Si escribes…» (tuteo).
6. **No tocar** la cascada a RENIEC ni `sincronizarCatalogo()` (sus hallazgos están en «Por
   analizar» del backlog).

## Fuera de alcance
- Clientes empresa como pasajeros.
- Fusionar fichas duplicadas ya existentes.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php`
- `api-sistema-fe/app/Services/AgenciaViajes/PasajeroCatalogoService.php`
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue`

## Criterios de aceptación
- [ ] **CA1** — Buscar por el DNI de un cliente que no es pasajero → sale con origen `cliente`. → test
- [ ] **CA2** — Elegirlo crea la ficha con `cliente_id` y la asigna al pasajero de la reserva. → test
- [ ] **CA3** — Buscar de nuevo → sale una sola vez, como `pasajero`. → test
- [ ] **CA4** — Cliente RUC o con `00000000` → no sale. → test
- [ ] **CA5** — Búsqueda por nombre parcial sin distinguir mayúsculas encuentra en las dos tablas. → test
- [ ] **CA6** — Prueba funcional en `agencia-demo`.
- [ ] **CA7** — Cliente cuyo DNI ya está en una ficha **sin** `cliente_id` → sale una sola vez, como
  `pasajero`. → test
- [ ] **CA8** — Guardar un pasajero con `cliente_id` y **otro** documento → no se vincula a la ficha
  del cliente (ni se le pone `cliente_id` a otra). → test
- [ ] **CA9** — Guardar dos veces el mismo pasajero con el mismo `cliente_id` → una sola ficha con ese
  `cliente_id`. → test
- [ ] **CA10** — Sin `cliente_id` en el request, `update()` se comporta como hoy. → tests existentes en verde

## Seguridad y correctitud
- [ ] Permisos: la ruta existente ya tiene su permiso; elegir un cliente no da acceso a más datos
  del cliente que nombre y documento
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: largo mínimo del texto buscado (como hoy); límite de resultados
- [ ] Concurrencia: crear la ficha con índice o búsqueda previa bajo transacción (doble clic no duplica)
- [ ] Dinero: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=BuscarCatalogo
php artisan test   # suite completa, contra PostgreSQL
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:** rama `feat/T-018-buscar-pasajero-clientes`, un commit (`git log` de la rama).
- **Tests:** `T018BuscarPasajeroClientesTest` (8, uno por CA1-CA5 y CA7-CA9) en verde; CA10 con los
  tests existentes. Suite completa: ver el mensaje de cierre. Frontend: `build` OK; `type-check` con
  47 errores previos, ninguno en `detalle.vue`, `agencia-viajes.ts` ni `reservaPasajeroService.ts`.
- **CA6 (prueba funcional en agencia-demo): pendiente.** El MCP de Playwright no conectó
  (`CONNECTION_CLOSED`), así que la pantalla no se probó en el navegador.
- **Desviaciones de la tarea y por qué:** ninguna. `update()` ahora corre dentro de `DB::transaction`
  (lo pide la decisión 4). `PasajeroCatalogo.id` pasó a `number | null` en el tipo del frontend
  (los clientes vienen con `id: null`).

## Hallazgos fuera de alcance
- Elegir un cliente cuyo documento es CE/PAS: `sincronizarCatalogo()` solo crea documentos tipo `DNI`
  (sin verificar si ya está en «Por analizar»); con `cliente_id` la ficha nace con el tipo correcto vía `desdeCliente()`,
  pero si luego se vuelve a sincronizar no se corrige.
