# Sesión 12h — Reasignación en vivo de `OpcionMayorista` en `ReservaItem`

Pegar este brief completo en una sesión nueva de Claude Code. Rama nueva, un commit, un chat (regla del proyecto).

> **CORRECCIÓN 01-sep-2026, antes de ejecutar:** este brief da por hecho
> que `reserva_items.opcion_mayorista_id` ya existe ("el cambio es de
> comportamiento, no de schema", §1). **No existe** — confirmado contra
> `api-sistema-fe/app/Models/AgenciaViajes/ReservaItem.php` y sus
> migraciones reales (`$fillable` no la tiene). Tal cual está escrita,
> la migración de §1 fallaría (`->after('opcion_mayorista_id')` sobre una
> columna que no está). Antes de ejecutar esta sesión, agregar
> `opcion_mayorista_id` (nullable, FK a `opcion_mayorista`) a la misma
> migración de §1, y decidir cómo se puebla al aceptar una reserva
> (mismo patrón que `proveedor_tarifa_id` en
> `ReservaController::crearReservaItemDesdeAlternativaItem()`). También
> depende de que 12d (`OpcionMayorista.alternativa_destino_id`) esté
> resuelto primero — y 12d a su vez depende de que
> `alternativa_destinos` vs. `alternativa_tramos` se reconcilie (ver
> nota en `plan-ejecucion-multidestino-mayoristas.md`).

## Contexto (no repetir preguntas, ya está decidido)

Hoy `ReservaItem` permite reasignar `proveedor_tarifa_id`/`guia_id` en vivo después de que la reserva ya está activa (patrón ya construido y en uso). No existe el equivalente para `OpcionMayorista`: si el mayorista elegido no puede honrar el precio o el cupo después de que la reserva ya fue aceptada, hoy no hay forma de reasignarlo en el sistema — se resuelve manualmente, fuera del sistema. Es un caso real confirmado por el negocio, no hipotético (afecta principalmente destinos internacionales).

Diseño completo y ya validado en `auditoria-arquitectonica-agencia-viajes.md` §9.2. Este brief solo lo traduce a implementación. No se está pidiendo diseñar nada nuevo — se está pidiendo construir lo ya decidido.

## Qué construir

### 1. Migración — columnas nuevas en `reserva_items`

```php
Schema::table('reserva_items', function (Blueprint $table) {
    $table->unsignedBigInteger('opcion_mayorista_original_id')->nullable()->after('opcion_mayorista_id');
    $table->text('motivo_reasignacion_mayorista')->nullable();
    $table->timestamp('fecha_reasignacion_mayorista')->nullable();
    $table->unsignedInteger('veces_reasignado_mayorista')->default(0);

    $table->foreign('opcion_mayorista_original_id')->references('id')->on('opcion_mayorista');
});
```

`opcion_mayorista_id` en `ReservaItem` ya existe — el cambio es de comportamiento, no de schema: hoy es de solo lectura tras `Alternativa.estado='aceptada'`, pasa a ser editable mediante el flujo de este brief únicamente (no vía update genérico del modelo).

### 2. Backend — `ReservaController::reasignarMayorista()`

Mismo patrón que el método `reprogramar()` ya existente (buscarlo y replicar su estructura: validación, transacción, auditoría, respuesta). Reglas de negocio exactas:

- Recibe: `reserva_item_ids[]` (los ítems que se mueven juntos — el paquete y sus opcionales vinculados al mismo mayorista), `nueva_opcion_mayorista_id`, `motivo` (obligatorio, string no vacío).
- Valida que todos los `reserva_item_ids` pertenezcan a la misma reserva y que su `opcion_mayorista_id` actual coincida (no se puede reasignar un lote mezclado de mayoristas distintos en una sola operación).
- Valida que `nueva_opcion_mayorista_id` pertenezca al mismo `alternativa_destino_id` que la opción actual (no se puede reasignar a un mayorista de otro destino).
- Por cada `ReservaItem` del lote, dentro de una transacción:
  - Si `opcion_mayorista_original_id` es null, guardarlo ahora con el valor actual de `opcion_mayorista_id` (solo se guarda una vez, nunca se sobreescribe en reasignaciones posteriores).
  - Actualizar `opcion_mayorista_id` al nuevo valor.
  - Actualizar `motivo_reasignacion_mayorista`, `fecha_reasignacion_mayorista = now()`.
  - Incrementar `veces_reasignado_mayorista`.
- **No tocar `precio_venta_snapshot` en ningún caso** — ni del `ReservaItem` ni de la `Reserva`. Este método no ajusta precios, solo referencia de mayorista. Si el negocio decide ajustar el precio del cliente, es una acción manual aparte desde Facturación, fuera del alcance de este método.
- Devuelve en la respuesta el costo anterior y el nuevo costo (`OpcionHotelTarifa`/`OpcionMayorista` del origen y destino) para que el frontend muestre la diferencia — el cálculo de diferencia es de presentación, no se persiste como campo.

### 3. Frontend — botón y modal en el detalle de reserva

Referencia visual exacta: mockup `ReasignarMayorista.dc.html` del Artifact "Cotizador Multidestino" (pedir el link al usuario si no se tiene a mano). Mismo componente/estilo que el modal "Reprogramar viaje" ya existente (reusar el componente base, no crear uno nuevo desde cero — plantilla Rizz).

- Botón "⇄ Reasignar mayorista" en el detalle de reserva, mismo nivel jerárquico que "Reprogramar viaje". Visible solo si `reserva.items` tiene al menos un ítem con `origen_tipo='mayorista'`.
- Modal con: bloque de estado actual (mayorista + fecha de elección), select/textarea de motivo (obligatorio), checklist de ítems afectados (pre-marcados los que comparten el mayorista actual), select de nueva opción de mayorista (filtrado al mismo `alternativa_destino_id`), y un bloque de diferencia de costo siempre visible (rojo/ámbar si sube, verde si baja) con el texto explícito de que el precio del cliente no cambia automáticamente.
- En el resumen de la reserva (sidebar), agregar el badge "reasignado N veces" cuando `veces_reasignado_mayorista > 0` (mismo patrón visual que ya se use para `fecha_reprogramacion` si existe un indicador equivalente).

### 4. Vouchers / documentos del cliente

Si el nuevo mayorista implica cambio de hotel o vuelo (información pública, no la identidad del mayorista), los vouchers/documentos que se generan para el cliente deben reflejar el dato actualizado (hotel/vuelo), nunca el nombre del mayorista — mismo principio ya vigente en el PDF comercial (§9 de la auditoría: el mayorista nunca se imprime). Verificar contra el generador de vouchers real cuál campo lee hoy (probablemente ya lee de `ReservaItem`/`OpcionHotel` en vivo, y este cambio no debería requerir tocarlo — confirmar, no asumir).

## Explícitamente fuera de alcance de esta sesión

- `SalidaMayorista` / control de cupo: no descontar ni liberar cupo en la reasignación — esa tabla no tiene todavía un punto de escritura real (§9 de la auditoría). Si se construye más adelante, la integración con este flujo es una sesión aparte.
- Ajuste de `precio_venta_snapshot` del cliente: nunca ocurre automáticamente en este flujo, bajo ninguna condición.
- Reasignación de mayorista a nivel de `Alternativa` (antes de aceptar) — eso ya funciona distinto (se resuelve editando la cotización, no aplica este método).

## Checklist de verificación

- [ ] Migración corre limpio en una copia de producción, sin pérdida de filas.
- [ ] `reasignarMayorista()` rechaza lotes con `reserva_item_ids` de mayoristas distintos.
- [ ] `reasignarMayorista()` rechaza `nueva_opcion_mayorista_id` de un `alternativa_destino_id` distinto al actual.
- [ ] `opcion_mayorista_original_id` se escribe solo la primera vez; una segunda reasignación no lo sobreescribe (test explícito con 2 reasignaciones seguidas).
- [ ] `precio_venta_snapshot` (ítem y reserva) permanece exactamente igual antes y después de reasignar, con costos de mayorista distintos (test de regresión explícito).
- [ ] El botón no aparece en reservas sin ítems `origen_tipo='mayorista'`.
- [ ] El PDF/voucher del cliente nunca imprime el nombre ni identificador del mayorista, antes y después de una reasignación (test de regresión explícito, mismo que ya debería existir para §9 — extenderlo, no reemplazarlo).
- [ ] Badge "reasignado N veces" se actualiza correctamente tras cada reasignación.
