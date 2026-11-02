# Recorrido: próximas acciones, «Mis pendientes de hoy» y Seguimiento (CRM C2)

02-nov-2026, tarea **T-051** (unida a main en `0d5cdf5`). Segunda fase del CRM, encima del
historial de contactos de T-050 (recorrido 20). Lo que T-050 anota es lo que **ya pasó**; lo de
esta fase es lo que **toca hacer**: llamar al cliente el jueves, cobrarle el saldo, saludarlo.

---

## 1. El problema

1. **Después de enviar no había forma de agendar el siguiente paso.** El vendedor se acordaba de
   llamar… o no.
2. **Nadie veía qué tocaba hacer hoy.** No había una lista de pendientes por vendedor ni para el
   supervisor.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `database/migrations/tenant/core/2026_11_02_200000_create_crm_acciones_table.php` | **nuevo**: tabla `crm_acciones` |
| `database/migrations/tenant/verticals/agencia-viajes/2026_11_02_200000_add_dias_proximo_contacto_to_configuracion_agencia.php` | **nuevo**: `dias_proximo_contacto` (2 por defecto) |
| `app/Models/Client/CrmAccion.php` | **nuevo**: modelo, tipos, `scopePendientes()`, `scopeDeReferencia()` |
| `app/Services/Crm/AccionesCrm.php` | **nuevo**: las reglas compartidas (validar, proponer al enviar, cerrar, marcar hecha, quién puede) |
| `app/Services/Crm/BandejaPendientes.php` | **nuevo**: la bandeja única (Inicio y Seguimiento) |
| `app/Http/Controllers/Client/CrmAccionController.php` | **nuevo**: opciones, listar, agendar, reprogramar, marcar hecha |
| `app/Http/Controllers/Client/CrmPendientesController.php` | **nuevo**: `GET crm/pendientes` (pantalla Seguimiento) |
| `CotizacionActividadController.php` / `CotizacionEnvioController.php` | aceptan `proxima_accion` al enviar |
| `ReservaController::aceptar()` / `VentaDirectaController::store()` | cierran las pendientes al aceptar |
| `app/Services/Dashboard/DashboardService.php` | bloque `pendientes` de Inicio |
| `database/seeders/MenuItemsSeeder.php` | «Seguimiento» en VENTAS |
| `admin-start-kit/src/services/admin/crmAccionService.ts` | **nuevo**: llamadas a la API y tipos de la bandeja |
| `components/Clientes/ProximaAccionPropuesta.vue` | **nuevo**: la línea «Después: llamar el jue 05/11 (tú)» |
| `components/Clientes/AccionesPendientes.vue` + `AccionCrmModal.vue` | **nuevos**: tarjeta de pendientes y modal agendar / hecha / reprogramar |
| `components/Clientes/MisPendientesHoy.vue` + `ListaPendientes.vue` | **nuevos**: bloque de Inicio y filas de la bandeja |
| `views/agencia-viajes/seguimiento/index.vue` | **nueva**: pantalla Seguimiento |

## 3. El recorrido

### 3.1 La tabla: una acción = una fila, pendiente mientras `hecha_en` es null

```
crm_acciones
  cliente_id       FK clients          ← igual que crm_actividades: vive POR CLIENTE
  referencia_tipo  'cotizacion' | 'reserva' | null
  referencia_id    (SIN FK: tablas del vertical)
  tipo             llamar | whatsapp | enviar_cotizacion | cobrar_saldo | saludar | otro
  descripcion      texto libre, opcional
  fecha            DATE: día de negocio en hora Perú
  responsable_id   FK users            ← quién la tiene que hacer
  hecha_en         TIMESTAMP UTC; null = pendiente
  hecha_por, creada_por, timestamps
```

**`fecha` es DATE y `hecha_en` es TIMESTAMP.** «Llamar el jueves» es un día de calendario, no un
instante: se guarda como fecha y se compara con `HoraPeru::hoyTexto()`. «Cuándo se marcó hecha»
sí es un instante: va en UTC, como todo instante del sistema (regla de fechas de `CLAUDE.md`).

### 3.2 Al enviar se propone «Llamar en N días»

```
Cotizador → «Enviar al cliente» (EnviarAlternativa.vue)
   al abrir el panel → armarPropuesta()
        ├─ ¿la cotización ya tiene una pendiente? → no propone (evita llamadas duplicadas)
        └─ GET crm/acciones/opciones → { usuario_id, dias_proximo_contacto, tipos, responsables }
              propuesta = { fecha: hoyPeru() + N días, responsable_id: usuario_id }
   ProximaAccionPropuesta.vue muestra «Después: llamar el mié 04/11 (tú)»  [Cambiar] [Quitar]

   Por WhatsApp / otro medio → POST alternativas/{id}/marcar-enviada { canal, nota, proxima_accion }
   Por correo               → POST alternativas/{id}/enviar-correo  { …, proxima_accion }
                                     │
                                     └─► AccionesCrm::crearAlEnviar($alternativa, $propuesta)
                                            null → no crea nada (el vendedor la quitó)
```

**Validación de la propuesta:** `AccionesCrm::reglas('proxima_accion.', false)`: la fecha no
puede ser anterior a hoy en Perú y el responsable tiene que ser un usuario activo. El mismo
método arma las reglas de «Agendar» y de «la siguiente» al marcar hecha: un solo lugar.

**El caché de opciones.** `crmAccionService.opciones()` guarda la respuesta en memoria (cambia
poco). Como trae el `usuario_id`, se olvida al cerrar sesión (`auth.removeSession()`) y al guardar
la Configuración de agencia. Sin eso, quien entraba después en la misma pestaña proponía las
llamadas a nombre del anterior: el logout no recarga la página (bug encontrado en la revisión).

### 3.3 Agendar, «Hecha» y «Reprogramar»

```
AccionesPendientes.vue (cotización, reserva o ficha)
   ├─ Agendar acción → POST clients/{id}/acciones          → CrmAccionController::store()
   ├─ Hecha          → POST crm-acciones/{id}/hecha        → AccionesCrm::marcarHecha()
   │                     { resultado?: {tipo, nota}, siguiente?: {tipo, fecha, responsable_id} }
   └─ Reprogramar    → PUT  crm-acciones/{id} {fecha, responsable_id?}
```

**«Hecha» es todo o nada.** `marcarHecha()` corre en una transacción y bloquea la fila
(`lockForUpdate`): dos clics a la vez no la cierran dos veces (el segundo recibe 422). El
resultado se guarda como actividad de T-050 (`crm_actividades`) con la misma referencia; la
siguiente, como una acción nueva.

**Quién puede tocar una acción** (`AccionesCrm::puedeGestionar()`): su responsable, siempre; o
quien puede editar la referencia (`CotizacionPolicy` / `ReservaPolicy`); sin referencia,
`edit_client`. Por eso un supervisor puede cerrar la llamada de un vendedor, y un vendedor puede
cerrar la que le asignaron aunque la cotización sea de otro.

### 3.4 Al aceptar, las pendientes se cierran solas

```
ReservaController::aceptar()   (dentro de la transacción, con la cotización bloqueada)
   alternativa → 'aceptada'
   AlternativaController::descartarOtras()
   AccionesCrm::cerrarPendientesDeCotizacion($cotizacionId, 'Cotización aceptada')
        ├─ sin pendientes → no hace nada (ni actividad)
        └─ con pendientes → hecha_en = now() en todas + UNA actividad 'estado':
                             «Cotización aceptada: se cerró la acción pendiente «Llamar» del 04/11.»
```

T-052 («Cerrar como perdida») llamará al mismo método con otro motivo.

### 3.5 La bandeja única: Inicio y Seguimiento

```
Inicio  → GET dashboard → DashboardService::pendientes() ─┐
Seguimiento → GET crm/pendientes?ambito=&responsable_id=&tipo= ─┤
                                                               ▼
                                    BandejaPendientes::filas($usuario, $hoy, $hasta, $filtros)
                                       cada fila: { fuente: 'crm', bloque, fecha, titulo, cliente,
                                                    referencia, responsable, accion, enlace }
                                       bloque = atrasadas (< hoy) | hoy | proximos (≤ hasta)
```

**¿Por qué `fuente` si hoy todo es `crm`?** Decisión del 27-oct: esta es **la** bandeja de
pendientes que prevé el plan de tours (fila 11f). Cuando 11f sume pendientes automáticos (salida
bajo el mínimo, pago a proveedor por vencer…), llegarán con `fuente: 'operacion'` y un `enlace`
donde se resuelven, sin «Marcar hecha». La pantalla ya muestra pestañas por origen cuando hay más
de una fuente.

**«De todos»** (o ver los de otro responsable) exige `cotizaciones.ver_todas`, y lo revisa el
**backend** (`CrmPendientesController`), no solo la pantalla.

**«Hoy» en Inicio** sale del `$hoy` que `DashboardService::inicio()` ya calcula en hora de Lima
(se puede inyectar en los tests); el endpoint de Seguimiento usa `HoraPeru::hoyTexto()`.

## 4. Tests

En `tests/Feature/AgenciaViajes/CrmAccionTest.php` (19), con el reloj a las **21:00 de Lima**: en
UTC ya es el día siguiente, así que un `now()->toDateString()` haría fallar los tests.

| Test | Criterio |
|---|---|
| `test_whatsapp_con_la_propuesta_crea_la_accion_llamar_a_nombre_de_quien_envia` | CA1 |
| `test_correo_con_la_propuesta_crea_la_accion` | CA1 |
| `test_la_propuesta_acepta_hoy_peru_y_rechaza_ayer_o_un_usuario_inactivo` | CA1, validación |
| `test_enviar_quitando_la_propuesta_no_crea_ninguna_accion` | CA2 |
| `test_aceptar_la_cotizacion_cierra_sus_acciones_pendientes_con_una_actividad` | CA3 |
| `test_aceptar_sin_acciones_pendientes_no_deja_actividad` | CA3 |
| `test_mis_pendientes_de_hoy_en_inicio_solo_las_del_usuario_hasta_hoy_atrasadas_primero` | CA4 |
| `test_seguimiento_trae_las_propias_en_tres_bloques_hasta_siete_dias` | CA4 |
| `test_de_todos_o_de_otro_responsable_solo_con_ver_todas` | CA4, permisos |
| `test_marcar_hecha_con_resultado_crea_la_actividad_y_agenda_la_siguiente` | CA5 |
| `test_marcar_hecha_sin_resultado_ni_siguiente_solo_la_cierra_y_no_dos_veces` | concurrencia |
| `test_el_responsable_la_gestiona_aunque_no_sea_el_vendedor_y_un_tercero_no` | permisos |
| `test_agendar_con_una_cotizacion_ajena_o_de_otro_cliente_no_se_permite` | seguridad |
| `test_agendar_desde_la_reserva_usa_reserva_policy` | permisos |
| `test_reprogramar_no_acepta_una_fecha_pasada_ni_una_accion_hecha` | validación |

El recorrido completo en pantalla se probó con Playwright en agencia-demo.

## 5. Para desplegar

Dos migraciones nuevas (core y Agencia): `php artisan tenants:migrate-verticales` y revisar su
salida. `deploy.sh` corre `MenuItemsSeeder` (ítem «Seguimiento»).

## 6. Ejercicios

1. En `AccionesCrm::marcarHecha()`, quita el `lockForUpdate()` mentalmente: ¿qué pasa si dos
   personas hacen clic en «Hecha» con resultado al mismo tiempo? ¿Cuántas actividades quedan?
2. ¿Por qué `crm_acciones.fecha` es `date` y no `timestamp`? Piensa en un vendedor que agenda
   «llamar mañana» a las 20:00 de Lima.
3. Busca quién llama a `cerrarPendientesDeCotizacion()`. Si mañana se agrega otro camino para
   aceptar una cotización, ¿qué tendrías que recordar?
4. En el navegador, entra con un usuario, abre «Enviar al cliente», cierra sesión y entra con otro
   en la misma pestaña. Vuelve a abrir el panel: ¿a nombre de quién propone la llamada? Mira
   `olvidarOpciones()` y explica por qué.
5. En `BandejaPendientes::deCrm()`, ¿qué campos tendría que llenar una fila de `fuente:
   'operacion'` para que la pantalla la muestre bien sin «Marcar hecha»?
