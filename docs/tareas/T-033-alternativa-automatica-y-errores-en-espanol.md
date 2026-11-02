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
- [x] **CA1** — Crear una cotización deja ya la "Alternativa A" en la moneda por defecto, con su destino; la pantalla abre lista para agregar servicios. → test + prueba funcional
- [x] **CA2** — Escribir otro nombre y moneda en la nueva cotización los respeta. → test
- [x] **CA3** — Cambiar la moneda de una alternativa sin servicios → ok; con servicios → 422. → test
- [x] **CA4** — "Alternativa A" se guarda "Alternativa A"; "viaje a cusco" sigue saliendo "Viaje a Cusco". → test de `TextoFormatoService`
- [x] **CA5** — "+ Nueva" con nombre vacío crea "Alternativa B" (si ya hay una). → test
- [x] **CA6** — Error de validación en español y junto al campo. → prueba funcional
- [x] **CA8** — Tipo de cambio: con uno `dia` registrado, se usa ese; sin ninguno y con SUNAT, se usa
  el `venta` de SUNAT y queda registrado; sin ninguno y sin SUNAT, 422 y no se crea ni la cotización
  ni la alternativa; reenviando con `tipo_cambio_valor`, se crean las dos. → test
- [x] **CA7** — Suite completa en verde; `type-check` y `build` sin errores.

## Seguridad y correctitud
- [x] Permisos: crear cotización ya exige `cotizaciones.crear`
- [x] Aislamiento de tenant: no aplica
- [x] Validación de entrada: moneda en (PEN, USD)
- [x] Concurrencia: cotización + alternativa en la misma transacción
- [x] Dinero: no aplica (sin servicios al cambiar moneda)
- [x] Fechas con `HoraPeru`: tipo de cambio "del día" con `HoraPeru`
- [x] Tributario: no aplica
- [x] `v-html`: no aplica
- [x] Migraciones: nueva en `verticals/agencia-viajes/`; `tenants:migrate-verticales`

## Cómo verificar
```
php artisan test --filter="Cotizacion|Alternativa|TextoFormato"
php artisan test   # suite completa, contra PostgreSQL
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:** `4f29161` (Sonnet) y el ajuste de la revisión (selector de moneda oculto en solo lectura; textos de `nueva.vue` con «tú»), rama `feat/T-033-alternativa-automatica`, unida a `main`.
- **Tests:**
  - `tests/Feature/AgenciaViajes/AlternativaAutomaticaTest.php` (nuevo, 9 tests): CA1, CA2, CA3,
    CA5, CA8 (los tres caminos del tipo de cambio + fuera de rango con confirmación) y CA6 del lado
    del backend (mensaje en español y `errors` por campo).
  - `tests/Unit/TextoFormatoServiceTest.php`: CA4 (2 tests nuevos).
  - `--filter="Cotizacion|Alternativa|TextoFormato|Capitalizacion|Sesion12c|ConfiguracionAgencia"`:
    225 verdes. Suite completa contra PostgreSQL: 1821 verdes (7137 aserciones).
  - Frontend: `type-check` con los mismos 46 errores que `main` (todos ajenos: portal,
    fake-backend, router); ninguno en archivos de esta tarea. `build` ok.
  - Prueba funcional (Playwright, agencia-demo): Nueva cotización muestra «Propuesta» con
    "Alternativa A" y la moneda de Configuración; «Crear» vacío muestra los 3 errores junto a cada
    campo, sin ventana; con cliente/destino/pasajero, nombre "opción económica" y USD → abre el
    cotizador con la pastilla "Opción Económica · USD"; el selector de moneda la pasa a PEN; "+ Nueva"
    muestra placeholder "Alternativa B" y con el nombre vacío crea "Alternativa B". Queda en
    agencia-demo la cotización de prueba CDKM-1126-0000005 (id 73).
- **Regla de H24 (exacta):** en `capitalizarNombrePropio()`, un conector se deja en minúscula salvo
  que sea la primera palabra **o** sea la última palabra **y** tenga una sola letra
  ("Alternativa A", "Opción E", "Lima y Cusco" → la "y" sigue en minúscula porque no es la última).
- **Desviaciones de la tarea y por qué:**
  - Campos de `POST cotizaciones` para la propuesta: `alternativa_nombre`, `moneda_cotizacion`,
    `tipo_cambio_valor`, `tipo_cambio_confirmado`; todos opcionales (vacíos = "Alternativa A" y
    `moneda_cotizacion_default`). El origen siempre es `dia`.
  - El paso 2 (SUNAT) solo se aplica con origen `dia` (un TC "de la agencia" no sale de SUNAT); con
    `agencia` sin ninguno registrado sigue el 422 pidiendo el valor. El `TipoCambioAgencia`
    registrado desde SUNAT lleva la **fecha de SUNAT** (si hoy no publicó, la del último día hábil),
    no la de hoy.
  - Nombre sugerido: la letra por cantidad de alternativas, saltando las ya usadas por nombre (si se
    borró la B y quedan A y C, la tercera no repite "Alternativa C": sale "Alternativa D"). El
    frontend replica la misma regla para el placeholder.
  - Cambiar moneda con `PUT alternativas/{id}`: resuelve el tipo de cambio **antes** de la
    transacción del update (si se registra el de SUNAT, es un dato válido aunque el update falle).
    Mandar la misma moneda no es un cambio (no bloquea renombrar una alternativa con servicios).
  - Selector de moneda: va dentro de la pastilla activa (junto al lápiz de renombrar), oculto en
    cotizaciones de modificación de reserva. Su error (422 con servicios) se muestra como toast: no
    es un formulario.
  - R7: el proyecto no tiene `lang/` (locale `en`), así que se agregó el trait
    `Concerns\ValidaEnEspanol` (mensajes de las reglas usadas + nombres legibles por campo), aplicado
    a los 5 validadores de `AlternativaController` y `CotizacionController`. Los 422 llevan además
    `errors` por campo. `ConfiguracionAgenciaController` no se tocó en esto.
  - `AlternativaController::resolverTipoCambio()` (privado) se movió a
    `AlternativaCreacionService::resolverTipoCambio()`; el tope de 5 alternativas también vive ahí.

## Hallazgos fuera de alcance
- Siguen en inglés los mensajes de validación de los demás controllers de Agencia (p. ej.
  `AlternativaItemController`, `ConfiguracionAgenciaController`): el trait `ValidaEnEspanol` se
  puede reusar.
- El form "+ Nueva" de `editar.vue` prellena «Valor nuevo» con la sugerencia SUNAT, así que cada
  alternativa nueva registra un `TipoCambioAgencia` aunque ya haya uno del día igual. Comportamiento
  previo a esta tarea; con el orden nuevo ese prellenado ya no hace falta.
