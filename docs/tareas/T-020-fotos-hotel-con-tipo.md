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
  (pasa a `otra`); borrar una foto sigue funcionando por `path`. Las respuestas devuelven
  `{path, tipo_foto, url}` (hoy devuelven URLs sueltas con `StorageUrl::resolveMuchas`).
- Frontend `proveedores/detalle.vue` y `form.vue`: adaptar a la forma nueva (miniaturas, visor
  grande, borrar); para proveedores de tipo Alojamiento, en cada foto un selector o botones
  "Fachada" / "Habitación" / "Otra" y una insignia visible en la fachada. Para otros tipos de
  proveedor, no se muestra el selector. Buscar con `grep -rn "\.fotos" admin-start-kit/src` cualquier
  otro lector de `proveedor.fotos`.
- **Promover** un hotel no registrado (`OpcionHotelController::promover()`): copiar sus fotos con su
  tipo al proveedor nuevo (hoy se pierden). Los archivos se reusan por `path` (mismo disco del
  tenant), sin copiar el archivo físico, salvo que algún borrado posterior los comparta: revisar
  y anotar la decisión en "Resultado".
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
- `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/2026_10_xx_migrar_fotos_proveedores_a_tipo_foto.php`
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
- [ ] **CA4** — Subir y borrar fotos del proveedor sigue funcionando con la forma nueva. → test
- [ ] **CA5** — Promover un hotel no registrado con fachada + 2 habitaciones → el proveedor nuevo
  tiene esas 3 fotos con su tipo. → test
- [ ] **CA6** — Fotos de `opciones_hotel` se comportan igual que antes (tests existentes en verde).
- [ ] **CA7** — Pantallas: ficha del proveedor muestra y cambia el tipo; el picker de Local sube
  fotos de un hotel no registrado. → prueba funcional en `agencia-demo`
- [ ] **CA8** — `type-check` y `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos: el endpoint nuevo usa el mismo permiso que editar el proveedor
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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
