# Recorrido: la ficha del cliente en Agencia (preferencias, resumen e historial)

16-nov-2026, tarea **T-060** (CRM C5). Origen: obs. 16 de
`docs/requerimientos/observaciones-pruebas-usuario.md` (fase C5, respuestas 3 y 4 del 27-oct-2026).
Archivos:
- `api-sistema-fe/app/Services/Crm/FichaClienteAgencia.php` (nuevo: `resumen()`, `atendidoPorUltimaVez()`, `lineaTiempo()`)
- `api-sistema-fe/app/Http/Controllers/Client/ClienteLineaTiempoController.php` (nuevo)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ClientePerfilViajeroController.php` (nuevo)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CrmTipoViajeController.php` (nuevo)
- `api-sistema-fe/app/Models/AgenciaViajes/ClientePerfilViajero.php`, `CrmTipoViaje.php` (nuevos)
- Migraciones `database/migrations/tenant/verticals/agencia-viajes/2026_11_16_100000_create_crm_tipos_viaje_table.php`
  y `2026_11_16_100100_create_cliente_perfil_viajero_table.php`
- `admin-start-kit/src/views/clients/ficha.vue`
- `admin-start-kit/src/components/Clientes/ResumenClienteAgencia.vue`, `PreferenciasViajero.vue`, `LineaTiempoCliente.vue` (nuevos)
- `admin-start-kit/src/components/AgenciaViajes/PreferenciasViajeroResumen.vue` (recuadro del cotizador) y
  `TiposViajeSeccion.vue` (Configuración), nuevos
- `admin-start-kit/src/services/admin/fichaClienteAgenciaService.ts` (nuevo)
- Tests: `tests/Feature/AgenciaViajes/CrmFichaClienteTest.php`

**Al desplegar:** 2 migraciones nuevas del vertical Agencia → `php artisan tenants:migrate-verticales`
y revisar su salida. Sin cambios de menú ni de permisos.

---

## 1. La idea

Cuando un cliente vuelve, lo que hay que saber de él estaba repartido entre cotizaciones, reservas y
comprobantes, y sus gustos no se guardaban en ningún lado. Ahora la **ficha del cliente** (solo en giro
Agencia) reúne todo:

| Bloque | Qué muestra | De dónde sale |
|---|---|---|
| Resumen (arriba) | cotizaciones (ganadas / perdidas), viajes realizados, total comprado por moneda, último viaje | calculado en `FichaClienteAgencia::resumen()` |
| «Atendido por última vez por» | el usuario de la actividad o cotización más reciente | calculado, no se guarda |
| Preferencias del viajero | tipos de viaje, presupuesto por persona, alimentación, movilidad, hotel, notas | tabla `cliente_perfil_viajero` |
| Historial del cliente | cotizaciones, reservas, comprobantes y notas, pagos, contactos y acciones, lo más reciente arriba, 20 por vez, con filtro | `FichaClienteAgencia::lineaTiempo()` |

En retail la ficha queda como estaba: las secciones dependen de `esAgencia` en `ficha.vue` y el backend
responde 404 en otro giro.

## 2. El recorrido

### 2.1 Preferencias del viajero

```
ficha.vue
  └─ PreferenciasViajero.vue
        GET  clients/{id}/perfil-viajero   (+ GET crm-tipos-viaje, en paralelo con Promise.all)
        PUT  clients/{id}/perfil-viajero   (permiso edit_client)
              → ClientePerfilViajeroController::update()
                   valida (presupuesto ≥ 0, textos de 150, notas de 2000, tipos que existan)
                   updateOrCreate(['cliente_id' => …])   → cliente_perfil_viajero
```

- **Una fila por cliente** (`cliente_id` único). El PUT reemplaza el perfil completo.
- `tipos_viaje` es un jsonb con **ids** de `crm_tipos_viaje`. Sin FK a propósito: un tipo desactivado se
  sigue mostrando en los clientes que ya lo tienen (en la ficha sale con «(desactivado)»).
- Si se borra el presupuesto, se borra también la moneda.
- El componente está **dentro del `<form>`** de la ficha: `evitarEnvio` frena el Enter para que no se
  dispare «Guardar cambios» del cliente. Tiene su propio botón «Guardar preferencias», que se activa
  solo si hubo cambios (`hayCambios` compara contra una instantánea).

**El catálogo de tipos de viaje** se administra en Configuración de la Agencia (sección 16,
`TiposViajeSeccion.vue`, permiso `agencia.configuracion`). Mismo patrón que motivos (T-052) y orígenes
(T-059): se agrega, renombra, ordena y desactiva, **nunca se borra**. Las 7 filas base (Naturaleza,
Aventura, Cultural, Gastronomía, Familia, Luna de miel, Relax) las inserta la misma migración, así llegan
también a los tenants que ya existen. El nombre es único sin distinguir mayúsculas (índice sobre
`LOWER(nombre)`).

**En el cotizador** (`cotizador/editar.vue`), `PreferenciasViajeroResumen.vue` pide el mismo
`GET perfil-viajero` y lo muestra en solo lectura, abierto al entrar y plegable. Sale en **toda**
cotización del cliente con perfil (no solo en las nuevas). Si el perfil está vacío (`vacio: true`) o el
usuario no tiene permiso, no se muestra.

### 2.2 Resumen y «Atendido por»

`GET clients/{id}/resumen-agencia` → `ClienteLineaTiempoController::resumen()`:

- **Ganadas** = cotizaciones con `estadoResumen() === 'reservada'`; **perdidas** = `'perdida'`. Las
  vencidas y descartadas no cuentan en ninguna de las dos.
- **Viaje realizado** = reserva `activa` con `fecha_viaje_hasta` anterior a hoy en Perú (no existe un
  estado «realizada»). **Último viaje** = la reserva activa con el `fecha_viaje_desde` más reciente que ya
  empezó.
- **Total comprado**, por moneda = comprobantes de sus reservas (factura, boleta, NV **y adelanto**)
  − notas de crédito + notas de débito (las NC rechazadas no restan). El adelanto se suma porque la
  factura final se guarda **neta** de los adelantos aplicados; la devolución de un adelanto es una NC sobre
  su comprobante y se resta sola.
- **Atendido por** mira la actividad más reciente (`crm_actividades`) y la cotización más reciente
  (`vendedor_id`) y se queda con la más nueva. Se calcula sobre **todo** el cliente, sin filtrar
  visibilidad, porque solo muestra un nombre.

### 2.3 Qué comprobantes son «de una reserva»

`FichaClienteAgencia::ventasDeReservas()` une tres caminos (en una sola consulta con `union`):

```
reserva_ventas                                 (el enlace normal)
sale_details → sale_detail_items → reserva_items (sobrevive a la NC total, que borra la fila de reserva_ventas)
advances → reserva_anticipos                   (los comprobantes de adelanto)
```

y se queda solo con los que tienen `sales.client_id` = este cliente. Con «Facturación múltiple por grupo»,
la sub-factura emitida a otra empresa **no** aparece en la ficha del que cotizó: no es una compra suya
(aceptado en la revisión; mostrarla en la ficha de esa empresa es T-104).

Las ventas directas sí traen su reserva, comprobantes y pagos, pero su cotización interna
(`origen = 'venta_directa'`) no aparece ni cuenta (`delCotizador()`).

### 2.4 La línea de tiempo

`GET clients/{id}/linea-tiempo?tipo=…&pagina=N` → `FichaClienteAgencia::lineaTiempo()`.

**Visibilidad** (la misma que las Policy):
- Cotizaciones y reservas con el scope `propias()`: un vendedor sin «ver todas» ve solo las suyas.
- Comprobantes y pagos, solo de reservas visibles.
- Actividades y acciones con referencia, solo si se ve esa cotización o reserva; sin referencia, todas
  (`filtrarReferencias()`).

**Paginación sin una consulta por elemento (CA4).** Los eventos salen de seis tablas distintas, así que no
se puede paginar con un `LIMIT/OFFSET` único. El truco:

```
página N → limite = offset + 20 + 1
por cada tipo: traer sus primeros `limite` por fecha (una consulta, con with() para los usuarios)
mezclar todo, ordenar por (instante, id) descendente
devolver eventos[offset .. offset+20]  y  hay_mas = quedan más
```

Funciona porque los primeros N de la mezcla siempre están entre los primeros N de cada tipo. El costo es
una cantidad fija de consultas por página (el test mide 19 con 2 o con 8 vueltas del cliente). En vez de
un total se devuelve `hay_mas`, para no sumar un `COUNT` por tipo; por eso la pantalla tiene «Ver más» y no
números de página.

**Acciones:** las hechas se ordenan por `hecha_en`; las pendientes por su día, a las 00:00 de Perú
(`fecha + 5 horas` en SQL, `HoraPeru::inicioDiaUtc()` en PHP), así lo que viene queda arriba. En pantalla
una pendiente dice «para el …» (sin hora).

**El id de cada evento** lleva prefijo (`cotizacion-5`, `nota-3`) porque los ids se repiten entre tablas y
la lista de Vue necesita una `key` única.

**En pantalla** (`LineaTiempoCliente.vue`): el filtro por tipo vuelve a la página 1; «la última petición
gana» (`peticion`) para que cambiar el filtro mientras carga «Ver más» no mezcle listas. «Anotar contacto»
(componente de T-050) vuelve a la ficha acá; al anotar se recargan el historial y el resumen. Las notas se
muestran con `{{ }}`, nunca `v-html`.

## 3. Cómo se probó

- `CrmFichaClienteTest` (10 tests, reloj a las 21:00 de Lima, cuando en UTC ya es mañana): preferencias
  guardadas y leídas como las lee el cotizador (CA1); validación; 2 cotizaciones + 1 reserva facturada + 3
  actividades en orden (CA2); acciones pendientes y hechas; «Atendido por» (CA3); conteo de consultas y 56
  eventos en 3 páginas sin repetidos (CA4); vendedor sin «ver todas»; resumen con venta directa y adelanto;
  factura anulada con NC total que sigue en el historial; 404 fuera de Agencia (CA5).
- En la revisión: `php artisan test --filter="Cliente|Crm"` 150/150; `type-check` sin errores nuevos (47
  previos, T-101).
- En pantalla (Playwright): agencia-demo cliente 1 (resumen, historial, preferencias con una nota que trae
  `<script>`, «Anotar contacto»), recuadro en la cotización 95 y tenant retail `umbo` sin las secciones.

## 4. Si algo falla

| Síntoma | Dónde mirar |
|---|---|
| Falta un comprobante en el historial | `ventasDeReservas()`: ¿está en alguno de los tres caminos? ¿`sales.client_id` es este cliente? |
| El total comprado sale de menos | ¿falta el comprobante del adelanto? La factura final está neta de adelantos |
| Un vendedor ve algo que no debería | `propias()` de `Cotizacion`/`Reserva` y `filtrarReferencias()` |
| La paginación repite o salta eventos | el orden es (instante, id); cada tipo debe traer `offset + 21` |
| La ficha da 404 en Agencia | `GiroActual::es('agencia_viajes')` en el controller |
| Enter en una preferencia guarda el cliente | `evitarEnvio` en `PreferenciasViajero.vue` |
| El recuadro no sale en el cotizador | perfil vacío (`vacio`) o el usuario sin permiso para `GET perfil-viajero` |

## 5. Ejercicios

1. Abre `FichaClienteAgencia::lineaTiempo()` y explica con tus palabras por qué alcanza con traer
   `offset + 21` de cada tipo para armar bien la página N.
2. En `ventasDeReservas()`, ¿qué comprobante se perdería si quitaras el segundo `union` (el de
   `sale_detail_items`)? Pista: mira el test `la_factura_anulada_con_nc_total_sigue_en_el_historial_por_sus_lineas`.
3. Desactiva un tipo de viaje en Configuración y abre la ficha de un cliente que lo tenga: ¿cómo se ve? ¿Y
   en la de uno que no lo tenga? Busca la línea de `PreferenciasViajero.vue` que decide eso.
