# T-003 — Calcular la diferencia entre lo vendido y la modificación

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-002 |
| **Reglas** | R-AGE-03, R-AGE-04, R-AGE-06 |
| **Decisiones** | D-AGE-031, D-AGE-032 |
| **Rama** | `feat/T-003-diferencia-modificacion` |

## Contexto
Con la modificación abierta (T-002), el vendedor ajusta la versión nueva en el cotizador.
Esta tarea calcula **qué cambia** respecto de lo vendido, línea por línea y pasajero por
pasajero, y si se puede aplicar o qué falta. Es la pieza que usan el panel de la pantalla
(T-007), el PDF (T-006) y el aplicar (T-005), que la recalcula bajo lock. No escribe nada en la
reserva.

## Alcance

**`ModificacionDiferenciaService::calcular(ReservaModificacion): DiferenciaModificacion`**
(un DTO con arreglos; todo en **centavos enteros**):

- **Servicios.** Para cada ítem de la versión nueva:
  - con `item_origen_id`: se compara contra el `reserva_item` que hoy apunta a ese origen;
  - sin origen: `agregado`.
  - Cada `reserva_item` cuyo ítem no tiene sucesor en la versión nueva: `quitado`.
- **Estado de cada línea:**
  - `igual`;
  - `cambio_precio` (vendido → nuevo);
  - `agregado`;
  - `quitado`;
  - `para_revisar`: el servicio no es por persona (`tarifa_fija`: habitación, transporte
    privado, mayorista, pasaje aéreo), cambió la cantidad de pasajeros que lo usan y su precio
    no cambió. Se confirma a mano (ver abajo);
  - `requiere_nota_credito`: está facturado y baja de precio o se quita;
  - `ajuste`: está facturado y sube de precio. Monto = la diferencia (D-AGE-032).
- **Facturado:** con `ReservaVenta::itemIdsFacturados()`. Una línea de tour con cualquier parte
  facturada (`ReservaVenta::itemsDeTourAMedias()`) cuenta como facturada. No reescribir esa
  lógica.
- **Pasajeros:**
  - `agregado`: copia sin `reserva_pasajero_id`;
  - `quitado`: un pasajero de la reserva sin copia en la modificación;
  - `igual`.
  - Un pasajero quitado que está facturado (`ReservaVenta::pasajeroIdsFacturados()`) es
    `requiere_nota_credito`. Uno no facturado necesita penalidad (la pone T-004; acá solo se
    marca `falta_penalidad`).
- **Totales:** vendido, nuevo, diferencia, ya facturado, anticipos aplicados.
- **Bloqueos** (R-AGE-06): lista de motivos con, por cada uno, a dónde lleva el aviso
  (`alternativa_item_id` del cotizador, `sale_id` del comprobante, `reserva_pasajero_id`).
  `se_puede_aplicar = bloqueos vacío`, y sin cambios también es "nada que aplicar".
- **`huella`:** un hash estable de la diferencia. T-005 lo compara para saber si cambió desde
  que el usuario la vio.

**Confirmar `para_revisar`:** columna `alternativa_items.revisado_en_modificacion`
(timestamp, nullable) y ruta `POST reservas/{id}/modificacion/items/{itemId}/revisado`. Si el
ítem cambia después de confirmarse, la confirmación se pierde (al guardar el ítem se limpia la
marca; verificar en qué endpoints del cotizador hace falta).

**Ruta:** `GET reservas/{id}/modificacion/diferencia`, con el mismo permiso y Policy que T-002.

**Nota de la revisión de T-002 (29-oct):** un servicio exclusivo de pasajeros que ya se
quitaron de la reserva llega a la copia con `pax_incluidos = []`, que en el sistema significa
"para todos" (en la reserva pasa lo mismo: `QuitarPasajeroReservaService` borra sus
`reserva_item_pasajeros` y el servicio queda sin asignar). La copia lo marca en
`items_para_revisar`. La diferencia no debe cobrarlo a todos: tratarlo a propósito (p. ej. una
línea `para_revisar` que bloquea aplicar hasta que el vendedor lo asigne o lo quite) y cubrirlo
con un test.

## Fuera de alcance
- Crear penalidades o ajustes (T-004/T-005); acá solo se informan.
- Aplicar o modificar la reserva.
- Pantallas.

## Archivos probables
- `api-sistema-fe/app/Services/AgenciaViajes/ModificacionDiferenciaService.php` (nuevo) + DTO
- `ReservaModificacionController` (de T-002)
- migración de `revisado_en_modificacion`
- `tests/Unit/...` o `tests/Feature/AgenciaViajes/ReservaModificacionDiferenciaTest.php`

## Criterios de aceptación
Cada caso de `docs/requerimientos/agencia-modificar-reserva.md` §2.3 que aplica a la
diferencia tiene su test:
- [ ] **CA1** (caso 1) — Se agrega un adulto a un tour por persona: el tour sale en
  `cambio_precio` por exactamente el precio de un adulto; el pasajero, en `agregado`.
- [ ] **CA2** (caso 2) — Se quita un pasajero no facturado: baja su parte y queda
  `falta_penalidad`; no se puede aplicar.
- [ ] **CA3** (caso 3) — Se quita un pasajero facturado: `requiere_nota_credito` con el
  `sale_id`.
- [ ] **CA4** (caso 4) — Un servicio nuevo sale `agregado`.
- [ ] **CA5** (caso 5) — Sube un servicio facturado: `ajuste` por la diferencia.
- [ ] **CA6** (caso 6) — Baja un servicio facturado: `requiere_nota_credito`.
- [ ] **CA7** (caso 7) — Una habitación con un pasajero menos y el mismo precio:
  `para_revisar`. Tras confirmar, deja de bloquear; si después se edita, vuelve a bloquear.
- [ ] **CA8** (caso 13) — Sin cambios: todo `igual`, diferencia 0, "nada que aplicar".
- [ ] **CA9** — Cuadre: en un tour repartido en 2 líneas de IGV, la suma de las diferencias por
  línea es exactamente nuevo − vendido, al céntimo, y la diferencia total coincide con la suma
  de las líneas.
- [ ] **CA10** — Dos cálculos seguidos sin cambios dan la misma `huella`; cualquier cambio de
  precio la cambia.
- [ ] **CA11** — Permisos: sin `reservas.modificar`, 403.

## Seguridad y correctitud
- [ ] Permisos / Policy: igual que T-002
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: `itemId` debe pertenecer a la alternativa nueva de **esta**
  modificación (si no, 404)
- [ ] Concurrencia: solo lectura; T-005 la recalcula bajo lock
- [ ] Dinero en centavos: todo el cálculo en enteros; convertir una sola vez al entrar
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: la línea `ajuste` lleva el `tip_afe_igv`/`destino_tributario` del servicio
  que ajusta
- [ ] `v-html`: no aplica
- [ ] Migraciones: una nueva

## Cómo verificar
```
php artisan test --filter=ReservaModificacionDiferencia
php artisan test
```

---

## Resultado
- **Commits:** ver `git log feat/T-003-diferencia-modificacion` (un commit de código + tests; el backlog y esta sección van en el mismo).
- **Tests:** `ReservaModificacionDiferenciaTest` 19 pasan (CA1–CA11 + servicio quitado, quitado facturado, 404/422 al confirmar, servicio de pasajero quitado, confirmación que se pierde al cambiar pasajeros). Suite completa: 1702 pasan, 6201 aserciones, contra PostgreSQL real.
- **Desviaciones de la tarea y por qué:**
  - `ya_facturado` = lo vendido de los servicios que están en alguna factura (misma base que `vendido`); no suma totales de `sales`, que tienen IGV y pueden estar en otra moneda. Un tour facturado a medias cuenta entero.
  - Nota de T-002 (pasajero quitado → `pax_incluidos = []`): la línea sale `para_revisar` con `sin_pasajeros = true`, no cobra a todos, bloquea con `servicio_sin_pasajeros` y **no** se puede confirmar con `/revisado` (422); se destraba asignándola o quitándola.
  - La marca `revisado_en_modificacion` se limpia en dos lugares: hook `updating` de `AlternativaItem` (todo guardado por el modelo) y `CotizacionController::actualizarPasajeros()` (cambio de pasajeros, que no toca los ítems). No hay otros guardados masivos de ítems que importen (`SepararGruposDuplicadosService` solo cambia `grupo_opcion_id`).
  - Se agregó `hay_cambios`; `se_puede_aplicar = hay_cambios && sin bloqueos`.
  - `falta_penalidad` se marca siempre para un pasajero no facturado quitado hasta que T-004 cree penalidades.
  - Antes de correr los tests hay que aplicar la migración nueva a `sistemafe_test_migrations`; en producción, `tenants:migrate-verticales`.

## Hallazgos fuera de alcance
- Modificaciones encadenadas: tras aplicar una, un servicio facturado sigue apuntando a la versión anterior y D-AGE-032 crea un "Ajuste" en la versión nueva; la siguiente modificación copiaría el servicio y el ajuste, y se contaría doble. La diferencia sube por `item_origen_id` para encontrar el servicio de la reserva, pero el doble conteo es de diseño de T-005.
- Un hotel o habitación (`tarifa_fija`, para todos) queda `para_revisar` apenas se agrega un pasajero; es lo pedido, pero T-007 debería permitir confirmarlos en bloque.
