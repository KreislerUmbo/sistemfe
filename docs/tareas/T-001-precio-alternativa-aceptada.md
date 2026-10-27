# T-001 — Bloquear el cambio de precio de un servicio en una alternativa aceptada

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | R-AGE-11 |
| **Decisiones** | — |
| **Rama** | `fix/T-001-precio-alternativa-aceptada` |

## Contexto
`AlternativaItemController::update()` cambia `descuento_pct` o `precio_convertido` de un
servicio y **no revisa** si la alternativa ya fue aceptada. Facturar una reserva lee el precio
en vivo de `alternativa_items.total_convertido` (`ReservaFacturacionController`), así que una
llamada directa a la API cambia el precio de lo que falta facturar de una reserva ya vendida.
La pantalla oculta la edición, pero la regla tiene que estar en el backend. Hallazgo de
`docs/requerimientos/agencia-modificar-reserva.md` §2.2: visto leyendo el código, todavía no
reproducido.

## Alcance
- Reproducir primero con un test que falle: con la alternativa aceptada, `PUT` del ítem con
  `precio_convertido` devuelve 200 y cambia el precio.
- Agregar en `update()` el mismo guard que ya usan los métodos parecidos: 422 si
  `alternativa.estado === 'aceptada'`, con el mensaje de `precioCarga()` ("…el precio ya no
  se cambia desde acá."). Va **antes** de calcular nada.
- Revisar el resto de los endpoints de `AlternativaItemController` que cambian precio,
  cantidad o pasajeros de un ítem (`actualizarPasajeAereo`, `actualizarManual`,
  `desdePlantilla`, `elegirOpcionGrupo`, `eliminarCarga`…). Anotar en "Hallazgos" cuáles no
  tienen el guard de alternativa aceptada ni el de "ya tiene reserva". **No arreglarlos acá**,
  salvo que sea exactamente el mismo caso que `update()`; en ese caso, consultar antes.

## Fuera de alcance
- `store()` sobre una alternativa aceptada sigue permitido a propósito: es lo que usa
  `ReservaController::sincronizarItems()`.
- Cambiar el frontend.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php`
- `api-sistema-fe/tests/Feature/AgenciaViajes/AlternativaItemBloqueaMoverSiAceptadaTest.php`
  (sumar casos acá, ya prueba guards del mismo tipo)

## Criterios de aceptación
- [ ] **CA1** — Dada una alternativa aceptada, cuando se hace `PUT` de un ítem con
  `precio_convertido`, entonces responde 422 y el ítem no cambia (`precio_convertido`,
  `descuento_pct`, `total_convertido`, `alternativa.total`). → test nuevo
- [ ] **CA2** — Igual que CA1 con `descuento_pct`. → test nuevo
- [ ] **CA3** — Con una alternativa en borrador, el `PUT` sigue funcionando igual que antes.
  → test (existente o nuevo)
- [ ] **CA4** — El test de CA1 falla sin el fix (confirmarlo y anotarlo en "Resultado").

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (no hay rutas nuevas)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: el guard va antes de calcular nada
- [ ] Concurrencia: no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=AlternativaItemBloqueaMoverSiAceptadaTest
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:** rama `fix/T-001-precio-alternativa-aceptada` (ver `git log`).
- **Tests:** 3 nuevos en `AlternativaItemBloqueaMoverSiAceptadaTest` (precio y descuento en
  aceptada → 422 sin cambios en el ítem ni en el total; en borrador sigue funcionando).
  **CA4:** sin el guard fallan los 2 de alternativa aceptada (el precio y el descuento sí
  cambiaban). Suite completa 1556 en verde.
- **Cómo quedó:** en `AlternativaItemController::update()`, el mismo guard y mensaje que
  `precioCarga()`, justo después de cargar el ítem y antes de validar o calcular.
- **Desviaciones de la tarea y por qué:** ninguna.

## Hallazgos fuera de alcance
Revisión del resto de `AlternativaItemController` (métodos que cambian precio, cantidad o
pasajeros):

| Método | Guard "alternativa aceptada" | Guard "ya tiene reserva" | Nota |
|---|---|---|---|
| `update()` | **sí (T-001)** | no | — |
| `precioCarga()` | sí | no | — |
| `elegirOpcionGrupo()` / `eliminarGrupo()` | sí | no | — |
| `moverCarga()` / `reasignarDia()` / `moverBloque()` / `recalcularTemporadas()` | sí | no | no cambian precio salvo `recalcularTemporadas()` |
| `actualizarPasajeAereo()` | no | sí | cubierto: en una aceptada sus ítems tienen reserva |
| `actualizarManual()` | no | sí | ídem |
| `destroy()` / `eliminarCarga()` | no | sí | ídem |
| `desdePlantilla()` / `store()` | no | no | a propósito: agregar a una aceptada es lo que usa `sincronizarItems()` |
| `promoverAProveedor()` | no | no | no cambia precio (solo anota el proveedor creado) |

- Un servicio **agregado** a una alternativa aceptada y todavía **sin sincronizar** a la reserva
  ya no puede cambiar de precio antes de sincronizarse (lo mismo pasaba con `precioCarga()` para
  tours). Si se necesita ajustar el precio de algo agregado después, el camino es "Modificar
  reserva" (T-002 a T-007).
