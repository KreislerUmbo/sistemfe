# Recorrido: «Nueva consulta» y origen del contacto (CRM C4)

03-nov-2026, tarea **T-059** (unida a main en `e6be7e5`). Cuarta fase del CRM, después del
historial (T-050, recorrido 20), las próximas acciones (T-051, recorrido 21) y el resultado de la
venta (T-052, recorrido 22).

---

## 1. El problema

1. **Registrar a alguien que pregunta por WhatsApp costaba demasiado.** Había que crear el cliente
   con sus datos y después una cotización completa, con edades de pasajeros.
2. **No se sabía por dónde llegaba la gente** (WhatsApp, Facebook, una recomendación…), así que
   tampoco qué canal trae ventas. Ese dato lo leerá el reporte de T-062.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `database/migrations/tenant/core/2026_11_03_100000_create_crm_origenes_contacto_table.php` | **nuevo**: catálogo + 10 filas base + índice único `LOWER(nombre)` |
| `database/migrations/tenant/core/2026_11_03_100100_add_origen_contacto_id_to_clients.php` | **nuevo**: el origen del primer contacto del cliente |
| `database/migrations/tenant/verticals/agencia-viajes/2026_11_03_100000_add_origen_contacto_id_to_cotizaciones.php` | **nuevo**: el origen de cada consulta |
| `app/Models/Client/CrmOrigenContacto.php` | **nuevo**: `scopeActivos()`, `scopeOrdenados()` |
| `app/Http/Controllers/Client/CrmOrigenContactoController.php` | **nuevo**: catálogo (index con `ultimo_del_usuario`, store, update; sin destroy) |
| `app/Services/AgenciaViajes/CotizacionCreacionService.php` | **nuevo**: crear la cotización con su «Alternativa A» (antes dentro de `CotizacionController::store()`) |
| `app/Http/Controllers/AgenciaViajes/ConsultaController.php` | **nuevo**: `coincidencias()` y `store()` |
| `CotizacionController` | `store()` usa el servicio; `index()` trae y filtra el origen |
| `ClienteRequest` / `ClientController::allowedFields()` / `ClientResource` | el origen se corrige en la ficha |
| `components/AgenciaViajes/NuevaConsultaModal.vue` | **nuevo**: el modal |
| `components/AgenciaViajes/OrigenesContactoSeccion.vue` | **nuevo**: sección 15 de Configuración de Agencia |
| `helpers/clientes/coincidencias.ts` | **nuevo**: cuándo buscar coincidencias (con su test de Vitest) |
| `views/agencia-viajes/cotizador/index.vue` / `nueva.vue` | botón, columna y filtro; selector de origen |
| `views/dashboards/analytics/index.vue` / `views/clients/ficha.vue` | botón en Inicio; origen en la ficha |

## 3. El recorrido

### 3.1 Dos orígenes distintos: el del cliente y el de cada consulta

```
clients.origen_contacto_id       → cómo nos conoció la PRIMERA vez (no cambia solo)
cotizaciones.origen_contacto_id  → por dónde llegó ESTA consulta
```

Roxana llegó por Instagram en marzo. En noviembre vuelve porque una amiga se lo recomendó: la
cotización de noviembre guarda «Recomendación», pero Roxana sigue siendo una clienta que **nos
conoció** por Instagram. Los dos datos sirven para preguntas distintas: «¿qué canal trae clientes
nuevos?» y «¿qué canal trae consultas?».

La regla vive en un solo lugar, `CotizacionCreacionService::crear()`:

```php
Client::whereKey($clienteId)->whereNull('origen_contacto_id')->update([...]);
```

El `whereNull` está **dentro** de la instrucción: si el cliente ya tenía origen, la base no lo
toca. No hace falta leerlo antes y decidir en PHP (lo que dejaría una carrera entre leer y
escribir).

### 3.2 Un solo camino para crear una cotización

```
«Nueva cotización» → POST cotizaciones   → CotizacionController::store() ─┐
«Nueva consulta»   → POST crm/consultas  → ConsultaController::store()  ─┤
                                                                          ▼
                                          CotizacionCreacionService::crear($datos, $usuarioId)
                                             código (CodigoGeneradorService, con lock)
                                             cabecera + pasajeros + «Alternativa A» (T-033)
                                             origen del cliente si no tenía
```

Antes la creación era un método privado del controller. Si la consulta la hubiera copiado, el día
que cambie cómo nace una cotización habría que acordarse de cambiarlo en dos lugares. Por eso la
tarea pedía sacarla a un servicio **sin cambiar el comportamiento** de `store()`: sus tests de
T-033 (`AlternativaAutomaticaTest`) siguen verdes sin tocarlos.

### 3.3 «Nueva consulta»: todo o nada

```
NuevaConsultaModal.vue
   escribe teléfono o nombre → (300 ms) GET crm/consultas/coincidencias
        «¿Es ROXANA … · 987654321?» → la elige → cliente_id
   [Guardar] → POST crm/consultas
        │  ruta: permission:cotizaciones.crear
        ▼
   ConsultaController::store()
        sin cliente_id y sin register_client → 403
        DB::transaction:
           1. sin cliente_id → Client::create(SND, '00000000', nombre, teléfono, origen)
           2. CotizacionCreacionService::crear(N adultos de 30, nacionales)
           3. nota → CrmActividad tipo 'nota' (T-050)
           4. casilla → CrmAccion 'enviar_cotizacion' para HoraPeru::hoyTexto() (T-051)
        AlternativaCreacionException (falta tipo de cambio) → 422 y NADA queda guardado
   → abre el editor de la cotización
```

**¿Por qué todo en una transacción?** El paso que más falla es el 2: si no hay tipo de cambio del
día, `AlternativaCreacionService` lanza la excepción y la pantalla lo pide. Sin transacción, el
cliente del paso 1 ya estaría creado: al reintentar se crearía **otro** cliente igual. Con la
transacción, el 422 deshace también el cliente, y el reintento empieza limpio.

El servicio abre su propia transacción por dentro. Dentro de otra, Laravel la convierte en un
*savepoint*: la excepción igual sube y la transacción de afuera deshace todo.

### 3.4 Coincidencias: comparar dígitos, no texto

```
lo escrito:  «987 654 321»  → dígitos «987654321» → últimos 9
guardado:    «+51 987654321» → regexp_replace(phone, '\D', '', 'g') = «51987654321»
             LIKE '%987654321%'  → ✔ coincide
```

`GET clients?search=` no servía: compara el texto tal cual, y «987 654 321» no encuentra
«987654321». Por eso la búsqueda es propia: en Postgres se quitan todos los no-dígitos del teléfono
guardado y se busca lo escrito **contenido** en él. Desde 6 dígitos, para que proponga mientras se
escribe. Como lo que entra es solo dígitos, no hay forma de colar un `%` ni nada raro en el `LIKE`.

En el frontend, una respuesta lenta no pisa a una más nueva: cada búsqueda lleva un número
(`busqueda`) y solo se aplica la respuesta de la última.

### 3.5 El catálogo y su índice único

Como el de motivos de T-052: se agrega, renombra, ordena y desactiva, **sin DELETE** (lo
referencian clientes y cotizaciones con FK). Además del control en PHP (`nombreRepetido()`), la
base tiene un índice único sobre `LOWER(nombre)`.

**¿Para qué los dos?** El control en PHP da un buen mensaje, pero dos personas que guardan
«Feria» al mismo tiempo pasan las dos el control antes de que ninguna haya guardado. El índice es
la red: la segunda choca con él (error 23505 de Postgres). En la revisión se agregó
`nombreDuplicado()`, que convierte ese choque en el mismo 422 «Ya existe un origen con ese nombre»
en vez de un 500.

`index()` devuelve también `ultimo_del_usuario`: el origen de la cotización más reciente de ese
usuario, **si sigue activo**. «Nueva cotización» y «Nueva consulta» lo proponen por defecto: quien
atiende el Facebook de la agencia casi siempre registra consultas de Facebook.

### 3.6 Quién lee el catálogo

La lectura la necesitan cuatro pantallas con permisos distintos: Configuración
(`agencia.configuracion`), crear cotización o consulta (`cotizaciones.crear`), el filtro del
listado (`cotizaciones.ver`/`ver_todas`) y la ficha del cliente (`register_client`/`edit_client`).
La ruta `GET crm-origenes-contacto` acepta cualquiera de ellos. La escritura, solo
`agencia.configuracion`. (Las dos últimas se sumaron en la revisión: sin ellas, el filtro quedaba
vacío para quien solo ve cotizaciones.)

## 4. Tests

En `tests/Feature/AgenciaViajes/ConsultaOrigenContactoTest.php` (15) y
`helpers/clientes/coincidencias.test.ts` (3, Vitest).

| Test | Criterio |
|---|---|
| `test_consulta_crea_cliente_snd_cotizacion_con_alternativa_nota_y_accion_para_hoy` | CA1 |
| `test_consulta_sin_nota_ni_casilla_no_registra_actividad_ni_accion` | CA1 |
| `test_consulta_valida_nombre_destino_telefono_y_pasajeros` | validación |
| `test_coincidencias_comparan_solo_digitos_del_telefono_y_nombre_desde_tres_letras` | CA2 |
| `test_consulta_con_cliente_elegido_no_crea_otro_y_usa_ese` | CA2 |
| `test_el_cliente_conserva_el_origen_del_primer_contacto_y_cada_cotizacion_guarda_el_suyo` | CA3 |
| `test_la_ficha_corrige_el_origen_aun_desactivado_si_existe` | CA3 |
| `test_desactivar_un_origen_en_uso_deja_de_ofrecerse_y_no_se_acepta_pero_los_datos_quedan` | CA4 |
| `test_catalogo_agrega_al_final_sin_repetir_nombre_y_renombra` | CA4 |
| `test_alta_simultanea_del_mismo_origen_responde_422_y_no_500` | concurrencia (revisión) |
| `test_index_devuelve_el_ultimo_origen_activo_que_uso_el_usuario` | origen por defecto |
| `test_sin_register_client_solo_con_cliente_elegido` | CA5, permisos |
| `test_sin_tipo_de_cambio_no_queda_cliente_y_reenviando_con_valor_se_crea_todo` | CA5, transacción |
| `test_rutas_con_sus_permisos_y_sin_delete` | permisos |
| `test_listado_trae_el_origen_y_filtra_por_el` | CA6 |

**El test de la carrera** no lanza dos peticiones a la vez (eso no se puede en un test). La
simula: con `CrmOrigenContacto::creating(...)` inserta «feria» justo antes de que se guarde
«Feria». El control en PHP ya pasó, así que el choque es contra el índice: exactamente lo que
pasaría con dos personas.

El recorrido en pantalla se probó con Playwright en agencia-demo.

## 5. Para desplegar

Dos migraciones en core (se aplican a **todos** los tenants, también retail y Créditos) y una en
Agencia: `php artisan tenants:migrate-verticales` y revisar su salida. Core corre antes que los
verticales, así que la columna de `cotizaciones` encuentra su catálogo.

## 6. Ejercicios

1. Cambia mentalmente el `whereNull` de §3.1 por: leer el cliente, `if ($cliente->origen_contacto_id
   === null)`, y guardar. ¿Qué pasa si dos consultas del mismo cliente nuevo llegan a la vez?
2. Quita la transacción de `ConsultaController::store()`. Registra una consulta en un día sin tipo
   de cambio, escribe el valor que pide la pantalla y guarda otra vez. ¿Cuántos clientes quedan?
3. ¿Por qué el catálogo de orígenes está en **core** y el de motivos de T-052 en el vertical de
   Agencia? Busca la respuesta 5 de la obs. 16.
4. Busca un teléfono guardado como «(01) 555-1234». ¿Qué dígitos compara la búsqueda? ¿Y si
   escribes solo «5551234»?
5. En `CrmOrigenContactoController::nombreDuplicado()`, ¿por qué se revisa el **nombre del
   índice** además del código 23505? Piensa qué otro choque podría dar el mismo código.
