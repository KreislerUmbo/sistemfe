# T-033 — La primera alternativa nace con la cotización; nombre y moneda editables; errores en español (R1, R7)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 9, R1 y R7 (aprobadas 26-oct-2026: el nombre es el título que ve el cliente y la moneda se tienen que poder cambiar); H23, H24. Del usuario, 01-nov-2026: tipo de cambio de la alternativa automática = último registrado, luego SUNAT del día, luego pedirlo (ver Alcance) |
| **Rama** | `feat/T-033-alternativa-automatica` |

## Contexto
Medido (obs. 9): tras crear la cotización la pantalla queda vacía y hay que crear la alternativa a
mano (nombre, moneda, tipo de cambio: 3–5 acciones). Sin nombre sale "The nombre field is
required." en una ventana; el texto gris dice "Alternativa B" en la primera (H23). "Alternativa A"
se guarda como "Alternativa a" porque `TextoFormatoService::capitalizarNombrePropio()` trata la "a"
como conector (H24). Hoy **no se puede cambiar la moneda** de una alternativa ya creada ("Convertir a
dólares" es solo una calculadora).

## Alcance
- **Nueva cotización (`cotizador/nueva.vue`):** bloque "Propuesta" con **Nombre** (prellenado
  "Alternativa A", editable: es el título que ve el cliente) y **Moneda** (prellenada con la moneda
  por defecto de la agencia). El tipo de cambio se toma solo, con `tipo_cambio_origen='dia'`
  (ver el punto siguiente). `CotizacionController::store()` crea la cotización **y** su primera
  alternativa en la misma transacción, reusando la lógica de `AlternativaController::store()`
  (extraerla a un servicio; no duplicarla, incluido el `AlternativaDestino` inicial).
- **Tipo de cambio de la alternativa automática** (decisión del usuario, 01-nov-2026). Hoy
  `AlternativaController::resolverTipoCambio()` (~línea 556) toma el último `TipoCambioAgencia`
  con ese origen y, si no hay ninguno, responde 422: con la alternativa automática eso trabaría
  crear la cotización. Orden nuevo, dentro del servicio extraído:
  1. el último `TipoCambioAgencia` con origen `dia` (como hoy);
  2. si no hay ninguno: `TipoCambioSunatResolver::resolverParaFecha(HoraPeru::hoy())`, valor
     `venta`, registrado como `TipoCambioAgencia` nuevo (origen `dia`, `registrado_por` = usuario);
  3. si tampoco hay SUNAT: 422 claro y **Nueva cotización muestra el campo de tipo de cambio**
     para escribirlo (`tipo_cambio_valor`, con el mismo control de rango y
     `tipo_cambio_confirmado`). No se crea nada a medias: cotización y alternativa, o ninguna.
  "+ Nueva" alternativa usa el mismo orden.
- `configuracion_agencia.moneda_cotizacion_default` (PEN por defecto) + campo en Configuración de
  Agencia.
- **Cambiar la moneda después:** `PUT alternativas/{id}` acepta `moneda_cotizacion` (con el tipo
  de cambio del día) **solo mientras la alternativa no tenga servicios**; con servicios, 422 claro
  ("Quita los servicios o crea otra alternativa en {moneda}"). En pantalla: selector de moneda junto
  al nombre de la alternativa, habilitado solo sin servicios.
- **Nombre:** sigue editable con "Renombrar". Arreglar H24: una letra suelta al final del nombre
  ("Alternativa A", "Opción B") no se pasa a minúscula; anotar en "Resultado" la regla exacta.
- **"+ Nueva" alternativa:** si el nombre queda vacío, se usa el sugerido (con la letra correcta:
  A, B, C según las que existan) en vez de error.
- **R7:** los mensajes de validación de `AlternativaController` y `CotizacionController` en español
  (atributos y mensajes del Validator), y en el cotizador se muestran junto al campo cuando es un
  formulario, no en ventana.

## Fuera de alcance
- Convertir los precios de una alternativa con servicios a otra moneda.
- Errores de otros módulos (anotar en "Hallazgos" si se ven en inglés).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaController.php` → `store()` (~43-117,
  incluye el `AlternativaDestino` inicial), `update()` (~119), `resolverTipoCambio()` (~556)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionController.php` → `store()` (~128, su
  `DB::transaction` ~148)
- `api-sistema-fe/app/Services/AgenciaViajes/` → servicio nuevo para crear alternativa
- `api-sistema-fe/app/Services/TipoCambio/TipoCambioSunatResolver.php` → `resolverParaFecha()` (solo usar)
- `api-sistema-fe/app/Services/TextoFormatoService.php` → conectores (~32), `capitalizarNombrePropio()` (~43)
- migración nueva en `database/migrations/tenant/verticals/agencia-viajes/`;
  `ConfiguracionAgenciaController.php` → `show()` (~44), `update()` (~53)
- `admin-start-kit/src/views/agencia-viajes/cotizador/nueva.vue` (264 líneas)
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue` (4479 líneas: **no leerlo entero**):
  formulario de alternativa ~200-240 (placeholder "Alternativa B" ~210), `crearAlternativa()` ~1918,
  renombrar ~1957; buscar el resto con `grep`
- `admin-start-kit/src/views/agencia-viajes/configuracion/index.vue`

## Criterios de aceptación
- [ ] **CA1** — Crear una cotización deja ya la "Alternativa A" en la moneda por defecto, con su destino; la pantalla abre lista para agregar servicios. → test + prueba funcional
- [ ] **CA2** — Escribir otro nombre y moneda en la nueva cotización los respeta. → test
- [ ] **CA3** — Cambiar la moneda de una alternativa sin servicios → ok; con servicios → 422. → test
- [ ] **CA4** — "Alternativa A" se guarda "Alternativa A"; "viaje a cusco" sigue saliendo "Viaje a Cusco". → test de `TextoFormatoService`
- [ ] **CA5** — "+ Nueva" con nombre vacío crea "Alternativa B" (si ya hay una). → test
- [ ] **CA6** — Error de validación en español y junto al campo. → prueba funcional
- [ ] **CA8** — Tipo de cambio: con uno `dia` registrado, se usa ese; sin ninguno y con SUNAT, se usa
  el `venta` de SUNAT y queda registrado; sin ninguno y sin SUNAT, 422 y no se crea ni la cotización
  ni la alternativa; reenviando con `tipo_cambio_valor`, se crean las dos. → test
- [ ] **CA7** — Suite completa en verde; `type-check` y `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos: crear cotización ya exige `cotizaciones.crear`
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: moneda en (PEN, USD)
- [ ] Concurrencia: cotización + alternativa en la misma transacción
- [ ] Dinero: no aplica (sin servicios al cambiar moneda)
- [ ] Fechas con `HoraPeru`: tipo de cambio "del día" con `HoraPeru`
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: nueva en `verticals/agencia-viajes/`; `tenants:migrate-verticales`

## Cómo verificar
```
php artisan test --filter="Cotizacion|Alternativa|TextoFormato"
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
