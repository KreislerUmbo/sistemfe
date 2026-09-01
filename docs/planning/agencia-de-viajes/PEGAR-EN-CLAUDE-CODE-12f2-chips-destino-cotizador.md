# Brief para Claude Code — Sesión 12f-2: chips de destino en el cotizador

> Referencia: `plan-ejecucion-multidestino-mayoristas.md` fila 12f,
> `auditoria-arquitectonica-agencia-viajes.md` §7.1. Depende de 12f-1
> (mergeada, `origin/main` commit `84316e4`). Segunda de 3 sub-sesiones —
> la más grande y de mayor riesgo de adopción de todo el bloque 12a-12h.
> Toca `editar.vue` a fondo — verificar en navegador real al cerrar, no
> solo type-check.

---

## 0. Decisiones tomadas antes de escribir este brief

1. **Sin auto-resolver el toggle Local/Nacional/Internacional por
   categoría del destino** (§7.1 punto 2 de la auditoría lo pedía, pero
   `destinos_atractivos` no tiene ningún campo de eso — solo `tipo`:
   zona/lugar/atractivo, que es jerarquía, no nacional/internacional).
   Decisión confirmada con el usuario: el toggle sigue siendo manual, se
   scopea al destino activo (el comparador que abre es el de ESE
   destino) pero no se auto-selecciona. No se toca `destinos_atractivos`.
2. **Gap real encontrado planificando esta sesión:**
   `OpcionMayoristaController::store()` (12d) resuelve
   `alternativa_destino_id` SIEMPRE del primer destino de la alternativa
   (`$alternativa->destinos()->value('id')`), sin aceptar un valor
   explícito — a diferencia de los 9 puntos de `AlternativaItemController`
   que 12f-1 sí dejó aceptando un `alternativa_destino_id` opcional. Con
   2+ destinos reales, crear una `OpcionMayorista` nueva siempre caería
   en el primer destino sin importar qué chip esté activo. Se cierra en
   esta sesión (§2).
3. **`dia_referencial` se reinicia por destino** — decisión ya cerrada
   desde el inicio del bloque (ver preámbulo de
   `plan-ejecucion-multidestino-mayoristas.md`). Con las tabs de día
   ahora anidadas dentro del destino activo (§7.1 punto 1), esto se
   vuelve real por primera vez: los computeds de días (`diasCreados`,
   `diaSiguiente`, etc.) pasan a operar sobre los ítems del destino
   activo únicamente, no de toda la alternativa.
4. **Con 1 solo destino (el caso de casi todos los datos reales hoy),
   el cotizador se ve exactamente igual que antes** — los chips no se
   muestran, ningún computed cambia de comportamiento. Mismo criterio ya
   usado para `mostrarTabsDia` (Punto B, sesión anterior): no agregar
   ruido visual cuando no hace falta.

---

## 1. Backend — `AlternativaDestinoController`

Agregar `update()`/`destroy()` (12f-1 solo tenía `index()`/`store()`):

- `update(Request, string $alternativaId, string $id)`: mismo guard de
  `alternativa.estado==='aceptada'` que `store()`. Valida
  `destino_atractivo_id`/`destino_texto`/`fecha_inicio`/`fecha_fin`
  (todos opcionales, actualiza solo lo que llega).
- `destroy(string $alternativaId, string $id)`: mismo guard de
  `aceptada`. Bloquea con 422 si es el ÚLTIMO destino de la alternativa
  (la garantía de 12b/12c es "al menos 1 destino siempre"). Bloquea con
  422 si tiene `AlternativaItem`/`OpcionMayorista` asociados (mismo
  criterio que `AlternativaController::destroy()` con reservas — no
  borrar en cascada contenido real sin decisión explícita del vendedor,
  que primero tiene que mover o borrar esos ítems).

Rutas nuevas en `routes/api.php`, junto a las de `store()`/`index()`:
```
PUT    alternativas/{alternativaId}/destinos/{id}
DELETE alternativas/{alternativaId}/destinos/{id}
```

## 2. Backend — `OpcionMayoristaController::store()`

Mismo patrón que ya usan los 9 puntos de `AlternativaItemController`
(12f-1): agregar `'alternativa_destino_id' => ['nullable', 'integer', Rule::exists('alternativa_destinos','id')->where('alternativa_id', $alternativa->id)]`
al validator, y resolver `$validado['alternativa_destino_id'] ??
$alternativa->destinos()->value('id')` en vez del `->value('id')` fijo
de hoy.

## 3. Frontend — `editar.vue`

### 3.1 Estado y chips

- `alternativaDestinos = computed(() => alternativaActiva.value?.destinos ?? [])`
  (ya viene ordenado por `orden` desde el backend, 12f-1).
- `destinoActivoId = ref<number | null>(null)`, con un `watch` que lo
  apunta al primer destino cuando cambia la alternativa activa o cuando
  el destino activo deja de existir en la lista (ej. después de
  borrarlo).
- `mostrarChipsDestino = computed(() => alternativaDestinos.value.length > 1)`
  — mismo criterio que `mostrarTabsDia`, no mostrar el nivel extra con 1
  solo destino.
- Chips renderizados ARRIBA de las tabs de día existentes (mismo lugar
  que describe §7.1 punto 1): `Tarapoto | México | + Agregar destino`.
- Modal/form "+ Agregar destino": reusar `DestinoTreeSelect.vue` (mismo
  patrón que `cotizador/nueva.vue` línea 54: `v-model` + `@update:label`)
  para `destino_atractivo_id`/`destino_texto`, más `fecha_inicio`/
  `fecha_fin` (sugerir `fecha_inicio` = `fecha_fin` del destino anterior
  `+ 1 día`, editable — "editable" es la palabra clave, no bloquear si
  el vendedor lo cambia). `POST` al endpoint de 12f-1, recarga
  `alternativaDestinos`, cambia `destinoActivoId` al nuevo.
- Chip activo permite renombrar/editar fechas (llama `update()`) y
  borrar (llama `destroy()`, con confirmación — mismo patrón de
  confirmación que ya usa "Eliminar esta alternativa").

### 3.2 Ítems scopeados al destino activo

Nuevo computed `itemsDelDestinoActivo`, insertado ANTES de la
filtración por día existente — trata un ítem con
`alternativa_destino_id == null` (stragglers de antes de 12c/12f-1, cada
vez más raros) como perteneciente al PRIMER destino, nunca los oculta:

```js
const itemsDelDestinoActivo = computed(() => {
    const items = alternativaActiva.value?.items ?? [];
    if (!mostrarChipsDestino.value) return items; // 1 destino: sin cambios
    const primerDestinoId = alternativaDestinos.value[0]?.id ?? null;
    return items.filter((i) => (i.alternativa_destino_id ?? primerDestinoId) === destinoActivoId.value);
});
```

`inicializarDias()`, `itemsSinDia`, `itemsDelDiaActivo` cambian su fuente
de `alternativaActiva.value?.items ?? []` a `itemsDelDestinoActivo.value`
— con esto, los días se reinician solos por destino sin tocar la lógica
de días en sí (decisión §0.3).

### 3.3 Comparador de mayoristas scopeado

`cargarOpcionesMayorista()` sigue pidiendo TODAS las opciones de la
alternativa (no hace falta endpoint nuevo — `alternativa_destino_id` ya
viene en cada opción desde 12d). Filtrar client-side antes de asignar a
`opcionesMayorista.value`:
```js
opcionesMayorista.value = res.opciones_mayorista.filter(
    (op) => !mostrarChipsDestino.value || (op.alternativa_destino_id ?? alternativaDestinos.value[0]?.id) === destinoActivoId.value
);
```
`guardarOpcionMayorista()` manda `alternativa_destino_id: destinoActivoId.value`
en el payload a `opcionMayoristaService.crear()` (el backend ya lo
acepta desde §2 de este brief).

### 3.4 Ítems nuevos van al destino activo

Los `POST` de creación de ítem (`clicBibliotecaItem`,
`guardarItemManual`, `agregarItemProveedorHotel`, `desdePlantilla`, el
form de pasaje aéreo, etc. — todos los que llaman a
`alternativaItemService`) agregan `alternativa_destino_id: destinoActivoId.value`
al payload. El backend (12f-1) ya lo acepta en los 9 puntos — es
agregar el campo al payload del frontend, sin tocar el service (los
services ya mandan `data` genérico vía spread, confirmar caso por caso).

### 3.5 Panel de precio — subtotal por destino

Con `mostrarChipsDestino`, envolver el desglose existente
(`gruposPrecioPanel`, agrupado por tour) en un nivel extra por destino,
cada uno colapsable con su propio subtotal:

```js
type BloqueDestino = { destinoId: number | null; destinoTexto: string; subtotal: number; grupos: BloqueItem[] };
const gruposPrecioPorDestino = computed<BloqueDestino[]>(() => {
    const primerDestinoId = alternativaDestinos.value[0]?.id ?? null;
    return alternativaDestinos.value.map((destino) => {
        const items = (alternativaActiva.value?.items ?? []).filter((i) => (i.alternativa_destino_id ?? primerDestinoId) === destino.id);
        return {
            destinoId: destino.id,
            destinoTexto: destino.destino_texto,
            subtotal: items.reduce((sum, it) => sum + totalConvertidoLocal(it), 0),
            grupos: agruparPorTour(items),
        };
    });
});
```

Con 1 solo destino, el panel sigue mostrando `gruposPrecioPanel` tal
cual (sin el nivel extra) — el total general (`totalLocal`) no cambia de
cálculo en ningún caso, sigue sumando TODOS los ítems de la alternativa.

## 4. Fuera de alcance (confirmado, no tocar en esta sesión)

- PDF agrupado por destino (12f-3).
- Auto-resolver el toggle Local/Nacional/Internacional (§0.1).
- Modal "Paso 0" (`cotizador/nueva.vue`) — sigue simple, destinos
  adicionales se agregan después desde los chips (§7.1 punto 5,
  confirmado sin cambios).
- Validación de solapamiento de fechas entre destinos (§23.2 punto 1 de
  la auditoría) — nullable, sin bloqueo, el vendedor puede pisar fechas
  si quiere. Queda anotado como deuda conocida, no bloquea esta sesión.

## 5. Verificación esperada

- Tests backend: `AlternativaDestinoController::update()`/`destroy()`
  (bloqueo por `aceptada`, bloqueo si es el último destino, bloqueo si
  tiene ítems/opciones asociados); `OpcionMayoristaController::store()`
  respeta `alternativa_destino_id` explícito.
- Suite completa de backend en verde.
- Frontend: `npm run dev`, verificar en navegador real contra
  `agencia-demo` (no solo type-check): crear una alternativa nueva
  (nace con 1 destino, chips ocultos — confirmar CERO regresión visual),
  agregar un 2do destino, confirmar que los días se reinician en 1 para
  el nuevo destino, agregar ítems en cada destino y confirmar que el
  lienzo/comparador de mayoristas solo muestra los del destino activo,
  confirmar el subtotal por destino en el panel de precio y que el total
  general sigue sumando todo. Revertir los datos de prueba al cerrar.
  Type-check de frontend sin regresiones sobre el baseline vigente (45).
- Actualizar `plan-hoja-de-ruta-ejecucion.md`: marcar 12f-2 como cerrada
  dentro de la fila 12f (la fila completa sigue sin `[x]` hasta que
  cierre también 12f-3).
