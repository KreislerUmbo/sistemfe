# T-131 — Salidas operativas vencidas: avisar y pasarlas a «Realizada»

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — (la regla de estados de salida vive en `plan-tours-venta-simple-costo-real.md` §3.6) |
| **Decisiones** | — |
| **Rama** | `feat/T-131-salidas-vencidas-realizada` |

## Contexto
En el recorrido de la fase 2 (23-nov-2026) las 7 salidas de agencia-demo eran de noviembre, ya
pasadas, y seguían «Programada». Compras compara el costo real contra salidas **terminadas**; si
nada las cierra, la rentabilidad (fase 4) y el filtro «realizadas» no sirven.

## Alcance
- Decidir con el usuario **cómo** pasa a «Realizada»: (a) botón manual «Marcar realizada» en el tablero y el detalle, (b) automático al día siguiente de `fecha`, o (c) aviso «ya pasó, ¿se realizó?» y botón. **Recomendación: (c)**: ninguna salida cambia de estado sola (puede haberse cancelado sin avisar).
- Resaltar en el tablero la salida pasada que sigue Programada/Confirmada.
- Que el cambio a «Realizada» no mueva dinero ni facture nada.

## Fuera de alcance
- Rentabilidad y costo real (fase 4). Cerrar reservas o servicios por esta vía.

## Dónde tocar
- `api-sistema-fe` → `SalidaOperativa` (estados) y `SalidaOperativaController` (acción de estado; ya existe «Confirmar» y «Cancelar»).
- `admin-start-kit/src/views/agencia-viajes/salidas/index.vue` → fila del tablero y modal de detalle.

## Criterios de aceptación
- [ ] **CA1** — Una salida con fecha anterior a `HoraPeru::hoyTexto()` y estado Programada/Confirmada se ve marcada como «vencida» en el tablero.
- [ ] **CA2** — Se puede pasar a Realizada; una Cancelada no.
- [ ] **CA3** — Una salida futura no se puede marcar Realizada.

## Seguridad y correctitud
- [ ] Permisos / Policy: la acción usa el mismo permiso que confirmar salida.
- [ ] Fechas con `HoraPeru`: comparar contra `hoyTexto()`, nunca `now()`.
- [ ] Aislamiento de tenant, entrada, concurrencia, dinero, tributario, `v-html`, migraciones: no aplica.

## Cómo verificar
```
php artisan test --filter=SalidaOperativa
```
Pantalla: `/agencia-viajes/salidas` en agencia-demo (las 7 salidas de noviembre).

---

## Resultado
- **Commits:**
- **Qué se hizo:**
  - Backend: `cambiarEstado()` permite programada → realizada (la fecha futura sigue dando 422);
    scope `SalidaOperativa::vencidas()` (en curso y `fecha < HoraPeru::hoyTexto()`); `index()`
    acepta `vencidas=1` (lista solo esas) y devuelve siempre `vencidas` (el total, sin importar el filtro).
  - Tablero: fila en rojo y marca «Vencida» (también en el detalle), botón «Marcar realizada»;
    aviso de una línea «Hay N salidas con la fecha pasada…» con «Ver vencidas», que bloquea fechas
    y estado hasta «Volver al tablero». `recargarDetalle()` usa los mismos filtros que el listado.
- **Tests:** `php artisan test --filter="Fila13Fase2aSalidasTest|SalidaOperativa"` → 30 pasan
  (nuevos: vencida programada → realizada directo y cancelada no; el listado cuenta y filtra las
  vencidas sin incluir las de hoy ni las realizadas). `type-check` y `build` en verde.
  Suite completa (25-nov): 2463 pasan y 1 falla, `T044AvisoGuiaChoferTest::el_listado_lee_cada_fecha_una_sola_vez`,
  que contaba también el `count(*)` de vencidas (una consulta por pedido, no por fila). Se ajustó su
  filtro para no contar `count(*)`; los 44 tests de salidas y T-044 pasan.
- **Prueba en pantalla (25-nov, Playwright, agencia-demo):** el tablero arranca en «hoy» con 0 salidas
  y el aviso «Hay 7 salidas con la fecha pasada…»; «Ver vencidas» lista las 7 (05/11 a 16/11) en rojo con
  «Vencida» y bloquea fechas y estado; «Marcar realizada» en la del 16/11 (id 54) la pasa a Realizada,
  quedan 6 y no hay movimientos de caja; «Volver al tablero» restablece el filtro. La salida 54 se
  devolvió a Programada con tinker.
- **Desviaciones de la tarea y por qué:**
  - Se tomó la opción (c) recomendada sin consultar. Sin cambios de BD.
  - El aviso «Ver vencidas» se agregó en la revisión (25-nov, decisión del usuario): el filtro
    del tablero arranca en «hoy» y sin él las vencidas no se veían (CA1).
