# Recorrido: Reportes comerciales (embudo, origen, vendedor, motivos)

16-nov-2026, tarea **T-062** (CRM C6b). Origen: obs. 16 de
`docs/requerimientos/observaciones-pruebas-usuario.md` (fase C6, 27-oct-2026).
Archivos:
- `api-sistema-fe/app/Services/AgenciaViajes/ReporteComercial.php` (nuevo: `generar()`, `filas()`, `agregar()`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReporteComercialController.php` (nuevo: `index()`, `opciones()`, `export()`)
- `api-sistema-fe/app/Exports/ReporteComercialExport.php` (nuevo)
- Migración `database/migrations/tenant/verticals/agencia-viajes/2026_11_16_200000_add_reportes_comerciales_permission.php`
- `database/seeders/AgenciaViajesRolesSeeder.php` (permiso para Administrador de agencia), `MenuItemsSeeder.php` (ítem en VENTAS)
- `admin-start-kit/src/views/agencia-viajes/reportes-comerciales/index.vue`, `services/admin/reporteComercialService.ts`, `router/routes.ts`
- Tests: `tests/Feature/AgenciaViajes/ReportesComercialesTest.php`, `MenuGirosTest.php`

**Al desplegar:**
1. `php artisan tenants:migrate-verticales` (crea el permiso) y revisar su salida.
2. `php artisan roles:sync-permisos-nuevos "Database\Seeders\AgenciaViajesRolesSeeder"`: `deploy.sh` no lo
   corre. El Propietario lo recibe con `roles:sync-propietario`.
3. El ítem de menú lo crea `MenuItemsSeeder` (paso 7 de `deploy.sh`).
4. Los usuarios tienen que volver a iniciar sesión para ver la pantalla (ver «Por analizar»: sin eso el
   router manda a «Error 500»).

---

## 1. La idea

La agencia ya registra de dónde llegó cada consulta (origen, T-059), quién la atiende (vendedor) y cómo
terminó (resultado y motivo, T-052). Esta pantalla responde **qué canal trae ventas, quién convierte más
y por qué se pierde**, para las cotizaciones del Cotizador **creadas** en un rango de días de Perú
(máximo 12 meses, por defecto el mes en curso), con filtro por vendedor y por origen.

## 2. El recorrido

```
VENTAS › Reportes comerciales   (permiso reportes_comerciales.ver)
  index.vue
    GET reportes-comerciales/opciones   → vendedores (incluye inactivos) y orígenes (incluye desactivados)
    GET reportes-comerciales?desde&hasta&vendedor_id&origen_contacto_id
          → ReporteComercialController::filtros()  (valida; 'sin' = sin vendedor / sin origen)
          → ReporteComercial::generar()
    GET reportes-comerciales/export     → la misma generar() → ReporteComercialExport (.xlsx)
```

Solo Agencia: el controller responde 404 en otro giro. Sin `cotizaciones.ver_todas`, el reporte (y la
lista de vendedores del filtro) muestra solo las cotizaciones propias (`Cotizacion::propias()`).

### 2.1 Una fila por cotización, con su resultado

`ReporteComercial::filas()` arma una subconsulta con **una fila por cotización** (`delCotizador()`: sin
ventas directas ni modificaciones), y a cada una le pone **un solo** resultado:

| Resultado | Cuándo |
|---|---|
| `perdida` | se cerró con «Cerrar como perdida» (`perdida_en`) |
| `abierta` | no tiene alternativa aceptada ni reemplazada (las vencidas cuentan acá) |
| `anulada` | se ganó, pero la reserva está cancelada (decisión del usuario: fila propia, no suma al monto) |
| `ganada` | lo demás |

Como cada cotización cae en un solo resultado, **por origen y por vendedor suman lo mismo que el embudo**
(CA2).

**«Enviada»** = alguna alternativa con `fecha_envio`, **o** ganada/anulada (aceptar desde borrador está
permitido y no deja fecha de envío).

**Monto vendido:** total de la alternativa a la que apunta **hoy** la reserva, en su moneda, PEN y USD por
separado y sin convertir. Si la reserva se modificó, la alternativa original queda `reemplazada` y la
reserva ya apunta a la versión nueva; la subconsulta la encuentra por `reserva_modificaciones` aplicada
(`alternativa_vendida_id`). Por eso el monto es el de la versión nueva: es lo que se vendió.

### 2.2 Todo agregado en SQL (CA4)

`agregar()` cuenta con `count(*) FILTER (WHERE f.resultado = …)` y suma montos en `numeric(14,2)` (se
devuelven como texto, sin pasar por float). `generar()` hace **4 consultas** sobre la misma subconsulta,
haya 5 o 5.000 cotizaciones: totales (con la mediana), por origen, por vendedor y motivos.

**Tiempo de respuesta:** mediana con
`percentile_cont(0.5) WITHIN GROUP (ORDER BY horas entre created_at y el primer envío)`, solo de las que
tienen una fecha de envío real.

**Motivos:** % sobre el total del tipo, con una fila «Sin motivo», así las filas suman 100 %. Los motivos
de ganancia cuentan ganadas y anuladas (las dos se ganaron).

**Conversión** = ganadas / consultas (las anuladas no cuentan como venta).

### 2.3 Fechas

El rango son días de Perú: `created_at >= HoraPeru::inicioDiaUtc(desde)` y `< finDiaUtc(hasta)`, nunca
`whereDate`. Hay un test en el borde (una cotización creada a las 21:00 de Lima, cuando en UTC ya es el
día siguiente). En pantalla, `hastaMaximo` repite el tope de 12 meses del backend (`addMonthsNoOverflow`).

### 2.4 El Excel

`ReporteComercialExport` recibe los datos ya calculados y solo arma la grilla, con las mismas secciones
una debajo de otra. Dos detalles que salieron al abrir el archivo de verdad:
- `WithStrictNullComparison`: sin él, los ceros salen como celdas vacías.
- Las filas en blanco llevan una celda `''`: una fila `[]` se descarta y corre las negritas.

### 2.5 La pantalla

Cada cambio de filtro vuelve a pedir el reporte (`watch` profundo sobre `filtros`). **Corregido en la
revisión:** «la última petición gana» (`peticion`), para que una respuesta lenta de filtros anteriores no
pise los números actuales.

## 3. Cómo se probó

- `ReportesComercialesTest` (14 tests): embudo 4/3/3 (CA1); borde del día de Perú; venta directa y
  modificación fuera; anulada; reserva modificada con el monto de la versión nueva; origen y vendedor suman
  lo del embudo con inactivo, origen desactivado, sin origen y sin vendedor (CA2); filtros; PEN/USD sin
  mezclar (CA3); las mismas consultas con 5 que con 25 cotizaciones (CA4); motivos con «Sin motivo»;
  mediana; sin `ver_todas` solo lo propio; rango > 12 meses, invertido o mal escrito → 422; permiso y 404
  en retail; Excel.
- En la revisión: `ReportesComerciales|MenuGiros|ReglaFechasNegocio|RutasAgenciaPermisos` 29/29;
  `type-check` sin errores nuevos (47 previos, T-101).
- En pantalla (Playwright, agencia-demo): noviembre con 13 consultas (4 ganadas, 9 abiertas, igual que el
  listado), filtro por origen y Excel descargado y leído (CA5).

## 4. Si algo falla

| Síntoma | Dónde mirar |
|---|---|
| Origen o vendedor no suman lo del embudo | `filas()` devuelve más de una fila por cotización: revisar los `leftJoin` de reserva y modificación |
| El monto de una reserva modificada sale viejo | el join `reserva_modificaciones` (`estado = aplicada`, `alternativa_vendida_id`) |
| Falta una cotización del día | el rango usa `inicioDiaUtc`/`finDiaUtc`; la fecha es la de **creación** |
| Un vendedor ve las de otros | `propias()` en `filas()` y en `opciones()` |
| Ceros vacíos o negritas corridas en el Excel | `WithStrictNullComparison` y `SEPARADOR` en `ReporteComercialExport` |
| «Error 500» al entrar tras un deploy | el usuario no volvió a iniciar sesión (permisos guardados en el login) |

## 5. Ejercicios

1. En `filas()`, ¿por qué el `CASE` mira `perdida_en` **antes** que la alternativa ganada? ¿Qué pasaría
   con el embudo si el orden fuera al revés?
2. Busca en `agregar()` cómo se cuentan las «enviadas» y explica por qué una ganada sin `fecha_envio`
   cuenta igual.
3. Con el test de CA4 como guía, agrega una cotización más al escenario y comprueba que el número de
   consultas no cambia.
