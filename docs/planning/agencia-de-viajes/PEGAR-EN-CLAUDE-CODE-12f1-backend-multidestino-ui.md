# Brief para Claude Code — Sesión 12f-1: backend para la UI multi-destino

> Referencia: `plan-ejecucion-multidestino-mayoristas.md` fila 12f,
> `auditoria-arquitectonica-agencia-viajes.md` §7.1. Depende de 12c+12d+12e
> (todas mergeadas). Es la **primera de 2-3 sub-sesiones** en las que se
> divide 12f (decisión del usuario, 01-sep-2026) — esta es puro backend,
> no toca `editar.vue`. La investigación previa (agente Explore) confirmó
> el estado real del código antes de escribir este brief — no asumido.

---

## 0. Alcance de esta sub-sesión

**Entra:**
1. `CotizacionController::show()` eager-carga `alternativas.destinos` —
   hoy no lo hace, confirmado por grep (0 matches de `destinos` en ese
   archivo).
2. `AlternativaDestinoController` nuevo (`index()`/`store()`) — gap real
   encontrado al planificar: el botón "+ Agregar destino" (§7.1 punto 1)
   necesita crear una `AlternativaDestino` explícita desde la UI, y ese
   endpoint no existe — hoy la única fila se crea automáticamente en
   `AlternativaController::store()` (sesión 12c).
3. Los 9 puntos de creación de `AlternativaItem` (confirmado por grep:
   4 en `AlternativaItemController::desdePlantilla()`, 1 cada uno en
   `crearItemProveedor()`/`crearItemMayorista()`/`crearItemGuia()`/
   `crearItemPasajeAereo()`/`crearItemManual()`) empiezan a setear
   `alternativa_destino_id` — cierra el gap que 12c dejó pendiente a
   propósito ("eso queda para 12f, que es donde recién se lee este
   dato").

**No entra (sub-sesiones futuras):**
- Nada de `editar.vue` — chips de destino, toggle resuelto por destino
  activo, subtotal por destino en el panel de precio (eso es 12f-2).
- PDF agrupado por destino (12f-3).
- `update()`/`destroy()` de `AlternativaDestino`, validación de
  solapamiento de fechas entre destinos (§23.2 punto 1 de la
  auditoría) — quedan para 12f-2, que es donde va a existir una UI real
  de edición. Mismo criterio que 12e dejó la UI de Opcionales fuera:
  no construir gestión completa antes de que exista el flujo que la
  necesita.

---

## 1. `CotizacionController::show()`

Agregar `'alternativas.destinos.destinoAtractivo'` al array de `with()`
(línea ~125-141 hoy). Sin cambios de comportamiento visible todavía — el
frontend no lee este dato hasta 12f-2, pero tiene que estar disponible
cuando esa sesión llegue.

## 2. `AlternativaDestinoController` (nuevo)

Mismo patrón que `OpcionMayoristaController`/`ContenidoTourController`
(alcance mínimo: solo lo que el flujo de "+ Agregar destino" necesita
hoy, sin `update()`/`destroy()`):

```
GET  alternativas/{id}/destinos   → index()   (lista ordenada, orderBy orden/id)
POST alternativas/{id}/destinos   → store()   (crea uno nuevo)
```

`store()`:
- Guard: si `alternativa.estado === 'aceptada'`, 422 con el mismo
  mensaje/patrón ya establecido ("Esta alternativa ya fue aceptada y
  generó una reserva...").
- Validar `destino_atractivo_id` (nullable, `exists:destinos_atractivos,id`)
  + `destino_texto` (required, string) + `fecha_inicio`/`fecha_fin`
  (nullable, date).
- `orden` = `MAX(orden)` de los destinos existentes de esta alternativa
  `+ 1` (no hardcodear 1 — a diferencia del backfill de 12b, acá puede
  haber ya 1+ destinos).
- Responder con el registro creado, mismo formato que
  `OpcionMayoristaController::store()`.

Rutas en `routes/api.php`, junto a las de `alternativas/{id}/opciones-mayorista`,
mismo middleware `permission:agencia.cotizaciones`.

## 3. `AlternativaItemController` — resolver compartido

Un solo punto de escritura (mismo criterio que el riesgo de
doble-escritura que la auditoría marca en §20): método privado nuevo

```php
private function reglaAlternativaDestinoId(Alternativa $alternativa)
{
    return \Illuminate\Validation\Rule::exists('alternativa_destinos', 'id')
        ->where('alternativa_id', $alternativa->id);
}

private function resolverAlternativaDestinoId(Alternativa $alternativa, ?int $explicito): ?int
{
    return $explicito ?? $alternativa->destinos()->value('id');
}
```

Agregar `'alternativa_destino_id' => ['nullable', 'integer', $this->reglaAlternativaDestinoId($alternativa)]`
a los validators de los 5 métodos privados (`crearItemProveedor`,
`crearItemMayorista`, `crearItemGuia`, `crearItemPasajeAereo` vía
`validarPasajeAereo()`, `crearItemManual`) y al validator de
`desdePlantilla()`. Agregar
`'alternativa_destino_id' => $this->resolverAlternativaDestinoId($alternativa, $validado['alternativa_destino_id'] ?? null)`
a cada uno de los 9 `AlternativaItem::create([...])`.

**Importante para `desdePlantilla()`:** resolver el id **una sola vez**
por request (no una vez por cada `entrada` del bucle) — todos los ítems
de una misma carga de plantilla van al mismo destino.

Sin UI de selección de destino todavía (eso es 12f-2), el explícito
siempre va a venir `null` por ahora — el fallback (`$alternativa->destinos()->value('id')`,
el primer destino por orden) es el comportamiento correcto hoy (sigue
habiendo 1 destino real por alternativa en la práctica) y queda listo
para que 12f-2 empiece a mandar el id real del destino activo sin volver
a tocar el backend.

## 4. Verificación esperada

- Test: `CotizacionController::show()` devuelve `alternativas[].destinos`
  poblado.
- Tests de `AlternativaDestinoController`: `store()` crea con `orden`
  auto-incrementado (2do destino de una alternativa con 1 ya existente
  → `orden=2`); rechaza con 422 si `alternativa.estado==='aceptada'`;
  `index()` devuelve ordenado.
- Un test por cada uno de los 9 puntos de creación de `AlternativaItem`
  (no menos — el resolver es idéntico en los 9 lugares, pero un error de
  copiar/pegar en uno solo no se detecta si no se prueban todos):
  confirmar que el ítem creado sin `alternativa_destino_id` explícito
  queda con el destino por defecto de la alternativa, y que pasando uno
  explícito (perteneciente a esa alternativa) se respeta.
- Suite completa de backend en verde. Sin cambios de frontend en esta
  sesión — type-check no debería moverse del baseline vigente.
- Actualizar `plan-hoja-de-ruta-ejecucion.md`: la fila 12f pasa a
  reflejar que 12f-1 (backend) está cerrada, 12f-2/12f-3 siguen
  pendientes — no marcar toda la fila 12f como `[x]` todavía, es una
  fila que cubre las 3 sub-sesiones.
