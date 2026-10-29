# Recorrido: borrar la operación de prueba de la agencia (T-070)

29-oct-2026, tarea **T-070** (`docs/tareas/T-070-limpiar-operacion-de-prueba.md`).

---

## 1. Para qué sirve

Antes de operar en campo, la agencia tiene cotizaciones, reservas, ventas, pagos y caja **de
prueba**, varias hechas con reglas que ya cambiaron (ej. la reserva `RDKM-1026-0000012`, con el
tour partido en dos líneas porque se cotizó antes de "exonerado = Amazonía"). El comando las borra
y deja todo lo que es configuración: tours, tarifas, proveedores, guías, destinos, clientes,
usuarios, series y datos de la empresa.

## 2. Cómo usarlo

```
# 1) Simular: muestra qué borraría. No cambia nada; se puede correr las veces que quieras.
php artisan agencia-viajes:limpiar-operacion dakamu

# 2) Ejecutar: pide escribir el ID del tenant para confirmar.
php artisan agencia-viajes:limpiar-operacion dakamu --ejecutar
```

**Antes de ejecutar en el servidor, siempre:**
1. Backup de la base (`pg_dump tenantdakamu > dakamu-AAAA-MM-DD.sql`) y del storage del tenant.
2. Que nadie esté usando el sistema.
3. Correr primero la simulación y revisar los números.

Después: recorrido de prueba completo (cotizar → reservar → anticipo → facturar en beta → nota de
crédito → cerrar caja).

## 3. Qué hace y qué no

| Se borra | Se conserva |
|---|---|
| Cotizaciones, alternativas, ítems, hoteles y mayoristas de la cotización | Tours, su Incluye, itinerario y precios |
| Reservas, pasajeros de reserva, salidas operativas | Proveedores y **todas sus tarifas**, guías, destinos |
| Ventas, notas, pagos, cuotas, anticipos, recibos | Clientes y su catálogo de pasajeros (saldo a favor → 0) |
| Caja: sesiones y movimientos | Cajas, conceptos, medios de pago |
| Tipo de cambio registrado | Series (vuelven a su número inicial), configuración, usuarios, roles |
| XML, CDR y adjuntos de caja del disco | Códigos de tours (no se reinician: los tours siguen) |

## 4. Archivos

| Archivo | Qué hace |
|---|---|
| `api-sistema-fe/app/Console/Commands/LimpiarOperacionAgencia.php` | El comando: busca el tenant, muestra la simulación, pide confirmación |
| `api-sistema-fe/app/Services/AgenciaViajes/LimpiarOperacionPrueba.php` | El trabajo: guardias, simulación, borrado en una transacción |
| `api-sistema-fe/tests/Feature/AgenciaViajes/LimpiarOperacionAgenciaTest.php` | 8 tests |

## 5. El recorrido

```
php artisan agencia-viajes:limpiar-operacion dakamu [--ejecutar]
   │
   └─ LimpiarOperacionAgencia::handle()
        ├─ Tenant::find('dakamu') ─ no existe → error
        └─ $tenant->run(...)                      ← todo pasa en la base del tenant
             ├─ motivosParaNoLimpiar()            ← si hay alguno, se detiene sin borrar
             │    ├─ giro ≠ agencia_viajes
             │    ├─ SUNAT producción + ventas enviadas
             │    ├─ creditos / credito_pagos / prendas con datos
             │    ├─ tablas sin clasificar        ← ver §6
             │    └─ tabla conservada con FK hacia una que se borra
             ├─ simular()                         ← conteos por tabla, archivos, series
             ├─ sin --ejecutar → termina acá
             ├─ pide escribir el ID → si no coincide, termina
             └─ ejecutar()
                  ├─ DB::transaction: borra TABLAS_A_BORRAR en orden,
                  │   reinicia series y códigos, saldo a favor → 0
                  ├─ borra archivos (recién después del commit)
                  └─ Cache::flush()
```

**Por qué una lista y no la cascada de la base:** `sale_details` y `sale_payments` no tienen FK
hacia `sales`; la cascada los dejaría sueltos. Y donde sí hay `ON DELETE CASCADE` puede llevarse
de más. Con la lista se sabe exactamente qué se borra.

**Por qué todo en una transacción:** si una tabla falla a la mitad, no queda la mitad borrada.
Los archivos se borran después, porque un archivo borrado no vuelve con un rollback.

## 6. Cuando agregues una tabla nueva

El servicio tiene **dos listas**: `TABLAS_A_BORRAR` y `TABLAS_QUE_SE_CONSERVAN`. Si en la base
aparece una tabla que no está en ninguna, el comando **se niega a correr** y la nombra:

```
- Tablas que el comando no sabe si borrar o conservar (...): mi_tabla_nueva
```

Hay que decidir: si guarda operación (algo que nace de vender o cobrar), va en `TABLAS_A_BORRAR`
**antes de sus padres** (los hijos primero); si es configuración, en `TABLAS_QUE_SE_CONSERVAN`.
Así nunca se borra o se olvida una tabla sin que alguien lo decida.

## 7. Ejercicios

1. Corré la simulación en `agencia-demo` y compará los números con lo que ves en pantalla
   (Cotizaciones, Reservas, Ventas).
2. Corrélo en `credishirley`: ¿qué guardias saltan y por qué?
3. Buscá en `LimpiarOperacionPrueba.php` por qué `codigo_secuencias` de `tour` no se reinicia.
4. En `LimpiarOperacionAgenciaTest`, `test_falla_revierte_todo`: ¿cómo provoca el fallo y qué comprueba después?
