# T-096 — Agencia: limpiar código sin uso y mensajes con voseo

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-092 |
| **Reglas** | — |
| **Decisiones** | — |
| **Rama** | `refactor/T-096-limpieza-agencia` |

## Contexto
La auditoría del 09-nov-2026 encontró código que ya nadie usa: comandos de una sola vez, métodos
de servicio del frontend sin llamadas y mensajes al usuario con voseo (la regla del proyecto es
tuteo). Las columnas y los modelos sin uso van en T-083, no acá.

## Alcance
1. **Comandos de una sola vez.** Antes de borrar, confirmar en **producción** que corrieron en cada
   tenant de Agencia (las tablas `ajustes_grupo_opcion` y `ajustes_plantilla_tarifa` registran lo
   que hicieron; el usuario no lo recuerda, supone que sí). Si corrieron, borrar el comando y su
   servicio; las tablas `ajustes_*` se conservan como registro. Comandos:
   `agencia-viajes:separar-grupos-duplicados`, `agencia-viajes:plantillas-a-tarifa-vigente`,
   `permisos:backfill-fase1b-agencia-viajes`, `agencia-viajes:diagnosticar-fechas-reserva`,
   `agencia-viajes:diagnosticar-tours`. Si alguno no corrió en algún tenant: parar y avisar.
2. **Métodos de servicio sin uso (frontend):** `opcionMayoristaService.listarHoteles/listarOpcionales/listarTours`,
   `salidaOperativaService.adjuntarItem/desengancharItem`, `destinoAtractivoService.listarPorTipo`,
   `temporadaService.listarOcurrencias`. Por cada uno, ver si su endpoint lo usa otra pantalla; si
   nadie, retirar método y ruta (y el método del controller si queda huérfano).
3. **Voseo → tuteo** en mensajes al usuario del vertical (backend y frontend, unos 19; p. ej.
   `DestinoServicioController.php:174` «No podés fusionar…», `AlternativaItemController` «mové todo el bloque»,
   `LimpiarOperacionAgencia` «Escribí el ID»). Buscar con
   `grep -rE "(mové|usá|elegí|escribí|cargá|revisá|tenés|podés|querés|guardá|completá|confirmá|seleccioná|ingresá) "`.

## Fuera de alcance
- Modelos `PagoProveedor`, `CronogramaPagoProveedor` (esperan a Compras) y `Recordatorio*` (11f).
- Recortar comentarios que narran historia: se hace al tocar cada archivo en T-094/T-095.

## Dónde tocar
- `api-sistema-fe/app/Console/Commands/{SepararGruposDuplicados,PlantillasATarifaVigente,BackfillFase1bAgenciaViajes,DiagnosticarFechasReserva,DiagnosticarTours}.php`
  y sus servicios (`SepararGruposDuplicadosService`, `PlantillasATarifaVigenteService`, `DiagnosticoToursService`) y tests
- `admin-start-kit/src/services/admin/{opcionMayoristaService,salidaOperativaService,destinoAtractivoService,temporadaService}.ts`

## Criterios de aceptación
- [ ] **CA1** — Queda escrito en «Resultado» cuántas filas `ajustes_*` hay por tenant en producción antes de borrar.
- [ ] **CA2** — `php artisan list` no muestra los comandos retirados; la suite pasa.
- [ ] **CA3** — Ningún método retirado tiene llamadas (`grep`); `type-check` y `build` en verde.
- [ ] **CA4** — El `grep` de voseo del alcance no devuelve nada en Agencia.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan list | grep agencia-viajes
php artisan test --filter=AgenciaViajes
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
