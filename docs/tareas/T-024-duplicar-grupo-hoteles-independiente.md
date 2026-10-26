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
- [ ] **CA1** — Duplicar una alternativa con un grupo → la copia tiene un `grupo_opcion_id` distinto
  y conserva el agrupamiento y la elegida. → test
- [ ] **CA2** — Eliminar el grupo en la copia → la original conserva todas sus filas y su total. → test
  (falla antes del fix; anotarlo)
- [ ] **CA3** — Original aceptada + copia en borrador → eliminar el grupo de la copia funciona. → test
- [ ] **CA4** — El comando encuentra grupos compartidos, `--simular` no escribe, sin `--simular`
  separa y registra; correrlo dos veces no cambia nada. → test
- [ ] **CA5** — Prueba funcional en `agencia-demo`: duplicar, borrar el bloque en la copia, la
  original intacta. Revertir datos de prueba.

## Seguridad y correctitud
- [ ] Permisos: misma ruta/permiso `cotizaciones.editar` (la verificación de dueño es T-025)
- [ ] Aislamiento de tenant: el comando corre por tenant de Agencia
- [ ] Validación de entrada: la alternativa del grupo debe existir y contener ese grupo
- [ ] Concurrencia: no aplica
- [ ] Dinero: total recalculado con `calcularTotalEfectivo()`
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: tabla de registro del comando, si se usa una, en `verticals/agencia-viajes/`

## Cómo verificar
```
php artisan agencia-viajes:separar-grupos-duplicados --simular
php artisan test --filter="Duplicar|EliminarGrupo"
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
