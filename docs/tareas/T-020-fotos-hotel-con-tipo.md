# T-020 — Fotos de hotel con tipo (fachada / habitación) en la galería del proveedor

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 7 (respuesta 1, "Diseño") |
| **Rama** | `feat/T-020-fotos-hotel-con-tipo` |

## Contexto
El PDF de la cotización necesita saber cuál foto de un hotel es la fachada. Los hoteles de
mayorista (`opciones_hotel.fotos`) ya guardan `{path, tipo_foto}`; la galería del proveedor
(`proveedores.fotos`) es una lista de rutas sin tipo. El usuario quiere marcar la fachada (sirve
para otras cosas más adelante). Esta tarea arma el dato; el PDF es T-021.

## Alcance
- **Formato único:** `proveedores.fotos` pasa a `{path, tipo_foto}[]`. Tipos `fachada` (máx. 1),
  `habitacion`, `otra`. Migración de **datos** en `tenant/verticals/agencia-viajes/` (la columna ya
  es json), idempotente, con las fotos existentes como `otra` — mismo patrón que
  `2026_09_05_100400_migrar_fotos_opciones_hotel_a_tipo_foto.php`.
- Extraer los helpers `OpcionHotel::normalizarFotos()`/`fotosResueltas()` a un lugar compartido
  (trait o clase, por ejemplo `FotosConTipo`) usado por `OpcionHotel` y `Proveedor`, con el tipo
  por defecto como parámetro (`habitacion` para OpcionHotel, como hoy; `otra` para Proveedor).
  Sin cambiar el comportamiento de OpcionHotel.
- `ProveedorController`: `store()`/`update()` agregan fotos como `otra`; endpoint nuevo
  `PUT proveedores/{id}/fotos/tipo` (`path`, `tipo_foto`) — marcar `fachada` desmarca la anterior
  (pasa a `otra`). Las respuestas devuelven `{path, tipo_foto, url}` (hoy devuelven URLs sueltas
  con `StorageUrl::resolveMuchas`).
- **Borrar una foto del proveedor (nuevo, aprobado el 13-nov-2026):** hoy no existe (`update()` solo
  agrega y el formulario tiene tope `MAX_FOTOS = 10`: un proveedor con 10 fotos queda trabado).
  Endpoint `DELETE proveedores/{id}/fotos` con `path` en el body, mismo patrón que
  `DestinoAtractivoController::eliminarFoto()` y `OpcionHotelController::eliminarFoto()`
  (`StorageUrl::relativo()`, 422 si el `path` no es de ese proveedor, borra el archivo físico del
  disco y lo saca del array). Botón de borrar por foto en la ficha (`detalle.vue`) y en `form.vue`,
  con confirmación.
- Frontend `proveedores/detalle.vue` y `form.vue`: adaptar a la forma nueva (miniaturas, visor
  grande, borrar); para proveedores de tipo Alojamiento, en cada foto un selector o botones
  "Fachada" / "Habitación" / "Otra" y una insignia visible en la fachada. Para otros tipos de
  proveedor, no se muestra el selector. Buscar con `grep -rn "\.fotos" admin-start-kit/src` cualquier
  otro lector de `proveedor.fotos`.
- **Promover** un hotel no registrado (`OpcionHotelController::promover()`): copiar sus fotos con su
  tipo al proveedor nuevo (hoy se pierden). **Se copia el archivo físico** a la carpeta
  `proveedores/` del disco del tenant y el proveedor guarda el `path` nuevo (decidido el
  13-nov-2026): `OpcionHotelController::eliminarFoto()` y el borrado nuevo del proveedor borran el
  archivo, así que compartir el `path` haría que borrar en uno rompa la foto del otro.
- **Picker de Local/Nacional** (`editar.vue`, `pasoDrawer === 'matrizHotel'`): para un hotel no
  registrado (`opciones_hotel` sin mayorista), permitir subir/borrar fotos con su tipo, reusando
  lo que ya hace el picker de mayorista (`permitir-gestion-hoteles`, `@agregar-fotos-hotel`,
  `POST/DELETE opciones-hotel/{id}/fotos`). Para un hotel Proveedor, enlace "Fotos en la ficha
  del proveedor" en vez de subir ahí.

## Fuera de alcance
- El PDF (T-021).
- Reordenar fotos.
- Fotos de otros catálogos (destinos, paquetes).

## Archivos probables
- `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/2026_11_13_xxxxxx_migrar_fotos_proveedores_a_tipo_foto.php` (posterior a la última, `2026_11_09_120000`)
- `api-sistema-fe/app/Models/AgenciaViajes/OpcionHotel.php`, `Proveedor.php`, helper compartido nuevo
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ProveedorController.php`, `OpcionHotelController.php`
- `api-sistema-fe/routes/api.php`
- `admin-start-kit/src/views/agencia-viajes/proveedores/detalle.vue`, `form.vue`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`

## Criterios de aceptación
- [ ] **CA1** — Tras la migración, las fotos existentes de un proveedor quedan `{path, tipo_foto:'otra'}`;
  correrla dos veces no cambia nada. → test
- [ ] **CA2** — Marcar una foto como fachada la deja `fachada` y la fachada anterior pasa a `otra`. → test
- [ ] **CA3** — `path` que no es del proveedor → 422; tipo inválido → 422. → test
- [ ] **CA4** — Subir fotos al proveedor funciona con la forma nueva (quedan `otra`). `DELETE
  proveedores/{id}/fotos` saca la foto del array y borra el archivo; `path` ajeno → 422 sin borrar
  nada; borrar la fachada deja al proveedor sin fachada. → test
- [ ] **CA5** — Promover un hotel no registrado con fachada + 2 habitaciones → el proveedor nuevo
  tiene esas 3 fotos con su tipo, en **archivos propios** (paths distintos a los del hotel); borrar
  una foto del hotel después no rompe la del proveedor. → test
- [ ] **CA6** — Fotos de `opciones_hotel` se comportan igual que antes (tests existentes en verde).
- [ ] **CA7** — Pantallas: ficha del proveedor muestra y cambia el tipo; el picker de Local sube
  fotos de un hotel no registrado. → prueba funcional en `agencia-demo`
- [ ] **CA8** — `type-check` y `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos: los dos endpoints nuevos (tipo y borrar) usan el mismo permiso que editar el proveedor
- [ ] Aislamiento de tenant: URLs con `StorageUrl` (nunca `/storage/` a mano)
- [ ] Validación de entrada: archivos con tipo validado (`FotoUploadService`, como hoy); `path`
  debe pertenecer a la galería de ese proveedor
- [ ] Concurrencia: no aplica (último en guardar gana, como el resto de la ficha)
- [ ] Dinero: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: nueva en `verticals/agencia-viajes/`; `tenants:migrate-verticales`

## Cómo verificar
```
php artisan tenants:migrate-verticales
php artisan test --filter=Proveedor
php artisan test   # suite completa, contra PostgreSQL
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:** en la rama `feat/T-020-fotos-hotel-con-tipo` (`git log main..`): decisiones del 13-nov, tests (CA1-CA5), backend y front.
- **Tests:** `ProveedorFotosTipoTest` (11, cubre CA1-CA5; se escribieron primero y fallaron por código
  faltante). Suite completa contra PostgreSQL: **2185 pasaron, 0 fallos** (incluye los tests de
  `opciones_hotel`, CA6). Front: `type-check` con los 47 errores previos (T-101), ninguno en los
  archivos tocados; `build` OK. Migración aplicada en `agencia-demo` con `tenants:migrate-verticales`.
- **Desviaciones de la tarea y por qué:**
  - **Picker de Local:** no se activó `permitir-gestion-hoteles` en el drawer de `matrizHotel`. Ese flag
    también habilita renombrar/borrar el hotel y editar tarifas, y para un hotel no registrado no hay
    endpoint de recarga: los botones quedarían muertos. En su lugar, `editar.vue` muestra un bloque
    "Fotos del hotel" (subir fachada/habitación y borrar) que usa los mismos endpoints y servicio
    (`POST/DELETE opciones-hotel/{id}/fotos`) y se actualiza con la respuesta. Para un hotel Proveedor,
    enlace "Fotos en la ficha del proveedor" (abre en pestaña nueva).
  - Componente nuevo `GaleriaFotosProveedor.vue` (galería con tipo y borrado) compartido por la ficha y el
    formulario, en vez de duplicar la lógica en ambos. Cada cambio de tipo o borrado se guarda al instante
    (no espera al "Guardar" del formulario); la confirmación de borrar lo dice.
  - Las fotos de `OpcionHotel` solo admiten `fachada`/`habitacion`; `otra` existe únicamente en proveedores.
    Quien las lea (T-021, el PDF) debe tratar `otra` como relleno.
- **CA7 — pasó (revisión del 13-nov, Playwright en `agencia-demo`).** Proveedor 11 (alojamiento): 3 fotos
  subidas desde Editar quedan "Otra"; marcar fachada la 1 y luego la 2 → la 1 vuelve a "Otra" y la insignia
  sigue a la 2; borrar desde la ficha y desde Editar (con confirmación) saca la foto y el archivo del disco;
  borrar la fachada deja sin insignia. Proveedor 10 (transporte): sin selector, con borrar. Cotizador
  (T061-PRUEBA-2) → "Hotel sin catálogo": subir fachada + habitación, el campo Fachada se deshabilita y se
  rehabilita al borrarla; hotel de biblioteca (Sui Sui) → enlace a `/agencia-viajes/proveedores/19` en
  pestaña nueva. Datos de prueba borrados (fotos de los proveedores 10/11 y el hotel no registrado 57).
  Pasos usados:
  1. Entrar a `agencia-demo` como `admin@agencia-demo.test`; abrir un proveedor de tipo Alojamiento con
     fotos (o subirle 2-3 desde Editar). Ficha → cada foto debe tener un selector y, al elegir "Fachada",
     la insignia "Fachada" aparece; marcar otra como fachada pasa la anterior a "Otra".
  2. Borrar una foto (confirmar): desaparece y el archivo ya no carga. Repetir en Editar (`form.vue`).
  3. Abrir un proveedor que NO sea Alojamiento: sin selector de tipo, pero sí botón de borrar.
  4. Cotizador (alternativa Local/Nacional) → "+ Agregar hotel no registrado" → guardar: en el drawer
     subir una fachada y una habitación; el input de fachada queda deshabilitado; borrar una.
  5. Elegir un hotel Proveedor desde la biblioteca: debe verse "Fotos en la ficha del proveedor".
  6. Revertir: borrar las fotos y el hotel de prueba, y los proveedores/fotos subidos.

## Hallazgos fuera de alcance
- `AlternativaCopiaService` (líneas ~92 y ~265) copia `opciones_hotel.fotos` tal cual al duplicar una
  alternativa: los hoteles copiados **comparten los paths** y, como `eliminarFoto()` borra el archivo,
  borrar una foto en uno rompe la del otro (mismo problema que T-020 resolvió en `promover()`).
- `ProveedorController::index()` ahora devuelve `fotos` como `{path, tipo_foto, url}[]` (antes URLs). Solo la
  ficha y el formulario las leen (verificado con grep en `admin-start-kit/src`); si el portal e-commerce, que
  vive fuera de este workspace, lee `proveedor.fotos`, hay que revisarlo.
- `components.d.ts` se regeneró solo (registro del componente nuevo).
- (Revisión) En el drawer del cotizador, borrar una foto del hotel no registrado no pide confirmación
  (igual que el picker de mayorista, `onEliminarFotoHotelMatrix`), y borra el archivo al instante.
- (Revisión) Si `promover()` falla después de copiar las fotos, la transacción se revierte pero los
  archivos copiados a `proveedores/` quedan huérfanos en el disco. Bajo impacto.
