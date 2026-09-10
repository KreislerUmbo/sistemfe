# Plan — Menús dinámicos por tenant + control de acceso por rol

> Documento transversal (aplica a todos los giros/verticales, no solo agencia de viajes).
> Formaliza y cierra el punto que `arquitectura-multitenant-backend.md` dejaba anotado como
> "Pendiente de definir cuando se implemente" en su sección "Menú lateral y control de acceso
> (3 capas: giro + plan + roles)". Ese documento define el QUÉ (el modelo conceptual de 3
> capas ya está decidido y no se toca acá); este documento define el CÓMO — el modelo de datos
> concreto, el catálogo de roles/permisos, y el plan de ejecución.
> Estado: diseño inicial, sin ejecutar.
> Última actualización: 10-sep-2026

---

## 1. Problema real que resuelve

Hoy el menú lateral de `admin-start-kit` (template Rizz) es, hasta donde se puede confirmar
sin auditar el repo directamente, una estructura estática en el frontend — no sale de ningún
endpoint. Eso ya no alcanza porque:

1. **Cada giro necesita un menú distinto.** Un tenant `agencia_viajes` ve Cotizaciones/
   Reservas/Proveedores; un futuro tenant `veterinaria` o `comercial` va a necesitar sus
   propios ítems, sin que compartan código ni se pisen entre sí.
2. **Dentro de un mismo giro, cada usuario debe ver solo lo que su rol permite** — no todos
   los usuarios de una agencia deben ver Caja consolidada, Notas de Crédito, o Configuración.
3. **Hay un bug real ya documentado** (`plan-modulo-caja.md`, verificación de Fase 5,
   19-jul-2026) que afecta exactamente esto: `AuthController::respondWithToken()` arma
   `permissions` desde la relación legacy `role->permissions` en vez de
   `getAllPermissions()` (el método real de Spatie, que mezcla rol + permisos asignados
   directo al usuario). El backend (middleware `permission:`) siempre estuvo bien protegido,
   pero el **frontend** (`isPermitedRoute()`, y por extensión cualquier menú dinámico que se
   construya sobre esa misma respuesta de login) nunca ve un permiso asignado directo a un
   usuario. Este plan no puede construir un menú confiable por permisos sin cerrar ese bug
   primero — se agrega como Fase 0 más abajo, no como nota aparte.

## 2. Relación con lo ya decidido (no se re-discute acá)

`arquitectura-multitenant-backend.md` ya estableció el modelo de 3 capas y no cambia:

1. **`giro`/`vertical` del tenant** — determina qué existe físicamente (tablas/migraciones).
2. **Plan + `tenant_modulo_overrides`** (`plan-modulo-planes-acceso.md`) — determina qué de lo
   que existe está habilitado para usarse, vía el catálogo `modulos` (central).
3. **Roles y permisos Spatie, namespaced por tenant** — determina qué ve cada usuario dentro
   de lo que las capas 1 y 2 ya dejaron pasar.

Ese documento también ya define el contrato: un endpoint tipo `/me/menu` calcula la
intersección de las 3 capas, y el frontend solo pinta lo que ese endpoint devuelve — nunca un
archivo de configuración estático. Este plan diseña exactamente ese endpoint y todo lo que le
falta alrededor para que sea real.

## 3. Modelo de datos

### 3.1 `menu_items` (nueva, conexión `central`, catálogo compartido por giro — no por tenant)

Vive junto a `modulos` en la base central, con el mismo criterio: es catálogo de plataforma,
editado por el equipo de desarrollo (o desde un futuro CRUD en central-panel), no algo que
cada tenant reconfigura por sí mismo. Separada de `modulos` a propósito — no todo ítem de
menú es un módulo facturable/gateable (ej. "Mi Perfil", "Configuración" son siempre visibles
si el rol lo permite, sin pasar por feature-gating de plan):

```
menu_items
 - id
 - codigo            (string, único — ej. "cotizaciones", "cotizaciones.listado")
 - parent_id          (FK a menu_items.id, nullable — null = ítem de primer nivel)
 - giro                (string, nullable — null = aplica a TODOS los giros, ej. "Ventas",
                        "Caja", "Mi Perfil"; con valor = exclusivo de ese giro, ej.
                        "agencia_viajes" para "Cotizaciones")
 - modulo_id            (FK a modulos.id, nullable — si tiene valor, el ítem además exige que
                        ese módulo esté en el set de módulos_efectivos(tenant) [ver
                        plan-modulo-planes-acceso.md §2]; si es null, el ítem es "core" del
                        giro, no sujeto a feature-gating de plan)
 - permiso_requerido     (string, nullable — nombre de permiso Spatie, ej. "cotizaciones.ver";
                        null = visible para cualquier usuario autenticado del tenant que ya
                        pasó las capas 1/2, típicamente headers de grupo sin acción propia)
 - label                 (string — texto mostrado)
 - icono                 (string, nullable — clase del set de íconos que ya usa Rizz)
 - ruta                  (string, nullable — nombre de ruta Vue Router; null en headers de
                        grupo que solo agrupan hijos, sin destino propio)
 - orden                 (integer — orden dentro de su mismo parent_id)
 - tipo                  (enum: 'grupo' | 'enlace' — un 'grupo' sin hijos visibles no se
                        pinta, ver §4.2)
 - activo                (bool, default true — apagar un ítem sin borrarlo)
```

`codigo` es jerárquico por convención (`cotizaciones`, `cotizaciones.crear` como acción
embebida en un botón, no necesariamente otro `menu_item` — el árbol de `menu_items` modela
navegación, no cada permiso de acción existente).

### 3.2 Catálogo de roles y permisos por giro — qué falta sembrar

`plan-modulo-planes-acceso.md` (sección 2) y `arquitectura-multitenant-backend.md` coinciden
en que el catálogo exacto de roles base por vertical está pendiente. Se resuelve acá como
**seeders versionados por giro**, uno por vertical (`AgenciaViajesRolesSeeder`,
`RetailRolesSeeder`, etc.), corridos dentro de `TenantProvisioningService::provision()` según
el `giro` del tenant nuevo — mismo mecanismo que ya usa `PermissionsDemoSeeder` hoy (el
provisioning ya siembra roles/permisos, según lo confirmado en Fase E Paso 0 de
`panel-superadmin/plan-panel-superadmin.md`; este plan reemplaza/extiende ese seeder genérico
por uno específico por giro).

**Convención de nombres de permiso**: `{recurso}.{accion}` (ej. `cotizaciones.ver`,
`cotizaciones.crear`, `reservas.facturar`, `caja.ver_todas` — mismo patrón que los permisos ya
existentes en Caja, `cash.open_session`/`cash.view_all`/`cash.close_others_session`/
`cash.approve_expenses`, sin inventar una convención nueva).

**Catálogo inicial — giro `agencia_viajes`** (a validar contigo antes de sembrar en real,
esto es punto de partida técnico, no decisión de negocio cerrada):

**Confirmado contigo (10-sep-2026): "Gerente" y "Administrador de agencia" son el mismo rol**
(dos nombres posibles para el mismo puesto, no dos roles distintos). El catálogo real de
5 roles por tenant de agencia de viajes queda:

| Rol | Alcance |
|---|---|
| `Super-Admin` | Rol técnico del desarrollador/plataforma. Ya existe (creado en provisioning). Todos los permisos, sin excepción — no se toca. |
| `Administrador de agencia` (= Gerente) | Todos los permisos del giro salvo los técnicos/de plataforma (no incluye nada de Panel Superadmin, que ni siquiera vive en este guard). Ve y administra Cotizaciones, Reservas, Proveedores, Notas de Crédito/Débito, Caja consolidada, Configuración del tenant, y es el único que gestiona usuarios/roles (§5). |
| `Supervisor` | **Confirmado (10-sep-2026): todo el flujo operativo/comercial igual que Administrador — `ver_todas` de Cotizaciones/Reservas, Caja consolidada, Notas de Crédito/Débito — pero SIN `roles.administrar` ni acceso a Configuración del tenant.** Piensa/opera como un Administrador para el día a día del negocio, pero no puede crear usuarios, cambiar roles, ni tocar la configuración de la agencia. |
| `Vendedor de agencia` | `cotizaciones.ver/crear/editar` (alcance propio — ver §3.3), `reservas.ver/crear` (alcance propio), `caja.abrir` (si cubre turno), sin acceso a Notas de Crédito/Débito, sin Caja consolidada, sin Configuración. |
| `Contador` | `ventas.ver`, `notas_credito.ver/crear`, `notas_debito.ver/crear`, `caja.ver_todas`, `cotizaciones.ver_todas`, `reservas.ver_todas`, reportes — sin `cotizaciones.crear`/`reservas.crear` (no opera el flujo comercial, solo lo fiscal/contable, pero necesita visión completa para conciliar). |

### 3.3 Scope de datos por fila (row-level) — decisión confirmada

**Confirmado contigo (10-sep-2026): Vendedor ve solo sus propias cotizaciones/reservas;
Administrador de agencia y Gerente ven todas.** Esto es una capa aparte del menú y de Spatie
tal como se señaló — Spatie/`menu_items` deciden si el ÍTEM aparece (¿este usuario puede
entrar a "Cotizaciones" en absoluto?), el scope de fila decide qué FILAS ve una vez adentro.
Se resuelve con permisos "hermanos" por recurso, no con lógica de rol hardcodeada:

- `cotizaciones.ver` — ve el módulo, pero solo sus propias filas por defecto.
- `cotizaciones.ver_todas` — ve el módulo completo, sin filtro de dueño.
- Mismo par para `reservas.ver`/`reservas.ver_todas` (la Reserva deriva de su Cotización
  padre, según ya está documentado en `plan-modulo-codigos-numeracion.md` — mismo `vendedor_id`
  heredado, mismo criterio de scope).

`menu_items.permiso_requerido` sigue apuntando solo al permiso base (`cotizaciones.ver`) —
el ítem de menú no necesita saber nada del scope, eso es responsabilidad exclusiva de la capa
de datos.

**Mecanismo técnico — un solo punto de verdad, no repetido por controller** (mismo criterio
que ya usa el proyecto en Caja/Backups/Suscripciones): un trait de Eloquent
(`EscopablePorVendedor` o un Global Scope equivalente) aplicado a los modelos `Cotizacion` y
`Reserva`. En cada query: si `!auth()->user()->can('{recurso}.ver_todas')`, agrega
automáticamente `where('vendedor_id', auth()->id())`. Los controllers/servicios existentes no
cambian su lógica de negocio — el scope se aplica solo, salvo que el caller lo desactive
explícitamente (`Cotizacion::withoutGlobalScope(...)`) para el caso ya cubierto de
`Administrador`/`Contador`/`Super-Admin`.

**Dos advertencias técnicas reales, para que la sesión de implementación no las pase por
alto:**

1. **El scope de listado NO alcanza para show/update/delete.** Un Global Scope filtra
   automáticamente cualquier `SELECT`, pero si un Vendedor arma manualmente la URL de detalle/
   edición de una cotización ajena (adivinando o probando un ID consecutivo), un Global Scope
   bien aplicado a `find()`/`findOrFail()` ya lo bloquea con 404 — pero solo si TODAS las
   consultas pasan por Eloquent con el scope activo, nunca por `DB::table()` crudo o un
   `withoutGlobalScopes()` accidental. Se necesita además una `Policy` de Laravel
   (`CotizacionPolicy::view/update/delete`) como segunda barrera explícita, no solo confiar en
   que el scope nunca se saltee — mismo principio de "nunca un solo punto de falla" que ya
   aplica el proyecto en fiscal/tributario.
2. **Reportes/dashboards agregados** (ej. "ventas del mes por vendedor" que Administrador sí
   debe ver desglosado) necesitan revisarse aparte — si están armados con queries crudas o
   agregaciones que no pasan por el modelo Eloquent, el Global Scope no los protege ni los
   afecta; hay que decidir caso por caso si cada reporte ya es exclusivo de roles con
   `ver_todas` o si necesita su propio filtro.

**Extensión a otros roles**: `Administrador de agencia`, `Supervisor`, `Contador` y
`Super-Admin` reciben `ver_todas` de ambos recursos en el seeder (tabla de arriba, ya
actualizada). `Vendedor` es el único rol sin `ver_todas` — el único con scope propio real.

**Giro `retail` (el core original, ya construido)**: no tiene todavía un catálogo de roles
formalizado del mismo modo — usa el `role_id` legacy mencionado en el bug de arriba. Migrar
retail al mismo modelo (roles Spatie reales, seeder propio) es parte del alcance de este plan,
no un giro aparte a diseñar después — ver Fase 2 en §7.

## 4. Backend

### 4.1 `MenuResolver` (servicio nuevo, único punto de verdad)

```
MenuResolver::paraUsuario(User $user, Tenant $tenant): array
```

1. Trae `menu_items` donde `giro IS NULL OR giro = $tenant->giro`, `activo = true`.
2. Filtra los que tienen `modulo_id` contra `modulos_efectivos($tenant)` — reutiliza
   exactamente el servicio ya diseñado en `plan-modulo-planes-acceso.md` §2 (plan +
   overrides), sin reimplementar esa regla acá.
3. Filtra por permiso: `permiso_requerido IS NULL OR $user->can($permiso_requerido)` — usa
   `getAllPermissions()`/`can()` de Spatie, nunca la relación legacy (cierra el bug de §1).
4. Arma el árbol por `parent_id`/`orden`.
5. Poda grupos vacíos: un `tipo='grupo'` sin ningún hijo visible después de los filtros 1-3
   no se incluye en la respuesta (evita headers de sección vacíos en el sidebar).

### 4.2 Endpoint

```
GET /me/menu   (auth:api, dentro del contexto de tenant ya resuelto)
```

Respuesta: árbol de nodos `{codigo, label, icono, ruta, hijos: [...]}` — sin exponer
`permiso_requerido`/`modulo_id`/`giro` al frontend, son detalles de resolución del backend.

### 4.3 Caché

`MenuResolver` se cachea por `tenant_id` + `user_id` (no solo por rol — Spatie permite
permisos directos al usuario además del rol, que es justo el caso que el bug de §1 pisaba;
cachear solo por rol repetiría el mismo error en otra capa). Invalidación explícita, sin
depender de TTL como única defensa:

- Al cambiar roles/permisos de un usuario (Spatie dispara evento `Spatie\Permission\Events\*`
  — enganchar un listener que limpia `menu:{tenant_id}:{user_id}`).
- Al cambiar `tenant_modulo_overrides` o el plan del tenant (afecta a TODOS los usuarios de
  ese tenant — limpiar todo el namespace `menu:{tenant_id}:*`).
- Al desplegar un cambio de catálogo `menu_items` (raro, evento de desarrollo — limpiar
  global, todos los tenants).

## 5. Gestión de roles y permisos — nuevo módulo dentro de `admin-start-kit`

Vive en el propio tenant (Spatie ya está namespaced por tenant, y así lo confirmó la
respuesta a la pregunta de diseño) — un módulo nuevo "Roles y Permisos", visible solo con un
permiso propio (`roles.administrar`, otorgado por defecto a `Administrador de agencia` y
`Super-Admin` — **`Supervisor` queda explícitamente afuera**, es justo la línea que lo
distingue de Administrador según lo confirmado en §3.2):

- Listado de roles del tenant, con sus permisos (checklist por recurso/acción).
- Crear rol nuevo (además de los sembrados por el seeder de giro — un tenant puede necesitar
  un rol propio, ej. "Vendedor Junior" con permisos recortados).
- **Editar permisos de un rol existente — libremente, checklist marcar/desmarcar por
  recurso/acción.** El catálogo de §3.2 es el punto de partida al provisionar, no una
  restricción posterior: `Administrador de agencia` puede, por ejemplo, quitarle
  `notas_credito.crear` a `Contador`, o darle `caja.abrir` a `Supervisor` si el negocio lo
  necesita. El seeder solo siembra el estado inicial; el módulo permite reconfigurarlo por
  tenant sin tocar código ni pasar por una sesión de Claude Code.
- **Asignar permisos directos a un usuario específico, además de (o por encima de) su rol** —
  Spatie ya soporta esto de fábrica y el proyecto ya lo usa hoy (`cash.close_others_session`/
  `cash.approve_expenses` asignados directo a un usuario puntual en Caja, sin crear un rol
  nuevo solo para ese caso). El módulo expone esto como una pestaña extra en el CRUD de
  usuarios ya existente (mencionado en `plan-modulo-caja.md` como reutilizable) — ej. "este
  Vendedor en particular, además de lo que le da su rol, también puede ver todas las
  reservas" sin convertirlo en Supervisor.
- Asignar rol(es) a un usuario (uno o varios).
- **No permite** tocar el rol `Super-Admin` en sí (rol técnico protegido — ni editar sus
  permisos ni eliminarlo). Sí se puede crear/editar cualquier otro rol, incluidos los 4 roles
  de negocio del catálogo (Administrador, Supervisor, Vendedor, Contador) y cualquier rol
  nuevo que el tenant necesite.

## 6. Frontend (`admin-start-kit`)

1. El sidebar deja de leer un archivo de configuración estático — se hidrata desde
   `GET /me/menu` al login (y se vuelve a pedir si cambia el usuario activo o su rol).
2. `isPermitedRoute()` (o el guard de ruta equivalente) deja de confiar en el `permissions`
   plano que hoy entrega `respondWithToken()` (el bug de §1) — una vez corregido el backend
   para usar `getAllPermissions()`, el guard ya queda bien alimentado sin cambiar su propia
   lógica interna.
3. Los íconos/rutas del menú dinámico deben mapear 1:1 a los nombres de ruta ya registrados en
   Vue Router — la auditoría de Fase 0 (§7) debe confirmar que no hay rutas sin nombre o con
   nombres inconsistentes antes de sembrar `menu_items.ruta`.

## 7. Fases de ejecución propuestas

> Numeración de sesión real a asignar recién al momento de ejecutar, verificando contra
> `git log` de `main` como manda `ways-of-working.md` — acá solo el orden lógico.

**Fase 0 — Prerequisito + auditoría (bloquea todo lo demás)**
- Fix del bug de `AuthController::respondWithToken()` (`role->permissions` →
  `getAllPermissions()`). Bug ya documentado, blast radius = login de todos los tenants, así
  que se hace con cuidado y test de regresión antes de tocar nada de menú.
- Auditoría real del menú actual de `admin-start-kit` (¿de dónde sale hoy? ¿archivo de config,
  hardcode en el template Rizz, algo intermedio?) e inventario de rutas con nombre registradas
  en Vue Router — insumo directo para poblar `menu_items` sin inventar rutas que no existen.
- **[§9.1]** Auditoría de rutas backend sin gate real: `route:list` cruzado contra qué rutas
  mutables (POST/PUT/PATCH/DELETE) NO llevan middleware `permission:`/`can:` — inventario de
  huecos reales, no se corrigen todos en esta fase, pero quedan documentados con prioridad
  para no seguir descubriéndolos de casualidad.

**Fase 1 — Backend: modelo + resolver**
- Migración `menu_items` (central).
- `MenuResolver` + caché + invalidación (Spatie events, evento de cambio de plan/override).
- Endpoint `GET /me/menu`.
- Seeder de `menu_items` para `agencia_viajes`, basado en el inventario de Fase 0.
- Seeder de roles/permisos `agencia_viajes` (tabla de §3.2, a confirmar contigo antes de
  sembrar en `sandbox`), incluidos los permisos `*.ver_todas` de §3.3.
- Trait/Global Scope `EscopablePorVendedor` sobre `Cotizacion`/`Reserva` + Policies
  (`CotizacionPolicy`, `ReservaPolicy`) para el scope de fila decidido en §3.3.
- **[§9.3]** `Gate::before` para `Super-Admin` en `AuthServiceProvider` — acceso total sin
  depender de que el seeder liste cada permiso uno por uno; se agrega en esta fase, antes de
  sembrar el resto de roles, para que el patrón quede establecido desde el primer commit.
- **[§9.4]** Comando `roles:sync-permisos-nuevos` (análogo a `tenants:migrate-verticales`) —
  agrega a los roles base de tenants YA existentes cualquier permiso nuevo del catálogo que
  todavía no tengan, sin pisar personalizaciones manuales (§5). Se construye en esta fase
  aunque su primer uso real sea recién cuando se agregue el próximo permiso nuevo — más caro
  armarlo reactivamente después del primer incidente que dejarlo listo ahora.
- **[§9.6]** `role_audit_logs` (conexión tenant, mismo patrón que `AuditLogger` central) —
  registra quién cambió qué permiso de qué rol/usuario y cuándo, desde el primer día del
  módulo de §5 (Fase 2), no agregado después de que falte hacer una investigación real.
- **[§9.7]** Confirmar y encadenar la invalidación de `PermissionRegistrar::
  forgetCachedPermissions()` (caché interno de Spatie) en el MISMO listener que ya limpia el
  caché de `MenuResolver` (§4.3) — un solo punto de invalidación para los dos cachés, nunca
  dos mecanismos separados que puedan desincronizarse.
- **[§9.8]** Test de matriz de permisos (rol × recurso × scope) contra rutas reales — se
  escribe en esta fase, con la matriz de §3.2/§3.3 como fixture, para que cualquier fase
  posterior que toque roles/permisos corra este test antes de mergear.

**Fase 2 — Frontend: consumo dinámico**
- Sidebar hidratado desde `/me/menu`.
- Guard de rutas usando el mismo set de permisos ya corregido en Fase 0.
- Módulo "Roles y Permisos" (§5).
- **[§9.5]** Guards anti-lockout en el módulo de §5: bloquear que el ÚLTIMO usuario con
  `roles.administrar` en el tenant se quite ese permiso a sí mismo o sea degradado por otro;
  bloquear eliminar un rol con usuarios activos asignados sin reasignación previa explícita.

**Fase 3 — Retail al mismo modelo**
- Reemplazar el `role_id` legacy de retail por roles Spatie reales + seeder propio, para que
  el mismo `MenuResolver` sirva sin ramas especiales por giro.
- Correr `roles:sync-permisos-nuevos` (§9.4, ya construido en Fase 1) sobre los tenants retail
  existentes en vez de sembrar sus roles a mano — mismo mecanismo, primer uso real.

**Fase 4 — Giros futuros**
- Al agregar `veterinaria`/`comercial`/etc. (ver "Cómo agregar un rubro nuevo" en
  `claude/INDICE.md`), el paso nuevo es: seeder de `menu_items` + seeder de roles propio del
  giro — el `MenuResolver`, el endpoint y el frontend no cambian.

**Transversal, sin fase fija — feature-gating y datos de plan (§9.2)**
- Cada endpoint de un módulo gateable por plan debe validar `modulos_efectivos($tenant)`
  server-side (no solo ocultarlo en el menú) — auditoría análoga a la de §9.1, pero contra
  `plan-modulo-planes-acceso.md` en vez de contra permisos Spatie. Candidato natural: mismo
  momento que la auditoría de rutas de Fase 0, o una sesión aparte dedicada solo a esto.
- Decisión de negocio pendiente, a resolver antes de que un tenant real baje de plan por
  primera vez: qué pasa con datos ya creados bajo un módulo que el nuevo plan ya no incluye
  (¿solo lectura, siguen en reportes, se ocultan?). No bloquea nada de este plan hoy, pero
  bloquea la primera vez que alguien pida bajar de plan en producción.

**Pre-producción, no específico de este plan (§9.9)**
- Restringir `allowed_origins` (CORS) antes de escalar a más tenants reales — pendiente ya
  anotado en memoria del proyecto desde antes de este documento, se repite acá para que quede
  en un solo lugar con el resto de la checklist de seguridad.

## 8. Riesgos y decisiones abiertas (a confirmar antes de ejecutar Fase 1)

**Ya resueltos (10-sep-2026), se dejan documentados para no volver a preguntarlos:**
- Catálogo de 5 roles cerrado: `Super-Admin`, `Administrador de agencia` (= Gerente),
  `Supervisor`, `Vendedor`, `Contador` (§3.2).
- Scope de fila: Vendedor = solo propias; Administrador, Supervisor, Contador y Super-Admin =
  todas (§3.3).
- Diferencia Supervisor/Administrador: Supervisor opera igual que Administrador en el día a
  día (ve y gestiona todo el flujo comercial/operativo), pero no administra usuarios/roles ni
  Configuración del tenant — eso es exclusivo de Administrador (§3.2, §5).

**Siguen abiertos:**

1. **Alcance del fix de Fase 0**: toca el login de TODOS los tenants (retail incluido, no solo
   agencia de viajes) — mismo criterio de cautela que ya aplica el proyecto a cualquier cambio
   de blast radius amplio.
2. **Migración de retail (Fase 3)** puede ser más grande de lo que parece si hay lógica que
   hoy depende directamente de `role_id` en más lugares de los que el bug ya documentado
   señala — requiere grep completo antes de tocar nada, mismo criterio que el resto del
   proyecto.
3. **Permisos finos de "Configuración"** todavía no se desglosaron ítem por ítem (ej. ¿un
   Supervisor puede ver reportes de configuración de series/numeración sin poder editarlos?)
   — se deja para la sesión de implementación, cuando exista el inventario real de pantallas
   de Configuración (Fase 0).

## 9. Brechas de robustez y seguridad — más allá del menú (a incorporar en las fases)

El menú y el scope de fila (§3.3) resuelven qué VE cada usuario. Eso no alcanza para que el
sistema sea robusto de punta a punta. Lo siguiente son gaps reales que el diseño hasta ahora
no cubre — ninguno bloquea Fase 0, pero varios deberían entrar como tareas explícitas en
Fase 1/2, no quedar implícitos.

### 9.1 El menú y el frontend NUNCA son el límite de seguridad real

Ocultar un ítem de menú o deshabilitar un botón no impide que alguien llame al endpoint
directo (Postman, curl, o un usuario que edita el JS del navegador). El middleware
`permission:` de Laravel es la única barrera real — y hoy no hay garantía de que TODAS las
rutas nuevas la tengan. **Falta un comando o test de auditoría** (`route:list` cruzado contra
qué rutas llevan `permission:`/`can:` middleware) que detecte una ruta mutable (POST/PUT/
DELETE) sin ningún gate de autorización — para no depender de que cada desarrollador se
acuerde de agregarlo a mano en cada sesión nueva.

**CONFIRMADO CON EVIDENCIA REAL (Fase 0, 10-sep-2026)** — esto dejó de ser un riesgo teórico:
la auditoría de rutas pedida en Fase 0 encontró **69 rutas backend mutables** (`sales`,
`clients`, `products`, `roles`, `users`, `enviarSunat`, `notas`, series de comprobantes, caja,
etc.) protegidas solo por `auth:api`, sin ningún gate de permiso Spatie — `routes/api.php:
246-364`, detalle completo en `docs/planning/claude/auditoria-menu-admin-start-kit.md`. Hoy,
en producción, **cualquier usuario autenticado de un tenant, sin importar su rol, puede
borrar un rol, crear un usuario, eliminar una venta o emitir a SUNAT llamando la API
directo** — el frontend nunca mostró esos botones, pero la API nunca los bloqueó.

**Riesgo de secuenciación al corregir esto (no es tan simple como agregar `permission:` a las
69 rutas)**: el giro `retail` todavía usa el `role_id` legacy (§3.2, migración a Spatie real
recién en Fase 3) — sus usuarios reales de producción (`sandbox`, `umbo`, `negocio2`) pueden
no tener NINGÚN permiso Spatie asignado hoy. Agregar middleware `permission:` a esas 69 rutas
sin antes confirmar que cada usuario real ya tiene el permiso Spatie correspondiente
bloquearía de golpe a usuarios reales de tenants reales — cambiaría un agujero de seguridad
real por una interrupción de servicio real, ambos evitables. **Antes de cerrar este hueco
hace falta, en este orden**: (1) auditar qué permisos Spatie tiene hoy cada usuario real de
cada tenant en producción (no asumir que `PermissionsDemoSeeder` alcanzó a todos); (2)
backfillear los permisos que falten para que el comportamiento actual no cambie el día que se
agregue el gate; (3) recién entonces agregar `permission:`/`can:` a las 69 rutas, tenant por
tenant o en un solo deploy con el backfill ya confirmado. Esto probablemente adelanta partes
de la Fase 3 (roles reales de retail) antes de lo planeado — a decidir contigo si este hueco
se prioriza por delante del propio menú dinámico (Fase 1/2), dado que es una vulnerabilidad
real activa hoy, no un riesgo futuro.

**✅ Paso 1 (Bucket A) CERRADO (10-sep-2026, Fase 0b —
`docs/planning/claude/gate-bucket-a-fase0b.md` tiene la clasificación completa de las 69
rutas en Bucket A/B con motivo, y la tabla de qué permiso usa cada una).** Gateadas 38 de
las 69 rutas — las administrativas/catastróficas (roles, users, company, branches,
cash-registers, payment-methods, suppliers, cash-concepts, series-comprobante, systems,
system_categories, recursos). Quedan sin tocar a propósito las 31 de Bucket B (uso operativo
diario: categories, products, clients, sales, sale_details, sale_payments, enviarSunat,
notas, installments — Vendedor/Cajero/Contador las necesitan, ver §9.1 modo "sombra" antes de
gatearlas). Investigación real contra `sandbox`/`umbo`/`negocio2` (Postgres directo, no
supuesto): 23 permisos `register_X`/`edit_X`/`delete_X` ya existían sembrados para 8 de los
12 recursos, sin usarse en ninguna ruta — reutilizados tal cual. 11 permisos nuevos creados
(`delete_serie_comprobante`, `company`, y 3 c/u de `system`/`categorie_system`/`recurso`) vía
comando nuevo `permisos:backfill-gate-bucket-a {tenant}` (idempotente,
`Permission::firstOrCreate`, nunca toca `role_has_permissions` — a propósito, para no repetir
el riesgo real encontrado de que `PermissionsDemoSeeder::syncPermissions()` resetearía los
permisos ya customizados a mano de `Contador` en `umbo`). En `sandbox`/`umbo`/`negocio2`
ningún rol de negocio real necesitó ninguno de estos permisos — el único que hoy llama estas
rutas es el propio Super-Admin, que bypasea todo vía `Gate::before()`. 77 tests nuevos
(`GateBucketARoutesTest`, doble capa: ruta↔permiso + middleware real con/sin permiso), suite
completa 641/647 verde (6 fallos pre-existentes de `TipoCambioSunat*`, confirmados no
relacionados). Verificado en vivo contra `sandbox` con tokens JWT reales: Cajero bloqueado con
403 en 4 rutas de Bucket A distintas (incluida `delete_serie_comprobante`, el permiso nuevo);
Super-Admin pasa el gate en las mismas rutas (422/404, nunca 403).

**⚠️ Regresión real encontrada en revisión pre-merge (10-sep-2026, corregida antes de
mergear) — `sandbox`/`umbo`/`negocio2` NO fueron representativos de todos los tenants
reales.** `agencia-demo` (marcado `tipo='demo'` en `tenants`, pero en uso real de negocio —
ver nota abajo) sí tenía un rol real en uso, `Admin-General` (usuario real
`admin@gmail.com`), con 14 de los permisos de Bucket A ya asignados — pero le faltaban
`register_branch`/`edit_branch`/`delete_branch` y
`register_supplier`/`edit_supplier`/`delete_supplier` (permisos que YA EXISTÍAN antes de esta
fase, no de los 11 nuevos). Mergear el gate sin corregir esto le habría roto a
`admin@gmail.com` una capacidad real que usa hoy sin gate (crear/editar/eliminar sucursales y
proveedores). Corregido en `permisos:backfill-gate-bucket-a` — otorga esos 6 permisos a
`Admin-General`, con un chequeo explícito `if ($tenantId === 'agencia-demo')` para no
tocar ningún otro tenant. Verificado con diff de `role_has_permissions` antes/después
(85→91 filas en `agencia-demo`, las 6 agregadas son exactamente las de `Admin-General`,
ningún otro rol tocado). **Nota aparte, no resuelta acá:** el campo `tenants.tipo` de
`agencia-demo` dice `'demo'` mientras que `sandbox`/`negocio2` dicen `'real'` — lo opuesto de
cómo se usan en la práctica (todo el desarrollo de Agencia de Viajes se verifica contra
`agencia-demo` como tenant de negocio real). Vale la pena corregir ese campo en una sesión
aparte para que futuras auditorías no repitan el mismo supuesto equivocado.

**✅ MERGEADO a `main`** (`ee008a2`, `beec5c3..ee008a2`) — rama
`fix/gate-permisos-bucket-a-rutas-criticas` (commits `938b606` + `cfec262`), autorizado
explícitamente después de ver el diff completo y la evidencia de verificación.

**⚠️ Hallazgo nuevo, fuera de alcance de Fase 0b, no corregido — prioridad alta:** verificando
en vivo `POST /api/systems` se descubrió que el grupo "100% CENTRALES" (`systems`/
`system_categories`/`recursos`, `routes/api.php` línea ~129) NO lleva
`InitializeTenancyBySubdomain` — el JWT se autentica contra la conexión Postgres DEFAULT
(`sv_facturacion`, la base pre-multitenant), no contra la base del tenant real. Confirmado con
evidencia real: un token de "Sandbox Admin" (`tenantsandbox.users.id=1`) autenticó exitosamente
como "Kreisler" (`sv_facturacion.users.id=1`, `umbosac@gmail.com`) — una persona completamente
distinta, coincidencia pura de ID numérico — y pasó el gate solo porque ese Kreisler también
tiene rol Super-Admin en `sv_facturacion`. Un usuario de tenant sin ID coincidente en
`sv_facturacion` (ej. Cajero Test, id=20) recibe 401 en vez de autenticar mal — así que hoy el
daño está acotado a "algunos usuarios ven 401 sin motivo" en vez de confusión de identidad
activa, pero el mecanismo de fondo (autenticar JWT de tenant contra la base equivocada) es real
y podría exponer datos/acciones de un usuario a nombre de otro si algún tenant nuevo llega a
tener usuarios con IDs bajos que coincidan con `sv_facturacion`. No es un problema de permisos
(mi gate de Bucket A corre DESPUÉS de `auth:api` en el pipeline, así que no lo causa ni lo
tapa) — es arquitectura de tenancy, mismo tipo de gap que el ya documentado en
`arquitectura-multitenant-backend_1.md`. Requiere sesión propia.

**✅ Paso 2 (Bucket B, modo "sombra") IMPLEMENTADO Y ACTIVO (10-sep-2026, Fase 0c —
`docs/planning/claude/shadow-mode-bucket-b-fase0c.md` tiene el diseño completo, el mapeo de
las 31 rutas a permiso, y la verificación con tráfico real).** `ShadowPermissionMiddleware`
(alias `shadow.permission`) aplicado a las 31 rutas de Bucket B — nunca bloquea, solo registra
en `permission_shadow_logs` (tabla nueva, migrada a los 5 tenants) cuando el usuario
autenticado no tiene el permiso que la ruta exigiría si se gateara de verdad. 8 de los 13
permisos del mapeo son nuevos (no existían antes de esta fase, ej. `enviar_sunat`,
`registrar-pago-credito`, `registrar-cronograma-credito`, `editar-cuota-credito`,
`register_sale_detail`/`edit_sale_detail`/`delete_sale_detail`,
`register_sale_payment`/`edit_sale_payment`/`delete_sale_payment`) — deliberadamente sin
agregar todavía a `PermissionsDemoSeeder`/`roles.ts` (no hay gate real que los necesite
asignables desde la UI hasta la Parte 3). 64 tests nuevos, suite completa 705/711 verde (6
fallos pre-existentes de `TipoCambioSunat*`, no relacionados). Verificado con tráfico real
contra `sandbox`/`umbo`/`agencia-demo` (JWT vía tinker para usuarios reales — Cajero Test 2,
Jose Ricardo/Contador, Admin-General —, nunca reseteo de password): la respuesta HTTP nunca
cambió (nunca 403), no se creó ningún dato real, y el log distinguió correctamente "tiene
permiso" de "no tiene permiso" en los 3 tenants. Filas de verificación truncadas después de
confirmar — el modo sombra arranca la ventana de observación real en 0 filas desde
2026-09-10.

**⚠️ Pendiente, requiere tiempo real antes de continuar — NO ejecutado en esta sesión a
propósito:** Parte 2 (backfill dirigido por los logs reales de `umbo`/`agencia-demo`, tras un
período de uso representativo — no solo unas horas) y Parte 3 (gate real con flag de
rollback). Ambas necesitan autorización explícita antes de tocar `umbo`/`agencia-demo` — ver
`shadow-mode-bucket-b-fase0c.md` para cómo consultar `permission_shadow_logs` cuando llegue el
momento.

### 9.2 Feature-gating por plan (módulos) tiene el mismo problema, no solo el menú

`plan-modulo-planes-acceso.md` ya define la regla "sin upsell" (backend nunca lista módulos
no habilitados, ni en menú ni por endpoint directo) — pero esa regla necesita el mismo tipo de
verificación que 9.1: cada endpoint de un módulo gateable debe validar
`modulos_efectivos($tenant)` server-side, no confiar en que el frontend no mostró el botón.
Falta decidir además **qué pasa con datos ya creados cuando un tenant baja de plan** (ej.
tenía "cotizaciones con mayoristas" habilitado, generó cotizaciones reales con esa
funcionalidad, y después la pierde por downgrade) — ¿quedan de solo lectura, se siguen
mostrando en reportes, o se vuelven inaccesibles de golpe? Sin decidir esto, un downgrade real
puede romper reportes/históricos sin que nadie lo note hasta que un cliente reclame.

### 9.3 `Super-Admin` no debería depender de que el seeder esté completo

Hoy el diseño (§3.2) asume que `Super-Admin` tiene "todos los permisos" porque el seeder se
los asigna uno por uno. Si más adelante se agrega un permiso nuevo (nuevo módulo, nueva
acción) y alguien olvida agregarlo también al seeder de `Super-Admin`, el rol técnico queda
con un hueco silencioso — exactamente el tipo de "fallback silencioso" que el proyecto ya
evita en lo fiscal. **Recomendación**: `Super-Admin` no debería pasar por la tabla de permisos
en absoluto — un `Gate::before(fn($user) => $user->hasRole('Super-Admin') ? true : null)`
en `AuthServiceProvider` lo deja con acceso total siempre, sin importar qué permisos existan
hoy o se agreguen después, y sin necesitar mantenimiento del seeder para ese rol específico.

### 9.4 Sincronización de permisos nuevos a tenants YA existentes

Mismo patrón exacto que el bug ya documentado de `tenants:migrate` (nunca corría
`verticals/*` para tenants ya provisionados, solo para los nuevos vía `provision()`) puede
repetirse acá: si se agrega un permiso/módulo nuevo al catálogo, los tenants que ya existen
NO lo reciben automáticamente en sus roles — solo los tenants nuevos, vía el seeder de
provisioning. Falta un comando de mantenimiento (`roles:sync-permisos-nuevos`, análogo a
`tenants:migrate-verticales`) que agregue permisos nuevos a los roles base de cada tenant
existente, sin pisar las personalizaciones que un `Administrador` ya haya hecho a mano
(§5) — requiere distinguir "permiso nuevo del catálogo, nunca visto por este tenant" de
"el tenant lo desactivó a propósito", lo cual implica versionar el catálogo de algún modo
(ej. tabla `permisos_catalogo` con fecha de alta, y un registro de qué tenant ya sincronizó
hasta qué versión).

### 9.5 Anti-lockout — proteger contra que un tenant quede sin ningún Administrador

Si `Administrador de agencia` es el único rol que puede tocar Roles y Permisos (§5), y se
permite editar/eliminar roles libremente, un tenant puede terminar sin ningún usuario con
`roles.administrar` (por error, no por malicia) — quedando atrapado sin poder autoadministrarse
y dependiendo de que vos (Super-Admin) entres a arreglarlo a mano. Dos guards recomendados,
mismo espíritu que ya usa el proyecto para no poder eliminar `Super-Admin`:
- No permitir que el ÚLTIMO usuario con `roles.administrar` en un tenant se quite ese permiso
  a sí mismo o sea degradado por otro.
- No permitir eliminar un rol que todavía tiene usuarios activos asignados, sin forzar antes
  una reasignación explícita.

### 9.6 Auditoría de cambios de roles/permisos — no existe hoy, a nivel de tenant

`central_audit_logs` (panel superadmin) audita acciones a nivel de plataforma, pero **no hay
ningún registro de quién cambió qué permiso de qué rol dentro de un tenant**. Dado que el
módulo de §5 le da a `Administrador` la capacidad de otorgar permisos libremente (incluido a
usuarios puntuales, más allá de su rol — una superficie de escalamiento de privilegios real
si la cuenta de un Administrador se ve comprometida), conviene un log propio por tenant
(`role_audit_logs`, o reusar el mismo patrón de `AuditLogger` ya existente en el proyecto
central, adaptado a conexión tenant) que registre: quién, qué rol/usuario, qué permiso, cuándo.
Sin esto, un cambio de permisos indebido (accidental o malicioso) no deja rastro para
investigar después.

### 9.7 Caché de permisos de Spatie — invalidación inmediata, no solo el caché del menú

§4.3 ya diseña la invalidación del caché de `MenuResolver`, pero Spatie Permission tiene SU
PROPIO caché interno (independiente del nuestro) que decide qué devuelve `can()`/
`hasPermissionTo()` en cada request real — si ese caché no se limpia en el mismo instante en
que se edita un rol/permiso desde el módulo de §5, un usuario puede seguir teniendo acceso
real (no solo de menú) a algo que ya le quitaron, hasta que el caché expire. Confirmar que
`PermissionRegistrar::forgetCachedPermissions()` (o el mecanismo real de la versión de Spatie
instalada) se dispara en el mismo punto que ya se diseñó para limpiar el caché del menú — son
dos cachés distintos que deben invalidarse juntos, no uno sin el otro.

### 9.8 Test de matriz de permisos (regresión automática)

Con 5 roles × N recursos × 2 niveles de scope (propio/todas) + feature-gating por plan, la
combinatoria es demasiado grande para verificar a mano cada vez que se agrega algo. Recomendado
para Fase 1/2: un test parametrizado que recorra la matriz completa rol×acción esperada
(allow/deny) contra las rutas reales — así un cambio futuro que rompa un permiso existente se
detecta en CI, no en producción con un cliente real reportando que ve algo que no debería (o
al revés, que no puede hacer algo que sí debería).

### 9.9 Pendiente ya conocido, solo recordado acá

CORS (`allowed_origins`) sigue sin restringir antes de producción (anotado en memoria del
proyecto desde antes de este plan) — no es específico de permisos/menú, pero es parte del
mismo paraguas de "seguridad antes de escalar a más tenants reales".