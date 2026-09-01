# Brief para Claude Code — Sesión 12a: gaps de bajo riesgo antes de multi-destino

> Pégale este archivo completo a una sesión nueva de Claude Code sobre el
> repo del sistema. Referencia de contexto general:
> `docs/planning/agencia-de-viajes/auditoria-arquitectonica-agencia-viajes.md`
> §3.2, §3.4, §5, §19 (Fase 0), y
> `docs/planning/agencia-de-viajes/plan-ejecucion-multidestino-mayoristas.md`
> (esta es la sesión 12a de esa tabla).
>
> Esta sesión es intencionalmente chica y sin dependencias — cierra 2
> hallazgos puntuales encontrados en la auditoría arquitectónica, antes
> de empezar a construir `alternativa_destinos` (sesión 12b en adelante).
> No toca el motor de precios, no toca moneda, no agrega tablas nuevas.
>
> **CORRECCIÓN 01-sep-2026, antes de ejecutar esta sesión:** la versión
> original de este brief traía un §3 que marcaba
> `alternativa_items.opcion_hotel_tarifa_id`/`paquete_plantilla_id` como
> "confirmadas muertas" para deprecarlas. Esa conclusión se basó en que la
> auditoría (`auditoria-arquitectonica-agencia-viajes.md`) no llegó a leer
> `docs/planning/agencia-de-viajes/plan-matriz-hoteles-cotizador.md` —
> pese a que el brief original que disparó la auditoría
> (`AUDITORÍA ARQUITECTÓNICA PROFUNDA-chatgpt.md`, líneas 36-42 y 611-617)
> ordenaba explícitamente leerlo y coordinar, "no crear una segunda
> solución incompatible". Ese plan (EN DISEÑO, 6 rondas cerradas al
> 29-ago) depende activamente de esa misma columna para el mecanismo de
> hoteles ad-hoc — la extiende, no la mata. **§3 se elimina de este brief.
> No deprecar ni dropear `opcion_hotel_tarifa_id`/`paquete_plantilla_id`
> en ninguna sesión de este plan sin coordinar antes con
> `plan-matriz-hoteles-cotizador.md`.**

---

## 0. Alcance de esta sesión

**Entra:**
1. Guard de congelamiento faltante en `AlternativaController::update()`.
2. Índice único parcial en `opcion_mayorista` para la regla "solo una
   elegida".

**No entra (fuera de alcance a propósito, sesiones futuras):**
- Nada de `alternativa_destinos` — eso empieza en la sesión 12b, y esa
  sesión además necesita reconciliarse primero con
  `plan-refactor-mayoristas-tramos.md` (`alternativa_tramos` — diseño
  paralelo e independiente para el mismo problema, ver nota en ese
  documento) antes de tocar schema.
- Nada de `contenido_tour`.
- Deprecar/dropear columnas de `alternativa_items` — ver corrección de
  arriba, queda fuera de este plan hasta coordinar con la matriz de
  hoteles.
- Pagos a proveedor, fidelización — fuera de esta ronda completa (ver
  `plan-ejecucion-multidestino-mayoristas.md` §0).

---

## 1. Guard: bloquear descuento global sobre alternativa ya aceptada

**Hallazgo (auditoría §3.2):** `AlternativaController::update()` no
verifica `alternativa.estado === 'aceptada'` cuando el request trae
`descuento_global_pct`/`descuento_global_monto` — es el único camino
documentado donde el total de una alternativa ya aceptada (con su
reserva ya creada) podría cambiar en vivo sin que nadie lo note.

**Qué hacer:**
1. Lee `AlternativaController::update()` completo y confirma qué otros
   campos del mismo método ya tienen el guard de "no tocar si está
   aceptada" (el propio hallazgo dice que "casi todos los demás caminos
   de mutación sí lo bloquean" — usa exactamente el mismo patrón/mensaje
   de error que ya usan esos otros campos, no inventes uno nuevo).
2. Agrega la misma validación para `descuento_global_pct` y
   `descuento_global_monto`: si `alternativa.estado === 'aceptada'` y el
   request trae cualquiera de los dos, responder 422 con un mensaje
   explícito (mismo estilo que los guards existentes).
3. Test: intentar cambiar `descuento_global_pct` sobre una alternativa
   `aceptada` → 422. Cambiarlo sobre una alternativa no aceptada → sigue
   funcionando igual que hoy (test de regresión).

---

## 2. Índice único parcial: una sola `OpcionMayorista` elegida por alternativa

**Hallazgo (auditoría §3.4):** la regla "solo una `OpcionMayorista`
elegida por alternativa" depende enteramente de que
`OpcionMayoristaController::elegir()` desmarque la anterior en código —
sin constraint de base de datos que lo garantice. Mismo patrón de riesgo
que ya causó el bug real de orden de filas (`Alternativa::items()` sin
`orderBy`) antes de corregirse.

**Qué hacer:**
1. **Antes de crear el índice**, correr una query de verificación contra
   cada tenant real con datos del vertical (`agencia-demo` como mínimo,
   confirmar si hay otros): ¿existe hoy alguna `alternativa_id` con más
   de una fila `estado='elegida'` en `opcion_mayorista`? Si existe,
   **no crear el índice todavía** — reportar los casos encontrados y
   pedir decisión de cómo resolverlos (probablemente dejar solo la más
   reciente como elegida) antes de continuar. No corregir datos de
   producción sin confirmar con el usuario primero.
2. Si la verificación sale limpia (o después de corregir los casos
   encontrados con aprobación explícita), crear la migración: índice
   único parcial `CREATE UNIQUE INDEX ... ON opcion_mayorista
   (alternativa_id) WHERE estado = 'elegida'` (ajustar sintaxis exacta
   según el motor real de BD — Postgres soporta `WHERE` en índice único
   directamente; si el proyecto usa MySQL, confirmar el mecanismo
   equivalente antes de asumir que existe el mismo feature).
3. Test: crear 2 `OpcionMayorista` para la misma alternativa e intentar
   marcar ambas como `elegida` sin pasar por `elegir()` (insert directo
   o segundo `elegir()` sin el desmarcado) → debe fallar por el índice,
   no solo por la lógica de aplicación. Confirmar también que
   `OpcionMayoristaController::elegir()` sigue funcionando normal (pasa
   de una elegida a otra sin violar el índice, porque desmarca antes de
   marcar, dentro de la misma transacción).

**Nota para la sesión 12d:** este índice es un paso intermedio. Cuando
`OpcionMayorista` se mueva a colgar de `alternativa_destino_id` (sesión
12d de `plan-ejecucion-multidestino-mayoristas.md`), este mismo índice
se reemplaza por uno equivalente sobre `alternativa_destino_id` — no
hace falta anticipar eso ahora, solo tenerlo presente para no sorprenderse
cuando la sesión 12d lo toque.

---

## 3. Verificación esperada de esta sesión

- Test nuevo de §1 en verde (bloqueo de descuento sobre alternativa
  aceptada) sin romper ningún test existente de `AlternativaController`.
- Query de verificación de §2 corrida y documentada en el commit (aunque
  el resultado sea "0 casos encontrados") — no crear el índice sin haber
  corrido y reportado esa query primero.
- Test nuevo de §2 en verde (índice único parcial rechaza el caso de 2
  elegidas).
- Suite completa de backend en verde, sin regresiones. Type-check de
  frontend sin regresiones sobre el baseline vigente (confirmar el
  número exacto antes de empezar, mismo criterio que usaron las sesiones
  11r/11s/11u).
- Actualizar `plan-hoja-de-ruta-ejecucion.md`: marcar la fila 12a como
  `[x]` con fecha y commit, y dejar una nota corta de qué se encontró en
  la query de verificación del índice (aunque sea "0 casos").
