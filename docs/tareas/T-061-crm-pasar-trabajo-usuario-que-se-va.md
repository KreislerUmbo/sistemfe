# T-061 — CRM: pasar el trabajo abierto de un usuario que se va

| | |
|---|---|
| **Módulo** | CLI + AGE |
| **Depende de** | T-051, T-052 (hechas) |
| **Reglas** | ayudas sin fricción (se ofrece, nunca se obliga) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 16, respuesta 3 (27-oct-2026: contratos de 3 a 6 meses; sin responsable fijo, pero el trabajo abierto no puede quedar huérfano) y respuesta 5 (solo Agencia por ahora). Detalles fijados al preparar la tarea (03-nov-2026), ver «Decisiones de detalle» |
| **Rama** | `feat/T-061-crm-traspaso-usuario` |

## Contexto
El personal rota cada 3 a 6 meses. Cuando alguien se va, sus cotizaciones abiertas y sus acciones
pendientes quedan a su nombre: nadie las ve en «Mis pendientes de hoy» ni en Seguimiento (T-051) y
el cliente se queda sin seguimiento. Créditos ya resolvió lo mismo para su cartera
(«Traspasar cartera», `ClienteCreditoController::traspasarCartera()`, componente
`TraspasoCartera.vue`): es el patrón a seguir. Base: recorridos 21 a 23 de `docs/aprendizaje/`.

## Alcance
- **«Pasar su trabajo a…»** en Usuarios, por fila (solo giro Agencia). El modal muestra cuánto
  tiene abierto el usuario (cotizaciones abiertas y acciones pendientes), pide el usuario destino y
  confirma. Al confirmar, en **una** transacción:
  - `cotizaciones.vendedor_id` → destino, **solo las abiertas** (definición abajo); las cerradas
    conservan quién vendió o perdió;
  - `crm_acciones.responsable_id` de las **pendientes** (`hecha_en` null) → destino, tengan o no
    referencia (cotización, reserva o ninguna);
  - **una actividad por cliente afectado** (`crm_actividades`, tipo `estado`, sin referencia):
    «Trabajo traspasado de Ana Pérez a Luis Soto: 2 cotizaciones y 1 acción».
- **Ofrecido al desactivar o eliminar**, sin obligar: al guardar un usuario como Inactivo o al
  eliminarlo, si tiene trabajo abierto, la pantalla pregunta «Ana tiene 2 cotizaciones abiertas y
  1 acción pendiente. ¿Pasar su trabajo ahora?» → abre el mismo modal. «Ahora no» no bloquea nada.
- **Aviso en Inicio** (Agencia, a quien tenga `edit_user`): «Ana Pérez (inactiva) tiene 2
  cotizaciones abiertas y 1 acción pendiente» con el botón «Pasar su trabajo» que lleva a
  Usuarios con el modal abierto para ella. Incluye usuarios inactivos (`state = 2`) y eliminados
  (soft delete). Sin trabajo abierto, no hay aviso.

## Decisiones de detalle (03-nov-2026, al preparar la tarea)
- **Cotización abierta** = del cotizador (`Cotizacion::delCotizador()`: excluye venta directa y
  modificaciones de reserva), **no perdida** (`perdida_en` null) y **sin alternativa `aceptada` ni
  `reemplazada`**. Las vencidas cuentan como abiertas: se pueden reenviar.
- **La condición va en el `UPDATE`**, no en una lista de ids leída antes: si mientras tanto alguien
  acepta una cotización, el `UPDATE … WHERE NOT EXISTS (alternativa aceptada/reemplazada)` ya no la
  toca y la ganada sigue de quien la vendió.
- **Destino válido:** distinto del origen, activo (`state = 1`), no eliminado y con
  `cotizaciones.editar` (si no, recibiría cotizaciones que no puede abrir). Si no → 422 con el motivo.
- **Origen:** puede estar activo, inactivo o eliminado (como `TraspasarCarteraRequest`, que no
  valida al que entrega contra usuarios activos). Sin nada abierto → 422 «Ese usuario no tiene
  trabajo abierto para pasar».
- **Solo Agencia:** en otro giro las rutas responden 404 (no existen las cotizaciones) y la
  pantalla no muestra el botón. El giro se lee con `GiroActual` (el frontend lo pide a
  `GET clients/contexto`, como la ficha del cliente).
- **Reservas:** siguen con su vendedor. Sus acciones pendientes **sí** pasan (son de `crm_acciones`).
- **Un solo destino por vez.** Para repartir entre varios se repite (fuera de alcance elegir por
  cotización).

## Fuera de alcance
- Repartir el trabajo entre varios usuarios en una sola operación.
- Cambiar el vendedor de reservas o ventas.
- Retail y Créditos (Créditos ya tiene su «Traspasar cartera»; no tocarlo).
- Bloquear la desactivación por tener trabajo abierto (a diferencia de la cartera de Créditos, que
  sí la bloquea en `UserController::avisoCartera()`: no cambiar eso).

## Dónde tocar
**Backend** (`api-sistema-fe/`)
- Servicio nuevo `app/Services/Crm/TraspasoTrabajoService.php`:
  - `resumen(int $usuarioId): array` → `{cotizaciones: int, acciones: int}`;
  - `traspasar(int $desdeId, User $hacia, User $actor): array` → lo que pasó; transacción única.
  - Un scope o método que arme «cotización abierta» para usarlo en el conteo y en el `UPDATE`
    (p. ej. `Cotizacion::scopeAbiertas()` en `app/Models/AgenciaViajes/Cotizacion.php`, junto a
    `scopeDelCotizador()` ~200).
  - Actividad: `CrmActividad::create([... 'referencia_tipo' => null, 'tipo' => CrmActividad::TIPO_ESTADO])`
    (las columnas de referencia son nullable; ver `ResultadoCotizacionService::registrarActividad()`).
- Controller nuevo `app/Http/Controllers/AgenciaViajes/TraspasoTrabajoController.php`:
  - `GET users/{id}/trabajo-abierto` → `resumen()` (el `{id}` puede ser un usuario eliminado:
    `User::withTrashed()`).
  - `POST users/{id}/traspasar-trabajo` `{hacia_usuario_id}`.
  - Rutas junto a `users/{id}/permisos` en `routes/api.php` (~354), con `permission:edit_user`;
    **antes** de `Route::resource("users", …)` si chocan los segmentos (mismo cuidado que
    `clients/credit-summary-list`, ~494). Fuera de Agencia → 404.
  - Super-Admin: si el origen o el destino es Super-Admin y el actor no lo es → 403, como
    `UserController::protegerPrivilegios()`.
- `app/Services/Dashboard/DashboardService.php::inicio()` (~51): bloque `trabajo_sin_dueno` si
  `$agencia && $usuario->can('edit_user')`: lista de `{usuario_id, nombre, estado: 'inactivo'|'eliminado',
  cotizaciones, acciones}` solo de quienes tienen algo abierto. Una consulta agrupada por usuario,
  no una por usuario.

**Frontend** (`admin-start-kit/src/`)
- `components/Usuarios/TraspasoTrabajoModal.vue` (nuevo; modal con `Teleport` como
  `components/AgenciaViajes/CerrarComoPerdidaModal.vue`, inputs `-sm`). Recibe el usuario origen;
  carga el resumen; selector de destino (usuarios activos, sin el origen); botón deshabilitado sin
  destino. Al terminar emite `hecho` con lo que pasó y muestra un toast.
- `services/admin/traspasoTrabajoService.ts` (nuevo).
- `views/users/index.vue`:
  - botón «Pasar su trabajo» por fila (~74, dentro de `puedeGestionar(user)`), solo en Agencia
    (`GET clients/contexto` → `giro === 'agencia_viajes'`);
  - tras guardar con `state = 2` (~455) o eliminar (`removeUser()` ~565): pedir el resumen y, si hay
    algo, preguntar con Swal y abrir el modal;
  - si la URL trae `?traspasar=<id>` (desde Inicio), abrir el modal para ese usuario al montar.
- `views/dashboards/analytics/index.vue`: tarjeta de aviso con el bloque `trabajo_sin_dueno`
  (cerca de `MisPendientesHoy`, ~31), con `router-link` a `/users?traspasar=<id>`.
- `types/dashboard.ts`: el bloque nuevo.

## Criterios de aceptación
- [x] **CA1** — Ana con 2 cotizaciones abiertas (una vencida), 1 ganada, 1 perdida y 3 acciones
  pendientes (una de una reserva) + 1 hecha → traspaso a Luis: las 2 abiertas y las 3 pendientes
  pasan; la ganada, la perdida y la hecha siguen de Ana. → test
- [x] **CA2** — Una actividad `estado` por cliente afectado, con nombres y cantidades; un cliente con
  2 cotizaciones recibe una sola. → test
- [x] **CA3** — Usuario inactivo o eliminado con trabajo abierto → aviso en Inicio para quien tiene
  `edit_user` en Agencia; sin trabajo, sin `edit_user` o en otro giro → sin aviso. → test
- [x] **CA4** — Destino inactivo, eliminado, igual al origen o sin `cotizaciones.editar` → 422;
  origen sin nada abierto → 422; sin `edit_user` → 403 (ruta); Super-Admin protegido → 403. → test
- [x] **CA5** — Cotización de modificación de reserva y de venta directa no cuentan ni pasan. → test
- [x] **CA6** — Prueba funcional en agencia-demo: desactivar un usuario con trabajo → se ofrece el
  traspaso; «Ahora no» → aviso en Inicio → desde el aviso, traspasar; el destino ve las
  cotizaciones en su listado y las acciones en «Mis pendientes de hoy». Suite completa en verde;
  `type-check` sin errores nuevos (base 47).

## Seguridad y correctitud
- [x] Permisos / Policy en rutas nuevas: `edit_user` en la ruta; Super-Admin protegido como en
  `UserController`
- [x] Aislamiento de tenant: tablas del tenant
- [x] Validación de entrada / archivos: destino distinto, activo, no eliminado y con `cotizaciones.editar`
- [x] Concurrencia (`lockForUpdate`): transacción única; la condición de «abierta» dentro del `UPDATE`
- [x] Dinero en centavos: no aplica
- [x] Fechas con `HoraPeru`: no aplica (las actividades usan `created_at` UTC)
- [x] Tributario sin fallback silencioso: no aplica
- [x] Sin `v-html` sin sanitizar: nombres como texto
- [x] Migraciones nuevas + `tenants:migrate-verticales`: no aplica (sin migraciones)

## Cómo verificar
```
php artisan test --filter="Traspaso|CrmAccion|Dashboard|User"
cd admin-start-kit && npm run type-check && npx vitest run
```
Prueba funcional: agencia-demo, `admin@agencia-demo.test` (memoria `reference_agencia_demo_admin_login`).
Crear un usuario vendedor de prueba para desactivarlo; no desactivar usuarios existentes.

---

## Resultado
- **Commits:** `a9ca0ac` (backend), `cda20ba` (frontend), y el de cierre con esta sección y el
  título del aviso al desactivar.
- **Tests:** nuevos `tests/Feature/AgenciaViajes/TraspasoTrabajoTest.php` (9 tests, 53
  aserciones: CA1, CA2, CA3, CA4 en tres tests, CA5, destinos del selector, 404 fuera de Agencia)
  y `traspasoTrabajoService.test.ts` (Vitest, texto de cantidades). Suite completa del backend:
  **1887 passed**, sin fallas (corrió con el commit `a9ca0ac`; los cambios posteriores del
  controller —nombre del usuario en el GET y helper `actor()`— se verificaron con el filtro
  `TraspasoTrabajoTest`). Frontend: `type-check` 47 (la base), Vitest 175 passed, build OK.
- **Prueba funcional (CA6, Playwright, agencia-demo):** se crearon «T061 Vendedor» (id 11) y
  «T061 Destino» (id 12), ambos «Vendedor de agencia»; se le cargaron a 11 por tinker 2
  cotizaciones (`T061-PRUEBA-1`/`-2`, ids 77 y 78, clientes 1 y 3) y 1 acción pendiente de hoy
  (id 6). Desactivar a 11 → «T061 Vendedor tiene 2 cotizaciones abiertas y 1 acción pendiente.
  ¿Pasar su trabajo ahora?» → «Ahora no» (quedó Inactivo) → Inicio mostró «Trabajo sin dueño»
  con el enlace `/users?traspasar=11` → el modal se abrió solo, con destinos «Admin General» y
  «T061 Destino», botón deshabilitado sin destino → traspaso a 12, toast y la URL sin el
  parámetro. Entrando como 12: la acción en «Mis pendientes de hoy» y las 2 cotizaciones en su
  listado. En la base: actividades 13 («…: 1 cotización y 1 acción», cliente 1) y 14 («…: 1
  cotización», cliente 3). No se probó a mano el camino «eliminar → se ofrece» (mismo
  `ofrecerTraspaso()` que al desactivar). `admin@agencia-demo.test` es Super-Admin, así que la
  protección de Super-Admin solo la cubren los tests.
- **Desviaciones de la tarea y por qué:**
  - `GET users/{id}/trabajo-abierto` devuelve, además del resumen (`data`), `usuario`
    `{id, nombre}` (desde Inicio llega solo el id y un eliminado no figura en el listado) y
    `destinos` (activos, no eliminados, con `cotizaciones.editar`, sin el origen; un Super-Admin
    solo se le ofrece a otro Super-Admin). No había un endpoint de usuarios con ese filtro y así el
    selector no ofrece a quien el POST rechazaría.
  - Para saber qué filas cambiaron sin leer los ids antes, cada `UPDATE` lleva `RETURNING id,
    cliente_id` (`TraspasoTrabajoService::actualizarDevolviendo()`, compila el WHERE del builder
    de Eloquent). No hace falta `lockForUpdate`: la condición se re-evalúa fila a fila en el
    propio UPDATE.
  - El aviso de Inicio vacío no cuenta como bloque para el mensaje «Bienvenido» (`sinBloques`).
  - Al desactivar, el título del aviso es fijo («Usuario guardado»): el mensaje del backend al
    editar dice «Usuario creado con exito» (ver Hallazgos).

## Hallazgos fuera de alcance
- `UserController::update()` responde «Usuario creado con exito» también al editar.
- El selector de destinos usa `User::permission('cotizaciones.editar')` (Spatie): un Super-Admin
  cuyo rol no tenga ese permiso explícito (lo tiene por `Gate::before`) no aparece, aunque el POST
  sí lo aceptaría. En agencia-demo, «Admin Agencia Demo» no figura como destino.
- El botón «Pasar su trabajo» depende de `GET clients/contexto`, que exige
  `list_client|register_client|edit_client`: quien tenga `edit_user` sin ninguno de esos no ve el
  botón ni recibe la oferta al desactivar (el aviso de Inicio sí le aparece, y su enlace abre el
  modal solo si el contexto carga).
