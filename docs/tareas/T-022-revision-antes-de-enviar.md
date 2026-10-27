# T-022 — Cotizador: revisión antes de enviar ("¿lista para el cliente?")

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | **T-028** y T-050 (el botón "Enviar" donde se muestra la revisión) (vínculo `version_anterior_id` y scope de "vigente"); si T-001 ya está hecha, "Actualizar al precio nuevo" respeta su bloqueo de alternativa aceptada |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 8 (respuestas 1-6, D2, "Diseño — solo lo crítico") |
| **Rama** | `feat/T-022-revision-antes-de-enviar` |

## Contexto
Antes de mandar una propuesta al cliente, el vendedor necesita responder rápido "¿ya está lista?".
Hoy se envía lo que haya. **Regla de fondo del usuario:** la revisión tiene que acelerar el envío,
no frenarlo — solo puntos críticos, silencio cuando todo está bien, nada trivial. Si durante la
construcción aparece la tentación de agregar otra revisión, **no**: anotarla en "Hallazgos".

## Alcance

**Backend**
- Servicio `RevisionAlternativaService::revisar(Alternativa): array` con **exactamente estas 6
  revisiones** (ver la tabla del requerimiento):
  1. sin servicios → `bloquea`;
  2. servicio con precio de venta nulo o 0 → `bloquea` (en grupos de hoteles, cada fila);
  3. venta por debajo del costo (siempre) o margen bajo `configuracion_agencia.margen_minimo_alerta_pct`
     (si está vacío, no se avisa margen) → `aviso`;
  4. tarifa del servicio con **versión siguiente activa** (precio nuevo), o que **no vale para la
     fecha del viaje** (vencida por `vigente_hasta`, `activo=false`, o su `temporada_id` no tiene
     ocurrencia que cubra la fecha) → `aviso`;
  5. pasaje aéreo con `tarifa_valida_hasta` vencida o anterior a la vigencia de la propuesta → `aviso`;
  6. alternativa `enviada` con `fecha_vencimiento` pasada → `aviso`.
  - Sin fecha de viaje, 4 (parte de temporada/vigencia) y 5 no se evalúan.
  - Grupos de hoteles abiertos: revisar cada fila; no es un pendiente que el grupo esté abierto.
  - Cada resultado: `codigo`, `nivel`, `mensaje` corto en español, `cantidad`, `destino` para
    navegar (`item_ids[]`, o `seccion`: `lienzo` / `datos_cotizacion` / `pasaje_aereo`), y para el 4,
    los datos de la versión nueva (`tarifa_nueva_id`, precio actual y nuevo).
  - Agrupar por código (una entrada por tipo con `cantidad` e `item_ids`), no una por ítem.
- `GET alternativas/{id}/revision` (misma Policy/permiso que ver la cotización).
- `CotizacionEnvioController::enviar()`: correr la revisión y responder 422 con la lista si hay
  algún `bloquea` (aunque se llame a la API directo). `preparar()` devuelve la revisión para el formulario.
- **Vínculo de versiones:** lo construye **T-028** (`proveedor_tarifas.version_anterior_id` y el
  scope de "vigente"). Acá solo se usa: "precio nuevo" = existe una tarifa vigente cuyo
  `version_anterior_id` es la del servicio. Versiones de antes de T-028 no avisan "precio nuevo";
  sí "vencida".
- **"Actualizar al precio nuevo":** `PUT alternativa-items/{id}/actualizar-tarifa` — re-engancha el
  ítem a la versión siguiente activa (costo y precio de la versión nueva, convertidos a la moneda de
  la alternativa como al agregar un ítem; conserva `descuento_pct` y cantidad), recalcula el total
  de la alternativa (`recalcularTotalAlternativa()`). 422 si la alternativa está aceptada o si ya no
  hay versión siguiente. Reusar el armado de precios que ya usa el alta del ítem (no duplicarlo).
- `configuracion_agencia.margen_minimo_alerta_pct` (decimal 5,2, nullable) + campo en Configuración
  de Agencia.

**Frontend (`cotizador/editar.vue` + componente nuevo `RevisionAlternativa.vue`)**
- Junto a los botones de envío: ✓ "Lista para el cliente" si no hay nada; si hay, insignia
  "N pendientes" que despliega la lista corta (una línea por tipo). Sin modal.
- Clic en una línea: lleva al primer servicio (abre su día/destino, hace scroll y lo resalta un
  momento); si hay varios, "siguiente". Secciones: abrir el formulario correspondiente.
- Línea de precio nuevo: botón "Actualizar" por servicio → confirmación "Precio actual X → nuevo Y.
  ¿Actualizar?" → aplica y recalcula. Uno por uno, sin "actualizar todos".
- Se recalcula tras cualquier cambio de la alternativa (mismo momento en que hoy se refresca el total;
  evitar llamadas en cada tecla).
- **Ver PDF:** nunca se bloquea.
- **Botón "Enviar" de T-050 (correo, WhatsApp, "ya la envié por otro medio"):** la revisión se
  muestra al elegir **cualquiera** de los tres caminos, no solo el correo; con `bloquea`, ese camino
  queda deshabilitado con el motivo; con solo avisos, el botón dice "Enviar igual". (Ajustado el
  27-oct-2026: T-050 reemplazó el envío solo por correo.)
- Configuración de Agencia: campo "Margen mínimo para avisar (%)".

## Fuera de alcance
- Cualquier revisión que no sea una de las 6 (ítem manual, referencial, edades, tipo de cambio…).
- Marca "por confirmar" en servicios (descartada, D2).
- "Actualizar todos".
- Revisión al aceptar la cotización.

## Archivos probables
- `api-sistema-fe/app/Services/AgenciaViajes/RevisionAlternativaService.php` (nuevo)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaController.php` o uno nuevo para `revision`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` (`actualizarTarifa`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionEnvioController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ProveedorTarifaController.php`, `ConfiguracionAgenciaController.php`
- migraciones en `tenant/verticals/agencia-viajes/`
- `admin-start-kit/src/components/AgenciaViajes/RevisionAlternativa.vue`, `EnviarCorreoCotizacion.vue`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`, pantalla de Configuración de Agencia

## Criterios de aceptación
- [ ] **CA1** — Alternativa sin ítems → `bloquea`; `POST enviar-correo` → 422. → test
- [ ] **CA2** — Ítem con precio 0 → `bloquea` con su `item_id`. → test
- [ ] **CA3** — Venta < costo → aviso aunque no haya margen configurado; margen 5% con mínimo 10% →
  aviso; sin mínimo configurado → sin aviso de margen. → test
- [ ] **CA4** — Editar el precio de una tarifa usada crea la versión con `version_anterior_id`; la
  revisión de la alternativa muestra "precio nuevo" con precio actual y nuevo. → test
- [ ] **CA5** — `actualizar-tarifa` re-engancha a la versión nueva, conserva el descuento y el total
  de la alternativa cambia; en alternativa aceptada → 422. → test
- [ ] **CA6** — Tarifa con `vigente_hasta` anterior a la fecha de viaje, o desactivada → aviso. Tarifa
  de temporada sin ocurrencia que cubra la fecha → aviso. → test
- [ ] **CA7** — Pasaje aéreo con `tarifa_valida_hasta` vencida → aviso. → test
- [ ] **CA8** — Alternativa enviada y vencida → aviso. → test
- [ ] **CA9** — Alternativa correcta → lista vacía (silencio). → test
- [ ] **CA10** — Solo avisos → el correo se envía ("Enviar igual"). → test
- [ ] **CA11** — 3 ítems con el mismo problema → una sola entrada con `cantidad=3`. → test
- [ ] **CA12** — Prueba funcional en `agencia-demo`: ✓ con todo bien; insignia y navegación al
  servicio; actualizar precio con confirmación; Ver PDF sin bloqueo; correo con bloqueo y con aviso.
  Revertir datos de prueba.
- [ ] **CA13** — `type-check` y `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos: `revision` con el permiso de ver cotizaciones; `actualizar-tarifa` con el de editar
- [ ] Aislamiento de tenant: temporadas/ocurrencias se leen de la base central con su modelo
  (`CentralConnection`)
- [ ] Validación de entrada: el ítem debe ser de la alternativa; la versión nueva se busca en el
  servidor (nunca un id enviado por el frontend)
- [ ] Concurrencia: `actualizar-tarifa` bloquea la alternativa (`lockForUpdate()`) igual que los
  demás cambios de precio
- [ ] Dinero: comparar en centavos; conversión de moneda con la misma función que el alta del ítem
- [ ] Fechas con `HoraPeru`: "hoy" para vencimientos con `HoraPeru`; `tarifa_valida_hasta` y
  `fecha_vencimiento` son instantes en UTC
- [ ] Tributario: `actualizar-tarifa` copia `tip_afe_igv`/destino de la versión nueva como el alta
- [ ] `v-html`: no (mensajes como texto)
- [ ] Migraciones: nuevas en `verticals/agencia-viajes/`; `tenants:migrate-verticales`

## Cómo verificar
```
php artisan tenants:migrate-verticales
php artisan test --filter=Revision
php artisan test   # suite completa, contra PostgreSQL
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
