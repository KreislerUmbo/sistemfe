# T-027 — Eliminar un tour clásico en una sola operación (total guardado correcto) + subtotal por destino

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna (si T-025 ya está hecha, el endpoint nuevo lleva su verificación de dueño) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión del flujo de cotizaciones", H12 y H14 |
| **Rama** | `fix/T-027-eliminar-tour-clasico` |

## Contexto
**H12.** En el cotizador, "Eliminar todo el tour" de un bloque clásico (ítems con el mismo
`tour_origen_id`) hace `Promise.all` de un `DELETE alternativa-items/{id}` por ítem
(`eliminarBloque()` en `editar.vue`). Cada `AlternativaItemController::destroy()` borra su ítem y
**después** recalcula y guarda `alternativas.total` leyendo los ítems que haya en ese momento. Con
las peticiones en paralelo, la última en guardar puede haber leído ítems que otra todavía no había
borrado: `alternativas.total` queda mal. Ese total es el que muestran las pestañas de alternativas
y el que usa el PDF (`AlternativaPdfService`: `$total = $alternativa->total`). Además, si falla un
borrado, el tour queda a medias. La línea de tour del modo nuevo ya tiene su endpoint de una sola
operación (`eliminarCarga()`); el bloque clásico no.

**H14.** Con varios destinos, el subtotal por destino del panel (`gruposPrecioPorDestino`) suma
con `totalConvertidoLocal` todas las filas, incluidas todas las opciones de una comparación de
hoteles. El total general usa `totalEfectivoLocal` y está bien.

## Alcance
- **Reproducir H12 primero:** test que borre en paralelo (o simule el orden de escritura) y muestre
  el total guardado incorrecto; si no se logra reproducir de forma confiable, anotarlo y seguir
  igual (el cambio elimina la posibilidad).
- Endpoint nuevo `DELETE alternativas/{id}/tours/{tourOrigenId}` (nombre a criterio, coherente con
  `eliminarCarga`): borra en **una transacción** todos los ítems del bloque clásico (con sus
  `cotizacion_pasaje_aereo` si los hubiera), con los mismos guardas que `destroy()` (alguno con
  reserva → 422 y no borra nada; alternativa aceptada → 422), y recalcula el total **una vez** al
  final. Bloquear la alternativa (`lockForUpdate()`) mientras tanto.
- `eliminarBloque()` en `editar.vue` usa el endpoint nuevo en vez de `Promise.all`.
- Revisar si hay otros `Promise.all` de borrados o cambios de ítems en el cotizador
  (`grep -n "Promise.all" editar.vue`) y anotarlos en "Hallazgos" si tienen el mismo problema.
- **H14:** `gruposPrecioPorDestino` calcula el subtotal con `totalEfectivoLocal(items)`.

## Fuera de alcance
- H13 (precio en línea que se pierde al recargar): necesita verificarse primero en pantalla.
- Cambiar `destroy()` de un ítem suelto.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php`
- `api-sistema-fe/routes/api.php`
- `admin-start-kit/src/services/admin/alternativaItemService.ts`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`

## Criterios de aceptación
- [ ] **CA1** — Bloque de 3 ítems → el endpoint los borra todos y `alternativas.total` = suma de lo
  que queda. → test
- [ ] **CA2** — Un ítem del bloque con reserva → 422 y no se borra ninguno. → test
- [ ] **CA3** — Alternativa aceptada → 422. → test
- [ ] **CA4** — Con 2 destinos y una comparación de 2 hoteles abierta en uno, el subtotal de ese
  destino suma solo la opción más barata (o la elegida). → prueba funcional
- [ ] **CA5** — Prueba funcional en `agencia-demo`: cargar un tour clásico, eliminarlo, la pestaña
  de la alternativa y el PDF muestran el total correcto. Revertir datos de prueba.
- [ ] **CA6** — `type-check` y `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos: `cotizaciones.editar` (+ verificación de dueño si T-025 ya está en `main`)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: el bloque debe ser de esa alternativa
- [ ] Concurrencia: **es parte del objetivo** — una transacción y un solo recálculo
- [ ] Dinero: total con `calcularTotalEfectivo()`
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=EliminarTour
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
