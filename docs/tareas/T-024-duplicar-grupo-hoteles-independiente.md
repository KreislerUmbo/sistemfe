# T-024 — La comparación de hoteles de una alternativa duplicada es independiente de la original

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión del flujo de cotizaciones", H1 |
| **Rama** | `fix/T-024-duplicar-grupo-hoteles` |

## Contexto
`AlternativaController::duplicar()` copia `grupo_opcion_id` tal cual, así que la original y la
copia comparten el identificador del grupo. `AlternativaItemController::eliminarGrupo()` busca
solo por `grupo_opcion_id`, sin alternativa: "Eliminar todo este bloque" en la copia borra
también la comparación de la original (y su total no se recalcula). Si la primera fila encontrada
es de una alternativa aceptada, responde 422 aunque se quiera borrar el de la copia. Confirmado
leyendo el código; reproducir con un test antes de corregir.

## Alcance
- **Reproducir primero** con un test: duplicar una alternativa con un grupo; eliminar el grupo en la
  copia; la original pierde sus filas.
- `duplicar()`: generar un `grupo_opcion_id` **nuevo** por cada grupo de la original (mapa
  viejo → nuevo, igual que `carga_tour`/`carga_combo` ya hacen con `$cargasClonadas`).
- `eliminarGrupo()`: acotar a una alternativa. La ruta pasa a llevar la alternativa
  (`DELETE alternativas/{alternativaId}/grupos/{grupoOpcionId}`) o recibe `alternativa_id`
  validado; elegir la forma que menos toque el frontend y anotarla. Recalcular el total de esa
  alternativa (ya lo hace).
- **Datos ya existentes:** comando `agencia-viajes:separar-grupos-duplicados --simular` que busca
  `grupo_opcion_id` presentes en más de una alternativa y asigna uno nuevo a cada alternativa
  salvo la primera (por id). Con registro de lo cambiado. Correrlo en `agencia-demo` en modo
  simulación y anotar cuántos encontró.
- Revisar si algún otro lugar busca por `grupo_opcion_id` sin alternativa
  (`grep -rn grupo_opcion_id app/`); los que ya filtran por alternativa (como `elegirOpcionGrupo()`)
  quedan igual.
- Frontend: ajustar la llamada de `eliminarGrupoLienzo()` en `cotizador/editar.vue` si cambia la ruta.

## Fuera de alcance
- Cambiar cómo se arma un grupo nuevo (T-019).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaController.php` (`duplicar()`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` (`eliminarGrupo()`)
- `api-sistema-fe/routes/api.php`
- `api-sistema-fe/app/Console/Commands/SepararGruposDuplicados.php` (nuevo)
- `api-sistema-fe/tests/Feature/AgenciaViajes/AlternativaDuplicarPreservaCamposTest.php`, `SesionUX2EliminarGrupoTest.php`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`

## Criterios de aceptación
- [x] **CA1** — Duplicar una alternativa con un grupo → la copia tiene un `grupo_opcion_id` distinto
  y conserva el agrupamiento y la elegida. → test
- [x] **CA2** — Eliminar el grupo en la copia → la original conserva todas sus filas y su total. → test
  (falla antes del fix; anotarlo)
- [x] **CA3** — Original aceptada + copia en borrador → eliminar el grupo de la copia funciona. → test
- [x] **CA4** — El comando encuentra grupos compartidos, `--simular` no escribe, sin `--simular`
  separa y registra; correrlo dos veces no cambia nada. → test
- [x] **CA5** — Prueba funcional en `agencia-demo`: duplicar, borrar el bloque en la copia, la
  original intacta. Revertir datos de prueba.

## Seguridad y correctitud
- [x] Permisos: misma ruta/permiso `cotizaciones.editar` (la verificación de dueño es T-025)
- [x] Aislamiento de tenant: el comando corre por tenant de Agencia
- [x] Validación de entrada: la alternativa del grupo debe existir y contener ese grupo
- [x] Concurrencia: no aplica
- [x] Dinero: total recalculado con `calcularTotalEfectivo()`
- [x] Fechas con `HoraPeru`: no aplica
- [x] Tributario: no aplica
- [x] `v-html`: no aplica
- [x] Migraciones: tabla de registro del comando, si se usa una, en `verticals/agencia-viajes/`

## Cómo verificar
```
php artisan agencia-viajes:separar-grupos-duplicados --simular
php artisan test --filter="Duplicar|EliminarGrupo"
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:** ver `git log fix/T-024-duplicar-grupo-hoteles`
- **Tests:** `T024GrupoHotelesIndependienteTest` (CA1-CA4 + 404 si el grupo no es de esa
  alternativa). Antes del fix fallaba CA1 ("la copia no comparte el grupo con la original"); CA2
  y CA3 no se podían escribir contra la firma vieja (sin alternativa). Ajustados
  `SesionUX2EliminarGrupoTest` (firma nueva) y `AlternativaDuplicarPreservaCamposTest` (el
  comentario decía que no hacía falta un grupo distinto; ahora lo exige).
- **CA5:** `agencia-demo`, cotización 47: duplicada la alternativa 69 → copia 81 con grupo propio;
  "Eliminar todo este bloque" en la copia → la copia quedó con 3 ítems y total 95; la 69 intacta
  (5 ítems, su grupo, total 215). Copia borrada después.
- **Comando:** `--simular` en `agencia-demo` → 0 grupos compartidos.
- **Desviaciones de la tarea y por qué:** se eligió la ruta con la alternativa
  (`DELETE alternativas/{alternativaId}/grupos/{grupoOpcionId}`) en vez de `alternativa_id` en
  el cuerpo: el frontend ya tiene `alternativa_id` en las filas del grupo, cambia una línea. El
  trabajo del comando vive en `SepararGruposDuplicadosService` para poder probarlo sin tenants;
  registro en `ajustes_grupo_opcion` (vertical agencia-viajes).

## Hallazgos fuera de alcance
- Ningún otro lugar busca por `grupo_opcion_id` sin alternativa: `elegirOpcionGrupo()`,
  `agruparPorGrupoOpcion()`, `ReservaController` y `AlternativaPdfService` ya trabajan con los
  ítems de una alternativa.
