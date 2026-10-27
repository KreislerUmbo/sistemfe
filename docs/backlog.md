# Backlog

> **Único lugar donde se escribe el estado de una tarea.** Ningún otro documento dice
> "pendiente", "en curso" o "hecho": enlaza a la línea de acá (`ver T-042`).
> Cómo se usa: `docs/proceso.md`.

**Estados:** `por hacer` · `en curso` · `en revisión` · `hecha` · `bloqueada` (decir por qué)

Cada línea: ID · qué · estado · depende de · rama o commit. El detalle está en
`docs/tareas/T-…md` (se borra al cerrar; para una tarea hecha, el commit es la referencia).
Próximo ID libre: **T-069**.

## Orden de construcción (27-oct-2026)

> Criterio: primero poner al día lo ya hecho; después lo que daña comprobantes, datos o seguridad
> en producción (todos los giros); después los errores de Agencia que traban la operación; el
> cobro; "Modificar reserva" (quitar un pasajero está bloqueado hasta entonces); y al final las
> mejoras. Dentro de cada ola, en el orden escrito. Detalle: obs. 19 de
> `docs/requerimientos/observaciones-pruebas-usuario.md`.

| Ola | Tareas | Por qué en este lugar |
|---|---|---|
| 0 | T-067 | Código terminado sin unir ni desplegar: cuanto más se construya encima, más difícil unir |
| 1 | T-064 → T-068 → T-008 → T-038 → T-026 | Todos los giros, producción: PDF de comprobantes que cambia al editar el cliente, precios del portal manipulables, caja que no se anula al eliminar una venta, monto en letras en dólares, fechas que se muestran un día antes |
| 2 | T-054 → T-056 → T-043 → T-042 → T-001 → T-030 → T-025 → T-024 → T-027 → T-028 → T-039 | Errores de Agencia: PDF de campo con el proveedor equivocado, operación trabada tras facturar, salidas que no se ven, cambios a una alternativa aceptada, permisos del cotizador, datos cruzados entre alternativas |
| 3 | T-009 → T-010 → T-011 → T-014 → T-013 → T-012 | Cobro y caja (orden aprobado el 25-oct). T-011 no se despliega con dólares sin T-013 |
| 4 | T-002 → T-003 → T-004 → T-005 → T-006 → T-007 | Modificar reserva: hoy no se puede quitar un pasajero y el agregado no cambia el precio |
| 5 | T-063 → T-033 → T-050 → T-051 → T-052 → T-059 → T-061 → T-053 | Menú nuevo y CRM base (T-033 antes: "Nueva consulta" crea la cotización con su alternativa) |
| 6 | T-041 → T-040 → T-055 → T-057 → T-058 → T-044 → T-017 → T-018 | Operación diaria de Agencia |
| 7 | T-045 → T-046 → T-047 → T-048 → T-049 | Venta directa (T-046 necesita T-017; T-048, el cobro de la ola 3) |
| 8 | T-032 → T-034 → T-019 → T-035 → T-036 → T-031 → T-022 → T-020 → T-021 → T-023 → T-037 → T-015 → T-016 | Cotizador más rápido y claro, PDF de cotización, aerolíneas |
| 9 | T-060 → T-062 → T-065 | Completar CRM y clientes |
| — | T-029 | Bloqueada: espera al contador (obs. 10) |

Después de estas olas (sin tareas todavía): **Compras** (fila 13, fase 3 de
`plan-tours-venta-simple-costo-real.md`, con la tabla única de proveedores) junto con el motor de
pendientes automáticos (11f), tras la pausa de prueba de la fase 2.

## Activas

| ID | Módulo | Qué | Estado | Depende de | Rama / commit |
|---|---|---|---|---|---|
| T-001 | AGE | Bloquear el cambio de precio de un servicio en una alternativa aceptada | por hacer | — | |
| T-002 | AGE | Modificar reserva: crear y descartar la modificación (cotización propia + copia) | por hacer | T-001 | |
| T-003 | AGE | Modificar reserva: calcular la diferencia con lo vendido | por hacer | T-002 | |
| T-004 | AGE | Modificar reserva: penalidad por pasajero quitado | por hacer | T-003 | |
| T-005 | AGE | Modificar reserva: aplicar a la reserva | por hacer | T-003, T-004 | |
| T-006 | AGE | Modificar reserva: PDF para el cliente | por hacer | T-003 | |
| T-007 | AGE | Modificar reserva: pantallas | por hacer | T-005, T-006 | |
| T-008 | VEN + CAJ | Eliminar una venta anula sus movimientos de caja (todos los giros) | por hacer | — | |
| T-009 | CAJ | Caja, paso A: la moneda en cada movimiento | por hacer | — | |
| T-010 | VEN + CAJ | Servicio compartido de cobro, con n.º de operación y moneda de pago (Obs. 1) | por hacer | T-009 | |
| T-011 | AGE + CAJ | Cobro al contado al facturar una reserva (Obs. 1) | por hacer | T-010 | |
| T-012 | AGE + CAJ | Traspaso del cobro tras una nota de crédito | por hacer | T-011 | |
| T-013 | CAJ | Caja, paso B: por moneda + cambio de moneda. **Requisito para desplegar T-011 con dólares** | por hacer | T-009 | |
| T-014 | VEN | Forma de pago y n.º de operación en el PDF (Obs. 2) | por hacer | T-010 | |
| T-015 | PLA + AGE | Catálogo central de aerolíneas + panel central (Obs. 3) | por hacer | — | |
| T-016 | AGE | Buscador de aerolíneas, corrección de nombres y aerolínea por defecto (Obs. 3) | por hacer | T-015 | |
| T-017 | AGE | El cliente como primer pasajero al aceptar (Obs. 4) | por hacer | — | |
| T-018 | AGE | "Buscar pasajero" también en Clientes (Obs. 5) | por hacer | T-017 | |
| T-019 | AGE | Comparar hoteles: empezar la comparación con 1 sola habitación (Obs. 6) | por hacer | — | |
| T-020 | AGE | Fotos de hotel con tipo (fachada / habitación) en la galería del proveedor (Obs. 7) | por hacer | — | |
| T-021 | AGE | PDF de cotización: fotos de todos los hoteles de la alternativa (Obs. 7) | por hacer | T-020 | |
| T-022 | AGE | Cotizador: revisión antes de enviar ("¿lista para el cliente?") (Obs. 8) | por hacer | T-028, T-050 | |
| T-023 | AGE | PDF de cotización con la comparación de hoteles abierta + descuento bien calculado (H4, H10) | por hacer | — | |
| T-024 | AGE | La comparación de hoteles de una alternativa duplicada es independiente de la original (H1) | por hacer | — | |
| T-025 | AGE | Cotizador: cada acción verifica que la cotización sea del vendedor (H2) | por hacer | — | |
| T-026 | TRA | `CampoFecha` muestra el día correcto aunque reciba una fecha con hora y zona (H11) | por hacer | — | |
| T-027 | AGE | Eliminar un tour clásico en una sola operación (total guardado correcto) + subtotal por destino (H12, H14) | por hacer | — | |
| T-028 | AGE | La versión vigente de una tarifa: solo se ofrece y se usa la vigente; plantillas de tours a la versión nueva (H5, H16, H17) | por hacer | — | |
| T-029 | AGE | Tour clásico desde plantilla: cada servicio nace con su tratamiento tributario; costo sin infantes (H18, H19) | bloqueada: espera la decisión de la obs. 10 (tributario deducido por reglas) y al contador | — | |
| T-030 | AGE | Una alternativa aceptada no cambia de estado ni de mayorista por API (H3, H8) | por hacer | — | |
| T-031 | AGE | Servicio de tour sin precio de niño: el niño paga como adulto; hoteles no cambian (H7) | por hacer | — | |
| T-032 | AGE | Cotizador: actualizar solo lo que cambió + indicadores de carga (R5, R4, H13) | por hacer | — | |
| T-033 | AGE | La primera alternativa nace con la cotización; nombre y moneda editables; errores en español (R1, R7, H23, H24) | por hacer | — | |
| T-034 | AGE | Hotel: noches calculadas y ocupantes marcados por defecto (R2) | por hacer | — | |
| T-035 | AGE | Biblioteca filtrada por el destino activo; sin tours "solo itinerario" en Local (R3) | por hacer | — | |
| T-036 | AGE | Mayorista: etiquetas, primera opción elegida sola, "solo adultos" a la vista (R6, H22) | por hacer | — | |
| T-037 | AGE | PDF: nombre de archivo con código y cliente; rótulo correcto de la tabla de hoteles (R8, H20) | por hacer | — | |
| T-038 | VEN | Monto en letras del XML según la moneda de la venta (H27) | por hacer | — | |
| T-039 | AGE | Listas de proveedores, hoteles, mayoristas y guías sin cortar a 15: buscador contra el servidor (H28) | por hacer | — | |
| T-040 | AGE | Avisos a proveedores también al reprogramar, quitar un servicio, agregar un pasajero o cancelar la salida (H29, H37) | por hacer | — | |
| T-041 | AGE | El check-in en su propia tabla: no cambia la asignación ni la facturación (H30) | por hacer | — | |
| T-042 | AGE | Reactivar una salida cancelada y avisar cuando una reserva cae en una (H35) | por hacer | — | |
| T-043 | AGE | Tablero de salidas: "Siguiente" cambia de página de verdad (H36) | por hacer | — | |
| T-044 | AGE | Aviso cuando un guía o un chofer ya tiene otra salida ese día (H38) | por hacer | — | |
| T-045 | AGE | Venta directa: total en vivo, modo de precio claro y fecha por defecto hoy (H42, H44) | por hacer | — | |
| T-046 | AGE | Venta directa: pasajeros rápidos con residencia y el cliente como primer pasajero (H43) | por hacer | T-017 | |
| T-047 | AGE | Venta directa: vender un tour del catálogo, enganchado a la salida del día (H41) | por hacer | T-045, T-046 | |
| T-048 | AGE + CAJ | Venta directa: "Crear y cobrar" en un solo paso (H45) | por hacer | T-011 | |
| T-049 | AGE | Venta directa: varias líneas en la misma venta y destino del árbol (H46, H47) | por hacer | T-047 | |
| T-050 | CLI + AGE | CRM C1: historial de actividades del cliente y "Enviar" la cotización (H48, H49) | por hacer | — | |
| T-051 | CLI + AGE | CRM C2: próximas acciones, "Mis pendientes de hoy" y pantalla Seguimiento (H50) | por hacer | T-050 | |
| T-052 | AGE | CRM C3: por qué se ganó o se perdió la venta (H51) | por hacer | T-050 | |
| T-053 | AGE | CRM C6a: listado de cotizaciones con el seguimiento a la vista (H52) | por hacer | T-051, T-052 | |
| T-054 | AGE | Reporte operativo: mostrar el proveedor real de la reserva, no el cotizado (H53) | por hacer | — | |
| T-055 | AGE | Reporte operativo: el hotel en cada noche de la estadía (H54) | por hacer | T-054 | |
| T-056 | AGE | Cambiar proveedor, guía, fecha, hora y hotel después de facturar (H55) | por hacer | — | |
| T-057 | AGE | Reporte operativo: el tour muestra su salida, guía, vehículo y por qué está pendiente (H57) | por hacer | — | |
| T-058 | AGE | Reporte operativo: "Mañana", búsqueda, check-in a todos, filtros recordados y rango controlado (H58, H59) | por hacer | T-041 | |
| T-059 | CLI + AGE | CRM C4: "Nueva consulta" y origen de contacto | por hacer | T-050, T-033 | |
| T-060 | CLI + AGE | CRM C5: la ficha del cliente con preferencias del viajero y línea de tiempo | por hacer | T-050, T-051 | |
| T-061 | CLI + AGE | CRM: pasar el trabajo abierto de un usuario que se va | por hacer | T-051 | |
| T-062 | AGE | CRM C6b: reportes de conversión, origen, vendedor y motivos | por hacer | T-052, T-059 | |
| T-063 | PLA + AGE | Menú de Agencia por tareas del negocio (Ventas, Viajes, Finanzas, Catálogos, Administración) | por hacer | — | |
| T-065 | CLI + VEN | Varias direcciones por cliente y elegir la dirección al emitir (H61) | por hacer | T-064 | |
| T-067 | PLA + AGE | Unir a `main` y desplegar lo ya construido (fila 13 fases 1b-2, F1, F3, F4 etapa 1, F5) + operación pendiente en producción | por hacer (cuándo desplegar: decide el usuario) | — | `feat/f1-facturacion-por-servicio` |
| T-068 | POR + TRA | Seguridad: precios del pedido del portal calculados en el servidor y `v-html` saneado | por hacer | — | |

## Por analizar

> Ideas, pendientes y hallazgos que todavía no son tareas (no tienen criterios de
> aceptación). Una línea cada uno, con origen. Se convierten en tarea o se descartan.

| Módulo | Qué | Origen |
|---|---|---|
| AGE | IGV de la penalidad por cancelación: preguntar al contador (hoy lo elige el vendedor, D-AGE-033) | requerimiento Modificar reserva, 24-oct-2026 |
| AGE | Penalidad calculada con `reglas_cancelacion` (franjas por días) | D-AGE-033 |
| AGE | Agregar pasajero rápido en la reserva para tours por persona (después de probar Modificar reserva) | fila F4 de la hoja de ruta |
| AGE | Enviar el PDF de modificación por correo | T-006, fuera de alcance |
| TRA | Escribir `docs/transversal/seguridad.md` (detalle del checklist de `proceso.md` §4) | `proceso.md`, 24-oct-2026 |
| TRA | Migración del proceso, pasos 3-6 de `proceso.md` §6 (adelgazar `CLAUDE.md`, archivar, pasar pendientes, limpiar memoria) | `proceso.md`, 24-oct-2026 |
| AGE | **Revisión de cotizaciones, lo que queda** (26-oct; el resto ya es tarea: T-023 a T-031): H9 y H15 bajas (H13 pasó a T-032) | `docs/requerimientos/observaciones-pruebas-usuario.md`, "Revisión del flujo de cotizaciones" |
| AGE | **Obs. 9, lo que queda** (R1-R8 → T-032 a T-037): **H21 paquete internacional nace "Exonerado · Amazonía" — consultar al contador qué tratamiento corresponde**; precio de niño en paquetes de mayorista (hoy solo adultos, decisión 26-oct); H25 verificar que el tipo de cambio diario se sincroniza en producción | `docs/requerimientos/observaciones-pruebas-usuario.md` §9 |
| AGE | **Obs. 10 — tributario fuera de la cotización**: recomendación B (el sistema lo deduce con reglas del contador; la cotización solo lleva costo, venta y margen). Espera las 6 preguntas al contador (la 1 ya se sabe: agencia en la Amazonía + servicio prestado allí). Pausa T-029 y H21 | `docs/requerimientos/observaciones-pruebas-usuario.md` §10, 26-oct-2026 |
| VEN + NOT | **H26** leyenda Amazonía del Catálogo 52 (2001/2002/2003) no va en el XML; el PDF usa otro texto — confirmar con el contador si es obligatoria (H27 pasó a T-038) | `docs/requerimientos/observaciones-pruebas-usuario.md` §10, 26-oct-2026 |
| AGE | **Obs. 11 — entrada separada al cotizador** ("tours y servicios" / "paquete de mayorista"): en espera, el usuario lo decide tras evaluar a los usuarios. Ya hecho: solo el renombre de las pestañas | `docs/requerimientos/observaciones-pruebas-usuario.md` §11, 26-oct-2026 |
| AGE | **Revisión de reservas (26-oct)** H31-H34 (H29 → T-040, H30 → T-041): reasignar mayorista no mueve el cupo (H31), reserva cancelada sin camino de vuelta, ver T-030 (H32), pasajero agregado sin residencia (H33), 500 en carrera de facturación externa (H34). Falta la prueba en vivo | `docs/requerimientos/observaciones-pruebas-usuario.md`, "Revisión de reservas" |
| AGE | **Revisión de salidas operativas (26-oct)** H39-H40 (H35 → T-042, H36 → T-043, H37 → T-040, H38 → T-044): servicio quitado vuelve solo cuando se engancha otra reserva (H39), desenganchar sin revisiones ni ReservaPolicy (H40) | `docs/requerimientos/observaciones-pruebas-usuario.md`, "Revisión de salidas operativas" |
| AGE | **Obs. 12 — Venta directa** (H41-H47 → T-045 a T-049): solo queda la pregunta 3, ¿vender un paquete de varios días por venta directa? (sin decidir) | `docs/requerimientos/observaciones-pruebas-usuario.md` §12, 27-oct-2026 |
| AGE | **Obs. 13 — Seguimiento de cotizaciones** (H48-H52): absorbida por el CRM (obs. 16); queda para después el enlace para el cliente ("abrió la cotización") | `docs/requerimientos/observaciones-pruebas-usuario.md` §13, 27-oct-2026 |
| AGE | **Obs. 15 — Reporte operativo** (H53-H59 → T-054 a T-058): H56 sin tarea, por ahora todos ven todo en solo lectura; si una agencia pide restringir, por usuario (supervisores y administradores ven todo) | `docs/requerimientos/observaciones-pruebas-usuario.md` §15, 27-oct-2026 |
| AGE + CLI | **Obs. 16 — CRM práctico** (→ T-050 a T-053, T-059 a T-062): solo Agencia por ahora; sin responsable fijo del cliente (rotación de personal) | `docs/requerimientos/observaciones-pruebas-usuario.md` §16, 27-oct-2026 |
| PLA | **Obs. 14 — Módulo de uso del sistema** (solo UmboSystem; qué se usa, dónde se traban, qué es lento): **en espera** hasta avanzar las tareas pendientes, por decisión del usuario | `docs/requerimientos/observaciones-pruebas-usuario.md` §14, 27-oct-2026 |
| CAJ + NOT | Devolver dinero al cliente por una NC sin volver a facturar (hoy la NC no mueve la caja en ningún giro) | revisión de fallos de las observaciones, 25-oct-2026 |
| AGE | **Ley de la Amazonía (fila F2), lo que queda:** interruptor "la empresa cumple la Ley de la Amazonía" (hoy cualquier agencia puede marcar exonerado) y expediente de sustento por reserva (itinerario + pasajeros + órdenes de servicio). Junto con la obs. 10 (contador) | `plan-hoja-de-ruta-ejecucion.md` fila F2, 23-oct-2026 |
| VEN | Comunicación de baja a SUNAT sin conectar a ningún flujo (verificado 27-oct: no hay uso en `app/` ni rutas) | `CLAUDE.md`, Ventas |
| VEN | Archivo de depuración del XML con nombre fijo: `FacturacionElectronicaController` escribe `debug_xml.xml` en cada envío (choque entre ventas simultáneas, y entre tenants si el disco no está particionado) — verificado 27-oct | `CLAUDE.md`, multitenancy §4 |
| VEN | `controla_stock` del producto ignorado: `SaleController` descuenta stock siempre (verificado 27-oct) | `TODO.md`, Sesión 9a |
| VEN + NOT | Al aceptar una NC/ND no se ajustan `debt`/`paid_out`/`state_payment`; `client_credit_movements` (saldo a favor) diseñado sin construir; `sales.debt`/`paid_out` quedan congelados al cobrar desde Cuentas por Cobrar | `CLAUDE.md`, Notas y Amortizaciones |
| CRE | **Tasa máxima como TEA:** `CreditoDatosRequest` compara la tasa tipeada con `tasa_maxima` sin convertir unidades (verificado 27-oct); no fijar `tasa_maxima` en credishirley hasta corregirlo. Plantilla de contrato del abogado con la cláusula de la Ley 29733 | `docs/planning/creditos/00-reglas-y-modelo.md` §5 |
| TRA | Seguridad de infraestructura (auditoría 08-oct): dependencias con advisories, certificados beta con clave privada en el historial de git, HSTS/CSP en nginx, CORS `*`. (Pedidos del portal y `v-html` → T-068) | memoria `project_auditoria_seguridad_2026-10-08` |
| AGE | Pendientes de diseño ya conocidos: Compras + pagos a proveedores (fila 13 fase 3, reemplaza 11g), limpieza multi-destino (12g, espera producción), guía cotizado que no pasa a la reserva privada, endoso de pasajeros (v2 sin aprobar) | `plan-hoja-de-ruta-ejecucion.md`, memoria |
| TRA | Sesión por inactividad: hoy el JWT vence a la hora fija desde el login (`JWT_TTL`, 60 min por defecto) y el aviso sale 2 min antes **aunque el usuario esté trabajando**. Propuesta: renovar en silencio mientras hay actividad y mostrar el aviso solo tras N minutos sin uso (falta decidir N). Solo frontend: `helpers/sessionExpiryWatcher.ts` + `helpers/http-client.ts`; `auth/refresh` ya existe | pregunta del usuario, 24-oct-2026 |

## Hechas

> Las últimas, para tener contexto rápido. Las viejas se pueden quitar de acá: el commit
> queda en `git log`.

| ID | Módulo | Qué | Commit |
|---|---|---|---|
| T-064 | VEN | El comprobante guarda documento, razón social y dirección del cliente al emitirse (PDF, XML y QR ya no cambian al editar el cliente) | `7fa1024` |
| — | TRA | **Hotfix en producción:** eliminar venta y aceptar NC fallaban en retail y créditos (`reserva_ventas`). Desplegado 24-oct-2026 | `89a1ff7` (rama de trabajo: `224137c`) |
| F5 | AGE | Condición de pago al facturar una reserva (contado / crédito con cuotas) y en el PDF de todos los giros; venta de reserva no editable desde Ventas | `37eed40` (sin unir a `main`) |
| F1 | AGE | El comprobante de una reserva detalla los servicios cobrados | `9416387` (sin unir a `main`) |
| F1 | AGE | Casillas para elegir qué servicios entran; céntimos del tour; confirmar tributario encima del modal | `c957976`, `1a56a64` (sin unir a `main`) |
