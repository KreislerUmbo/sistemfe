# T-028 — La versión vigente de una tarifa: solo se ofrece y se usa la vigente

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna (T-022 depende de esta: usa `version_anterior_id`) |
| **Reglas** | regla del versionado: "nunca se sobrescribe un precio ya usado en una cotización" (`ProveedorTarifaController::update()`) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión del flujo de cotizaciones", H5, H16, H17 |
| **Rama** | `fix/T-028-version-vigente-tarifa` |
| **Implementa** | otro agente (Sonnet), en **2 partes con un commit cada una**. Análisis, tests de la parte 1 y revisión: Claude Opus, 28-oct-2026 |

## Contexto (verificado contra el código el 28-oct-2026)
Editar una tarifa ya usada en `alternativa_items` cierra la fila actual y crea una versión nueva
(`ProveedorTarifaController::update()`, línea ~149). Problemas:
- **H5:** la vieja se cierra con `vigente_hasta = hoy` y la nueva empieza hoy: ese día la
  biblioteca ofrece las dos.
- **Fecha que manda el formulario (hallado al preparar):** `proveedores/detalle.vue` edita con
  `{ ...tarifa }`, o sea manda el `vigente_desde` **original** (p. ej. 01-ago). La nueva versión
  nace "vigente desde el 01-ago", superpuesta con la vieja.
- **Vínculo:** `version_anterior_id` solo viaja en la respuesta JSON; no hay columna.
- **H16:** la matriz de habitaciones (`GET proveedor-servicios/{id}/tarifas` →
  `ProveedorTarifaController::index()`, llamada desde `clicBibliotecaItem()` en
  `cotizador/editar.vue` ~2725) y "usar tarifa registrada" del hotel de un mayorista
  (`ProveedorController::tarifasHotel()`, ~línea 158) listan **todas** las tarifas: viejas,
  desactivadas y futuras. `crearItemProveedor()` (`AlternativaItemController` ~935) solo rechaza
  desactivadas (~995): una versión vieja entra a la cotización con su precio viejo.
- **H17:** `paquete_plantilla_items.proveedor_tarifa_id` sigue en la versión vieja y
  `ComboExplosionService` cotiza los tours con el precio viejo.
- El filtro "vigente hoy" está copiado en 3 lugares, uno incompleto:
  `ProveedorTarifaController::biblioteca()` (~56), `BibliotecaCotizadorController` (~128) y
  `TourCostoEstimadoService` (~170, **le falta** `vigente_desde <= hoy`).

## Regla de fechas (decidida, no cambiarla)
Al versionar (`update()` de una tarifa con uso):
1. `desdeNueva` = el `vigente_desde` del pedido **si es posterior a hoy** (precio programado); si no, **hoy** (`HoraPeru::hoyTexto()`).
2. La vieja: `vigente_hasta = desdeNueva − 1 día`. Si eso queda antes de su `vigente_desde`
   (la vieja empezaba hoy o en el futuro), se guarda igual: el rango vacío significa "nunca vigente", y el scope la excluye.
3. Si el pedido trae `vigente_hasta` y es anterior a `desdeNueva` → 422 "La fecha de fin no puede ser anterior al inicio de la nueva versión (DD/MM/AAAA)." sin escribir nada.
4. Una tarifa **sin uso** se corrige en el lugar como hoy (sin tocar esta regla).

## Antes de empezar (obligatorio)
1. Leer `CLAUDE.md` (raíz) y `docs/proceso.md`. Comentarios y mensajes en español.
2. `git checkout main && git checkout -b fix/T-028-version-vigente-tarifa`
3. Tests de la parte 1 ya escritos: `api-sistema-fe/tests/Feature/AgenciaViajes/T028VersionVigenteTarifaTest.php`.
   **No cambiarlos** para que pasen; si uno parece incorrecto, para y anótalo en "Desviaciones".
4. **No correr** `composer install`/`composer update`. No correr dos `php artisan test` completos a la vez.
5. Migración nueva: aplicarla a la base de tests con
   `DB_DATABASE=sistemafe_test_migrations php artisan migrate --path=database/migrations/tenant/verticals/agencia-viajes/<archivo>.php --force`
   y a los tenants locales con `php artisan tenants:migrate-verticales` (**nunca** `tenants:migrate` a secas).
6. Fechas: solo `HoraPeru::hoyTexto()`/`HoraPeru::hoy()`; nunca `now()`, `today()` ni `date()` (hay un test que falla si aparecen).

## Parte 1 — vigencia, versionado y tours (commit 1)

**1. Scope** en `app/Models/AgenciaViajes/ProveedorTarifa.php`:
```php
// T-028: única definición de "vigente hoy" (activa y dentro de su rango).
public function scopeVigentes(Builder $query, ?string $dia = null): Builder
```
`$dia ?? HoraPeru::hoyTexto()`; `activo = true`, `vigente_desde <= $dia`, `vigente_hasta` nulo o `>= $dia`.
Más un método `estaVigente(?string $dia = null): bool` con la misma regla (para validar una sola fila).

**2. Usar el scope** en lugar del filtro copiado: `ProveedorTarifaController::biblioteca()`,
`BibliotecaCotizadorController` y `TourCostoEstimadoService` (aquí cambia el resultado: deja de
tomar tarifas futuras, a propósito).

**3. Migración** `database/migrations/tenant/verticals/agencia-viajes/2026_10_28_110000_add_version_anterior_id_to_proveedor_tarifas.php`:
`version_anterior_id` nullable, FK a `proveedor_tarifas.id` con `nullOnDelete()`. Agregarlo a `$fillable`.
Comentario de cabecera como las otras migraciones de esa carpeta.

**4. `ProveedorTarifaController::update()`**, rama con uso: aplicar la "Regla de fechas" de arriba.
Todo en **un** `DB::transaction` con `lockForUpdate()` sobre la tarifa actual:
cerrar la vieja → crear la nueva (`version_anterior_id` = vieja) →
`PaquetePlantillaItem::where('proveedor_tarifa_id', vieja)->update(['proveedor_tarifa_id' => nueva])`.
No tocar `alternativa_items`, `reserva_items` ni `opciones_hotel_tarifas`. Mantener la respuesta
actual (`proveedor_tarifa`, `version_anterior_id`, mensaje); si se movieron tours, agregar al
mensaje "N tour(s) pasan a usar el precio nuevo." Actualizar el comentario de arriba del método.

**5. `crearItemProveedor()`**: después del chequeo de `activo` (~995), si `$tarifa && ! $tarifa->estaVigente()` → 422
`'Esta tarifa ya no está vigente: usa la versión actual.'` (para una futura: `'Esta tarifa todavía no está vigente (desde DD/MM/AAAA).'`
también vale, pero el test de la vieja busca el texto "ya no está vigente").

**6. Matriz y "usar tarifa registrada" (H16):**
- `index()` acepta `?solo_vigentes=1` (`$request->boolean('solo_vigentes')`) y aplica `vigentes()`; sin el parámetro, igual que hoy
  (la pantalla de proveedores necesita el historial). Cambiar la firma a `index(Request $request, string $proveedorServicioId)`.
- `tarifasHotel()` aplica `vigentes()` siempre (solo lo usa el cotizador: confirmado, `editar.vue` ~3046).
- Frontend: `proveedorService.listarTarifas(id, params?: { solo_vigentes?: boolean })` y en
  `clicBibliotecaItem()` (~2725) pasar `{ solo_vigentes: true }`. `proveedores/detalle.vue` no cambia.

**7. Tests que agrega Sonnet** en `T028VersionVigenteTarifaTest.php` (al final, mismos helpers; no tocar los existentes):
- `index()` con `solo_vigentes=1` no devuelve vieja, desactivada ni futura; sin el parámetro devuelve las 4.
- `tarifasHotel()` igual (solo la vigente).
- `vigente_hasta` del pedido anterior al inicio de la nueva → 422 y nada cambia.

Verificar y hacer **commit 1**: `fix(agencia): solo se ofrece y se usa la versión vigente de una tarifa (T-028)`.

## Parte 2 — datos ya existentes (commit 2)

**8. Servicio** `app/Services/AgenciaViajes/PlantillasATarifaVigenteService.php` + **comando**
`app/Console/Commands/PlantillasATarifaVigente.php` (`agencia-viajes:plantillas-a-tarifa-vigente {--simular} {--tenant=*}`).
**Copiar el patrón** de `SepararGruposDuplicadosService` / `SepararGruposDuplicados` (T-024): el servicio hace el trabajo y devuelve
la lista de cambios; el comando recorre tenants `giro=agencia_viajes` no archivados con `$tenant->run()` y muestra tablas.
- Candidatos: `paquete_plantilla_items` con `proveedor_tarifa_id` de una tarifa **no vigente hoy** (cerrada o desactivada).
- Equivalente: tarifas `vigentes()` con mismo `proveedor_servicio_id`, `tipo_tarifa`, `modalidad`, `tipo_habitacion`,
  `temporada_id` y `diferenciador` (array: comparar en PHP, no en SQL). Exactamente **una** → reapuntar; **cero o varias** →
  listar como "revisar a mano" sin tocar.
- Registro: tabla `ajustes_plantilla_tarifa` (`paquete_plantilla_item_id`, `tarifa_anterior_id`, `tarifa_nueva_id`, `created_at`),
  migración `2026_10_28_120000_create_ajustes_plantilla_tarifa_table.php` (copiar `2026_10_28_100000_create_ajustes_grupo_opcion_table.php`).
- **Tests** (Sonnet), archivo nuevo `T028PlantillasATarifaVigenteTest.php`, copiar el estilo de
  `T024GrupoHotelesIndependienteTest::test_ca4…`: reapunta el caso unívoco y lo registra; deja el ambiguo (2 vigentes) y el sin
  equivalente; `simular: true` no escribe; correrlo dos veces no cambia nada. Filtrar los resultados por los ids del test (la base
  de pruebas tiene otros datos).
- Correrlo en `agencia-demo` con `--simular` y anotar en "Resultado" cuántos reapuntaría y cuántos quedan para revisar.

Verificar y hacer **commit 2**: `feat(agencia): comando para pasar tours a la tarifa vigente (T-028)`.

## Fuera de alcance (no tocar)
- Alerta de "precio nuevo" antes de enviar (T-022). Precios de cotizaciones ya armadas.
- `GuiaTarifaController` tiene el **mismo** versionado (~línea 92): no tocarlo; anotar en "Hallazgos" si tiene los mismos problemas.
- `tieneUso` solo mira `alternativa_items`: no cambiarlo.

## Criterios de aceptación
- [ ] **CA1 (H5)** — Vieja con `vigente_hasta` = ayer, nueva desde hoy con `version_anterior_id`; la biblioteca solo ofrece la nueva. → test
- [ ] Precio programado a futuro: la vieja sigue hasta la víspera. → test
- [ ] **CA2 (H16)** — Matriz con `solo_vigentes=1` y `tarifasHotel()` solo vigentes; `index()` sin parámetro devuelve todo. → test (Sonnet)
- [ ] **CA3 (H16)** — Alta de ítem con versión vieja o futura → 422. → test
- [ ] **CA4 (H17)** — El tour que usaba la vieja pasa a la nueva. → test
- [ ] **CA5** — Comando: reapunta unívocos, lista ambiguos, `--simular` no escribe, idempotente. → test (Sonnet)
- [ ] **CA6** — Cotizaciones ya armadas no cambian. → test
- [ ] **CA7** — `agencia-demo`: cambiar el precio de una matrimonial usada; la matriz del cotizador muestra una sola matrimonial con el
  precio nuevo; un tour que la usa cotiza el nuevo; revertir. → prueba funcional (la hace Opus)
- [ ] `npm run type-check` (46 errores preexistentes; ninguno nuevo) y `npm run build` sin errores.

## Seguridad y correctitud
- [ ] Permisos: sin rutas nuevas
- [ ] Tenant: el comando corre por tenant de Agencia
- [ ] Concurrencia: versión nueva + tours en la misma transacción, con `lockForUpdate()`
- [ ] Dinero: no se recalcula ningún precio guardado
- [ ] Fechas: `HoraPeru`; columnas `date`
- [ ] Tributario: la nueva conserva `tip_afe_igv`/`destino_tributario`
- [ ] Migraciones: en `verticals/agencia-viajes/`, aplicadas con `tenants:migrate-verticales`

## Cómo verificar
```
cd api-sistema-fe
php artisan test --filter="T028|Tarifa|Biblioteca|TourCosto"
php artisan test        # suite completa; intermitente conocido: Fila13Fase1cFacturacionTest::descuento_del_combo… (ver backlog)
php artisan agencia-viajes:plantillas-a-tarifa-vigente --simular
cd ../admin-start-kit && npm run type-check && npm run build
```

## Al terminar
Llenar "Resultado" (salida de los comandos, desviaciones, hallazgos). Commits con `Co-Authored-By:` de tu sesión.
**No** unir a `main` ni pushear: lo revisa Opus.

---

## Resultado
- **Commits:** parte 1 `3b48cb3`, parte 2 (ver `git log`)
- **Tests:** suite completa tras la parte 1: 1582 en verde; T028 13/13; type-check 46 errores (los preexistentes). `agencia-viajes:plantillas-a-tarifa-vigente --simular` en agencia-demo: reapuntaría 0, 1 para revisar a mano (ítem de tour 38, tarifa 26, sin tarifa vigente equivalente). CA7 (prueba funcional) queda para Opus.
- **Desviaciones de la tarea y por qué:** ninguna. Un test mío ("vigente_hasta anterior") usa fin pasado con inicio original, porque con inicio futuro caía antes en la validación existente `after_or_equal`.

- **Revisión (Opus, 28-oct-2026), commit 3:** `update()` no revisaba, tras el lock, si la versión ya
  tenía sucesora: un doble clic o editar una fila "Vencida" creaba una tercera versión vigente desde
  hoy junto a la segunda (H5 otra vez). Ahora → 422 "ya fue reemplazada". Además la vieja ya no se
  estira: se cierra en el menor entre su `vigente_hasta` y la víspera de la nueva. 2 tests nuevos.
- **CA7 (Opus, 28-oct-2026, `agencia-demo`, migraciones aplicadas con `tenants:migrate-verticales`):**
  matrimonial #2 de Cumbaza editada desde Proveedores 120→140: la vieja queda vencida al 27/10, la
  nueva (#59, `version_anterior_id=2`) desde hoy; la matriz (`solo_vigentes=1`) y la biblioteca
  ofrecen solo la #59. Tarifa #23 (Turismo Selva) 45→55: "1 tour(s) pasan a usar el precio nuevo",
  el ítem 89 de "Tours Laguna Azul" pasó a la #60. Editar otra vez la #23 → 422 "ya fue reemplazada".
  Datos revertidos (#59/#60 borradas, #2/#23 sin fin, ítem 89 → #23). Hallado en la prueba: la
  etiqueta de `proveedores/detalle.vue` mostraba "Programada" en una tarifa que empieza hoy (comparaba
  `"2026-10-28T00:00:00Z"` con `"2026-10-28"` como texto; ya pasaba antes, T-028 lo hacía visible en
  cada versión nueva) — corregido comparando solo el día. type-check: 46 (los preexistentes).

## Hallazgos fuera de alcance
- `GuiaTarifaController::update()` (~línea 76) tiene el mismo problema que tenía esta: cierra la vieja
  con `vigente_hasta = hoy` (dos vigentes ese día), toma el `vigente_desde` original del formulario,
  no guarda `version_anterior_id` y los tours (`paquete_plantilla_items.guia_tarifa_id`) siguen con el
  precio viejo. Candidata a tarea propia.
