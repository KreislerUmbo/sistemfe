# Recorrido: «Cerrar como perdida», «Reabrir» y por qué se ganó (CRM C3)

02-nov-2026, tarea **T-052** (unida a main en `4b28288`). Tercera fase del CRM, encima del
historial de T-050 (recorrido 20) y de las próximas acciones de T-051 (recorrido 21). Hasta acá una
cotización que no se vendía se quedaba «enviada» o «vencida» para siempre, y nadie sabía por qué se
gana ni por qué se pierde.

---

## 1. El problema

1. **No había forma de decir «esta venta se perdió».** Solo se descartaba una alternativa al
   aceptar otra, sin motivo.
2. **No se sabía por qué se gana.** Sin ese dato no hay reporte de motivos (T-062).

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `database/migrations/tenant/verticals/agencia-viajes/2026_11_02_300000_create_crm_motivos_resultado_table.php` | **nuevo**: catálogo de motivos + filas base |
| `…/2026_11_02_300100_add_resultado_to_cotizaciones.php` | **nuevo**: `resultado_motivo_id`, `resultado_detalle`, `perdida_en`, `perdida_por` |
| `…/2026_11_02_300200_add_estado_antes_de_perdida_to_alternativas.php` | **nuevo**: el estado que tenía cada alternativa antes de perder |
| `app/Models/AgenciaViajes/CrmMotivoResultado.php` | **nuevo**: constantes `TIPO_GANADA`, `TIPO_PERDIDA`, `NOMBRE_OTRO` |
| `app/Models/AgenciaViajes/Cotizacion.php` | `estaPerdida()`, `estadoResumen()` con `perdida`, relaciones `resultadoMotivo()`/`perdidaPor()` |
| `app/Services/AgenciaViajes/ResultadoCotizacionService.php` | **nuevo**: `cerrarComoPerdida()` y `reabrir()` |
| `app/Http/Controllers/AgenciaViajes/CotizacionResultadoController.php` | **nuevo**: `POST cotizaciones/{id}/perdida` y `/reabrir` |
| `app/Http/Controllers/AgenciaViajes/CrmMotivoResultadoController.php` | **nuevo**: catálogo (index, store, update; sin destroy) |
| `ReservaController::aceptar()` | acepta `resultado_motivo_id` opcional |
| `AlternativaController::store()` / `duplicar()` | 422 si la cotización está perdida |
| `app/Services/Dashboard/DashboardService.php` | `perdidas` en el bloque de cotizaciones de Inicio |
| `components/AgenciaViajes/CerrarComoPerdidaModal.vue` | **nuevo**: motivo + detalle |
| `components/AgenciaViajes/AceptarAlternativaModal.vue` | **nuevo**: reemplaza el Swal de «Aceptado por cliente» |
| `components/AgenciaViajes/MotivosResultadoSeccion.vue` | **nuevo**: sección 14 de Configuración de Agencia |
| `views/agencia-viajes/cotizador/editar.vue` | cabecera con el aviso «Perdida: …» y los botones |
| `views/agencia-viajes/cotizador/index.vue` | badge y filtro «Perdida» |

## 3. El recorrido

### 3.1 El catálogo: se agrega, se renombra, se desactiva… nunca se borra

```
crm_motivos_resultado
  tipo    'ganada' | 'perdida'   (CHECK en la base)
  nombre  único dentro del tipo, sin distinguir mayúsculas
  activo, orden
```

Las filas base (Precio, Eligió otra agencia, … / Rapidez de respuesta, Recomendación, …) las
inserta **la misma migración**: así llegan a los tenants que ya existen y a los nuevos, sin seeder.

**¿Por qué no se borra?** Las cotizaciones apuntan al motivo (`resultado_motivo_id` con FK). Borrar
uno dejaría cotizaciones sin explicación o rompería la FK. Por eso no existe la ruta `DELETE`: se
**desactiva**, deja de ofrecerse y los datos viejos quedan.

**«Otro» es especial:** se reconoce por su nombre (`CrmMotivoResultado::NOMBRE_OTRO`), no se puede
renombrar ni desactivar, y un motivo nuevo se ubica justo antes de él para que siga último. Al
perder con «Otro» el detalle es obligatorio (si no, «Otro» no dice nada).

### 3.2 Cerrar como perdida

```
Cabecera del cotizador → «Cerrar como perdida» → CerrarComoPerdidaModal.vue
   POST cotizaciones/{id}/perdida { motivo_id, detalle }
        │  ruta: permission:cotizaciones.editar
        ▼
   CotizacionResultadoController::cerrarComoPerdida()
        autorizarCotizacion('update')          ← CotizacionPolicy: su vendedor o ver_todas
        valida motivo (activo, tipo 'perdida'); «Otro» sin detalle → 422
        ▼
   ResultadoCotizacionService::cerrarComoPerdida()   — TODO en una transacción
        1. lockForUpdate() de la cotización
        2. con el lock: ¿ya perdida? ¿es una modificación? ¿alguna alternativa aceptada/reemplazada? → 422
        3. UPDATE alternativas SET estado_antes_de_perdida = estado, estado = 'descartada'
              WHERE estado IN ('borrador','enviada')
        4. cotización: motivo, detalle, perdida_en = now() (UTC), perdida_por
        5. actividad 'estado' «Cotización perdida: Precio — …»          (T-050)
        6. AccionesCrm::cerrarPendientesDeCotizacion(…, 'Cotización perdida')  (T-051)
```

**El paso 3 en una sola instrucción.** En Postgres, dentro de un `UPDATE … SET a = estado, estado
= 'descartada'`, todas las columnas de la derecha se leen con su valor **anterior**. Por eso
`estado_antes_de_perdida` recibe `borrador`/`enviada` y no `descartada`, aunque vayan en la misma
línea.

**¿Por qué el lock?** `ReservaController::aceptar()` bloquea la misma fila. Si dos pestañas
intentan una «perder» y la otra «aceptar» la misma cotización, la segunda espera, vuelve a leer y
encuentra el estado nuevo: o la cotización ya está perdida (no se acepta: todas descartadas) o ya
tiene una alternativa aceptada (no se pierde).

### 3.3 Reabrir

```
POST cotizaciones/{id}/reabrir → ResultadoCotizacionService::reabrir()   (transacción + lock)
   UPDATE alternativas SET estado = estado_antes_de_perdida, estado_antes_de_perdida = NULL
      WHERE estado_antes_de_perdida IS NOT NULL
   cotización: motivo, detalle, perdida_en, perdida_por → NULL
   actividad «Cotización reabierta»
```

Una alternativa que **ya** estaba descartada antes de perder tiene `estado_antes_de_perdida`
null: no se toca y sigue descartada. Las fechas de envío y vencimiento nunca se borraron, así que
vuelven tal cual (si ya venció, el listado la mostrará «vencida», que es la verdad).

### 3.4 Mientras está perdida

No hace falta una regla nueva en cada pantalla: como **todas** sus alternativas quedaron
descartadas, enviar (`ESTADOS_SIN_ENVIO`) y aceptar (`alternativaAceptable()`) ya se bloquean solos.
Lo único que se agregó es el 422 al **crear** o **duplicar** una alternativa, porque nacería en
borrador y «reviviría» la cotización por la puerta de atrás.

`Cotizacion::estadoResumen()` revisa `estaPerdida()` **primero**: si no, una perdida con todas
sus alternativas descartadas se mostraría como «descartada».

### 3.5 Al aceptar: «¿Qué decidió la venta?»

```
«Aceptado por cliente» → AceptarAlternativaModal.vue (antes un Swal)
   motivos de tipo 'ganada' como botones; uno o ninguno (opcional: ayudas sin fricción)
   POST alternativas/{id}/aceptar { pasajero_catalogo_ids, resultado_motivo_id?, resultado_detalle? }
        ReservaController::aceptar(): valida ANTES de la transacción y guarda DENTRO,
        junto a cerrarPendientesDeCotizacion()
```

La misma columna `resultado_motivo_id` sirve para ganar y para perder: lo que distingue los dos
casos es `perdida_en` (null = no perdida) y el `tipo` del motivo.

Si el modal no puede cargar los motivos, acepta igual sin preguntar: el motivo nunca puede trabar
una venta.

## 4. Tests

En `tests/Feature/AgenciaViajes/CotizacionResultadoTest.php` (11), más
`DashboardTest::test_agencia_cotizaciones_del_mes_y_viajes_proximos` con una perdida.

| Test | Criterio |
|---|---|
| `test_cerrar_con_precio_descarta_las_alternativas_y_cierra_las_pendientes` | CA1 |
| `test_show_devuelve_el_motivo_y_quien_la_cerro` | CA1 |
| `test_no_se_cierra_con_alternativa_aceptada_ni_otro_sin_detalle_ni_motivo_invalido_ni_dos_veces` | CA2 |
| `test_un_vendedor_sin_ver_todas_no_cierra_ni_reabre_una_cotizacion_ajena` | CA2, permisos |
| `test_reabrir_devuelve_cada_alternativa_a_su_estado_con_sus_fechas` | CA3 |
| `test_aceptar_guarda_el_motivo_de_ganancia_opcional` | CA4 |
| `test_una_cotizacion_perdida_no_se_acepta` | CA4 |
| `test_un_motivo_nuevo_aparece_antes_de_otro_y_se_puede_usar` | CA5 |
| `test_desactivado_no_se_ofrece_ni_se_acepta_y_otro_no_se_renombra_ni_desactiva` | CA5 |
| `test_rutas_sin_delete_y_con_sus_permisos` | CA5, permisos |
| `test_perdida_no_crea_ni_duplica_alternativas` | CA6 |

El recorrido en pantalla se probó con Playwright en agencia-demo (CDKM-1126-0000004).

**En la revisión** se arregló que «Aceptado por cliente» aparecía en alternativas descartadas o
reemplazadas (el backend respondía 422): ahora usa la misma regla que `alternativaAceptable()`.

**Queda abierto (muy improbable):** «otro medio» (`CotizacionActividadController::marcarEnviada()`)
pasa la alternativa a `enviada` sin bloquear la cotización. Si justo coincide con «Cerrar como
perdida», puede quedar una alternativa enviada en una cotización perdida.

## 5. Para desplegar

Tres migraciones nuevas en Agencia: `php artisan tenants:migrate-verticales` y revisar su salida.

## 6. Ejercicios

1. En `cerrarComoPerdida()`, separa mentalmente el paso 3 en dos `UPDATE` (primero `estado =
   'descartada'`, después `estado_antes_de_perdida = estado`). ¿Qué guardaría? ¿Por qué la
   versión en una sola instrucción no tiene ese problema?
2. Una cotización tiene A (enviada), B (borrador) y C (descartada a mano antes). Ciérrala como
   perdida y reábrela: ¿en qué estado queda cada una? Compáralo con el test de CA3.
3. ¿Qué pasaría si `estadoResumen()` revisara `estaPerdida()` al final y no al principio?
4. Busca todos los lugares que crean una alternativa (`AlternativaCreacionService`,
   `AlternativaCopiaService`). ¿Cuáles revisan `estaPerdida()`? ¿Por qué la modificación de
   reserva no lo necesita?
5. Desde Configuración, intenta desactivar «Otro» y crear «precio» (en minúscula) como motivo de
   pérdida. ¿Qué responde el backend y dónde está esa regla?
