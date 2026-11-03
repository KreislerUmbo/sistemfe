# Recorrido: el seguimiento a la vista en el listado de cotizaciones (CRM C6a)

03-nov-2026, tarea **T-053**. Cierra el CRM base, después del historial (T-050, recorrido 20), las
próximas acciones (T-051, recorrido 21), el resultado de la venta (T-052, recorrido 22), «Nueva
consulta» (T-059, recorrido 23) y el traspaso del trabajo (T-061, recorrido 24).

---

## 1. El problema

El listado del Cotizador decía «Enviada» o «Vencida», pero no cuándo se envió, qué tocaba hacer ni
cuándo vencía. Para saber a quién llamar había que abrir las cotizaciones una por una. Y si una
cotización vencía, no había cómo darle más días: había que rehacerla.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Services/AgenciaViajes/SeguimientoCotizacion.php` | **nuevo**: el filtro, el orden y el texto de la columna |
| `CotizacionController::index()` | `?por_seguir=1`, el motivo de pérdida precargado y la columna para toda la página |
| `CotizacionActividadController::extenderVigencia()` | **nuevo**: `POST alternativas/{id}/extender-vigencia` |
| `ConfiguracionAgenciaController` | `dias_aviso_vencimiento_cotizacion` (la columna ya existía, por defecto 2) |
| `components/AgenciaViajes/ExtenderVigenciaModal.vue` | **nuevo**: el modal |
| `views/agencia-viajes/cotizador/index.vue` | columna «Seguimiento» y botón «Por seguir hoy» |
| `views/agencia-viajes/cotizador/editar.vue` | «Vigente hasta el…» / «Vencida el…» y «Extender vigencia» |
| `views/agencia-viajes/configuracion/index.vue` | «Días de aviso antes del vencimiento» |

## 3. El recorrido

### 3.1 Qué entra en «Por seguir hoy»

```
Cotizacion::abiertas()                       ← la definición de T-061, no otra
  Y (  acción pendiente con fecha <= hoy
     O la ÚLTIMA vigencia entre sus enviadas vence <= fin del día (hoy + N días de aviso) )
```

Las ganadas, perdidas, ventas directas y modificaciones de reserva nunca entran: `abiertas()` ya
las saca. Las vencidas **sí** entran, aunque hayan vencido hace semanas: una cotización abierta y
vencida es trabajo pendiente. Sale del filtro al extenderle la vigencia o al cerrarla como perdida.

### 3.2 ¿Por qué la ÚLTIMA vigencia? (ajuste de la revisión)

```
Alternativa A  enviada 20/10, vencida el 01/11
Alternativa B  reenviada 02/11, vigente hasta el 20/11
```

`Cotizacion::estadoResumen()` dice **Enviada**: mientras quede una alternativa vigente, hay una
propuesta viva sobre la mesa. La primera versión miraba cada alternativa por separado: como A
estaba vencida, la cotización quedaba en «Por seguir hoy» para siempre y en rojo, sin decir por
qué. Ahora todo (filtro, orden, texto y rojo) mira la vigencia de **la cotización**: la última
entre sus enviadas. La regla es la misma que la de `estadoResumen()`, así que no se contradicen.

En SQL es una subconsulta, `SeguimientoCotizacion::ultimaVigencia()`:

```sql
SELECT CASE WHEN bool_or(fecha_vencimiento IS NULL) THEN NULL ELSE MAX(fecha_vencimiento) END
FROM alternativas WHERE cotizacion_id = cotizaciones.id AND estado = 'enviada'
```

Si una enviada no tiene `fecha_vencimiento` (la agencia no configuró vigencia), la cotización no
vence: el `CASE` da NULL, y `NULL <= fecha` no es verdadero en SQL.

### 3.3 En SQL, para que pagine bien

El filtro de estado (`?estado_resumen=`) se resuelve en PHP en dos pasadas (ver el comentario en
`index()`). Para «Por seguir hoy» eso no servía: el orden también tiene que ir en la base, si no la
página 2 no seguiría a la página 1. Por eso son `whereExists` y una subconsulta, y el orden es:

```sql
ORDER BY LEAST( (MIN(fecha) de las acciones pendientes),
                (día Perú de la última vigencia) ) ASC, id DESC
```

`LEAST` de Postgres ignora los NULL: si no hay acción, manda la vigencia, y al revés. Lo más
atrasado sale primero.

### 3.4 El día en que vence: un segundo antes

```
«Vigente hasta el 10/11»  →  se guarda HoraPeru::finDiaUtc('2026-11-10') = 11/11 00:00 de Perú (05:00 UTC)
día que se muestra        →  (fecha_vencimiento − 1 segundo) en hora Perú = 10/11 23:59:59 → 10/11
```

`finDiaUtc()` devuelve el **inicio del día siguiente**: es un límite exclusivo (`col < finDiaUtc`).
Si se tomara el día de ese instante tal cual, «vigente hasta el 10/11» se leería «vence el 11/11».
Restando un segundo, el backend, el `ORDER BY` y el editor muestran el mismo día. Esto lo detectó
Sonnet al construir: la tarea pedía las dos reglas a la vez y juntas se contradecían.

Los días se cuentan en **días de calendario de Perú**, no en horas: algo enviado el lunes a las
23:00 está «Enviada ayer» el martes a las 08:00, aunque hayan pasado 9 horas.

### 3.5 La columna sin consultas por fila

```
index()
  paginate()                              ← cotizaciones + alternativas (precargadas, como siempre)
  SeguimientoCotizacion::paraPagina()
     1 consulta: CrmAccion::pendientes() whereIn(referencia_id, ids de la página) + responsable
     por cada fila: texto($cotizacion, su acción más vieja)   ← sin consultas: todo ya está cargado
```

`texto()` es pura: recibe la cotización con sus relaciones y un «hoy», y devuelve
`{texto, urgente}`. Por eso los tests de textos no tocan la base.

Para cumplir CA4 (el mismo número de consultas con 5 que con 50 filas) hubo que quitar del listado
`tiene_grupos_sin_resolver`, un atributo de cada alternativa que hacía una consulta de ítems por
alternativa. El listado no lo usaba; el editor sí, y ahí sigue.

Ejemplo de texto: «Enviada hace 3 días · Vence mañana · llamar hoy (Ana)». Va en rojo si hay una
acción para hoy o atrasada, o si la cotización vence hoy o ya venció.

### 3.6 Extender vigencia

```
editar.vue → «Extender vigencia» → ExtenderVigenciaModal (CampoFecha, mínimo hoy)
   POST alternativas/115/extender-vigencia {fecha: '2026-11-17'}
      ruta: permission:cotizaciones.editar · controller: CotizacionPolicy::update
      fecha anterior a hoy · alternativa no enviada · cotización perdida · no es del Cotizador → 422
      DB::transaction:
         fecha_vencimiento = HoraPeru::finDiaUtc('2026-11-17')
         CrmActividad tipo 'estado': «Vigencia de «Alternativa A» extendida hasta el 17/11/2026 (antes: 30/10/2026)»
```

No cambia el estado de la alternativa: sigue `enviada`. Deja de verse «vencida» porque
`estadoResumen()` compara la fecha con el ahora. El modal propone hoy + los días de vigencia de la
agencia (o 7 si no los configuró).

## 4. Tests

En `tests/Feature/AgenciaViajes/SeguimientoCotizacionTest.php` (15), con «hoy» fijo en el 03/11/2026.

| Test | Criterio |
|---|---|
| `test_por_seguir_trae_las_acciones_de_hoy_y_atrasadas_lo_mas_viejo_primero` | CA1 |
| `test_por_seguir_trae_las_que_vencen_dentro_del_aviso_y_las_vencidas_abiertas` | CA2 (incluye la A vencida con la B vigente) |
| `test_el_orden_mezcla_acciones_y_vencimientos_por_la_fecha_mas_urgente` | orden |
| `test_textos_de_perdida_ganada_borrador_y_descartada` | CA3 |
| `test_textos_de_envio_y_vencimiento` | CA3 (varias enviadas: la última vigencia) |
| `test_textos_de_la_accion_pendiente` | CA3 |
| `test_los_dias_son_de_calendario_de_peru_al_cruzar_la_medianoche` | CA3 |
| `test_el_listado_devuelve_el_seguimiento_de_cada_fila` | columna |
| `test_el_listado_hace_las_mismas_consultas_con_5_y_con_50_cotizaciones` | CA4 |
| `test_extender_una_vencida_la_deja_vigente_con_su_actividad_y_sale_del_filtro` | CA5 |
| `test_extender_sin_vigencia_previa_lo_anota` | CA5 |
| `test_extender_rechaza_fecha_pasada_borrador_perdida_y_modificacion` | CA5 |
| `test_extender_pide_permiso_de_edicion_y_respeta_la_policy` | CA5, permisos |
| `test_un_vendedor_sin_ver_todas_no_ve_en_el_filtro_las_de_otro` | CA6 |
| `test_configuracion_guarda_los_dias_de_aviso` | configuración |

**El test de CA4** activa `DB::enableQueryLog()`, pide el listado con 5 y con 50 cotizaciones, y
compara cuántas consultas hizo. Si alguien agrega algo que consulta por fila, los números dejan de
coincidir y el test falla.

El recorrido en pantalla se probó con Playwright en agencia-demo.

## 5. Para desplegar

Sin migraciones: `dias_aviso_vencimiento_cotizacion` ya existía (por defecto 2). Solo código.

## 6. Ejercicios

1. Vuelve mentalmente a la primera versión de §3.2 (cualquier enviada vencida cuenta). Con la A
   vencida y la B vigente hasta fin de mes, ¿qué ve el vendedor en el listado cada mañana?
2. ¿Por qué el filtro «Por seguir hoy» no se pudo resolver en PHP como `estado_resumen`? Piensa en
   qué pasa con la página 2.
3. Guarda «vigente hasta el 10/11» y quita el `- interval '1 second'` del `ORDER BY`. ¿Qué día usa
   el orden? ¿Coincide con el que se muestra en la columna?
4. Un vendedor envía una cotización el lunes a las 23:30 de Perú. ¿Qué dice la columna el martes
   a las 07:00? ¿Y qué diría si los días se contaran por horas?
5. En `extenderVigencia()`, ¿por qué se rechaza una cotización de modificación de reserva? Mira
   qué es `origen` en `Cotizacion`.
