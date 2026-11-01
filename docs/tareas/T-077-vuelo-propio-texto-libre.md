# T-077 — Vuelo propio del pasajero en un solo texto, como observación

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | Del usuario, 01-nov-2026: el vuelo que el pasajero compró por su cuenta es un dato para avisarle, no un servicio de la agencia. Va en un texto corto y sale como observación del pasajero en todas sus filas del reporte operativo (opción A). El vuelo que vende la agencia (`reserva_item_vuelo_pasajero`) no cambia. dakamu no tiene datos de vuelo propio (lo confirmó el usuario): no hace falta migrar datos |
| **Rama** | `feat/T-077-vuelo-propio-texto` |

## Contexto
En Pasajeros de la reserva, «vuelo propio» son 6 campos: aerolínea, fecha y hora de ida y de
vuelta (`reserva_pasajeros.vuelo_*`). Llenarlos toma tiempo y se confunde con el bloque de abajo,
que es el vuelo que vende la agencia. Hoy el reporte operativo arma con ellos filas «Vuelo ida» y
«Vuelo vuelta» en su fecha (`ReporteOperativoController::extenderConFilasDeVuelo()`), y el Excel
tiene 6 columnas.

## Alcance
- **Migración nueva** (`verticals/agencia-viajes`): `reserva_pasajeros.vuelo_propio` text,
  nullable. Las 6 columnas viejas se quedan sin usar (no se borran ni se editan migraciones).
- **Pantalla** (`reservas/detalle.vue`, bloque «Completar solo si el pasajero ya tiene su propio
  vuelo…»): los 6 campos pasan a ser un solo texto corto (input o textarea de 2 líneas), con un
  placeholder de ejemplo: «LATAM LA2203, llega 30/10 08:40 · regresa 02/11 19:10». El texto de
  ayuda dice que es solo para avisarle al pasajero.
- **Backend:** `ReservaPasajeroController` valida y guarda `vuelo_propio` (`nullable|string|max:500`)
  y deja de aceptar los 6 campos viejos. `ReservaPasajero` (`$fillable`), tipos en
  `agencia-viajes.ts`.
- **Reporte operativo:** se quitan las filas sintéticas «Vuelo ida» / «Vuelo vuelta» del vuelo
  propio (las de «(agencia)» se quedan). El texto sale como observación del pasajero en **todas**
  sus filas, junto con alimentación especial y discapacidad. Pantalla y Excel: 1 columna
  «Vuelo propio» (u «Observaciones» con los tres datos) en vez de 6.
- Ajustar los tests que usan los campos viejos: `ReporteOperativoTest`,
  `ReservaItemPasajeroVueloTest`, `ReservaQuitarItemsPasajerosTest`.

## Fuera de alcance
- El vuelo que vende la agencia (`reserva_item_vuelo_pasajero`, «Vuelo ida/vuelta (agencia)»).
- Borrar las 6 columnas viejas: en otra tarea, cuando se confirme que no se usan.
- El catálogo de pasajeros (`pasajeros_catalogo`): el vuelo es de esta reserva, no de la persona.

## Dónde tocar
- `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/` → migración nueva
- `api-sistema-fe/app/Models/AgenciaViajes/ReservaPasajero.php` → `$fillable`, casts
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php` → validación (~línea 112)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReporteOperativoController.php` → fila del pasajero (~475-490), `extenderConFilasDeVuelo()` (~768-806)
- `api-sistema-fe/app/Exports/ReporteOperativoDatosSheet.php` → columnas (~107-112) y encabezados
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue` → bloque de vuelo propio (~329-353) y payload (~1446)
- `admin-start-kit/src/views/agencia-viajes/reporte-operativo/index.vue` → columnas de vuelo (~189-193)
- `admin-start-kit/src/types/agencia-viajes.ts` → `ReservaPasajero`, fila del reporte (~1296)

## Criterios de aceptación
- [ ] **CA1** — Guardar un pasajero con vuelo propio «LATAM LA2203, llega 30/10 08:40» → queda en `vuelo_propio`. → test
- [ ] **CA2** — Reporte operativo con ese pasajero en 3 días → el texto sale en sus 3 filas; no hay filas «Vuelo ida» / «Vuelo vuelta» propias. → test
- [ ] **CA3** — Un pasaje de la agencia con su vuelo confirmado sigue saliendo como fila «Vuelo ida (agencia)» en su fecha. → test existente en verde
- [ ] **CA4** — El Excel tiene la columna nueva y no las 6 viejas. → test
- [ ] **CA5** — En pantalla, el bloque de vuelo propio es un solo campo, con el de la agencia igual que antes. → a mano en `agencia-demo`

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas nuevas; `ReservaPolicy` como hoy)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: `vuelo_propio` `nullable|string|max:500`
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica (texto libre, sin fechas)
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: el texto se muestra con `{{ }}`, nunca con `v-html`
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí

## Cómo verificar
```
php artisan test --filter=ReporteOperativo
php artisan test --filter=ReservaPasajero
php artisan test   # suite completa, contra PostgreSQL
```
Prueba a mano en `agencia-demo`: reserva con un pasajero con vuelo propio, abrir el reporte
operativo del rango del viaje y exportar el Excel.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
