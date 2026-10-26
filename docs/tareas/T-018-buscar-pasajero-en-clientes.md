# T-018 — "Buscar pasajero" también busca en Clientes

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-017 (comparte la vinculación catálogo ↔ cliente) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 5 |
| **Rama** | `feat/T-018-buscar-pasajero-clientes` |

## Contexto
`ReservaPasajeroController::buscarCatalogo()` solo busca en `pasajeros_catalogo` (7 en
agencia-demo, contra 18 clientes). El vínculo `pasajeros_catalogo.cliente_id` existe pero no se usa.

## Alcance
- `buscarCatalogo()` devuelve resultados de **pasajeros y clientes persona** a la vez, cada uno con
  `origen` (`pasajero`/`cliente`). Un cliente que ya tiene ficha vinculada sale una sola vez (como
  pasajero). Clientes RUC y sin documento real (`00000000`) no salen.
- Al elegir un resultado de origen `cliente`, se resuelve su ficha con el mismo método de T-017
  (`PasajeroCatalogoService::desdeCliente()`): se reusa o se crea vinculada, sin duplicar.
- Frontend (`reservas/detalle.vue`, buscador de pasajero): etiqueta "Pasajero" / "Cliente" en cada
  resultado. Si no aparece nada, sigue como hoy (se escribe a mano y queda en el catálogo).

## Fuera de alcance
- Clientes empresa como pasajeros.
- Fusionar fichas duplicadas ya existentes.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php`
- `api-sistema-fe/app/Services/AgenciaViajes/PasajeroCatalogoService.php`
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue`

## Criterios de aceptación
- [ ] **CA1** — Buscar por el DNI de un cliente que no es pasajero → sale con origen `cliente`. → test
- [ ] **CA2** — Elegirlo crea la ficha con `cliente_id` y la asigna al pasajero de la reserva. → test
- [ ] **CA3** — Buscar de nuevo → sale una sola vez, como `pasajero`. → test
- [ ] **CA4** — Cliente RUC o con `00000000` → no sale. → test
- [ ] **CA5** — Búsqueda por nombre parcial sin distinguir mayúsculas encuentra en las dos tablas. → test
- [ ] **CA6** — Prueba funcional en `agencia-demo`.

## Seguridad y correctitud
- [ ] Permisos: la ruta existente ya tiene su permiso; elegir un cliente no da acceso a más datos
  del cliente que nombre y documento
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: largo mínimo del texto buscado (como hoy); límite de resultados
- [ ] Concurrencia: crear la ficha con índice o búsqueda previa bajo transacción (doble clic no duplica)
- [ ] Dinero: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=BuscarCatalogo
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
