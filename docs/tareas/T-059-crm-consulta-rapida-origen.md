# T-059 — CRM C4: "Nueva consulta" y origen de contacto

| | |
|---|---|
| **Módulo** | CLI + AGE |
| **Depende de** | T-050, T-051, T-033 (hechas). Crear la cotización con su primera alternativa **reusando** el camino de `CotizacionController::store()`, no armándola a mano como `VentaDirectaController` |
| **Reglas** | ayudas sin fricción (el origen nunca es obligatorio) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 16, fase C4, y respuestas 2, 3 y 5 (27-oct-2026: origen por consulta, el cliente guarda el del primer contacto; sin responsable fijo; tablas generales en el núcleo). Detalles fijados al preparar la tarea (02-nov-2026), ver «Decisiones de detalle» |
| **Rama** | `feat/T-059-crm-consulta-origen` |

## Contexto
Registrar a alguien que pregunta por WhatsApp obliga a crear el cliente con sus datos y después una
cotización completa. Tampoco se registra por dónde llegó (WhatsApp, Facebook, recomendación…), así
que no se sabe qué canal trae ventas (lo leerá T-062). Base: recorridos 20, 21 y 22 de
`docs/aprendizaje/` (T-050, T-051, T-052).

## Alcance
- **Catálogo `crm_origenes_contacto` en core** (respuesta 5: retail lo usará después): `nombre`
  (único sin distinguir mayúsculas), `activo`, `orden`, timestamps. Filas base en **la misma
  migración** (patrón de `2026_11_02_300000_create_crm_motivos_resultado_table.php`): WhatsApp,
  Facebook, Instagram, TikTok, Página web, Recomendación, Pasó por la oficina, Llamada, Cliente
  antiguo, Otro. Sección en Configuración de Agencia: agregar, renombrar, ordenar, desactivar;
  **sin DELETE**. Aquí «Otro» no tiene trato especial (no hay detalle obligatorio).
- `clients.origen_contacto_id` (core, nullable, FK al catálogo): el del **primer** contacto. Se llena
  solo cuando está null y llega una cotización con origen (por «Nueva consulta» o por
  «Nueva cotización»). Se puede corregir en la ficha (solo giro Agencia).
- `cotizaciones.origen_contacto_id` (vertical Agencia, nullable, FK al catálogo de core: misma base
  del tenant): el de esa consulta.
- En **«Nueva cotización»** (`nueva.vue`): selector de origen, por defecto **el último que usó ese
  usuario** (su cotización más reciente con origen); opcional.
- **«Nueva consulta»** (modal): nombre (obligatorio), teléfono, origen, destino de interés
  (obligatorio), fechas tentativas (opcionales), cantidad de pasajeros (1 por defecto) y nota.
  - Mientras escribe teléfono o nombre, busca clientes que coincidan y los propone («¿Es Juan Pérez
    · 999 888 777?»). Si lo elige, se usa ese cliente y no se crea otro.
  - Si no elige ninguno, crea el cliente **SND** (`n_document = '00000000'`, como
    `ClienteRequest::prepareForValidation()`) con nombre, teléfono y origen.
  - Crea la cotización con su «Alternativa A» (mismo camino que `store()`), vendedor = el usuario,
    con el origen.
  - La nota, si hay, queda como actividad `nota` de T-050 referida a la cotización.
  - Casilla opcional «Agendar "Enviar cotización" para hoy» (T-051): acción `enviar_cotizacion`,
    fecha `HoraPeru::hoyTexto()`, responsable = el usuario, referida a la cotización. Desmarcada por
    defecto.
  - Todo en **una** transacción: si falla la alternativa (p. ej. falta tipo de cambio) no queda un
    cliente suelto.
  - Al terminar lleva al editor de la cotización.
- Botón «Nueva consulta» en el listado de cotizaciones (junto a «Nueva cotización») y en el
  bloque de cotizaciones de Inicio (Agencia).
- Columna «Origen» y filtro por origen en el listado de cotizaciones.

## Decisiones de detalle (02-nov-2026, al preparar la tarea)
- **Pasajeros:** la cantidad crea N adultos de **30 años, nacionales** (el mismo valor que el botón
  «+ Adulto(s)» de `nueva.vue`). Las edades se corrigen después en el cotizador (editar pasajeros
  ya existe). Mínimo 1, máximo 30.
- **Destino obligatorio:** `cotizaciones.destino` lo exige hoy; es lo mínimo que se sabe de una
  consulta.
- **Siempre crea la cotización.** La obs. 16 decía «si se quiere»; la tarea aprobada la crea siempre:
  una consulta sin cotización no aparece en ningún listado ni bandeja. Si el usuario lo quiere
  distinto, se cambia antes de construir.
- **Moneda y tipo de cambio:** los de siempre (moneda por defecto de la agencia, tipo de cambio del
  día). Si `AlternativaCreacionService` pide confirmar o ingresar tipo de cambio (422 con `extra`),
  el modal lo muestra igual que `nueva.vue` y reenvía con los campos.
- **Búsqueda de coincidencias:** por teléfono, comparando **solo dígitos** y los últimos 9
  (`regexp_replace(phone, '\D', '', 'g')`); por nombre, `full_name ILIKE %texto%` desde 3 letras.
  Máximo 5, entre clientes activos. No reusar `GET clients?search=`: compara el texto tal cual y
  «999 888 777» no encuentra «999888777».

## Fuera de alcance
- Consultas que entran solas por un formulario web o WhatsApp Business (integraciones).
- Reportes por origen (T-062). Columna de seguimiento en el listado (T-053).
- Origen en retail o Créditos (el catálogo queda en core, pero solo se usa en Agencia).
- Cambiar el alta normal de clientes (`ClientController::store()`) o sus reglas de duplicado.

## Dónde tocar
**Backend** (`api-sistema-fe/`)
- Migraciones nuevas (no editar las existentes):
  - `database/migrations/tenant/core/2026_11_0X_…_create_crm_origenes_contacto_table.php` (catálogo +
    filas base) y `…_add_origen_contacto_id_to_clients.php`.
  - `database/migrations/tenant/verticals/agencia-viajes/…_add_origen_contacto_id_to_cotizaciones.php`.
- `app/Models/Client/CrmOrigenContacto.php` (nuevo, como `CrmMotivoResultado`: `scopeActivos()`,
  `scopeOrdenados()`); `Client` (`$fillable`) y `Cotizacion` (`$fillable`, relación `origenContacto()`).
- Catálogo: `app/Http/Controllers/Client/CrmOrigenContactoController.php` (index, store, update; sin
  destroy). Copiar el patrón de `AgenciaViajes/CrmMotivoResultadoController.php` (nombre repetido
  sin mayúsculas, nuevo al final). `index` devuelve además `ultimo_del_usuario` (origen de la
  cotización más reciente del usuario con origen). Rutas junto a `crm-motivos-resultado` en
  `routes/api.php` (~823): `GET` con `permission:agencia.configuracion|cotizaciones.crear`,
  `POST`/`PUT` con `permission:agencia.configuracion`.
- **Crear cotización reusable:** hoy la lógica está en el método privado
  `CotizacionController::crearCotizacionConAlternativa()` (~196) más `umbralesEdad()` y
  `derivarTipoPax()`. Moverla a un servicio (p. ej. `app/Services/AgenciaViajes/CotizacionCreacionService.php`)
  que usen `store()` y la consulta, **sin cambiar el comportamiento de `store()`** (sus tests
  deben seguir verdes). `store()` acepta además `origen_contacto_id` (nullable, existe y activo).
- Al crear una cotización con origen: si `clients.origen_contacto_id` es null, llenarlo (en el
  servicio, dentro de la transacción).
- **Consulta:** controller nuevo `app/Http/Controllers/AgenciaViajes/ConsultaController.php`:
  - `GET crm/consultas/coincidencias?telefono=&nombre=` → hasta 5 `{id, full_name, phone, type_document}`.
  - `POST crm/consultas` → `{cliente_id?, nombre, telefono?, origen_contacto_id?, destino,
    fecha_viaje_desde?, fecha_viaje_hasta?, pasajeros (1-30), nota?, agendar_envio?, +campos de tipo
    de cambio}`. Responde `{cotizacion: {id, codigo}, cliente_creado: bool}`.
  - Rutas junto a `cotizaciones` (~978) con `permission:cotizaciones.crear`. Si **no** viene
    `cliente_id`, además `register_client` (revisarlo en el controller: 403). Si viene, el cliente
    tiene que existir.
  - Teléfono con la misma regla que `ClienteRequest` (`max:25`, `regex:/^[0-9+\-\s()]*$/`); nombre
    `max:250`.
  - Actividad: `CrmActividad::create([... 'tipo' => CrmActividad::TIPO_NOTA, referencia cotización])`,
    como `ResultadoCotizacionService::registrarActividad()`.
  - Acción: `CrmAccion` tipo `TIPO_ENVIAR_COTIZACION`; ver cómo la crea `CrmAccionController::store()`
    (campos `cliente_id`, `referencia_*`, `fecha`, `responsable_id`, `creada_por`).
- `CotizacionController::index()` (~69): `with('origenContacto:id,nombre')` y filtro
  `origen_contacto_id`.
- Ficha: `ClientController::allowedFields()` + `ClienteRequest` aceptan `origen_contacto_id`
  (nullable, `exists`; puede quedar uno desactivado si ya lo tenía).

**Frontend** (`admin-start-kit/src/`)
- `services/admin/crmOrigenContactoService.ts` (nuevo, como `crmMotivoResultadoService.ts`) y
  `consultaService.ts` (nuevo) o métodos en `cotizacionService.ts`.
- `components/AgenciaViajes/NuevaConsultaModal.vue` (nuevo, modal con `Teleport` como
  `CerrarComoPerdidaModal.vue`; inputs `-sm`, fechas con `CampoFecha.vue`). Coincidencias con
  debounce (~300 ms).
- `components/AgenciaViajes/OrigenesContactoSeccion.vue` (nuevo, copia de
  `MotivosResultadoSeccion.vue`) en `views/agencia-viajes/configuracion/index.vue` (~616).
- `views/agencia-viajes/cotizador/index.vue`: botón junto a «Nueva cotización» (~11), columna
  «Origen» (~59) y filtro.
- `views/agencia-viajes/cotizador/nueva.vue`: selector de origen con el último del usuario.
- `views/dashboards/analytics/index.vue`: botón en el bloque de cotizaciones (~210).
- `views/clients/ficha.vue`: selector de origen en la tarjeta de Agencia (~245,
  `contexto.giro === 'agencia_viajes'`).
- `types/agencia-viajes.ts`: `origen_contacto_id` y `origen_contacto` en `Cotizacion`.

## Criterios de aceptación
- [ ] **CA1** — Consulta con nombre, teléfono y origen «Facebook», 2 pasajeros → cliente SND con
  origen Facebook, cotización con «Alternativa A», 2 adultos, el mismo origen y vendedor = usuario;
  con nota → actividad `nota`; con la casilla → acción `enviar_cotizacion` para hoy (Perú). → test
- [ ] **CA2** — Coincidencias: «999 888 777» encuentra al cliente guardado como «999888777» y como
  «+51 999888777»; consulta con `cliente_id` no crea otro cliente. → test + prueba funcional
- [ ] **CA3** — Segunda cotización del mismo cliente con origen «Recomendación» → la cotización lo
  guarda; el cliente conserva «Facebook». Cliente sin origen + cotización con origen → el cliente lo
  toma. → test
- [ ] **CA4** — Desactivar un origen en uso → deja de ofrecerse y no se acepta en una cotización
  nueva; los datos quedan; no hay ruta DELETE. → test
- [ ] **CA5** — Sin `register_client` y sin `cliente_id` → 403; sin `cotizaciones.crear` → 403;
  falla del tipo de cambio → 422 y **no** queda cliente creado. → test
- [ ] **CA6** — `store()` de siempre sin cambios (tests de `nueva cotización` verdes) y acepta
  `origen_contacto_id`; listado con columna y filtro por origen. → test
- [ ] **CA7** — Prueba funcional en agencia-demo (consulta nueva, consulta de un cliente que ya
  existe, origen en la ficha, filtro del listado); suite completa en verde; `type-check` sin errores
  nuevos (base 47).

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: consulta con `cotizaciones.crear` (+ `register_client` si crea
  cliente); catálogo con `agencia.configuracion` (lectura también con `cotizaciones.crear`)
- [ ] Aislamiento de tenant: todas las tablas en la base del tenant
- [ ] Validación de entrada / archivos: teléfono como `ClienteRequest`; nombre obligatorio; origen
  existe y activo; pasajeros 1-30; nota máx. `CrmActividad::NOTA_MAX`
- [ ] Concurrencia (`lockForUpdate`): código de cotización con `CodigoGeneradorService` (ya lo hace)
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: fechas tentativas son fechas de negocio; la acción «para hoy» con
  `HoraPeru::hoyTexto()`
- [ ] Tributario sin fallback silencioso: un cliente SND no recibe factura (validación existente)
- [ ] Sin `v-html` sin sanitizar: nombres y teléfonos de las coincidencias como texto
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí (core y Agencia)

## Cómo verificar
```
php artisan test --filter="Cotizacion|Cliente|Crm|Consulta"
cd admin-start-kit && npm run type-check && npx vitest run
```
Prueba funcional: agencia-demo, `admin@agencia-demo.test` (memoria `reference_agencia_demo_admin_login`).

---

## Resultado
- **Commits:** `34c6f3e` backend (migraciones, `CotizacionCreacionService`, `ConsultaController`,
  `CrmOrigenContactoController`, ficha y listado), `66e13f6` frontend, y el de docs.
- **Tests:** `tests/Feature/AgenciaViajes/ConsultaOrigenContactoTest.php` (14 tests, 97 aserciones:
  CA1-CA6, validaciones, ficha, catálogo, `ultimo_del_usuario`, permisos de rutas);
  `AlternativaAutomaticaTest` (store de T-033) sigue verde sin cambios. Vitest:
  `helpers/clientes/coincidencias.test.ts` (3). Suite completa contra Postgres: 1877 pasan (7462 aserciones), sin fallos (tampoco el intermitente conocido). `type-check`: 47
  (base, sin nuevos); Vitest 173/173; `build` OK. Migraciones: `down()` probado en
  `sistemafe_test_migrations` (rollback de las 3 y vuelta a subir); aplicadas con
  `tenants:migrate-verticales` (core en todos los tenants, la de cotizaciones en agencia-demo).
- **Prueba funcional (Playwright, agencia-demo, `admin@agencia-demo.test`):**
  1. Ficha del cliente 10 (ROXANA…): teléfono `987654321` y origen «Instagram»; se guarda y al
     recargar sigue «Instagram».
  2. «Nueva consulta» desde el listado: «Prueba T059 Consulta Nueva», `955 444 333`, Facebook,
     Tarapoto, 2 pasajeros, nota y casilla → cliente 31 SND con Facebook, `CDKM-1126-0000007` (id 75)
     con «Alternativa A», 2 adultos de 30 nacionales, nota y «Enviar cotización · lun 02/11» visible en
     la cabecera del cotizador.
  3. «Nueva consulta» desde el Inicio escribiendo `987 654 321`: propone «¿Es ROXANA… · 987654321?»;
     el origen venía en Facebook (el último del usuario); elegida la clienta y origen «Recomendación»
     → `CDKM-1126-0000008` (id 76) del cliente 10, sin cliente nuevo; la clienta conserva «Instagram».
  4. Listado: columna «Origen» y filtro «Facebook» → solo `CDKM-1126-0000007`. Sin errores de consola.
     La sección 15 «Orígenes de contacto» de Configuración muestra las 10 filas base.
- **Desviaciones de la tarea y por qué:**
  - Único sin mayúsculas también en la base (índice `LOWER(nombre)`), además de la validación en PHP.
  - Coincidencias por teléfono: los últimos 9 dígitos de lo escrito, **contenidos** en los dígitos del
    guardado (desde 6 dígitos), para que proponga mientras se escribe; con 9 dígitos equivale a
    comparar los últimos 9.
  - `nombre` es obligatorio también con `cliente_id` (el modal manda el nombre del cliente elegido).
    Con cliente elegido no se le cambia el teléfono.
  - `ClientResource` devuelve `origen_contacto_id` (la ficha lo necesita). La ficha muestra el
    selector también al registrar un cliente nuevo en Agencia, y lista el desactivado solo si es el
    que tiene el cliente.
  - En el Inicio la tarjeta de cotizaciones era un `router-link` entero: queda una tarjeta con el
    cuerpo como enlace y el botón al pie (un botón dentro de un enlace no es válido).
  - El botón «Nueva consulta» se muestra solo con `cotizaciones.crear`; sin `register_client` el
    modal avisa y solo deja usar un cliente que ya existe.
  - `CotizacionController` ya no inyecta `CodigoGeneradorService` ni `AlternativaCreacionService`
    (los usa el servicio nuevo).

## Hallazgos fuera de alcance
- ~~`GET crm-origenes-contacto` solo con `agencia.configuracion|cotizaciones.crear`~~ → arreglado en
  la revisión de Opus: la lectura se abre también a `cotizaciones.ver`, `cotizaciones.ver_todas`,
  `register_client` y `edit_client` (filtro del listado y selector de la ficha).
- ~~Dos altas simultáneas del mismo origen dan 500~~ → arreglado en la revisión: el choque con el
  índice único (23505) responde 422 «Ya existe un origen con ese nombre», en el alta y al renombrar
  (`test_alta_simultanea_del_mismo_origen_responde_422_y_no_500`). El catálogo de motivos de T-052
  no tiene índice: ahí la carrera deja dos filas iguales, sin error.
- El botón «Nueva Cotización» del listado se muestra sin mirar `cotizaciones.crear` (anterior a
  T-059).
- «Nueva consulta» crea el cliente SND sin la confirmación de «nombre ya registrado» que hace
  `ClientController::store()` (409): las coincidencias la reemplazan, pero si el usuario las ignora
  quedan dos clientes con el mismo nombre.
