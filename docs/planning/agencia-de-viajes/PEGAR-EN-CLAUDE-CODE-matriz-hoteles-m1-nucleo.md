# Brief para Claude Code — Sesión M1: núcleo del agrupador de opciones de hotel

> Pégale este archivo completo a una sesión nueva de Claude Code sobre el
> repo del sistema. Referencia de contexto completo:
> `docs/planning/agencia-de-viajes/plan-matriz-hoteles-cotizador.md`
> (diseño CERRADO, 6 rondas — leer completo antes de empezar, en especial
> Ronda 1/2 para la mecánica de `grupo_opcion_id`/`opcion_elegida`) y
> `docs/planning/agencia-de-viajes/plan-ejecucion-matriz-hoteles-cotizador.md`
> (esta es la sesión M1 de esa tabla).
>
> Esta sesión es **backend puro, sin UI** — construye el mecanismo de
> agrupación y su efecto en precio/aceptación. Las sesiones siguientes
> (M2-M5) construyen trazabilidad hacia la reserva, alta ad-hoc de
> hoteles, la pantalla real del cotizador y el PDF. No toca ninguna de
> esas cosas todavía — al terminar M1, no hay forma de crear un grupo
> desde la UI real (eso es M4), pero el mecanismo de datos y sus reglas
> de negocio ya funcionan y están testeados.

---

## 0. Alcance de esta sesión

**Entra:**
1. Migración: `alternativa_items.grupo_opcion_id`/`opcion_elegida`.
2. Guard 422 al aceptar una alternativa con algún grupo sin resolver.
3. Precio en vivo del cotizador (backend) y reparto de
   `descuento_global_pct`: tratamiento correcto de ítems agrupados.

**No entra (fuera de alcance a propósito, sesiones futuras):**
- Nada de `opcion_hotel_tarifa_id`, `reserva_items`, o el resolver de
  nombre — eso es M2.
- Nada de alta ad-hoc de hoteles ni columnas de impuesto en
  `opciones_hotel_tarifas` — eso es M3.
- Nada de `HabitacionMatrixPicker.vue` ni del lienzo del cotizador —
  eso es M4. Para probar esta sesión, crear los `alternativa_items`
  agrupados directo por Tinker/factory en los tests, no hace falta UI.
- Nada del PDF — eso es M5.

---

## 1. Migración — `grupo_opcion_id`/`opcion_elegida` en `alternativa_items`

```php
Schema::table('alternativa_items', function (Blueprint $table) {
    $table->uuid('grupo_opcion_id')->nullable()->after('opcion_mayorista_id');
    $table->boolean('opcion_elegida')->default(false)->after('grupo_opcion_id');
    $table->index('grupo_opcion_id');
});
```

- `grupo_opcion_id` es un UUID generado por el CALLER en el momento de
  insertar varias filas juntas (no autoincremental, no FK a ninguna
  tabla — es un valor compartido entre N filas de `alternativa_items`,
  nada más). En M1 no hay ningún endpoint que lo genere todavía (eso es
  M4) — para los tests de esta sesión, generarlo a mano con
  `Str::uuid()` al crear las filas de prueba.
- `opcion_elegida` arranca siempre en `false`. Se marca `true` en
  **exactamente una** fila de cada grupo para "resolverlo" — no hay
  constraint de base de datos que lo garantice todavía (documentar como
  regla de aplicación, mismo criterio que ya usa el proyecto para reglas
  equivalentes — ver el índice único parcial de `opcion_mayorista` en
  `plan-ejecucion-multidestino-mayoristas.md` sesión 12a como precedente
  de CUÁNDO sí conviene un constraint real; acá no aplica todavía porque
  el UUID no tiene una tabla padre sobre la que indexar "una elegida por
  grupo" de forma limpia — si en el futuro se detecta un caso real de
  2 filas `opcion_elegida=true` en el mismo grupo, evaluar agregarlo,
  no ahora).
- Ítems sin grupo (el 100% de los existentes hoy): `grupo_opcion_id=null`,
  `opcion_elegida=false` — se comportan exactamente igual que antes en
  todo el sistema. Verificar esto con un test de regresión explícito.

Actualizar `AlternativaItem::$fillable` (o el equivalente que use el
modelo) con ambos campos.

---

## 2. Guard — bloquear "Aceptar" con un grupo sin resolver

**Dónde:** `ReservaController::aceptar()` (el método que hoy valida
`estado in ['borrador','enviada']` y bloquea si ya hay una reserva activa
— agregar esta validación en el mismo lugar, mismo estilo de respuesta
422 que las otras).

**Regla:** antes de crear la reserva, agrupar los `alternativa_items` de
la alternativa por `grupo_opcion_id` (ignorando los `null`). Para cada
grupo no vacío, contar cuántas filas tienen `opcion_elegida=true`:
- 0 → 422, mensaje explícito: "Hay N grupo(s) de opciones de hotel sin
  resolver ([nombres/descripciones si existen, o "grupo #N"]). Marca la
  opción elegida por el cliente antes de aceptar."
- exactamente 1 → OK, ese grupo está resuelto.
- 2+ → esto no debería poder pasar si el resto del sistema respeta la
  regla de "una sola elegida por grupo", pero si pasa (dato corrupto,
  edición manual, bug futuro), tratarlo como error 422 también — no
  asumir que el primero es el correcto y seguir silenciosamente.

Test: alternativa con un grupo sin ninguna marcada → `aceptar()` responde
422 y no crea la reserva. Con una marcada → `aceptar()` funciona igual
que antes (test de regresión: una alternativa SIN ningún grupo sigue
aceptándose exactamente igual que hoy).

---

## 3. Precio en vivo — "desde $X" mientras el grupo está abierto

**Dónde:** el método que hoy calcula el total de la alternativa para el
panel de precio en vivo del cotizador (buscar en `AlternativaController`
o `PriceEngineService` — confirmar el nombre exacto leyendo el código,
no asumir).

**Regla (Ronda 2/P5-P6 del diseño):**
- Ítems sin `grupo_opcion_id`: sin cambios, se suman como siempre.
- Dentro de un grupo con alguna fila `opcion_elegida=true`: se suma
  **solo** esa fila (con su descuento si tiene), igual que un ítem
  normal — las demás filas del grupo NO se suman al total (existen,
  pero no cuentan).
- Dentro de un grupo SIN ninguna fila `opcion_elegida=true` (grupo
  abierto): se suma el **mínimo** `precio_venta_snapshot` (o el campo
  equivalente de precio de lista, confirmar el nombre exacto) entre las
  filas del grupo — sin descuento aplicado a esa cifra, aunque
  `descuento_global_pct` esté seteado en la alternativa (ver punto
  siguiente). El resultado debe poder distinguirse en la respuesta JSON
  como "estimado/desde" vs. un total cerrado — agregar un flag booleano
  a nivel de alternativa en la respuesta (ej.
  `tiene_grupos_sin_resolver`) para que el frontend (M4) sepa mostrar
  "desde $X" en vez de un total fijo. No hace falta UI en esta sesión,
  pero el dato tiene que estar en la respuesta del endpoint.

**Reparto de `descuento_global_pct` (Ronda 2/P6):**
- Encontrar el método que reparte el descuento global entre líneas
  respetando el piso individual (`descuento_maximo_pct`/
  `margen_minimo_pct` de cada `proveedor_tarifa`).
- Ítems sin grupo: sin cambios.
- Filas de un grupo abierto (sin `opcion_elegida=true`): **excluir del
  reparto por completo** — no reciben descuento, se muestran a precio
  de lista siempre.
- La fila `opcion_elegida=true` de un grupo resuelto: **incluir en el
  reparto normalmente**, como cualquier ítem — respeta el piso de SU
  PROPIA `proveedor_tarifa` (si la tiene; en M1 todos los datos de
  prueba son de proveedor real, M3 agrega el caso ad-hoc sin piso).

Tests:
- Grupo abierto con `descuento_global_pct=10` seteado en la alternativa
  → ninguna fila del grupo lleva descuento, el resto de ítems normales
  sí.
- Grupo resuelto (una fila `opcion_elegida=true`) con el mismo
  descuento → esa fila sí lo lleva, respetando su piso individual.
- Cambiar cuál fila es la elegida (de A a B dentro del mismo grupo) y
  confirmar que el descuento se recalcula sobre la nueva elegida, no
  queda pegado a la anterior — este es el caso que el usuario preguntó
  explícitamente en el diseño (Ronda 2/P6), no lo saltees.

---

## 4. Verificación esperada de esta sesión

- Todos los tests nuevos de §1-§3 en verde.
- Test de regresión explícito: una alternativa con ítems 100% sin grupo
  (el caso de hoy) se comporta idéntico en `aceptar()`, en el cálculo de
  precio en vivo, y en el reparto de descuento — antes y después de esta
  sesión, mismos números exactos.
- Suite completa de backend en verde, sin regresiones.
- Actualizar `docs/planning/agencia-de-viajes/plan-ejecucion-matriz-hoteles-cotizador.md`:
  marcar la fila M1 con `[x]`, fecha y commit.
