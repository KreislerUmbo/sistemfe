# T-027 — Eliminar un tour clásico en una sola operación (total guardado correcto) + subtotal por destino

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-025 (hecha: cada acción verifica dueño con `autorizarCotizacion()`) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión del flujo de cotizaciones", H12 y H14 |
| **Rama** | `fix/T-027-eliminar-tour-clasico` |
| **Implementa** | otro agente (Sonnet). Análisis, tests y revisión: Claude Opus, 28-oct-2026 |

## Contexto
**H12.** En el cotizador, "Eliminar todo el tour" de un bloque clásico (ítems con el mismo
`tour_origen_id` y **sin** `carga_tour`) hace `Promise.all` de un `DELETE alternativa-items/{id}`
por ítem (`eliminarBloque()` en `editar.vue`, línea ~2356). Cada `destroy()` borra su ítem y
**después** recalcula `alternativas.total` con los ítems que hay en ese momento: con peticiones en
paralelo, el último en guardar puede haber leído ítems que otro aún no borró, y el total guardado
(el de las pestañas y el del PDF) queda mal. Si falla un borrado, el tour queda a medias.

**H14.** `gruposPrecioPorDestino` (`editar.vue`, línea ~2316) suma el subtotal por destino con
`totalConvertidoLocal` fila por fila, contando **todas** las opciones de una comparación de
hoteles. El total general usa `totalEfectivoLocal(items)` y está bien.

## Antes de empezar (obligatorio)
1. Leer `CLAUDE.md` (raíz) y `docs/proceso.md`. Responder y comentar en español.
2. `git checkout main && git checkout -b fix/T-027-eliminar-tour-clasico`
3. Los tests ya están escritos: `api-sistema-fe/tests/Feature/AgenciaViajes/T027EliminarBloqueTourTest.php`.
   **No cambiarlos** para que pasen. Si alguno te parece incorrecto, para y anótalo en "Desviaciones".
4. **No correr** `composer install` ni `composer update` (el lock es para PHP 8.5; local es 8.2).
5. No correr dos `php artisan test` completos a la vez (comparten la base de pruebas).

## Alcance — qué escribir exactamente

### Backend
**1. `AlternativaItemController::eliminarBloque(Request $request, string $alternativaId): JsonResponse`**
(`api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php`). Ponerlo
junto a `eliminarCarga()` (línea ~653), que es **el patrón a copiar**. Orden:
1. `$alternativa = Alternativa::findOrFail($alternativaId);` + `$this->autorizarCotizacion('update', $alternativa);`
2. Validar con `Validator::make` (mismo estilo que `moverCarga()`): `item_ids` → `required|array|min:1`,
   `item_ids.*` → `integer|distinct`. Falla → 422 con `$validator->errors()->first()`.
3. Alternativa aceptada → 422 (copiar el mensaje de `moverBloque()`, adaptado a "eliminar").
4. `DB::transaction`, y dentro:
   - `Alternativa::whereKey($alternativa->id)->lockForUpdate()->first();`
   - Cargar `AlternativaItem::where('alternativa_id', $alternativa->id)->whereIn('id', $ids)->lockForUpdate()->get()`.
   - Si no se encontraron **todos** los ids → 404 "No se encontró ese tour en esta alternativa."
   - Todos deben tener el **mismo** `tour_origen_id` no nulo y `carga_tour` nulo; si no → 422
     ("Estos servicios no forman un solo tour."). La línea de tour del modo nuevo se quita con `eliminarCarga()`.
   - Alguno con `ReservaItem` → 422, mismo mensaje que `eliminarCarga()`.
   - Borrar los `CotizacionPasajeAereo` de los ítems `ORIGEN_PASAJE_AEREO` (como `destroy()`), luego los ítems.
   - `$this->recalcularTotalAlternativa($alternativa);` **una sola vez**, dentro de la transacción.
   - Para devolver un 404/422 desde dentro de la transacción, haz que el closure devuelva la
     respuesta (o `null` si todo fue bien) y retórnala fuera; nada se escribe antes de los chequeos.
5. 200 `['code' => 200, 'message' => 'Tour eliminado correctamente']`.
6. Comentario breve arriba del método: `// T-027 (H12): …` explicando por qué existe (ver Contexto).

**2. Ruta** en `api-sistema-fe/routes/api.php`, junto a `mover-bloque` (línea ~1014):
```php
Route::post("alternativas/{id}/items/eliminar-bloque", [AlternativaItemController::class, 'eliminarBloque'])
    ->middleware('permission:cotizaciones.editar');
```
(POST y no DELETE: lleva cuerpo con los ids.)

### Frontend
**3. `admin-start-kit/src/services/admin/alternativaItemService.ts`** — junto a `eliminarCarga`:
`async eliminarBloque(alternativaId: number, itemIds: number[])` → `httpClient.post(\`/alternativas/${alternativaId}/items/eliminar-bloque\`, { item_ids: itemIds })`.

**4. `editar.vue` → `eliminarBloque()`** (línea ~2356): reemplazar el `Promise.all` por
`await alternativaItemService.eliminarBloque(alternativaActiva.value.id, bloque.items.map((i) => i.id))`.
Salir temprano si no hay `alternativaActiva.value`. Mensaje de error: `'No se pudo eliminar el tour'`.
Actualizar el comentario de arriba (ya no es "un DELETE por ítem").

**5. `editar.vue` → `gruposPrecioPorDestino`** (línea ~2316):
`subtotal: totalEfectivoLocal(items)` en vez del `reduce` con `totalConvertidoLocal`.

**6.** `grep -n "Promise.all" editar.vue`: si algún otro borra o cambia **varios ítems** en paralelo,
no lo toques; anótalo en "Hallazgos" (los que solo recargan datos en paralelo están bien).

## Fuera de alcance (no tocar)
- `destroy()` de un ítem suelto, `eliminarCarga()`, `moverBloque()`.
- H13 (precio en línea que se pierde al recargar).
- Ya sabido, **no corregir aquí**: `destroy()` y `eliminarCarga()` no rechazan una alternativa
  aceptada (solo miran si hay reserva); `moverBloque()` mueve el tour en **todos** los días, no solo
  el visible. Quedan anotados para otra tarea.

## Criterios de aceptación
- [x] **CA1** — Bloque de 3 ítems → se borran todos y `alternativas.total` = lo que queda. → test
- [x] **CA2** — Un ítem del bloque con reserva → 422 y no se borra ninguno. → test
- [x] **CA3** — Alternativa aceptada → 422. → test
- [x] Solo borra los ids mandados (mismo tour en otro día queda); rechaza ids ajenos, sin tour,
  de dos tours, o línea de tour nueva; lista vacía → 422. → tests
- [x] **CA4** — 2 destinos, comparación de 2 hoteles en uno: el subtotal de ese destino suma solo
  la opción efectiva. → prueba funcional (la hace Opus en la revisión)
- [x] **CA5** — `agencia-demo`: cargar un tour clásico, eliminarlo, la pestaña y el PDF muestran el
  total correcto; revertir. → prueba funcional (la hace Opus en la revisión)
- [x] **CA6** — `npm run type-check` y `npm run build` sin errores.

## Seguridad y correctitud
- [x] Permisos: `cotizaciones.editar` + `autorizarCotizacion('update', $alternativa)`
- [x] Validación: los ids deben ser todos de esa alternativa y un solo tour clásico
- [x] Concurrencia: una transacción con `lockForUpdate()` y un solo recálculo
- [x] Dinero: total con `recalcularTotalAlternativa()` (usa `calcularTotalEfectivo()`)
- [x] Tenant, fechas, tributario, `v-html`, migraciones: no aplica

## Cómo verificar (correr todo antes de entregar)
```
cd api-sistema-fe
php artisan test --filter="T027|EliminarGrupo|EliminarCarga"
php artisan test        # suite completa; una falla intermitente conocida: Fila13Fase1cFacturacionTest::descuento_del_combo… (ver backlog)
cd ../admin-start-kit
npm run type-check && npm run build
```

## Al terminar
- Un commit en la rama, mensaje en español estilo
  `fix(agencia): eliminar un tour clásico en una sola operación (T-027)`, terminando con la línea
  `Co-Authored-By:` que indique tu sesión. **No** unir a `main` ni pushear: lo revisa Opus.
- Llenar "Resultado" abajo (tests y salida de los comandos, desviaciones, hallazgos).

---

## Resultado
- **Commits:** rama `fix/T-027-eliminar-tour-clasico` (sin unir a main).
- **Tests:** `--filter="T027|EliminarGrupo|EliminarCarga"` 9/9; suite completa 1572 passed (5539 assertions). `npm run build` OK. `npm run type-check`: 46 errores, idénticos antes y después del cambio (preexistentes; ninguno en `editar.vue` ni `alternativaItemService.ts`).
- **Desviaciones de la tarea y por qué:** ninguna. Tests no modificados.
- **Revisión (Opus, 28-oct-2026):** diff correcto, sin cambios pedidos. Confirmado: 9/9 tests, 46 errores de type-check preexistentes (0 en los archivos tocados). CA4 en `agencia-demo` (copia de la alternativa 69 + segundo destino): subtotal del destino 215.00 = total (antes habría sumado 305). CA5: "Eliminar todo el tour" manda una sola petición `POST …/eliminar-bloque`; pestaña, base y PDF quedan en PEN 120.00. Copia de prueba borrada.

## Hallazgos fuera de alcance
- (Opus, al preparar) `destroy()` y `eliminarCarga()` no rechazan alternativa aceptada; `moverBloque()`
  mueve el tour en todos los días.
- (Sonnet) Los demás `Promise.all` de `editar.vue` (~3140-3235, 4211, 4232) solo recargan datos en
  paralelo; ninguno borra ni cambia varios ítems.
