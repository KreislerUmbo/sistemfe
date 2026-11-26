# T-135 — Comprobante de compra (y módulos activables por tenant)

| | |
|---|---|
| **Módulo** | COM (+ PLA por la parte A) |
| **Depende de** | T-134 (hecha) |
| **Reglas** | «Fechas y horas», «Archivos por tenant» y «Dinero y caja» de `CLAUDE.md`; multi-tenancy (catálogos centrales sin FK) |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7, §3.7c (paso 1), §3.7f punto 4 y §5c (C8); `plan-modulo-planes-acceso.md` §2 (modelo) y §3.5 (doble capa); decisiones D1-D9 de abajo (usuario, 26-nov-2026) |
| **Rama** | `feat/T-135-comprobante-de-compra` |

## Contexto
Compras es un módulo del sistema base, para todos los giros. Esta tarea registra el **comprobante
recibido**, solo con montos:
- **sin pagos:** van en T-136, con `pagos_proveedor` y aplicaciones;
- **sin líneas de producto:** van en T-154;
- **sin asignación a reserva o salida:** va en T-152;
- **sin validación SUNAT:** va en T-139.

El diseño ya está cerrado en §3.7. Lo que esta tarea fija es lo que el plan dejaba abierto frente al código.

**Estado del código al empezar (verificado el 26-nov):**
- **No existe ningún mecanismo de «módulo activable por tenant».** `menu_items.modulo_id` existe
  (nullable, sin FK) y no lo usa nadie. `MenuResolver::resolver()` dice explícitamente que el
  filtro por `modulos_efectivos($tenant)` no está construido. Y como el Propietario recibe **todos**
  los permisos en cada deploy (`roles:sync-propietario`), sin un flag Compras le aparecería a
  todos los dueños, también a `credishirley`.
- **La tabla 10 de SUNAT ya existe:** el catálogo central `tipos_comprobante`
  (`App\Models\Sale\TipoComprobante`, clave `codigo`), con los códigos 00 a 14 más `NV`. Le falta
  el **91** (comprobante del exterior) y una marca de cuáles sirven para compras.
- Proveedor (T-134): `App\Models\Proveedor`, con `numero_documento`, `tipo_documento`,
  `es_no_domiciliado`, `pais` y `ciudad`. El RUC **no es único**: varios establecimientos pueden
  compartirlo. Alta rápida: `components/Proveedores/ProveedorAltaRapida.vue`.
- Tipo de cambio: `App\Services\TipoCambio\TipoCambioSunatResolver::resolverParaFecha()`.
- Adjuntos: `App\Rules\ArchivoSubido` (10 MB). La URL firmada de `Sale\ArchivoSunatController`
  (`URL::temporarySignedRoute`) es el patrón para descargar sin exponer la ruta.
- Un interruptor por tenant en el Panel Superadmin, con auditoría:
  `Central\TenantAdminController::updateFacturacionHabilitada()`.

## Decisiones (26-nov-2026)
- **D1 — Módulos activables: la parte mínima del diseño de `plan-modulo-planes-acceso.md`, sin planes.**
  - Tabla central `modulos` (`id`, `codigo` único, `nombre`, `descripcion`, `giro` nullable) y
    tabla central `tenant_modulo_overrides` (`tenant_id`, `modulo_id`, `habilitado`, `notas`,
    timestamps, único por `tenant_id` + `modulo_id`).
  - `módulos_efectivos(tenant)` = los overrides con `habilitado = true`. Los módulos del plan
    (`plan_modulo`) no entran todavía.
  - Se siembra el módulo `compras`. **Nace apagado en todos los tenants.**
  - Doble capa, como en §3.5: el menú no lista lo que no está activo, y un middleware bloquea la API.
  - Interruptor en el detalle del tenant del Panel Superadmin, con auditoría.
  - Rendiciones y Registros lo van a reusar.
- **D2 — Tipo de comprobante:** se reusa `tipos_comprobante`. Migración central: columna
  `uso_compras` (boolean) y fila `91` «Comprobante de no domiciliado».
  - `uso_compras = true`: 01, 02, 03, 04, 05, 07, 08, 10, 12, 13, 14 y 91.
  - Quedan fuera: 00, 06, 09, 11 y NV.
  - Sin FK cross-DB: el código se valida en PHP contra el catálogo.
- **D3 — No duplicados.** La compra guarda una **copia del documento del proveedor**
  (`proveedor_documento`).
  - La clave única es `clave_proveedor` + tipo + serie + número, donde `clave_proveedor` es
    `proveedor_documento` en mayúsculas y sin espacios, o `id:<proveedor_id>` si el proveedor no
    tiene documento.
  - Así dos establecimientos del mismo RUC no registran dos veces la misma factura, como pide el
    paso 1 de §3.7c.
  - Índice único parcial **excluyendo las anuladas** (una anulada no impide registrarla de nuevo bien).
  - El segundo intento responde 409 con la compra existente.
- **D4 — Establecimiento:** la compra lleva `proveedor_id`, el establecimiento elegido. Si el RUC
  tiene varios, el buscador los muestra con su ciudad («Casa Andina — Tarapoto») y se elige uno.
  **No se crea un proveedor «General»:** si la factura es de la oficina central, el usuario crea
  ese proveedor con el alta rápida. Los reportes agrupan por `proveedor_documento`.
- **D5 — Proveedor sin documento** (§3.7c, «se pide completar el RUC en la primera compra»):
  `store()` responde **422 con `requiere_documento: true`** si el proveedor no tiene
  `numero_documento`, y la pantalla abre la edición del proveedor para completarlo.
  - Excepción: un no domiciliado con tipo **91**.
  - Para 01, 02, 05, 07, 08 y 14 el documento tiene que ser RUC de 11 dígitos. Para 04
    (liquidación de compra) basta DNI o RUC.
- **D6 — Periodo de anotación y periodo cerrado.**
  - `periodo` es un texto `AAAA-MM`. Por defecto, el mes de la emisión; no puede ser anterior.
    Tope superior: 12 meses después de la emisión, **a confirmar con el contador (P13)**. Mientras
    tanto, solo un aviso en pantalla, sin bloquear.
  - Tabla de tenant `compra_periodos`: `periodo` único, `estado` (`abierto` o `cerrado`),
    `cerrado_at`, `cerrado_por`, `reabierto_at`, `reabierto_por`, `motivo_reapertura`.
  - Un periodo sin fila está **abierto**.
  - En un periodo cerrado no se crean, editan ni anulan compras: 422 «El periodo AAAA-MM está
    cerrado». Corregir = registrar en un periodo abierto (§3.7).
- **D7 — Reabrir un periodo: sí, con permiso y motivo** (decisión del usuario). Lo hace quien
  tiene `compras.cerrar_periodo`, que también cierra. Reabrir exige motivo y deja quién y cuándo.
  Cada cierre y reapertura queda en la tabla; nada se borra.
- **D8 — Anular, nunca borrar.** No hay `destroy()`. Anular pide motivo y guarda `anulada_at` y
  `anulada_por`. La regla «con pagos aplicados no se anula» llega con T-136; acá solo se deja el
  punto donde va (un comentario en el método).
- **D9 — Adjuntos privados:** PDF y XML en el disco **`private`** del tenant (son facturas de
  terceros). Se descargan con una URL firmada de 10 minutos, mismo patrón que
  `ArchivoSunatController`. Nada de `/storage/` público.

## Alcance

### Parte A — Módulos activables (commit propio)
- **Migraciones centrales:** `modulos`, `tenant_modulo_overrides` y el seed del módulo `compras` (D1).
- **Servicio** `App\Services\ModulosTenant`:
  - `efectivos(Tenant): array` de códigos, con caché por tenant;
  - `activo(Tenant, string $codigo): bool`;
  - `cambiar(Tenant, string $codigo, bool $habilitado, ?string $notas)`, que limpia la caché del
    tenant y la del menú (`MenuResolver::invalidarTenantActivo()` dentro de `$tenant->run()`).
- **Middleware** `modulo:<codigo>` (alias en `bootstrap/app.php`): responde 403 «Este módulo no
  está habilitado» si el módulo no está activo en el tenant actual.
- **Menú:** `MenuResolver::resolver()` agrega el filtro donde hoy está el comentario del «Paso 2».
  Un `menu_item` con `modulo_id` solo se muestra si ese módulo está en `efectivos()`; sus hijos se
  caen con él.
- **Panel Superadmin:**
  - `GET tenants/{id}/modulos` (catálogo con su estado en ese tenant);
  - `PUT tenants/{id}/modulos/{codigo}` con `habilitado`, que deja auditoría
    `tenant.modulo_updated` (valor anterior y nuevo), mismo patrón que
    `updateFacturacionHabilitada()`;
  - en `central-panel/src/views/TenantDetailView.vue`, una sección «Módulos» con un interruptor por módulo.

### Parte B — Compras
- **Migración de tenant** (`tenant/core/`), tabla `compras`:

  | Grupo | Columnas |
  |---|---|
  | Proveedor | `proveedor_id` (FK `proveedores`), `proveedor_documento` (copia), `clave_proveedor` |
  | Comprobante | `tipo_comprobante_codigo` (string 2, tabla 10), `serie` (string 20), `numero` (string 20), `fecha_emision` (date), `periodo` (char 7) |
  | Moneda | `moneda` (`PEN`/`USD`), `tipo_cambio` (decimal 10,4, como `sales.tipo_cambio_sunat_aplicado`; nullable en PEN) |
  | Montos (decimal 14,2) | `base_gravada`, `igv`, `exonerado`, `inafecto`, `otros`, `total` |
  | Condición | `condicion` (`contado`/`credito`), `fecha_vencimiento` (date, nullable) |
  | Referencia | `compra_referencia_id` (nullable, para NC/ND) |
  | Adjuntos | `adjunto_pdf`, `adjunto_xml` (rutas en `private`) |
  | Otros | `estado_sunat` (string, default `sin_validar`: lo llena T-139), `observaciones` |
  | Anulación | `anulada_at` (timestamp UTC), `anulada_por`, `motivo_anulacion` |
  | Auditoría | `user_id`, timestamps |

  Índice único parcial (D3): `(clave_proveedor, tipo_comprobante_codigo, serie, numero) WHERE anulada_at IS NULL`.
  Índices por `periodo` y por `proveedor_id`.
- **Migración de tenant:** `compra_periodos` (D6, D7).
- **Modelos:** `App\Models\Compras\Compra` y `CompraPeriodo`.
- **Validación** (en un servicio, p. ej. `App\Services\Compras\CompraService`, que usa el controlador):
  - el tipo es un código del catálogo con `uso_compras`;
  - la serie y el número no van vacíos; se guardan en mayúsculas y sin espacios;
  - la fecha de emisión no es futura (`HoraPeru::hoyTexto()`);
  - el periodo no es anterior a la emisión, y su periodo no está cerrado (D6);
  - la moneda: en `USD` se exige `tipo_cambio > 0`; si no viene, la pantalla lo propone con
    `TipoCambioSunatResolver::resolverParaFecha(fecha_emision)`;
  - los montos son ≥ 0 y `total = base_gravada + igv + exonerado + inafecto + otros` al centavo
    (con `Brick\Math\BigDecimal`, como en ventas);
  - el IGV no se recalcula: se registra lo que dice el comprobante;
  - si la condición es `credito`, la fecha de vencimiento es obligatoria y ≥ emisión; en `contado` va vacía;
  - en una NC/ND (07/08), `compra_referencia_id` es obligatoria, es del mismo `clave_proveedor` y no está anulada;
  - el proveedor sin documento sigue D5;
  - el duplicado sigue D3 (409 con la existente).
- **API** (`routes/api.php`, todas con `modulo:compras`):

  | Ruta | Qué hace | Permiso |
  |---|---|---|
  | `GET compras` | Filtros: `periodo`, `proveedor_id`, `tipo`, `search` (serie-número o razón social), `incluir_anuladas`. Paginado, con totales del filtro en PEN | `compras.ver` |
  | `GET compras/{id}` | Detalle de una compra | `compras.ver` |
  | `POST compras` | Crear (multipart, por los adjuntos) | `compras.registrar` |
  | `POST compras/{id}` | Editar (multipart), solo si no está anulada y su periodo está abierto | `compras.registrar` |
  | `POST compras/{id}/anular` | Anular, con `motivo` | `compras.anular` |
  | `GET compras/{id}/adjunto/{pdf\|xml}` | Devuelve la URL firmada (D9) | `compras.ver` |
  | `GET compra-periodos` | Lista de periodos | `compras.ver` |
  | `POST compra-periodos/{periodo}/cerrar` | Cerrar un periodo | `compras.cerrar_periodo` |
  | `POST compra-periodos/{periodo}/reabrir` | Reabrir, con `motivo` | `compras.cerrar_periodo` |

  La ruta firmada de descarga sigue el patrón de `sunat.archivo`.
- **Permisos** (migración de tenant, sin backfill, porque son nuevos): `compras.ver`,
  `compras.registrar`, `compras.anular` y `compras.cerrar_periodo`.
  - El Propietario los recibe con `roles:sync-propietario`.
  - Agregarlos a `PermissionsDemoSeeder` y a `admin-start-kit/src/types/roles.ts`, en un grupo «Compras».
- **Menú** (`MenuItemsSeeder`, todos los giros, `giro` NULL): grupo «Compras» con «Comprobantes»
  (`compras.ver`) y «Periodos» (`compras.cerrar_periodo`), con `modulo_id` = el módulo `compras`.
  - El seeder busca el id por código. Ojo: el módulo vive en la base central, igual que `menu_items`.
- **Pantallas** (`admin-start-kit/src/views/compras/`), con el estilo de Ventas y Agencia
  (inputs `-sm`, `card-header`, `CampoFecha.vue`):
  - **`index.vue`** — filtros (periodo, proveedor, tipo, buscar, «ver anuladas») y tabla:
    - columnas: fecha, tipo, serie-número, proveedor con ciudad, moneda, total y condición, con
      la fecha de vencimiento en rojo si ya pasó;
    - badge «Anulada» y totales al pie;
    - acciones: ver, editar y anular (con motivo).
  - **`form.vue`** (crear y editar):
    - **proveedor:** buscador de todos los proveedores (`proveedores-base`, alcance todos) con
      ciudad, y el alta rápida montada para crear uno sin salir;
    - **comprobante:** tipo (selector del catálogo con `uso_compras`), serie, número, fecha de
      emisión, periodo (por defecto el de la emisión), moneda y tipo de cambio sugerido, montos con
      el total calculado y el aviso si no cuadra, condición y vencimiento, compra de referencia si
      es NC/ND, adjuntos;
    - **409:** «Ya está registrada» con enlace a la existente;
    - **422 `requiere_documento`:** abre la edición del proveedor para completar el RUC.
  - **`periodos.vue`** — lista de periodos con su estado, cerrar y reabrir (con motivo), y quién y cuándo.
  - Rutas en `src/router/routes.ts`, con el permiso de cada una.

## Fuera de alcance
- Pagos, estado pagado/parcial/vencido derivado de pagos, cuentas por pagar y el aviso desde Caja (T-136 y siguientes).
- Líneas de producto, stock y costo promedio (T-154). Asignación a reserva o salida (T-152).
- Validación SUNAT del comprobante (T-139) y SIRE (fase 8). Registro de Compras / PLE.
- Gasto sin comprobante (T-138). Cuotas de una compra a crédito (solo vencimiento).
- Detracción y retención de 4ta (con los pagos, P13).
- Módulos del plan (`plan_modulo`), dependencias entre módulos (`modulo_requiere`) y el resto del
  feature-gating de `plan-modulo-planes-acceso.md`.
- Aplicar `modulo:` a módulos que ya existen: solo Compras nace con flag.

## Dónde tocar
- **Central:**
  - `api-sistema-fe/database/migrations/` — `modulos`, `tenant_modulo_overrides` y la columna
    `uso_compras` + fila 91 en `tipos_comprobante`;
  - `app/Models/Central/Modulo.php` y `TenantModuloOverride.php` (`CentralConnection`);
  - `app/Services/ModulosTenant.php`, `app/Http/Middleware/EnsureModuloActivo.php`, `bootstrap/app.php`;
  - `app/Services/MenuResolver.php` → `resolver()`;
  - `app/Http/Controllers/Central/TenantAdminController.php` (o un controlador `TenantModuloController` al lado) y sus rutas centrales;
  - `central-panel/src/views/TenantDetailView.vue`.
- **Tenant:**
  - `database/migrations/tenant/core/` — `compras`, `compra_periodos` y permisos;
  - `app/Models/Compras/`, `app/Services/Compras/CompraService.php`;
  - `app/Http/Controllers/Compras/CompraController.php` y `CompraPeriodoController.php`;
  - `routes/api.php`;
  - `database/seeders/MenuItemsSeeder.php` y `PermissionsDemoSeeder.php`.
- **Frontend:** `admin-start-kit/src/views/compras/`, `src/router/routes.ts`, `src/types/roles.ts`
  y un `src/types/compras.ts`.

## Criterios de aceptación
- [ ] **CA1** — Con el módulo `compras` apagado: el menú no muestra «Compras» y `GET compras` da 403,
  aunque el usuario tenga `compras.ver`. Al encenderlo desde el Panel Superadmin, aparecen los dos
  sin volver a iniciar sesión (la caché del menú se limpia), y queda la auditoría.
- [ ] **CA2** — Una compra válida en PEN y otra en USD con tipo de cambio se guardan. Un total que
  no cuadra al centavo, un tipo fuera de `uso_compras`, una fecha futura o un crédito sin
  vencimiento dan 422.
- [ ] **CA3** — Registrar dos veces la misma factura da 409 con la existente, también si se elige
  otro establecimiento del mismo RUC. Si la primera está anulada, se puede registrar de nuevo.
- [ ] **CA4** — Un proveedor sin documento da 422 con `requiere_documento`. Un no domiciliado con
  tipo 91 se registra sin RUC.
- [ ] **CA5** — Con el periodo cerrado, crear, editar o anular una compra de ese periodo da 422.
  Reabrirlo sin motivo da 422; con motivo se reabre, queda quién y cuándo, y se puede editar.
- [ ] **CA6** — Anular exige motivo, deja `anulada_at`/`anulada_por` y la compra sigue en la base.
  No existe ruta para borrar.
- [ ] **CA7** — Una NC sin compra de referencia, o con una de otro proveedor, da 422.
- [ ] **CA8** — El adjunto PDF se guarda en `private` y solo se descarga con la URL firmada; la URL
  vencida o adulterada da 403.
- [ ] **CA9** — Sin `compras.registrar` no se puede crear (403). Sin `compras.cerrar_periodo` no se
  puede cerrar ni reabrir (403).
- [ ] **CA10** — Las migraciones corren en un tenant de cada giro (retail, agencia y créditos) sin error.

## Seguridad y correctitud
- [ ] Permisos: los cuatro permisos por ruta **y** `modulo:compras` en todas. Sin Policy de fila:
  las compras son del tenant y cualquiera con permiso ve todas.
- [ ] Entrada: tipo validado contra el catálogo central; serie y número normalizados; montos con
  `BigDecimal`; adjuntos con `ArchivoSubido` (PDF y XML, 10 MB). El XML no se interpreta, solo se guarda.
- [ ] Fechas: `fecha_emision` y `fecha_vencimiento` son fechas de negocio (`HoraPeru::hoyTexto()`
  para «hoy»); `anulada_at`, `cerrado_at` y `reabierto_at` en UTC. `tests/Unit/ReglaFechasNegocioTest.php` en verde.
- [ ] Archivos: disco `private` y URL firmada; nunca `/storage/` ni `env('APP_URL')`.
- [ ] Concurrencia: el duplicado lo frena el índice único parcial (si hay carrera, capturar la
  violación del índice y responder el mismo 409). Cerrar un periodo y registrar en él a la vez:
  bloquear la fila del periodo (`lockForUpdate`) al validar.
- [ ] Multi-tenancy: `modulos` y `tenant_modulo_overrides` centrales, `compras` de tenant, sin FK
  cross-DB. Migraciones de tenant con `tenants:migrate-verticales`; centrales con `migrate`.
- [ ] Tributario: se guarda lo que dice el comprobante, sin recalcular IGV. El tope de 12 meses
  del periodo queda como aviso hasta P13.
- [ ] `v-html`: no se usa.

## Cómo verificar
```
php artisan test --filter=Compra
php artisan test --filter=Modulo
php artisan test --filter=MenuResolver
```
Después, la suite completa una vez. En el frontend: `npm run type-check`, `npm run build` y
Vitest si hay helpers con lógica.

Pantalla, en agencia-demo:
1. Encender el módulo desde el Panel Superadmin.
2. Registrar una factura con PDF.
3. Repetirla (409).
4. Registrar una compra en USD.
5. Cerrar el periodo, intentar editar, y reabrir con motivo.
6. Anular con motivo.
7. Apagar el módulo y comprobar que el menú y la API se cierran.

---

## Resultado
- **Commits** (rama `feat/T-135-comprobante-de-compra`, sin unir):
  - `5f5ac040` — Parte A, módulos activables.
  - `af41c7fa` — Parte B, compras.
- **Tests:**
  - Por criterio: CA1 `ModulosTenantTest` y `CompraTest::ca1_y_ca9…`; CA2 a CA9 `CompraTest`; CA10
    `ComprasMigracionesPorGiroTest`; menú sembrado por giro en `MenuGirosTest`.
  - Suite completa: 2502 en verde y 1 fallo, el intermitente conocido
    `Fila13Fase1cFacturacionTest::descuento_del_combo…`; su archivo solo pasa (34/34).
  - `admin-start-kit`: `type-check` y `build` sin errores; Vitest 364 en verde, más los 4 de
    `compras.test.ts`. `central-panel`: `build` sin errores.
  - Pantalla en agencia-demo: los 7 pasos de «Cómo verificar», sin errores de página. Los datos de
    prueba se borraron y el módulo quedó apagado. CA10 además en local: `tenants:migrate-verticales`
    corrió en los tenants de los tres giros.
- **Desviaciones de la tarea y por qué:**
  - `compra_periodos` lleva una columna más, `historial` (decisión del usuario, 26-nov): con
    `periodo` único (D6) un segundo ciclo pisaba el primero, contra D7.
  - Ruta que no estaba en la tabla: `GET compras/catalogos` (tipos con `uso_compras` y tipo de
    cambio de una fecha). Y `GET compras` acepta `referencia_para`, para elegir la compra de una NC/ND.
  - En los totales del filtro, la nota de crédito (07) **resta** y las anuladas no cuentan.
  - El tipo de cambio sugerido es el de **venta**.
  - D5: «completar el RUC» abre un modal propio (razón social y documento), no el formulario
    completo del proveedor, que vive dentro de `views/cash/proveedores.vue`.
  - La migración de permisos y la de tablas van separadas; `ArchivoSubido` ganó `pdf()` y `xml()`.
  - Un proveedor con compras ya no se puede eliminar (422); sin esto la FK daba un 500.
  - Tests existentes ajustados: el catálogo tiene 17 filas (la 91) y `ProveedoresTablaBaseTest`
    quita `compras` antes de borrar `proveedores`.
- **Al desplegar:** lo hace `deploy.sh` en su orden (`migrate`, `tenants:migrate-verticales`,
  `MenuItemsSeeder`, `roles:sync-propietario`); después, encender «Compras» por tenant en el Panel
  Superadmin.

## Hallazgos fuera de alcance
- Se puede anular una compra que tiene una NC/ND vigente que la referencia, o cambiarle el
  proveedor; la nota queda apuntando a una compra anulada o de otro emisor.
- Serie y número se comparan como texto: `F001-123` y `F001-0000123` no se detectan como la misma.
- Quien registre compras sin ser Propietario necesita además `proveedores.ver` (buscador),
  `proveedores.gestionar` (alta rápida y completar RUC) y `compras.ver` (elegir la compra de una
  NC/ND). Ningún rol operativo recibe permisos de Compras.
- Reemplazar un adjunto deja el archivo anterior en el disco `private`.
- Entorno local: `php artisan migrate` a secas ve pendientes tres migraciones de `menu_items` que
  ya están aplicadas (quedaron registradas en `db_tenant_central` y el `.env` apunta a
  `sv_facturacion`); las nuevas se aplicaron con `--path`.
