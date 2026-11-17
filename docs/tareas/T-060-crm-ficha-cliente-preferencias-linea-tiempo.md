# T-060 — CRM C5: la ficha del cliente con preferencias del viajero y línea de tiempo

| | |
|---|---|
| **Módulo** | CLI + AGE |
| **Depende de** | T-050, T-051 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 16, fase C5 y respuestas 3 y 4 (27-oct-2026: preferencias propuestas, se ajustan con el uso; "atendido por última vez por" en vez de responsable fijo) |
| **Rama** | `feat/T-060-crm-ficha-cliente` |

## Contexto
Para atender bien a un cliente que vuelve hay que saber qué le gusta y qué pasó antes. Hoy eso
está repartido entre cotizaciones, reservas y ventas, y las preferencias no se guardan.

## Alcance
- Tabla `cliente_perfil_viajero` (vertical agencia, una fila por cliente): `tipos_viaje` (json,
  etiquetas de un catálogo editable: Naturaleza, Aventura, Cultural, Gastronomía, Familia,
  Luna de miel, Relax…), `presupuesto_por_persona` + `moneda`, `alimentacion`, `movilidad`,
  `hotel_preferido` (texto), `notas`.
- Ficha del cliente (`views/clients/ficha.vue`), solo en giro agencia:
  - Sección **"Preferencias del viajero"** editable.
  - **"Atendido por última vez por"**: el usuario de la actividad o cotización más reciente
    (calculado).
  - **Línea de tiempo**: consultas y cotizaciones (con estado y resultado), reservas, comprobantes
    y pagos, actividades y acciones (hechas y pendientes), en orden de fecha, con enlaces. Paginada
    (20 por vez). Filtro por tipo.
  - **"Anotar contacto"** junto a la línea de tiempo: el componente `Clientes/AnotarContacto.vue` y
    `POST clients/{id}/actividades` sin referencia (permiso `edit_client`) ya existen desde T-050;
    se sacó de la ficha hasta tener dónde ver lo anotado.
  - Resumen arriba: cotizaciones (ganadas / perdidas), viajes realizados, total comprado, último
    viaje.
- Al crear una cotización para un cliente con perfil, el editor muestra sus preferencias en un
  recuadro plegable (solo lectura).

## Fuera de alcance
- Usar las preferencias para sugerir tours (más adelante).
- Retail (la ficha mantiene sus secciones actuales).

## Archivos probables
- migraciones nuevas (vertical agencia) + seeder del catálogo de tipos de viaje
- `api-sistema-fe/app/Http/Controllers/Client/` (endpoint de línea de tiempo) y controller del perfil
- `admin-start-kit/src/views/clients/ficha.vue`, `views/agencia-viajes/cotizador/editar.vue`

## Criterios de aceptación
- [ ] **CA1** — Guardar preferencias → se ven en la ficha y en el editor de una cotización nueva. → test + prueba funcional
- [ ] **CA2** — Línea de tiempo de un cliente con 2 cotizaciones, 1 reserva facturada y 3 actividades → todo en orden de fecha. → test
- [ ] **CA3** — "Atendido por última vez por" = usuario de lo más reciente. → test
- [ ] **CA4** — Línea de tiempo paginada sin consultas por elemento. → test (contar consultas)
- [ ] **CA5** — Cliente de un tenant retail → la ficha no muestra estas secciones. → prueba funcional
- [ ] **CA6** — Suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: ver clientes; editar preferencias con editar clientes; la línea de tiempo respeta qué cotizaciones/reservas puede ver el usuario
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: textos con largo máximo; presupuesto ≥ 0
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: presupuesto decimal(12,2)
- [ ] Fechas con `HoraPeru`: instantes con `formatFechaHora()`
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: notas como texto
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí

## Cómo verificar
```
php artisan test --filter="Cliente|Crm"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:** `727d062` backend (migraciones, modelos, `FichaClienteAgencia`, controllers, rutas,
  tests) · `6fab3c4` frontend (ficha, componentes, servicio, sección de Configuración, recuadro
  del cotizador) · este commit de docs.
- **Decisiones tomadas con el usuario antes de codificar (16-nov-2026):**
  - Tipos de viaje: tabla `crm_tipos_viaje` (vertical Agencia), sección «Tipos de viaje» en
    Configuración de la Agencia con `agencia.configuracion`, igual que motivos y orígenes.
  - Resumen: ganadas = `estadoResumen() === 'reservada'` (las anuladas no cuentan); perdidas =
    `'perdida'` (las vencidas y descartadas no); viaje realizado = reserva `activa` con
    `fecha_viaje_hasta` < hoy Perú (no existe un estado «realizada»); último viaje = reserva
    activa con el `fecha_viaje_desde` más reciente ≤ hoy.
  - Total comprado = comprobantes de sus reservas (factura, boleta, NV **y adelanto**) − NC + ND,
    por moneda. Se corrigió la primera propuesta («sin adelantos»): la factura final se guarda
    **neta** de los adelantos aplicados, así que sin ellos el total salía de menos; la
    devolución de un adelanto es una NC sobre su comprobante y se resta sola.
  - Comprobantes de una reserva: `reserva_ventas` + líneas (`sale_detail_items` →
    `reserva_items`, que sobrevive a la NC total, que borra la fila de `reserva_ventas`) +
    `reserva_anticipos`.
  - Ventas directas: su reserva, comprobantes y pagos sí; su cotización interna no aparece ni
    cuenta.
  - Visibilidad: cotizaciones y reservas con `propias()` (= `CotizacionPolicy`/`ReservaPolicy::view`);
    comprobantes y pagos solo de reservas visibles; actividades y acciones con referencia solo si
    se ve la referencia, sin referencia todas; el resumen con la misma visibilidad; «Atendido por»
    sobre todo el cliente (solo un nombre).
- **Tests:** 10 nuevos en `tests/Feature/AgenciaViajes/CrmFichaClienteTest.php` (69 aserciones),
  reloj a las 21:00 de Lima:
  | Test | Criterio |
  |---|---|
  | `test_guardar_preferencias_y_leerlas_como_las_lee_el_cotizador` | CA1 |
  | `test_preferencias_validan_presupuesto_largos_y_tipos` | CA1, validación |
  | `test_linea_de_tiempo_con_dos_cotizaciones_una_reserva_facturada_y_tres_actividades_en_orden` | CA2, filtro |
  | `test_acciones_pendientes_por_su_dia_y_hechas_por_cuando_se_hicieron` | CA2 (acciones) |
  | `test_atendido_por_ultima_vez_es_el_usuario_de_lo_mas_reciente` | CA3 |
  | `test_linea_de_tiempo_paginada_con_una_cantidad_fija_de_consultas` | CA4 (19 consultas con 2 o con 8 vueltas; 56 eventos en 3 páginas sin repetidos) |
  | `test_un_vendedor_sin_ver_todas_solo_ve_lo_suyo_y_lo_sin_referencia` | Policy |
  | `test_resumen_ganadas_perdidas_viajes_ultimo_viaje_y_total_comprado` | resumen, venta directa, adelanto |
  | `test_la_factura_anulada_con_nc_total_sigue_en_el_historial_por_sus_lineas` | NC total |
  | `test_fuera_de_agencia_responde_404` | CA5 (backend) |

  Suite completa (16-nov, Postgres): 2222 passed, 4 failed. Los 4 no son de esta tarea: los 3 de
  fecha vencida que arregla T-103 (todavía no está en `main`: `Fila13Fase2aSalidasTest` ×2,
  `GananciaSalidaTest::test_tarifa_de_temporada`) y el intermitente conocido
  `Fila13Fase1cFacturacionTest::descuento_del_combo…`, que pasa solo. `npm run type-check`: 47 errores, los mismos de T-101, ninguno
  en archivos de esta tarea. `npm run build`: OK.
- **Prueba en pantalla (Playwright):**
  - agencia-demo, cliente 1: el resumen (5 cotizaciones, 2 ganadas, 2 viajes, S/ 1,570.00,
    último viaje) y el historial con cotizaciones, reservas (una cancelada), boleta sin enviar,
    pago, el traspaso de T-061 y la acción pendiente. Se guardaron las preferencias (Naturaleza,
    Gastronomía, S/ 1,200, hotel, alimentación y una nota con `<script>`): Enter en un campo
    no envía el formulario del cliente. «Anotar contacto» deja la llamada arriba del historial
    con hora de Perú y actualiza «Atendido por».
  - Cotización nueva para ese cliente (quedó la **95** en agencia-demo): el recuadro sale
    abierto en la cabecera con todas las preferencias, la nota como texto, y se pliega a
    «· Naturaleza, Gastronomía» (CA1).
  - Tenant retail `umbo`, cliente 18: la ficha solo muestra «Datos del cliente», sin errores en
    consola (CA5).
  - Quedan en agencia-demo, del cliente 1: el perfil del viajero, la actividad «Llamó para
    preguntar por Iquitos (prueba T-060)» y la cotización 95.
- **Al desplegar:** 2 migraciones nuevas del vertical Agencia → `tenants:migrate-verticales` y
  revisar su salida. Sin cambios de menú ni de permisos.
- **Desviaciones de la tarea y por qué:**
  - «Seeder del catálogo de tipos de viaje» → las filas base van en la migración, como en
    `crm_motivos_resultado` (T-052) y `crm_origenes_contacto` (T-059): así quedan también en los
    tenants que ya existen.
  - Solo cuentan los comprobantes emitidos **a este cliente**: con «Facturación múltiple por
    grupo», la sub-factura de una reserva emitida a otra empresa no aparece en la ficha del que
    cotizó (no es una compra suya). Decisión mía, no consultada: revisar en la revisión.
  - El recuadro del cotizador sale en **toda** cotización del cliente con perfil (no solo en
    una nueva) y abierto al entrar; no hay forma barata de saber que es «nueva», y en una ya
    armada también sirve.
  - Paginación con «Ver más» (agrega 20), sin números de página. El backend devuelve `hay_mas`
    en vez de un total, para no sumar una consulta de conteo por tipo.
  - Dos endpoints (`resumen-agencia` y `linea-tiempo`) en vez de uno: el resumen no se vuelve a
    pedir al paginar ni al filtrar.

## Hallazgos fuera de alcance
- AGE: `GET crm-tipos-viaje` en un tenant sin Agencia da 500 (no existe la tabla), igual que
  `crm-motivos-resultado`; ninguna pantalla de retail los pide.
- AGE: las etiquetas del estado de una cotización están copiadas en `LineaTiempoCliente.vue` y
  `badgeEstado()` de `cotizador/index.vue`; convendría un helper compartido.
- CLI: con «Facturación múltiple por grupo» la sub-factura a otra empresa no aparece en la ficha
  de ningún cliente: esa empresa no tiene reservas propias (ver la desviación de arriba).
- TRA: `CrmAccionTest` crea reservas con `estado = 'confirmada'`, que no existe (solo
  `activa | cancelada`); no rompe nada, pero confunde al leer el test.
- TRA: T-103 no está en `main`: los 3 tests de fecha vencida (`Fila13Fase2aSalidasTest` ×2,
  `GananciaSalidaTest::test_tarifa_de_temporada`) fallan en esta rama por eso.
