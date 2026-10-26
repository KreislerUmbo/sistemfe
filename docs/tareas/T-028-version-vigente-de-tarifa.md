# T-028 — La versión vigente de una tarifa: solo se ofrece y se usa la vigente

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna (T-022 depende de esta: usa `version_anterior_id`) |
| **Reglas** | — (regla vigente del versionado: "nunca se sobrescribe un precio ya usado en una cotización", `ProveedorTarifaController::update()`) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión del flujo de cotizaciones", H5, H16, H17 |
| **Rama** | `fix/T-028-version-vigente-tarifa` |

## Contexto
Editar una tarifa ya usada en una cotización cierra la fila actual (`vigente_hasta` = hoy) y crea
una versión nueva (`vigente_desde` = hoy). El vínculo entre versiones **no se guarda**
(`version_anterior_id` solo viaja en la respuesta JSON). Tres problemas salen de ahí:
- **H5:** el día del cambio, la biblioteca del cotizador muestra la versión vieja y la nueva (las dos
  cumplen `vigente_hasta >= hoy`).
- **H16:** la matriz de habitaciones de un hotel (`GET proveedor-servicios/{id}/tarifas`,
  `ProveedorTarifaController::index()`) y "usar tarifa registrada" del hotel de un mayorista
  (`ProveedorController::tarifasHotel()`) listan **todas** las tarifas: versiones viejas,
  desactivadas y futuras. `crearItemProveedor()` solo rechaza las desactivadas: una versión vieja
  **entra a la cotización con su precio viejo**.
- **H17:** las plantillas de tours (`paquete_plantilla_items.proveedor_tarifa_id`) siguen apuntando a
  la versión vieja; `ComboExplosionService` cotiza los tours con el precio viejo (modo clásico,
  el que usan todos los tenants).

## Alcance
- **Una sola definición de "vigente hoy"**: scope en `ProveedorTarifa` (por ejemplo
  `vigentes()`): `activo = true`, `vigente_desde <= hoy`, `vigente_hasta` nulo o `>= hoy`, con
  `HoraPeru::hoyTexto()`. Usarla en la biblioteca (`BibliotecaCotizadorController`,
  `ProveedorTarifaController::biblioteca()`), en la matriz y en `tarifasHotel()`.
- **Versionado sin solaparse (H5):** al crear la versión nueva, la anterior se cierra el **día
  anterior** a `vigente_desde` de la nueva (no el mismo día). Revisar que ninguna cotización ni
  reserva dependa de que la vieja siga "vigente" hoy (las cotizaciones guardan snapshot).
- **Vínculo de versiones:** migración `proveedor_tarifas.version_anterior_id` (nullable, FK a sí
  misma); `update()` lo llena. (Este punto sale de T-022, que ahora depende de esta tarea.)
- **Matriz y "usar tarifa registrada" (H16):** solo vigentes. `index()` lo usa también la pantalla
  de proveedores (`proveedores/detalle.vue`), que necesita ver el historial: agregar un parámetro
  (`?solo_vigentes=1`) que use el cotizador, sin cambiar lo que ve la pantalla de proveedores.
  `tarifasHotel()` solo la usa el cotizador: filtrar siempre (confirmar con `grep`).
- **Alta de un ítem:** `crearItemProveedor()` rechaza (422) una tarifa no vigente hoy, con mensaje
  claro ("Esta tarifa ya no está vigente: usa la versión actual").
- **Plantillas de tours (H17):** al crear la versión nueva, mover a ella los
  `paquete_plantilla_items` que apuntaban a la vieja (en la misma transacción). Las cotizaciones y
  `opciones_hotel_tarifas` de alternativas existentes **no** se tocan.
- **Datos ya existentes:** comando `agencia-viajes:plantillas-a-tarifa-vigente --simular` que, por
  cada `paquete_plantilla_item` apuntando a una tarifa cerrada o desactivada, busca la vigente
  equivalente (mismo `proveedor_servicio_id`, `tipo_tarifa`, `modalidad`, `tipo_habitacion`,
  `temporada_id`, `diferenciador`; la de `vigente_desde` más reciente). Si hay exactamente una, la
  reapunta (con registro, reversible); si hay cero o varias, la lista para revisión manual sin tocarla.
  Correrlo en `agencia-demo` en simulación y anotar el resultado.

## Fuera de alcance
- Alerta de "precio nuevo" en la revisión antes de enviar (T-022).
- Tarifas de guía (`guia_tarifas`): revisar si tienen el mismo problema y anotarlo en "Hallazgos".
- Cambiar precios de cotizaciones ya armadas.

## Archivos probables
- `api-sistema-fe/app/Models/AgenciaViajes/ProveedorTarifa.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ProveedorTarifaController.php`, `ProveedorController.php`, `BibliotecaCotizadorController.php`, `AlternativaItemController.php`
- migración en `tenant/verticals/agencia-viajes/`
- `api-sistema-fe/app/Console/Commands/PlantillasATarifaVigente.php` (nuevo)
- `admin-start-kit/src/services/admin/proveedorService.ts`, `views/agencia-viajes/cotizador/editar.vue`

## Criterios de aceptación
- [ ] **CA1 (H5)** — Editar el precio de una tarifa usada → la vieja queda con `vigente_hasta` = ayer,
  la nueva con `version_anterior_id`; ese mismo día la biblioteca muestra solo la nueva. → test
- [ ] **CA2 (H16)** — La matriz (`?solo_vigentes=1`) y `tarifasHotel()` no devuelven versiones viejas,
  desactivadas ni futuras; `index()` sin el parámetro devuelve todo (pantalla de proveedores igual). → test
- [ ] **CA3 (H16)** — `POST` de un ítem con una versión vieja → 422. → test (falla antes del fix)
- [ ] **CA4 (H17)** — Editar el precio de una tarifa usada en un tour → el `paquete_plantilla_item`
  apunta a la nueva; cargar el tour en una cotización usa el precio nuevo. → test (falla antes del fix)
- [ ] **CA5** — El comando reapunta los casos unívocos, lista los ambiguos, `--simular` no escribe,
  repetirlo no cambia nada. → test
- [ ] **CA6** — Cotizaciones existentes no cambian de precio. → test
- [ ] **CA7** — Prueba funcional en `agencia-demo`: cambiar el precio de una matrimonial ya usada;
  la matriz del cotizador muestra una sola matrimonial con el precio nuevo; un tour que la usa
  cotiza el nuevo. Revertir datos de prueba.

## Seguridad y correctitud
- [ ] Permisos: sin rutas nuevas (el parámetro va en una existente)
- [ ] Aislamiento de tenant: el comando corre por tenant de Agencia
- [ ] Validación de entrada: `solo_vigentes` booleano
- [ ] Concurrencia: crear versión + reapuntar plantillas en la misma transacción
- [ ] Dinero: no se recalcula ningún precio guardado
- [ ] Fechas con `HoraPeru`: "hoy" y "ayer" con `HoraPeru`; columnas `date`
- [ ] Tributario: la versión nueva conserva su `tip_afe_igv`/`destino_tributario` (sin cambios)
- [ ] `v-html`: no aplica
- [ ] Migraciones: nueva en `verticals/agencia-viajes/`; `tenants:migrate-verticales`

## Cómo verificar
```
php artisan tenants:migrate-verticales
php artisan agencia-viajes:plantillas-a-tarifa-vigente --simular
php artisan test --filter=Tarifa
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
