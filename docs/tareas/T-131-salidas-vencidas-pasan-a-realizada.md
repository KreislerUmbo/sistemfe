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
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
