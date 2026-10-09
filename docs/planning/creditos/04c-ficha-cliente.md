# Fase 4c — Ficha del cliente y cartera por asesor

Estado: **CERRADA el 02-oct-2026** (pasos 1-4); **en producción** desde el 05-oct-2026 (main `1974b7c`). Al desplegar: `php artisan tenants:migrate-verticales` (3 migraciones; la del
índice único de documento se detiene si encuentra duplicados en producción).

### Paso 3 — notas de construcción
- `ClientesCreditoConsulta`: situación (al día / atrasado / bloqueado / sin créditos), requisitos
  faltantes y asesor, en SQL (filtrable y paginable). `GET clients` suma `credito` por fila y los
  filtros `asesor_id` (0 = sin asesor), `situacion`, `ficha_incompleta` — solo en el giro Créditos.
- `TarjetaResumenCliente`: "Ficha incompleta" con lo que falta y enlace "Completar ficha" (pestaña
  nueva). `AvisosLimites` explica la regla `ficha_incompleta` ("falta DNI (reverso), …").
- `FichaCobro` quedó de solo lectura; "Editar ficha" lleva a la página del cliente.
- Configuración → "Cartera y ficha del cliente": `asesor_cobra` y `requisitos_ficha` (campo tipo
  `lista`).

### Paso 4 — verificación (02-oct-2026, creditos-demo y agencia-demo)
Alta completa con ficha, punto en el mapa y foto en cola; reapertura con todo guardado; listado
con columnas y filtros; Nuevo crédito con `?cliente=` mostrando "Ficha incompleta"; activar bloqueado
con "falta DNI (reverso), Foto del cliente" + Autorizar (el borrador de prueba se anuló);
Configuración guardada y revertida; "Editar ficha" desde el detalle. Regresión en agencia-demo:
página sin secciones de Créditos, cliente RUC guardado sin cambios, modal rápido del Cotizador con
error por campo. Backend 1179/1179, frontend 113/113, `vue-tsc` en su línea base (46).
Dato demo que queda: cliente "Rosa Prueba Cuatroc" (id 5) con ficha parcial.

## Fase 4c.1 — ajustes de la revisión previa a reportes (02-oct-2026)
Rama `feat/creditos-fase4c1-ajustes` (sale de la 4c). Revisión completa del módulo (permisos, cartera,
dinero, concurrencia, idempotencia, documentos, alta de tenant desde cero); 7 hallazgos, todos corregidos:
1. **Saldo a favor completo**: ver (ficha, tarjeta, cobro), usar al cobrar y devolver en caja
   (`GET/POST clientes/{id}/saldo-a-favor[/devolver]`, `SaldoAFavorCliente.vue`). Antes se podía generar
   pero no se veía, no se usaba desde la pantalla y no se podía devolver.
2. **Cartera del cliente** en preview/crear/editar/migrar crédito y en asignar cartera/límites
   (`ControllerCreditos::asegurarCliente()`): un asesor podía crear créditos o ver la deuda de clientes ajenos por API.
3. **Editar borrador**: transacción + bloqueo + estado releído; antes podía cambiar el monto de un crédito
   que se activaba en ese momento.
4. **Saldo a favor concurrente**: se bloquea la fila del cliente al usarlo, devolverlo o revertirlo.
5. **Rol "Asesor de créditos"** sembrado (creditos-demo ya lo tiene).
6. **Castigo automático** sigue con los demás créditos/tenants si uno falla (queda en el log; el comando
   termina con FAILURE si falló un tenant).
7. **Alta de tenant**: "Caja Principal" para todos los giros; sin roles retail en el giro créditos.
8. **Traspaso de cartera** (pedido del usuario en la misma revisión: "¿qué pasa si el asesor se va?"):
   Clientes → "Traspasar cartera" (requiere `creditos.cartera.asignar` + `creditos.ver_todos`) mueve todos los
   clientes de un usuario a otro, con historial y auditoría (`cartera.traspasar`); acepta usuarios inactivos o ya
   eliminados como origen. Los créditos conservan su `asesor_id` (salvo que se marque "Pasar también sus créditos", 08-oct-2026; ver 00 §1.14). No se elimina ni desactiva a un usuario con
   cartera (Usuarios responde 405 con el aviso); los selectores de cartera solo ofrecen usuarios activos.
9. **Bug fuera de Créditos (commit propio `2bb8ddf`)**: `UserController::update()` tenía la condición de la
   contraseña invertida desde la primera versión: editar un usuario sin escribir contraseña la reemplazaba por
   `bcrypt('')` y lo dejaba sin acceso, en todos los tenants. Evidencia local: `admin@gmail.com` de agencia-demo con
   contraseña vacía. **En producción** conviene buscar usuarios afectados (solo lectura) antes de avisarles.
Verificado: tenant de créditos creado desde cero (y borrado), saldo a favor en navegador sin confirmar nada
(datos de prueba borrados). Backend 1187/1187, frontend 113/113.

~~**Pendiente, fuera de esta fase:** reporte por asesor~~ — **resuelto en 4d** (reporte 5 "Por asesor", base
para comisiones).

### Paso 2 — notas de construcción
- `views/clients/ficha.vue` (`/clients/nuevo`, `/clients/:id`) reemplaza al modal de Clientes en
  todos los giros; `views/clients/index.vue` reescrito (lista + navegación, sin modal).
- Las secciones se deciden por **giro**, no por permisos: `GET clients/contexto` (el Super-Admin
  tiene todos los permisos en cualquier giro, así que "por permiso" mostraba Créditos en umbo).
- `ClienteDatosBase.vue` + `helpers/clientes/formCliente.ts`: un solo formulario para la página y
  `ClientFormQuick` (P6). Efecto: el modal rápido ahora calcula `es_amazonia` por la región (antes
  enviaba siempre `false` y al editar lo pisaba); solo afecta la sugerencia de destino en Ventas.
- Validación del documento: al editar **sin cambiar** el documento no se exige el formato (había
  1 cliente de prueba en agencia-demo con DNI de 7 dígitos; revisados los 40 clientes locales).
- `MapaUbicacion.vue` (Leaflet + OpenStreetMap; la plantilla Rizz traía un token demo de Mapbox),
  `DocumentosCliente.vue` (cola en el alta, compresión en el navegador), cartera, límites y
  créditos del cliente. "Nuevo crédito" acepta `?cliente=ID`.
- `ClienteRequest` acepta `TM` (Tarjeta militar), que el formulario viejo ofrecía.

### Paso 1 — notas de construcción
- `cartera_asignaciones.cobrador_id` pasó a `usuario_id` + `funcion`, con índice único parcial
  (una vigente por cliente y función). Endpoints: `GET creditos/cartera/usuarios`,
  `PUT clientes/{id}/cartera`, `PUT clientes/{id}/limites-credito` (reemplazan a
  `creditos/cobradores` y `clientes/{id}/cobrador`).
- La cobranza del día usa solo la función **cobrador** (`AlcanceCartera::aplicar(..., Cobrador)`);
  ver créditos y clientes, cualquiera de las dos.
- `GiroActual` (servicio) dice si el tenant es de Créditos; los tests lo fijan sin tenancy.
- Hallazgos en el camino: `guardarFicha`/`subirArchivo` no revisaban la cartera (cualquiera con
  `creditos.crear` editaba fichas ajenas) — corregido; `credito_autorizaciones.regla` es un CHECK
  de Postgres con lista cerrada — migración `120200` agrega `ficha_incompleta`.
- 11 tests de Agencia de Viajes creaban dos clientes con el mismo DNI fijo en un mismo test: ahora
  usan documentos al azar (sin cambiar lo que prueban).
- `TipoCambioSunatAplicadoSaleTest::test_venta_en_usd…` falla entre las 19:00 y 24:00 de Lima,
  preexistente y ajeno a esta fase: `Sale::setCreatedAtAttribute()` cambia la zona horaria global a
  Lima a mitad del test y el dato se insertó con la fecha UTC.
Reglas de fondo: `00-reglas-y-modelo.md` §1.14 (cartera), §1.17 (límites), §4 (seguridad).
Los reportes y el panel de inicio que eran la "4c" pasan a ser la **Fase 4d**.

## Decisiones tomadas con el usuario (01-oct-2026)
| Tema | Decidido |
|---|---|
| Cómo se registra un cliente | **Página propia** (`/clients/nuevo`, `/clients/:id`), no modal. Reemplaza el modal también en umbo y agencia. El modal rápido sigue solo *dentro* de Ventas, Cotizador y Nuevo crédito |
| Datos obligatorios | El registro pide lo mínimo; la **ficha completa se exige al activar un crédito** (lista configurable) |
| Asesor y cobrador | Hoy son la misma persona, pero el sistema queda preparado para separarlos sin migrar datos |

## Lo que ya existe (no se rehace)
- Tablas: `credito_cliente_fichas` (dirección de cobro, tipo, referencia, latitud/longitud,
  teléfono alterno, ocupación, notas), `credito_cliente_archivos` (`dni_anverso`, `dni_reverso`,
  `foto_cliente`, `otro`, disco privado), `cartera_asignaciones` (con vigencia e historial),
  `credito_cliente_limites` (modelo y lectura en `LimitesService`, **sin API ni pantalla**).
- API: `clientes/{id}/ficha-credito` (GET/PUT), `…/archivos` (subir/ver), `clientes/{id}/cobrador`
  (PUT, permiso `creditos.cartera.asignar`, cierra la asignación vigente y abre otra, auditado).
- `AlcanceCartera`: sin `creditos.ver_todos` solo se ven créditos de clientes asignados (404 fuera).
- Frontend: `FichaCobro.vue` (lectura + edición, cámara en el celular), `BuscadorCliente.vue` con
  `ClientFormQuick` en modal, `TarjetaResumenCliente.vue`.

## Problemas encontrados en el código actual de clientes (se corrigen en esta fase)
| # | Problema | Impacto | Corrección |
|---|---|---|---|
| P1 | `ClientController::store/update` rechaza un cliente si **otro tiene el mismo nombre**, y el documento único solo se cuida en PHP (sin índice) | Dos "Juan Pérez" distintos no se pueden registrar; dos peticiones simultáneas pueden duplicar un DNI | Regla decidida (01-oct, todos los giros): **el número de documento nunca se repite** (DNI, RUC, CE, pasaporte…, por tipo + número, sin espacios) y se asegura con **índice único parcial** en Postgres (excluye `SND` y eliminados). El **nombre repetido ya no bloquea**: solo **advierte** cuando el cliente es sin documento (`SND`). Si el documento pertenece a un cliente eliminado, se ofrece restaurarlo en vez de crear otro |
| P2 | `store/update` no validan nada (sin `FormRequest`) | Se puede guardar un DNI de 3 dígitos, correo inválido, etc. | `ClienteRequest` con reglas por tipo de documento (DNI 8, RUC 11, CE…) |
| P3 | `GET /clients` lista **todos** los clientes a cualquiera con `list_client` | Un cobrador ve nombre, DNI, teléfono y dirección de clientes que no son suyos (Ley 29733) | Con permisos de Créditos y sin `creditos.ver_todos`, el listado y `show` se filtran por cartera (mismo criterio que `AlcanceCartera`) |
| P4 | `destroy` borra (soft delete) un cliente aunque tenga créditos | El crédito queda apuntando a un cliente "eliminado" | Bloquear si tiene créditos no anulados (o ventas a crédito pendientes); se puede **desactivar** (`state`) en su lugar |
| P5 | El token de apisperu tiene un valor por defecto escrito en el código | Token expuesto en el repositorio | Solo desde `.env`, sin valor por defecto (aparte de esta fase, cambio de 1 línea) |
| P6 | Hay dos formularios de cliente duplicados (`clients/index.vue` y `ClientFormQuick.vue`) | Cada cambio se hace dos veces y ya divergen | Un componente de datos base compartido por la página y el modal rápido |

## Modelo: asesor y cobrador preparados para separarse
- `cartera_asignaciones.funcion` (`asesor` | `cobrador`), nueva columna. Las filas existentes
  quedan como `cobrador`.
- `credito_configuracion.asesor_cobra` (bool, default **true**):
  - **true (hoy):** la pantalla muestra un solo selector "Asesor"; guardar escribe las dos funciones
    con el mismo usuario en una transacción.
  - **false (cuando crezcan):** aparecen dos selectores, "Asesor" y "Cobrador", independientes.
- `AlcanceCartera`: un usuario ve al cliente si es su asesor **o** su cobrador vigente.
- `creditos.asesor_id` (nuevo, nullable): quién colocó el crédito; se toma del asesor vigente del
  cliente al crear y se congela al activar. Sirve para reportes y comisiones aunque luego se
  reasigne la cartera.
- **Alta:** el cliente queda asignado al usuario que lo registra si ese usuario puede tener cartera
  (mismo criterio que `cobradoresDisponibles()`); con `creditos.cartera.asignar` se puede elegir
  otro en la misma pantalla. Sin esto, un asesor sin `ver_todos` registraría un cliente y dejaría de
  verlo al guardar.

## Ficha exigida para prestar
- `credito_configuracion.requisitos_ficha` (json). Default:
  `["dni_anverso", "dni_reverso", "foto_cliente", "direccion_cobro", "ubicacion"]`.
  Editable en Configuración → Condiciones por defecto (casillas).
- Se revisa en `ActivacionService` junto con los límites: si falta algo, es una infracción más
  ("Ficha incompleta: falta DNI reverso, ubicación") y se autoriza igual que un límite
  (`creditos.autorizar_excepcion` + motivo, queda en `credito_autorizaciones`).
- Créditos migrados: solo **advierte**, no bloquea (clientes antiguos).
- `TarjetaResumenCliente` en Nuevo crédito muestra lo que falta con enlace "Completar ficha".

## Pantallas

### 1. Listado de clientes `/clients` (todos los giros)
- Igual que hoy: buscador, "Nuevo cliente" (ahora va a la página), "Cliente sin datos" (retail).
- Columnas base: cliente, documento, teléfono, estado. Acciones: Ver, Editar, Eliminar/Desactivar.
- Con permisos de Créditos se agregan:
  - Columnas "Asesor", "Situación" (al día / moroso / bloqueado / sin créditos) y "Ficha"
    (completa ✓ / "faltan 2").
  - Filtros: asesor, situación y ficha incompleta.
  - El cobrador solo ve su cartera, filtrada en el backend (P3).

### 2. Página del cliente `/clients/nuevo` y `/clients/:id`
Encabezado estándar (nombre, documento, insignias de estado, "Volver"). Dos columnas en escritorio;
una en celular, con la barra fija de guardar (`BarraAccionMovil`).

```
┌ Izquierda (secciones, cards con card-header) ─────────────┐ ┌ Derecha (~340 px) ─────────┐
│ Datos personales   (todos los giros)                      │ │ Foto del cliente           │
│ Dirección          (todos)                                │ │ Resumen de créditos        │
│ Datos tributarios  (solo con permisos de Ventas)          │ │  (TarjetaResumenCliente)   │
│ ── Créditos (solo con creditos.ver) ──                    │ │ Ficha: completa / faltan…  │
│ Cobro: dirección de cobro, tipo, referencia, ocupación,   │ │ [Nuevo crédito para este   │
│        teléfono alterno, notas                            │ │  cliente]                  │
│ Ubicación: mapa + "Usar mi ubicación" + pin movible       │ └────────────────────────────┘
│ Documentos: DNI anverso, DNI reverso, foto, otros         │
│ Cartera: asesor (y cobrador si asesor_cobra = false),     │
│          historial de asignaciones                        │
│ Límites: bloqueo manual con motivo, límites propios       │
│ Créditos del cliente: lista con estado y enlace           │
└───────────────────────────────────────────────────────────┘
```

- **Las secciones se muestran por permiso, no por giro**: el frontend no conoce el giro, pero los
  permisos `creditos.*` solo existen en ese tenant. Una sección nueva en el futuro (referencias,
  datos del negocio, garantes) es un componente más en la lista, sin rehacer la página.
- **Datos personales:** tipo y número de documento con "Buscar" (RENIEC/SUNAT, como hoy), nombres,
  apellidos o razón social, nombre comercial, teléfono, correo, nacimiento, género, estado.
- **Ubicación:** mapa con Leaflet + OpenStreetMap (sin API key ni costo). "Usar mi ubicación" toma
  el GPS del celular (requiere HTTPS: funciona en producción; en local se prueba con el pin
  manual). Enlace "Abrir en Google Maps" para el cobrador. Coordenadas en las columnas existentes.
- **Documentos:** cámara directa en el celular, **compresión en el navegador** (lado mayor ~1600 px,
  JPEG ~80 %) antes de subir. Reemplazar una foto sube una nueva y conserva la anterior.
- **Guardar en el alta:** un solo botón. Crea el cliente, guarda la ficha y sube las fotos en
  orden; si una foto falla, la página queda en modo edición con esa foto marcada para reintentar
  (el cliente ya existe, nada se duplica).
- **Límites:** API nueva `PUT clientes/{id}/limites-credito` (permiso `creditos.configurar`,
  auditada) sobre la tabla que ya existe.

### 3. Modal rápido (sigue existiendo)
- En Ventas, Cotizador, Venta directa, Reservas y Nuevo crédito, `ClientFormQuick` sigue para
  registrar al vuelo, ahora con el componente de datos base compartido (P6).
- En Nuevo crédito, después de crearlo, la tarjeta del cliente muestra "Ficha incompleta" con
  enlace a la página (pestaña nueva, para no perder el formulario del crédito).

### 4. Detalle del crédito
`FichaCobro.vue` queda como vista de lectura; "Editar ficha" lleva a la página del cliente, a la
sección de cobro.

## Seguridad
- Fotos de DNI y la ficha: solo con `creditos.ver` y dentro de su cartera (ya funciona así).
  Se agrega auditoría de **quién vio** un DNI (`credito_auditoria`, acción `cliente.ver_documento`).
- La cláusula de consentimiento de datos personales va en la plantilla del contrato; texto a
  revisar con su abogado (como el resto de la plantilla).

## Orden de construcción
1. **Backend de clientes:** P1-P4 (la migración del índice único revisa antes si hay documentos
   duplicados y, si los hay, se detiene listándolos: en local no hay ninguno, revisado el 01-oct en
   los 6 tenants; producción se revisa al desplegar), `ClienteRequest`, filtro por cartera, `funcion` + `asesor_cobra`
   + `creditos.asesor_id`, asignación automática en el alta, API de límites, `requisitos_ficha` en
   la activación. Tests.
2. **Página del cliente** (datos base + secciones de Créditos), componente de datos base compartido
   con el modal, mapa, compresión de fotos.
3. **Listado** con columnas y filtros de Créditos; `TarjetaResumenCliente` con ficha incompleta;
   `FichaCobro` enlazada a la página.
4. Verificación en `creditos-demo` (asesor sin `ver_todos`, alta, reasignación, activar con ficha
   incompleta) y regresión en `umbo` y `agencia-demo` (alta/edición de cliente, modal rápido en
   Ventas y Cotizador).

## Pendiente de decidir (no bloquea empezar)
- Zonas (`cartera_asignaciones.tipo = 'zona'`): quedan para la Fase 8.
