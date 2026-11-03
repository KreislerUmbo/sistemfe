# T-053 — CRM C6a: listado de cotizaciones con el seguimiento a la vista (H52)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-051, T-052, T-061 (hechas) |
| **Reglas** | ayudas sin fricción; fechas en hora Perú (CLAUDE.md, «Fechas y horas») |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 13 (H52, punto 5) y obs. 16, fase C6 (27-oct-2026). Detalles fijados al preparar la tarea (03-nov-2026), ver «Decisiones de detalle» |
| **Rama** | `feat/T-053-cotizaciones-por-seguir` |

## Contexto
El listado del Cotizador muestra el estado («Enviada», «Vencida»…) pero no cuándo se envió, qué
toca hacer ni cuándo vence: el vendedor tiene que abrir cotización por cotización para saber a
quién llamar. «Mis pendientes de hoy» (T-051) ya muestra las acciones en Inicio; esta tarea pone el
seguimiento **en el listado** y permite extender la vigencia sin rehacer la cotización.
Base: recorridos 21 a 24 de `docs/aprendizaje/`. La columna «Origen» ya la hizo T-059.

## Alcance
- **Columna «Seguimiento»** en el listado: un texto corto por fila, calculado en el backend sin
  consultas por fila (ver «Textos»). En rojo si hay algo para hoy o atrasado.
- **Filtro «Por seguir hoy»**: cotizaciones **abiertas** (`Cotizacion::abiertas()`, T-061) con una
  acción pendiente para hoy o atrasada, **o** con una alternativa enviada que vence dentro de
  `dias_aviso_vencimiento_cotizacion` días (incluidas las ya vencidas). Lo más atrasado primero.
- **«Extender vigencia»** de la alternativa activa en el editor (alternativa `enviada`, vigente o
  vencida): nueva fecha → `fecha_vencimiento` y una actividad `estado` en el historial.
- **Días de aviso configurables**: `dias_aviso_vencimiento_cotizacion` (la columna ya existe, por
  defecto 2) se edita en Configuración de Agencia junto a «Días vigencia cotización».
- Respeta lo que cada usuario ve hoy en el listado (`Cotizacion::propias()`).

## Decisiones de detalle (03-nov-2026, al preparar la tarea)
- **Las vencidas entran en «Por seguir hoy»**, aunque hayan vencido hace semanas: una cotización
  abierta y vencida es trabajo pendiente (extender la vigencia o cerrar como perdida). Para que
  salga del filtro hay que hacer una de las dos cosas. El filtro es opcional y no estorba a
  nadie.
- **«Abierta» es `Cotizacion::abiertas()`**: no se escribe otra definición. Ganadas, perdidas,
  venta directa y modificaciones de reserva no entran nunca en el filtro.
- **El filtro va en SQL**, no en PHP (a diferencia de `estado_resumen`, ~123 de `index()`), para
  que pagine bien. Las dos condiciones son `whereExists`:
  - acción: `crm_acciones` con `referencia_tipo = 'cotizacion'`, `referencia_id = cotizaciones.id`,
    `hecha_en IS NULL` y `fecha <= HoraPeru::hoyTexto()` (`fecha` es fecha de negocio, `date`);
  - vencimiento: `alternativas` con `estado = 'enviada'` y
    `fecha_vencimiento <= HoraPeru::finDiaUtc(hoy + N días)` (`fecha_vencimiento` es instante UTC).
    Sin `fecha_vencimiento` (la agencia no configuró vigencia) no cuenta por vencimiento.
- **Orden con el filtro activo**: por la fecha más urgente, ascendente: la menor entre la fecha de
  la acción pendiente más vieja y el día Perú del vencimiento más próximo de sus enviadas
  (`LEAST(...)`, que en Postgres ignora los NULL). Desempate por `id desc`. Sin el filtro, el orden
  de hoy (`id desc`).
- **Días en hora Perú**: «hace 3 días» y «vence mañana» comparan **días de calendario de Perú**
  (`HoraPeru::deUtc($instante)->startOfDay()` contra `HoraPeru::hoy()`), no horas. `diffInDays`
  siempre con `absolute: true` (Carbon 3 devuelve con signo) y el signo se decide aparte.
- **Extender vigencia, solo hacia adelante**: la nueva fecha es un día (`Y-m-d`) **desde hoy**
  (Perú); se guarda como `HoraPeru::finDiaUtc($fecha)` (vigente todo ese día). Se permite acortar
  una vigencia mientras la fecha nueva no sea anterior a hoy. El campo propone
  `hoy + dias_vigencia_cotizacion` (o hoy + 7 si la agencia no lo configuró).
- **Extender no cambia el estado**: la alternativa sigue `enviada`; deja de verse «vencida»
  porque `estadoResumen()` ya compara `fecha_vencimiento` con el ahora.
- **Extender se rechaza (422)** si la alternativa no está `enviada`, si la cotización está perdida
  o si no es del cotizador (venta directa o modificación de reserva). Permiso: `cotizaciones.editar`
  en la ruta y `CotizacionPolicy::update` en el controller, como `marcarEnviada()`.
- **Inicio no cambia**: «Mis pendientes de hoy» (T-051) ya lleva a cada cotización. El bloque
  «Cotizaciones por seguir» de H52 queda cubierto por el filtro del listado.

### Textos de «Seguimiento»
Partes unidas con « · », en este orden; las que no aplican no salen.

| Caso | Texto |
|---|---|
| Perdida | «Perdida: Precio» (nombre del motivo; sin motivo → «Perdida») — nada más |
| Reservada o anulada | «Ganada» — nada más |
| Envío (si tiene alternativas enviadas) | «Enviada hoy» / «Enviada ayer» / «Enviada hace 3 días» (la `fecha_envio` más reciente entre sus enviadas) |
| Vencimiento: todas las enviadas vencidas | «Vencida hoy» / «Vencida ayer» / «Vencida hace 2 días» (el vencimiento más reciente) |
| Vencimiento: la vigente que vence primero, si vence dentro de N días | «Vence hoy» / «Vence mañana» / «Vence en 2 días» |
| Acción pendiente (la más vieja por `fecha`) | «llamar hoy (Ana)» / «llamar mañana (Ana)» / «llamar el 05/11 (Ana)» / atrasada: «llamar, atrasada 2 días (Ana)» |
| Borrador sin acción | «Sin enviar» |
| Descartada | sin texto |

- El verbo de la acción es `CrmAccion::etiquetaTipo()` en minúscula; el nombre, el primer nombre
  del responsable (como la cabecera «Próxima acción» del editor, `editar.vue` ~18).
- La primera parte empieza en mayúscula; si la única parte es la acción, también («Llamar hoy (Ana)»).
- `urgente = true` si la acción es de hoy o atrasada, o si alguna enviada vence hoy o ya venció.

Ejemplo completo: «Enviada hace 3 días · Vence mañana · llamar hoy (Ana)».

## Fuera de alcance
- Reportes (T-062); recordatorios por correo o push (11f).
- Bloque nuevo en Inicio (ver decisiones).
- Extender la vigencia desde el listado (se hace en el editor: un clic en la fila).
- Cambiar `estadoResumen()` o la taxonomía de estados.

## Dónde tocar
**Backend** (`api-sistema-fe/`)
- Servicio nuevo `app/Services/AgenciaViajes/SeguimientoCotizacion.php`:
  - `diasAviso(): int` → `ConfiguracionAgencia::value('dias_aviso_vencimiento_cotizacion') ?? 2`;
  - `aplicarFiltroPorSeguir(Builder $query, string $hoy, int $diasAviso): void` → las dos
    `whereExists` y el `orderByRaw(LEAST…)`;
  - `paraPagina(Collection $cotizaciones, CarbonImmutable $hoy, int $diasAviso): void` → **una**
    consulta de `CrmAccion::pendientes()` con `whereIn('referencia_id', ids)` y
    `with('responsable:id,name,surname')`, agrupada por cotización, y a cada fila le pone
    `seguimiento = ['texto' => string, 'urgente' => bool]`;
  - `texto(Cotizacion $c, ?CrmAccion $accion, CarbonImmutable $hoy, int $diasAviso): array`
    pública y pura (sin consultas), para testear los textos directamente.
- `app/Http/Controllers/AgenciaViajes/CotizacionController.php::index()` (~55):
  - sumar `'resultadoMotivo:id,nombre'` al `with` (~67; relación `Cotizacion::resultadoMotivo()`, ~95);
  - `?por_seguir=1` → `aplicarFiltroPorSeguir()` antes del `paginate()`; ojo: hoy hay
    `orderByDesc('id')` fijo en la línea del `paginate` (~136): con el filtro, el orden del servicio
    va primero;
  - en el `transform` (~138), después del bucle: `paraPagina()` sobre la colección de la página.
- `app/Http/Controllers/AgenciaViajes/CotizacionActividadController.php`: método nuevo
  `extenderVigencia(Request $request, string $id)` junto a `marcarEnviada()` (~36), mismo patrón
  (`autorizarCotizacion('update', …)`, `validadorEs`). En una transacción: `update` de
  `fecha_vencimiento` y `CrmActividad::create` con `tipo = TIPO_ESTADO`,
  `referencia_tipo = REFERENCIA_COTIZACION`, `referencia_id`, `alternativa_id` y nota
  «Vigencia de «Alternativa A» extendida hasta el 10/11/2026 (antes: 03/11/2026)» (o «(no tenía)»).
  Devuelve `fecha_vencimiento`.
- `routes/api.php`: `POST alternativas/{id}/extender-vigencia` junto a `marcar-enviada` (~1037),
  con `permission:cotizaciones.editar`.
- `app/Http/Controllers/AgenciaViajes/ConfiguracionAgenciaController.php`:
  `'dias_aviso_vencimiento_cotizacion' => 2` en los valores por defecto (~30) y
  `'required|integer|min:0|max:60'` en la validación (~63-68).

**Frontend** (`admin-start-kit/src/`)
- `views/agencia-viajes/cotizador/index.vue`:
  - columna «Seguimiento» después de «Estado» (~77 y la celda ~104): `seguimiento.texto`,
    `text-danger` si `urgente`, `text-muted small` si no;
  - botón conmutable «Por seguir hoy» (`btn-sm`, `btn-outline-danger` ↔ `btn-danger`) en la fila
    de filtros (~25-60), con `por_seguir: 1` en el GET (~206), vuelve a la página 1 y lo limpia
    `limpiarFiltros()` (~237); si la URL trae `?por_seguir=1`, arranca activo.
- `types/agencia-viajes.ts` (~327, junto a `estado_resumen`): `seguimiento?: { texto: string; urgente: boolean }`.
- `views/agencia-viajes/cotizador/editar.vue`, junto a `<EnviarAlternativa>` (~827): si
  `alternativaActiva.estado === 'enviada'`, una línea «Vigente hasta el 10/11» o «Vencida el
  03/11» (en rojo) y el botón «Extender vigencia», salvo con `modificacionInfo` o
  `cotizacionPerdida`. El modal (componente nuevo
  `components/AgenciaViajes/ExtenderVigenciaModal.vue`, con `Teleport` como
  `CerrarComoPerdidaModal.vue`) lleva un `CampoFecha.vue` con mínimo hoy (`hoyPeru()`) y el valor
  propuesto; al guardar actualiza `alternativa.fecha_vencimiento` (como `onAlternativaEnviada()`,
  ~1963) y recarga el historial (`historialRef?.recargar()`).
- `views/agencia-viajes/configuracion/index.vue`: campo «Días de aviso antes del vencimiento»
  junto a «Días vigencia cotización» (~89-92) y `dias_aviso_vencimiento_cotizacion: 2` en el
  formulario (~673).

## Criterios de aceptación
- [ ] **CA1** — Cotización con una acción pendiente de ayer y otra con acción de hoy → las dos
  salen en «Por seguir hoy», la de ayer primero; una con acción de mañana y nada por vencer → no
  sale. → test
- [ ] **CA2** — Con aviso de 2 días: enviada que vence pasado mañana → sale; vence en 3 días → no;
  vencida hace 10 días y abierta → sale; ganada o perdida con acción atrasada → no; venta directa →
  no. → test
- [ ] **CA3** — Textos de «Seguimiento» de cada caso de la tabla, con `urgente`, probando
  `SeguimientoCotizacion::texto()` con un «hoy» fijo; incluye el cruce de medianoche (envío a las
  21:00 de Perú = 02:00 UTC del día siguiente → «Enviada hoy» esa noche). → test
- [ ] **CA4** — El listado con 5 y con 50 cotizaciones (con acciones y alternativas) hace el mismo
  número de consultas (`DB::enableQueryLog()`), con y sin `por_seguir`. → test
- [ ] **CA5** — Extender la vigencia de una alternativa vencida → `estadoResumen()` deja de ser
  `vencida`, queda la actividad `estado` con la nota y la cotización sale de «Por seguir hoy» si no
  tiene otra cosa pendiente. Fecha anterior a hoy, alternativa en borrador, cotización perdida o de
  modificación → 422; sin `cotizaciones.editar` → 403; cotización de otro vendedor sin
  `cotizaciones.ver_todas` → 403 (Policy). → test
- [ ] **CA6** — Lo que ve cada uno: un vendedor sin `ver_todas` no ve en el filtro las cotizaciones
  de otro. → test
- [ ] **CA7** — Prueba funcional en agencia-demo: el filtro, la columna y extender una vigencia
  desde el editor; Configuración guarda los días de aviso. Suite completa en verde; `type-check`
  sin errores nuevos (base 47).

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: `cotizaciones.editar` + `CotizacionPolicy::update`
- [ ] Aislamiento de tenant: tablas del tenant
- [ ] Validación de entrada / archivos: fecha `date_format:Y-m-d` y no anterior a `HoraPeru::hoyTexto()`
- [ ] Concurrencia (`lockForUpdate`): no aplica (una sola columna, última escritura gana; queda en el historial)
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: «hoy» con `HoraPeru::hoyTexto()`/`hoy()`; `fecha_vencimiento` es instante → `finDiaUtc()`; `crm_acciones.fecha` es fecha de negocio
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: textos como texto
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica (la columna de aviso ya existe)

## Cómo verificar
```
php artisan test --filter="Seguimiento|CotizacionEstadoResumen|CotizacionResultado|CrmActividadCotizacion|ConfiguracionAgencia"
cd admin-start-kit && npm run type-check && npx vitest run
```
Tests nuevos en `tests/Feature/AgenciaViajes/SeguimientoCotizacionTest.php`.
Prueba funcional: agencia-demo, `admin@agencia-demo.test` (memoria
`reference_agencia_demo_admin_login`; es Super-Admin). Para CA6 sirven los usuarios de T-061
(«T061 Destino», id 12, `t061-destino@agencia-demo.test`, contraseña `T061prueba`, «Vendedor de
agencia»). Crear los datos de prueba con prefijo `T053-` y anotarlos en el Resultado.

---

## Resultado
- **Commits:** ver `git log main..feat/T-053-cotizaciones-por-seguir` (un commit `feat(agencia): … (T-053)`).
- **Tests:** nuevos en `tests/Feature/AgenciaViajes/SeguimientoCotizacionTest.php` (15 tests:
  CA1, CA2 y orden mezclado, CA3 en 4 tests con «hoy» fijo y cruce de medianoche, seguimiento en el
  listado, CA4 con `DB::enableQueryLog()`, CA5 en 4 tests, CA6, configuración). El filtro de
  «Cómo verificar»: 51 tests en verde. Suite completa: 1902 en verde y 1 falla, el intermitente
  conocido `Fila13Fase1cFacturacionTest::descuento_del_combo…`, que pasa al correrlo solo (32/32). Frontend: `type-check` con 47
  errores (la base, ninguno en archivos tocados); Vitest 175/175.
- **Prueba funcional (CA7, agencia-demo, 02-nov-2026 ~21:50 Perú):**
  - Como «T061 Destino» (vendedor sin `ver_todas`): «Por seguir hoy» muestra solo `T061-PRUEBA-1`,
    «Llamar hoy (T061)» en rojo.
  - Como admin: `?por_seguir=1` arranca con el botón activo; la cotización vencida sale primero
    («Enviada hace 10 días · Vencida hace 3 días», en rojo). En el editor: «Vencida el 30/10» en
    rojo → «Extender vigencia» propone 17/11 (hoy + 15 días de vigencia) → «Vigente hasta el
    17/11», historial «Vigencia de «Alternativa A» extendida hasta el 17/11/2026 (antes:
    30/10/2026)»; la cotización pasa a «Enviada» y sale del filtro.
  - Configuración: días de aviso 15 → guarda y la cotización vuelve al filtro con «Vence en 15
    días»; se dejó otra vez en 2.
  - **Datos de prueba creados en agencia-demo:** cotización `T053-PRUEBA-1` (id 82, vendedor admin)
    con «Alternativa A» (id 115, enviada, vigente hasta el 17/11/2026) y su actividad de historial;
    clientes «T053 Cliente Vencida» ids **33** (el de la cotización) y **34** (huérfano, quedó de un
    intento fallido del script; se puede borrar).
- **Desviaciones de la tarea y por qué:**
  1. **Día del vencimiento = día Perú de `fecha_vencimiento − 1 segundo`** (backend, `ORDER BY` y
     editor). La tarea pide guardar `finDiaUtc($fecha)` (inicio del día siguiente, límite
     exclusivo) y a la vez comparar `deUtc($instante)->startOfDay()`: juntas, «vigente hasta el
     10/11» se leería como «vence el 11/11». Con el segundo menos, las dos reglas quedan
     coherentes; el filtro (`<= finDiaUtc(hoy + N)`) ya era coherente con esto.
  2. **CA4: el listado ya no serializa `tiene_grupos_sin_resolver` en las alternativas**
     (`setAppends([])` en `index()`). Era un N+1 previo (una consulta de ítems por alternativa) que
     impedía cumplir CA4; el listado no lo usa. Aprobado por el usuario al construir.
  3. **Descartada con acción pendiente muestra la acción** («Llamar hoy (Ana)»). La tabla dice
     «sin texto», pero esa cotización es abierta y entra en «Por seguir hoy» por la acción: con la
     columna vacía no se sabría por qué está en el filtro. Sin acción, sigue sin texto.
  4. `dias_aviso_vencimiento_cotizacion` se valida `sometimes|required|integer|min:0|max:60`
     (con `sometimes`, como `dias_proximo_contacto` de T-051), para no romper a quien guarda la
     configuración sin el campo.
  5. «atrasada 1 día» en singular; con 2 o más, «atrasada N días».
  6. **Ajuste de la revisión (Opus): la vigencia es la de la cotización, no la de cada
     alternativa.** La regla «incluidas las ya vencidas» de la tarea miraba cada enviada por
     separado: con la A vencida y la B reenviada vigente por 15 días, la cotización figuraba
     «Enviada» (`estadoResumen()`) pero quedaba para siempre en «Por seguir hoy» y en rojo sin decir
     por qué. Ahora filtro, orden, texto y `urgente` miran la **última** `fecha_vencimiento` entre
     las enviadas (ninguna si alguna no tiene): `SeguimientoCotizacion::ultimaVigencia()`. Tests:
     caso nuevo en CA2 y los de varias enviadas en `test_textos_de_envio_y_vencimiento`. El editor
     sigue mostrando la vigencia de la alternativa activa.

## Hallazgos fuera de alcance
- `Cotizacion::alternativas` se serializa entera en el listado (todas las columnas de cada
  alternativa) aunque la pantalla solo usa `alternativas_count`; se podría acotar el `select`.
- `php artisan tinker <archivo>` incluye el archivo y luego abre la consola interactiva: en este
  entorno se queda colgado. Para scripts usar `tinker --execute="require '…';"`.
