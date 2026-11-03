# Recorrido: pasar el trabajo abierto de un usuario que se va (CRM)

03-nov-2026, tarea **T-061**. Sigue a las próximas acciones (T-051, recorrido 21), el resultado de
la venta (T-052, recorrido 22) y «Nueva consulta» (T-059, recorrido 23).

---

## 1. El problema

El personal de la agencia rota cada 3 a 6 meses. Cuando alguien se va, sus cotizaciones abiertas
y sus acciones pendientes siguen a su nombre: nadie las ve en «Mis pendientes de hoy» ni en
Seguimiento, y el cliente se queda sin que lo llamen. Créditos ya resolvía lo mismo para su
cartera («Traspasar cartera»); esta tarea copia ese patrón para Agencia.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Models/AgenciaViajes/Cotizacion.php` | `scopeAbiertas()`: qué es una cotización abierta |
| `app/Services/Crm/TraspasoTrabajoService.php` | **nuevo**: `resumen()`, `destinos()`, `traspasar()` |
| `app/Http/Controllers/AgenciaViajes/TraspasoTrabajoController.php` | **nuevo**: el GET y el POST; 404 fuera de Agencia, 403 por Super-Admin |
| `routes/api.php` | `users/{id}/trabajo-abierto` y `users/{id}/traspasar-trabajo`, con `edit_user` |
| `app/Services/Dashboard/DashboardService.php` | bloque `trabajo_sin_dueno` de Inicio |
| `components/Usuarios/TraspasoTrabajoModal.vue` | **nuevo**: el modal |
| `services/admin/traspasoTrabajoService.ts` | **nuevo**: llamadas y el texto «2 cotizaciones abiertas y 1 acción pendiente» (con su test de Vitest) |
| `views/users/index.vue` | botón por fila, pregunta al desactivar o eliminar, `?traspasar=<id>` |
| `views/dashboards/analytics/index.vue` | tarjeta «Trabajo sin dueño» |

## 3. El recorrido

### 3.1 Qué es «abierta», escrito una sola vez

```php
// Cotizacion::scopeAbiertas()
$query->delCotizador()                      // sin venta directa ni modificación de reserva
    ->whereNull('perdida_en')               // no perdida (T-052)
    ->whereDoesntHave('alternativas', fn ($q) => $q->whereIn('estado', ['aceptada', 'reemplazada']));
```

Las vencidas **cuentan** como abiertas: se pueden reenviar. El mismo scope lo usan tres lugares:
el conteo del modal, el `UPDATE` del traspaso y el aviso de Inicio. Si mañana cambia la
definición, cambia en los tres a la vez y no pueden contradecirse («tiene 2» en el aviso y «no
tiene nada» en el modal).

Todo el scope es `WHERE`: no carga nada en PHP. Por eso sirve dentro de un `UPDATE`.

### 3.2 La condición dentro del UPDATE

```
POST users/11/traspasar-trabajo {hacia_usuario_id: 12}
   │  ruta: permission:edit_user
   ▼
TraspasoTrabajoController::traspasar()
   fuera de Agencia → 404
   origen o destino Super-Admin y quien opera no lo es → 403
   ▼
TraspasoTrabajoService::traspasar()
   validarDestino(): distinto, no eliminado, activo, con cotizaciones.editar → si no, 422
   DB::transaction:
      UPDATE cotizaciones SET vendedor_id = 12 WHERE <abiertas> AND vendedor_id = 11
         RETURNING id, cliente_id
      UPDATE crm_acciones SET responsable_id = 12 WHERE hecha_en IS NULL AND responsable_id = 11
         RETURNING id, cliente_id
      nada cambió → 422 «Ese usuario no tiene trabajo abierto para pasar»
      una crm_actividad tipo 'estado' por cliente: «Trabajo traspasado de A a B: 1 cotización y 1 acción»
```

**¿Por qué no leer primero los ids y después actualizarlos?** Entre leer y escribir, otro usuario
puede aceptar una de esas cotizaciones. Con la lista leída antes, la cotización ganada pasaría al
nuevo vendedor y se perdería quién la vendió. Con la condición en el `UPDATE`, la base la vuelve a
evaluar en el momento de escribir.

**El límite (READ COMMITTED):** el `NOT EXISTS` de alternativas mira la base como estaba al empezar
la sentencia. Una aceptación que se confirma justo en esos milisegundos puede no verse. Se aceptó
porque la ventana es mínima y cerrarla obligaría a bloquear las alternativas.

**¿Y `RETURNING`?** Para escribir una actividad por cliente hace falta saber qué filas cambiaron.
Laravel no tiene `update()->returning()`, así que `actualizarDevolviendo()` compila el `WHERE` del
builder de Eloquent y le agrega `returning id, cliente_id`. Así se sabe exactamente qué se movió,
sin una lectura aparte que podría no coincidir.

Las cotizaciones **cerradas** (ganadas o perdidas) y las **reservas** no cambian de vendedor: son
historia. Las acciones pendientes de una reserva sí pasan, porque son trabajo por hacer.

### 3.3 A quién se le puede pasar

El selector del modal no lista «todos los usuarios»: el GET devuelve `destinos`, armado con el
mismo criterio que `validarDestino()`. Así la pantalla no ofrece a nadie que el POST rechazaría.

```
destinos = User::permission('cotizaciones.editar')
             sin el origen · no inactivos · no eliminados
             sin Super-Admin, salvo que quien opera lo sea
```

Trampa conocida: `User::permission()` de Spatie solo ve permisos guardados. Un Super-Admin que los
tiene por `Gate::before` y no en su rol no aparece en la lista, aunque el POST lo aceptaría.

### 3.4 Se ofrece, nunca se obliga

```
Usuarios → editar → Estado: Inactivo → Guardar
   ¿antes NO estaba inactivo? ¿tiene trabajo abierto (GET trabajo-abierto)?
      sí → «T061 Vendedor tiene 2 cotizaciones abiertas y 1 acción pendiente. ¿Pasar su trabajo ahora?»
            [Pasar su trabajo] → modal          [Ahora no] → queda inactivo igual
Usuarios → eliminar → mismo ofrecerTraspaso()
```

A diferencia de Créditos, que **bloquea** desactivar a quien tiene cartera, acá no se bloquea nada
(regla «ayudas sin fricción»). Si el usuario ya estaba inactivo y solo se le corrige otro dato, no
se vuelve a preguntar (ajuste de la revisión): para eso está el aviso de Inicio.

### 3.5 El aviso de Inicio, en una consulta

```sql
SELECT users.id, …, sum(cotizaciones), sum(acciones)
FROM (
   SELECT vendedor_id    AS usuario_id, count(*) AS cotizaciones, 0 AS acciones FROM <abiertas>    GROUP BY vendedor_id
   UNION ALL
   SELECT responsable_id AS usuario_id, 0, count(*) FROM crm_acciones WHERE hecha_en IS NULL GROUP BY responsable_id
) abierto
JOIN users ON users.id = abierto.usuario_id
WHERE users.state = 2 OR users.deleted_at IS NOT NULL
GROUP BY users.id …
```

Una sola consulta, no una por usuario. El `JOIN users` se hace con `DB::query()` y no con el modelo
`User`, a propósito: el modelo esconde los eliminados (soft delete) y justamente esos son los que
interesan. Solo sale para quien tiene `edit_user` en Agencia, y un Super-Admin solo se le muestra a
otro Super-Admin (ajuste de la revisión: a los demás el traspaso les daría 403).

El botón lleva a `/users?traspasar=<id>`. Usuarios lee el parámetro al montar, abre el modal y lo
borra de la URL al cerrar. El nombre lo trae el GET: un eliminado no figura en el listado.

## 4. Tests

En `tests/Feature/AgenciaViajes/TraspasoTrabajoTest.php` (10) y
`services/admin/traspasoTrabajoService.test.ts` (Vitest).

| Test | Criterio |
|---|---|
| `test_pasan_las_abiertas_y_las_pendientes_y_lo_cerrado_sigue_de_ana` | CA1 |
| `test_una_actividad_de_estado_por_cliente_con_nombres_y_cantidades` | CA2 |
| `test_aviso_en_inicio_de_inactivos_y_eliminados_con_trabajo_abierto` | CA3 |
| `test_aviso_no_muestra_un_super_admin_a_quien_no_lo_es` | CA3 (revisión) |
| `test_destino_invalido_u_origen_sin_trabajo_da_422` | CA4 |
| `test_super_admin_protegido_y_rutas_con_edit_user` | CA4, permisos |
| `test_el_selector_ofrece_solo_destinos_validos` | selector = validación |
| `test_el_origen_eliminado_se_puede_traspasar` | origen eliminado |
| `test_fuera_de_agencia_responde_404` | solo Agencia |
| `test_venta_directa_y_modificacion_no_cuentan_ni_pasan` | CA5 |

El recorrido en pantalla (desactivar → «Ahora no» → aviso en Inicio → traspasar → el destino ve
su trabajo) se probó con Playwright en agencia-demo.

## 5. Para desplegar

Sin migraciones ni seeders. Solo código.

## 6. Ejercicios

1. Reescribe mentalmente `traspasar()` leyendo primero `Cotizacion::abiertas()->pluck('id')` y
   después `whereIn('id', $ids)->update(...)`. ¿Qué cotización podría cambiar de vendedor sin
   deber hacerlo?
2. En `trabajoSinDueno()`, cambia `DB::query()->…->join('users', …)` por una consulta sobre
   `User::query()`. ¿Qué usuarios desaparecen del aviso?
3. ¿Por qué una cotización vencida cuenta como abierta y una perdida no?
4. Un usuario tiene `edit_user` pero ningún permiso de clientes. ¿Ve el botón «Pasar su trabajo»?
   Sigue `esAgencia` en `views/users/index.vue` hasta `GET clients/contexto`.
