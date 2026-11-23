# Recorrido: inventario y limpieza de columnas sin uso

23-nov-2026, tarea **T-083**. La auditoría de Agencia del 09-nov encontró columnas y tablas que el código ya no
lee ni escribe. Borrar a ciegas es peligroso; esta tarea muestra el camino seguro: **inventario → conteo en
producción → decisión → migración con salvaguarda**.

Archivos:
- `api-sistema-fe/app/Console/Commands/InventarioColumnasSinUso.php` — comando de solo lectura
- `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/2026_11_23_100000_quitar_columnas_sin_uso_tanda1.php`

**Al desplegar:** backend; `deploy.sh` corre `tenants:migrate-verticales` (solo afecta a tenants de Agencia).

---

## 1. Inventario: quién nombra cada columna

Con `grep` en `app/`, `resources/`, `database/seeders/`, `routes/`, `tests/` y el frontend. Que una columna
aparezca en `$fillable` no es «uso»: solo dice que se *podría* escribir. Lo que importa es si algún
controller, servicio, PDF, Excel o pantalla la lee o la escribe.

| Elemento | Decisión |
|---|---|
| `proveedores.pais_id/departamento_id/provincia_id/distrito_id` | Borrada (tanda 1) |
| `salidas_mayorista.fecha_retorno` | Borrada (tanda 1) |
| `reserva_item_pasajero.checkin_realizado/checkin_hora` | Borrada (tanda 1): el check-in vive en `reserva_item_checkins` (T-041) |
| Reembolso (`reglas_cancelacion`, `reserva.monto_reembolso`, `porcentaje_reembolso_aplicado`) | Se deja: se va a construir |
| `pago_proveedor`, `cronograma_pago_proveedor` | Se dejan hasta Compras |
| Recordatorios (3 tablas) | Se dejan: motor de pendientes (11f) |

## 2. Conteo en producción

`php artisan inventario:columnas-sin-uso` recorre los tenants (`$tenant->run()`) y cuenta: filas de una tabla,
o filas con dato en una columna (no nulo y distinto de 0/false, que es lo que dejan los defaults). «—» = no
existe en ese tenant. En producción (23-nov) todo dio **0** en dakamu.

## 3. La migración con salvaguarda

Antes de `dropColumn`, `verificarSinDatos()` revisa que no haya datos; si los hay, lanza una excepción y **no
toca nada**. Para el check-in, el control es más fino: un check-in viejo marcado que **no** esté copiado en
`reserva_item_checkins` se perdería. `down()` vuelve a crear las columnas vacías y nullable.

## 4. Lo que hubo que ajustar en los tests

- Los «dorados» de T-095 (`fixtures/T095/*.json`) guardan la respuesta exacta de la API: se quitaron las 4
  claves de ubigeo, que ya no vienen.
- `ReporteOperativoCheckinTest` probaba la copia de T-041 con las columnas viejas. Ahora las recrea con el
  `down()` de la tanda 1 **dentro de la transacción del test** (en Postgres el DDL se deshace), y un test nuevo
  prueba que la salvaguarda se detiene con un check-in sin copiar.

## 5. Ejercicios

1. ¿Por qué el inventario cuenta `!= 0` en columnas numéricas y no solo `IS NOT NULL`?
2. Si mañana alguien crea un proveedor con `pais_id`, ¿qué pasa? (Pista: mira `$fillable` de `Proveedor`.)
3. ¿Qué pasaría con `ReporteOperativoCheckinTest` si la base de tests fuera MySQL en vez de Postgres?
