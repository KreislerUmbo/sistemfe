# Recorrido: retiro de los pagos a proveedor viejos

25-nov-2026, tarea **T-141**. Limpieza antes de Compras: no hay pantalla nueva. Lo que se aprende acá
es **cómo se borra una tabla sin riesgo** en un sistema con muchos tenants.

Archivos:
- `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/2026_11_25_100000_retirar_pago_proveedor_viejo.php`
- `api-sistema-fe/app/Services/AgenciaViajes/LimpiarOperacionPrueba.php` — `TABLAS_A_BORRAR`
- `api-sistema-fe/app/Console/Commands/InventarioColumnasSinUso.php` — `ELEMENTOS`
- `api-sistema-fe/tests/Feature/AgenciaViajes/RetirarPagoProveedorViejoTest.php`
- Borrados: `app/Models/AgenciaViajes/PagoProveedor.php` y `CronogramaPagoProveedor.php`

**Al desplegar:** `tenants:migrate-verticales` y revisar su salida (`deploy.sh` sigue aunque falle).

---

## 1. Por qué se borra

`pago_proveedor` y `cronograma_pago_proveedor` nacieron en las sesiones 8b y 9b de Agencia, pero
nunca tuvieron controlador, servicio ni pantalla: nadie escribía en ellas. Compras (T-136) trae un
modelo de pagos único (`pagos_proveedor` + aplicaciones) y su modelo se llama también
`PagoProveedor`. Si lo viejo seguía, chocaban los nombres y quedaban dos formas de "pagar a un
proveedor". Una sola fuente de verdad: se retira lo viejo **antes** de construir lo nuevo.

## 2. La salvaguarda: mirar antes de borrar

Un `dropIfExists` a secas borraría en todos los tenants de Agencia lo que hubiera adentro. El conteo
de producción del 23-nov (`inventario:columnas-sin-uso`) dio 0 filas en dakamu, pero un conteo es una
foto: la migración vuelve a mirar en el momento de correr, en cada tenant.

```php
$conDatos = array_values(array_filter(
    self::TABLAS,
    fn (string $tabla) => Schema::hasTable($tabla) && DB::table($tabla)->exists()
));

if ($conDatos !== []) {
    throw new RuntimeException('T-141: hay filas en tablas que se iban a borrar: ...');
}
```

Se revisan **las dos** antes de borrar **ninguna**: si solo una tiene datos, no queda el tenant a
medias. Es el mismo patrón de T-083.

## 3. `down()`: poder volver

`down()` vuelve a crear las dos tablas **vacías** con su esquema original (copiado de las migraciones
viejas, que no se editan). Los datos no se recuperan, porque no había; lo que se recupera es la
estructura, por si hay que hacer rollback.

## 4. Por qué es migración del vertical y no de `core/`

Las tablas solo existen en tenants de Agencia. Por eso la migración vive en
`verticals/agencia-viajes/` y se aplica con `tenants:migrate-verticales`: un tenant de retail o de
Créditos nunca la ve.

## 5. Qué más se tocó

- `LimpiarOperacionPrueba::TABLAS_A_BORRAR` las nombraba para vaciarlas al limpiar datos de prueba;
  ya no existen, así que salen de la lista. `LimpiarOperacionAgenciaTest` sigue verificando que
  ninguna tabla quede sin clasificar.
- `InventarioColumnasSinUso::ELEMENTOS` las contaba en producción; ya cumplió su función.

## 6. Lo que se queda (a propósito)

Los recordatorios (motor 11f, sin backend todavía) tienen el tipo `pago_proveedor_pendiente`,
`recordatorios.entidad_tipo = 'pago_proveedor'` y `configuracion_agencia.dias_aviso_pago_proveedor`.
Son la **configuración del aviso** de pago, no los pagos. Cuando exista el motor, ese aviso apuntará
a las compras y cuotas de Compras.

## 7. Cómo se prueba

`RetirarPagoProveedorViejoTest` corre la migración **dentro de la transacción del test**: en Postgres
el DDL (`CREATE`/`DROP TABLE`) también se deshace con el `rollBack()`, así que la base de tests queda
igual.

- **CA1:** `down()` crea las tablas, `up()` las borra, y un segundo `up()` no falla.
- **CA2:** con una fila en `cronograma_pago_proveedor`, `up()` lanza la excepción y las dos tablas
  siguen ahí, con la fila.
- **CA3:** recorre `app/` buscando los nombres; solo puede aparecer `Recordatorio.php`.
