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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
