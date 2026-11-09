# T-092 — Depuración de seguridad de Agencia: HTML en avisos, endpoint huérfano y rutas sin método

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | Retirar `POST reservas/{id}/pasajeros` (confirmado por el usuario el 09-nov-2026) |
| **Rama** | `fix/T-092-depuracion-seguridad-agencia` |

## Contexto
Salió de la auditoría del vertical del 09-nov-2026. Las rutas y las Policies están bien. Quedan
huecos chicos: avisos Swal que arman HTML con nombres escritos por el usuario (inyección de
HTML/JS entre usuarios del mismo tenant), un endpoint que ninguna pantalla usa y que agrega un
pasajero a una reserva sin pasar por «Modificar reserva» (no recalcula el precio, el bug que
cerró la ola 4), rutas `create`/`edit` registradas sin método (dan 500) y el `orden` de
filas hijas calculado con `max()+1` sin lock.

## Alcance
1. **HTML en Swal:** escapar todo texto que venga del usuario o del servidor antes de meterlo en
   `html:` (helper único `escaparHtml()` en `admin-start-kit/src/helpers/`, o pasar a `text:`
   cuando no hace falta formato). Absorbe la línea de «Por analizar» sobre `guardarPasajeros()`.
2. **Retirar `POST reservas/{id}/pasajeros`:** borrar la ruta y `ReservaPasajeroController::store()`.
   El camino para sumar un pasajero es «Modificar reserva».
3. **Rutas `create`/`edit` inexistentes:** `->except(['create', 'edit'])` en los cuatro `Route::resource`.
4. **Orden con lock:** calcular el siguiente `orden` con un `lockForUpdate()` sobre el padre
   (alternativa, salida) o sobre la consulta, dentro de la transacción.
5. **`ilike` con comodines:** escapar `%`, `_` y `\` del texto buscado.
6. **Comentario desactualizado:** quitar de `ReporteOperativoController::pdf()` la frase de que
   «AlternativaController usa resolve()…» (hoy `MembreteAgenciaPdfService` usa `resolveParaPdf`).

## Fuera de alcance
- El resto de los avisos que no interpolan texto del usuario.
- El voseo de los mensajes (T-096).
- Tests HTTP del resto de las rutas (T-093).

## Dónde tocar
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue` → `guardarPasajeros()` (~1912),
  aviso «No se tocaron» (~2660), «Guías pendientes de asignar» en cargar desde plantilla (~3002)
- `admin-start-kit/src/views/agencia-viajes/paquetes/detalle.vue` → combos afectados al desactivar (~1000)
- `admin-start-kit/src/views/agencia-viajes/destinos/form.vue` (~263, ~375) y
  `proveedores/form.vue` (~414, ~535) → fotos rechazadas o no subidas (`nombre` = nombre del archivo)
- `admin-start-kit/src/components/AgenciaViajes/SalidaReprogramar.vue` (~97) → revisar `res.avisos`
- `api-sistema-fe/routes/api.php` → `reservas/{id}/pasajeros` (~1296); `Route::resource` de
  proveedores (~745), servicios (~795), temporadas (~798), guias (~805)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php` → `store()` (~32)
- `AlternativaDestinoController::store()` (~51), `SalidaServicioController` (~41),
  `SalidaVehiculoController` (~73), `CrmMotivoResultadoController` (~54)
- `ConsultaController::coincidencias()` (~77), `ReservaPasajeroController::buscarCatalogo()` (~183)
- `ReporteOperativoController::pdf()` (~105-110)

## Criterios de aceptación
- [ ] **CA1** — Dado un guía llamado `<img src=x onerror=alert(1)>`, cuando se carga un tour desde
  plantilla con ese guía pendiente, entonces el aviso muestra el texto literal y no ejecuta nada
  → test Vitest del helper + paso manual en agencia-demo.
- [ ] **CA2** — Lo mismo con nombre de alternativa (pasajeros), nombre de combo y nombre de archivo de foto → paso manual.
- [ ] **CA3** — `POST api/reservas/{id}/pasajeros` ya no existe (`route:list`) y ningún test ni pantalla lo usa → test que lo verifica.
- [ ] **CA4** — `GET api/guias/create` (y los otros 7) ya no está registrada → test de rutas.
- [ ] **CA5** — Dos altas simultáneas de destino en la misma alternativa quedan con `orden` distinto → test con dos conexiones o lock comprobado.
- [ ] **CA6** — Buscar `50%` en coincidencias no trae clientes sin `50%` en el nombre → test.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (no hay rutas nuevas; se quita una)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: escape de comodines `ilike`
- [ ] Concurrencia (`lockForUpdate`): sí, punto 4
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: sí, punto 1 (Swal `html:` es el mismo riesgo)
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter="ReservaPasajero|AlternativaDestino|Consulta"
cd admin-start-kit && npm run type-check && npx vitest run
```
Manual en `agencia-demo` (`admin@agencia-demo.test`): crear un guía o una alternativa con HTML en
el nombre y recorrer los avisos del punto 1.

---

## Resultado
- **Commits:** ver `git log main..fix/T-092-depuracion-seguridad-agencia` (uno por la tarea).
- **Qué se hizo, por punto:**
  1. `helpers/escaparHtml.ts` (único; reemplaza las dos copias locales de `avisosProveedores.ts`
     y `SalidaReprogramar.vue`). Se escapan: `guardarPasajeros()` (alternativa y motivo),
     «No se tocaron» (`res.omitidas`), «Guías pendientes» (guía, tour, destino), combos afectados
     al desactivar (nombre y código; el id va con `Number()`), fotos rechazadas en el navegador y
     no subidas por el servidor en `destinos/form.vue` y `proveedores/form.vue` (también
     `res.message`, que va en el mismo `html:`).
  2. Ruta `POST reservas/{id}/pasajeros` y `ReservaPasajeroController::store()` borradas (y sus
     `use` sin uso: `Reserva`, `SalidaMayorista`, `SalidaOperacionService`).
  3. `->except(['create', 'edit'])` en proveedores, servicios, temporadas y guías.
  4. `lockForUpdate()` dentro de una transacción: sobre la alternativa
     (`AlternativaDestinoController::store()`), sobre la salida (`SalidaServicioController::store()`,
     `SalidaVehiculoController::store()`; en vehículos entra también la decisión «es el primero»,
     que tenía la misma carrera) y sobre las filas del tipo en `CrmMotivoResultadoController::store()`
     (no hay padre; Postgres no permite `FOR UPDATE` con `max()`; «Otro» existe siempre).
  5. `App\Services\BusquedaLike::contiene()/escapar()` (escapa `\`, `%`, `_`; `\` es el escape por
     defecto de `LIKE` en Postgres). Usado en `ConsultaController::coincidencias()` y en los cinco
     `ilike` de `ReservaPasajeroController::buscarCatalogo()`.
  6. Frase quitada de `ReporteOperativoController::pdf()`.
- **Tests:**
  - Nuevo `tests/Feature/AgenciaViajes/T092DepuracionSeguridadAgenciaTest.php` (7): CA3 (ruta y
    método no existen; `PUT reserva-pasajeros/{id}` y `pasajeros-catalogo` siguen), CA4 (las 8
    rutas fuera y las 5 de API de cada resource siguen), CA5 (registro de consultas: el
    `SELECT … FOR UPDATE` del padre va antes del `max("orden")` y del `INSERT`, en los cuatro
    controllers; dos altas seguidas dan orden 1 y 2), CA6 (`BusquedaLike` y `buscarCatalogo` con
    `50%` y `_`).
  - `ConsultaOrigenContactoTest::test_coincidencias_por_nombre_toman_los_comodines_literales` (CA6).
  - Vitest `helpers/escaparHtml.test.ts` (CA1); `avisosProveedores.test.ts` sigue pasando.
  - `php artisan test --filter="T092|ConsultaOrigenContacto|ReservaPasajero|AlternativaDestino|Sesion12f1|Fila13Fase2b|T044|CotizacionResultado|Consulta"`: 96 en verde.
    Suite completa (Postgres real): 2080 en verde y 8 fallas en la primera corrida: el
    intermitente conocido `Fila13Fase1cFacturacionTest::descuento_del_combo…` (pasa al
    repetirlo) y 7 que llamaban a `store()` directo, sin URL (por eso no aparecieron al buscar
    consumidores). Se retiraron con el endpoint, como pide CA3:
    `ReservaQuitarItemsPasajerosTest` (los 4 `test_agregar_pasajero_*`),
    `ReservaPolicyAccionesTest::test_vendedor_no_puede_facturar_ni_cambiar_la_reserva_de_otro`
    (solo la línea de `store()`), `T040AvisosSalidasTest::test_agregar_un_pasajero_avisa_a_cada_salida`
    (borrado; el aviso por cambio de pasajeros lo cubre `ReservaModificacionAplicarTest`) y
    `test_una_reserva_sin_salidas_no_trae_avisos` (solo la parte de agregar). Esos 4 archivos y
    `Fila13Fase1c` volvieron a correr: 65 en verde. Vitest: 187 en verde. `vite build`: OK. `type-check`: 47 errores,
    ninguno en archivos de esta tarea (ya anotados en «Por analizar», que dice 46).
  - **Manual en agencia-demo (Playwright)**, con datos de prueba revertidos al terminar (nombres de
    guía 4 y combo 15, ítem 98 del tour 19 y la línea 517 de la alternativa 114; el total de
    CDKM-1126-0000009 quedó en PEN 600.00): combo llamado `<img src=x onerror=alert(2)>Paquete`
    al desactivar «Tours a Lamas» → se ve literal, 0 `<img>` (se canceló, el tour sigue activo);
    foto de 6 MB llamada `<img src=x onerror=alert(3)>.jpg` en destinos y proveedores → literal,
    0 `<img>`.
- **Desviaciones de la tarea y por qué:**
  - CA1 en pantalla no se pudo recorrer: agencia-demo está en `modo_tours = nuevo` y ahí
    `desdePlantilla()` devuelve `guias_pendientes` vacío (el aviso solo sale en el modo anterior).
    Se cargó el tour con el guía `<img …>` igual y el nombre no se ejecutó en el lienzo. El escape
    del aviso queda cubierto por el Vitest del helper y el diff.
  - CA2 «nombre de alternativa (pasajeros)» no se recorrió en pantalla: hace falta que el
    recálculo deje ítems «para revisar», que no se arma sin preparar precios a propósito. Cubierto
    por el diff (mismo helper).
  - Punto 4 se aplicó también a «es el primer vehículo» en `SalidaVehiculoController::store()`
    (misma transacción; sin el lock dos altas simultáneas se llevaban las dos todas las reservas).
  - El helper de `ilike` es una clase en `app/Services/` (junto a `HoraPeru`/`StorageUrl`): la
    tarea no decía dónde.

## Hallazgos fuera de alcance
- **Swal: el segundo argumento de `fire(título, texto, icono)` es HTML**, y `title:` también.
  En `views/agencia-viajes` y `components/AgenciaViajes` hay ~147 llamadas
  `fire('…', res.message | error.response.data.message, …)`. Si un mensaje del servidor incluye
  un nombre escrito por un usuario, es el mismo riesgo de este punto 1. Propuesta: helper
  `avisar(título, texto, icono)` que use `text:` y reemplazo en bloque; o revisar qué mensajes del
  backend interpolan nombres. `SalidaReprogramar.vue` usa `title: res.message`.
- `ReservaPasajeroController` importa `PasajeroDocumento` sin usarlo (ya estaba así).
- `type-check` pasó de 46 a 47 errores desde el 02-nov (la línea de «Por analizar» dice 46).
