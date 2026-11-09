# T-099 — Agencia: correr en producción los comandos de una sola vez y retirarlos

| | |
|---|---|
| **Módulo** | AGE + PLA |
| **Depende de** | — (los 5 comandos ya están desplegados desde `e9d5e02`) |
| **Reglas** | — |
| **Decisiones** | — |
| **Rama** | `refactor/T-099-retirar-comandos-agencia` (solo la parte 2) |

## Contexto
Salió de T-096 el 09-nov-2026. La auditoría propuso borrar 5 comandos de una sola vez, pero el
usuario confirmó que **no los corrió en producción**. En producción el único tenant de Agencia es
**dakamu**. Los comandos que escriben datos no se pueden borrar hasta haber corrido.

| Comando | Qué hace | Destino |
|---|---|---|
| `agencia-viajes:separar-grupos-duplicados` (T-024) | Escribe; registra en `ajustes_grupo_opcion` | Correr (`--simular` primero) y retirar |
| `agencia-viajes:plantillas-a-tarifa-vigente` (T-028) | Escribe; registra en `ajustes_plantilla_tarifa` | Correr (`--simular` primero) y retirar |
| `permisos:backfill-fase1b-agencia-viajes {tenant}` | Escribe: solo **agrega** roles y permisos, nunca quita; sin `--simular` | Revisar los roles, correr y retirar |
| `agencia-viajes:diagnosticar-fechas-reserva` | Solo lectura | Retirar (la migración que alimentaba ya pasó) |
| `agencia-viajes:diagnosticar-tours` | Solo lectura | **Conservar**: el cierre de T-088 pide correrlo en producción |

## Alcance
1. **En el servidor (lo hace el usuario):** por cada comando que escribe, contar las filas de registro
   antes, correrlo en `--simular`, revisar, correrlo de verdad y contar después. Para el backfill,
   listar los roles de dakamu antes y después. Anotar las salidas en «Resultado».
2. **En el código (después de la parte 1):** borrar los 4 comandos que se retiran, sus servicios
   (`SepararGruposDuplicadosService`, `PlantillasATarifaVigenteService`) si nadie más los usa, y
   sus tests. Las tablas `ajustes_*` se conservan como registro.

## Fuera de alcance
- `agencia-viajes:diagnosticar-tours` y `DiagnosticoToursService`.
- Borrar las tablas `ajustes_*` (T-083).

## Criterios de aceptación
- [ ] **CA1** — En «Resultado» quedan las filas `ajustes_*` de dakamu antes y después, y los roles
  que el backfill tocó.
- [ ] **CA2** — `php artisan list` no muestra los 4 comandos retirados; `diagnosticar-tours` sigue.
- [ ] **CA3** — `grep` sin usos de los servicios borrados; la suite de Agencia pasa.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica
- [ ] Aislamiento de tenant: los comandos corren por tenant (`--tenant=dakamu`)
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan list | grep -E "agencia-viajes|backfill-fase1b"
php artisan test --filter=AgenciaViajes
```

---

## Resultado
- **Salidas del servidor (dakamu, 09-nov-2026, `e9d5e02`):** respaldo previo
  `/tmp/tenantdakamu-antes-T099.dump` (1.4 MB), sin usar.
  - `ajustes_grupo_opcion`: 0 y `ajustes_plantilla_tarifa`: 0 (nunca corrieron).
  - `separar-grupos-duplicados --simular`: «separaría 0 grupo(s) compartido(s)».
  - `plantillas-a-tarifa-vigente --simular`: «reapuntaría 0 ítem(s) de tour; 0 para revisar a mano».
  - Backfill fase 1b: a los 4 roles del catálogo no les falta ningún permiso (incluidos los de
    T-078), y el único rol ajeno con el permiso plano (`Admin Empresario`) ya tiene los 8 granulares.
  - Ninguno se corrió de verdad: no había nada que cambiar. Las tablas `ajustes_*` siguen en 0.
- **Commits:** ver la rama `refactor/T-099-retirar-comandos-agencia`.
- **Tests:** `php artisan test --filter=AgenciaViajes`: 1160 pasan, 1 falla (el intermitente
  `Fila13Fase1cFacturacionTest::descuento_del_combo…`; solo pasa). `php artisan list`: solo quedan
  `diagnosticar-tours` y `limpiar-operacion`.
- **Desviaciones de la tarea y por qué:**
  - De `T024GrupoHotelesIndependienteTest` solo sale `test_ca4` (probaba el servicio); los otros 4
    tests son del arreglo de T-024 y se quedan. `T028PlantillasATarifaVigenteTest` sale entero (solo
    probaba el servicio).
  - Para sincronizar roles de Agencia en un tenant existente, de ahora en adelante:
    `php artisan roles:sync-permisos-nuevos 'Database\Seeders\AgenciaViajesRolesSeeder' --tenant=<id>`.

## Hallazgos fuera de alcance
- `docs/tareas/` todavía tiene los archivos de T-024, T-028 y T-078, que están hechas (el cierre
  manda borrarlos), y dicen que hay que correr los comandos retirados.
- dakamu no tiene rol Propietario (último despliegue anterior a `RolPropietario`); ver T-078.
