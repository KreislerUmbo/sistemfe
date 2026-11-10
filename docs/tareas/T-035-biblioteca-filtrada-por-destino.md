# T-035 — Biblioteca del cotizador filtrada por el destino activo (R3)

| | |
|---|---|
| **Módulo** | AGE (frontend) |
| **Depende de** | ninguna (combina con T-032, que agrega el indicador de carga) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 9, R3 (aprobada 26-oct-2026, con el caso de varios destinos) |
| **Rama** | `feat/T-035-biblioteca-por-destino` |

## Contexto
Medido (obs. 9): la biblioteca abre sin filtro: para una cotización a Cusco muestra Alto Mayo y
Lamas. En Local/Nacional, 6 de 12 tours visibles son "Solo itinerario (mayorista)" o
internacionales y no se pueden cargar ahí.

## Alcance
- Al abrir la biblioteca, `filtroDestinoId` se prellena con el `destino_atractivo_id` del **destino
  activo** de la alternativa (`destinoAtractivoIdActivo`). Con varios destinos, el del chip
  seleccionado (es adonde va lo que se agregue). Si el destino no tiene `destino_atractivo_id`
  (escrito a mano), sin filtro.
- El backend ya incluye los lugares hijos (`DestinoAtractivo::idsConDescendientes()`): confirmar.
- Encima de la lista: "Mostrando: {destino} ✕". La ✕ quita el filtro. Si el vendedor cambia el
  filtro a mano, se respeta mientras la biblioteca siga abierta (no se reimpone en cada búsqueda);
  al volver a abrirla, vuelve al destino activo.
- Cambiar de chip de destino con la biblioteca cerrada → la próxima vez abre con el nuevo destino.
- En la pestaña "Servicios de la agencia" (antes "Local / Nacional") no se listan tours con `uso = 'solo_itinerario'` ni los de ámbito
  internacional (revisar el campo que lo indica en `paquetes_plantilla`), en el backend
  (`BibliotecaCotizadorController`) para que la paginación cuente bien.

## Fuera de alcance
- Cambiar el árbol de destinos o la forma de buscar.

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/BibliotecaCotizadorController.php`

## Criterios de aceptación
- [ ] **CA1** — Cotización a Cusco: la biblioteca abre mostrando solo Cusco y sus lugares, con "Mostrando: Cusco ✕". → prueba funcional
- [ ] **CA2** — Con Lima y Cusco, chip Lima activo → abre con Lima. → prueba funcional
- [ ] **CA3** — ✕ → muestra todo; buscar después no vuelve a filtrar. → prueba funcional
- [ ] **CA4** — "Servicios de la agencia" no lista tours "solo itinerario" ni internacionales. → test del endpoint
- [ ] **CA5** — `type-check` y `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: no aplica
- [ ] Concurrencia: no aplica
- [ ] Dinero: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=Biblioteca
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:** en la rama `feat/T-035-biblioteca-por-destino` (ver `git log`).
- **Qué se hizo:** `BibliotecaCotizadorController::buscarPaquetes()` excluye `uso = 'solo_itinerario'` y
  `categoria = 'internacional'` en la consulta. `cotizador/editar.vue`: un `watch(drawerBibliotecaAbierto)`
  prellena `filtroDestinoId` con el `destino_atractivo_id` del chip activo (sin catálogo → sin filtro) cada
  vez que se abre; banda "Mostrando: {destino} y sus lugares ✕" (`data-test="biblioteca-filtro-destino"`).
  El backend ya incluía los lugares hijos (`idsConDescendientes`; confirmado con el test existente y en pantalla).
- **Tests:** `php artisan test --filter=BibliotecaCotizadorFiltros` → 6 pasan (nuevo:
  `test_no_lista_tours_solo_itinerario_ni_internacionales`, CA4, con `tipo=todos` y `tipo=tour`).
  `vue-tsc`: 47 errores, **los mismos 47 antes y después** del cambio (preexistentes, p. ej.
  `helpers/fake-backend.ts`; ninguno en `editar.vue`), por eso `npm run build` (que encadena `vue-tsc`) no
  pasa en este árbol; `vite build` (`build-only`) compila sin errores. No corrí la suite completa del backend.
- **Prueba funcional (Playwright, agencia-demo, login `/admin/login`; script con `playwright-core` porque
  el MCP de Playwright estaba tomado por otra instancia):**
  - CA1 — cotización 89 (Cusco): "Agregar servicio" abre con `destino_atractivo_id=1`, banda "Mostrando: Cusco y
    sus lugares" y solo los tours de Cusco y de un lugar hijo (Mirador de Taray); sin Alto Mayo ni Lamas. Para
    esto creé 3 tours temporales (Cusco, Taray, Lima), ya borrados: el demo solo tenía en Cusco tours "solo itinerario".
  - CA2 — cotización 90 (chips Tarapoto y Lamas): abre con Tarapoto (`destino_atractivo_id=6`); cerrada, chip
    Lamas → abre con Lamas (`=9`); de vuelta al chip Tarapoto → Tarapoto.
  - CA3 — ✕: la banda desaparece, la petición sale sin `destino_atractivo_id` (12 resultados de todos los
    destinos); escribir "Tour" en la búsqueda no reimpone el filtro; al cerrar y reabrir vuelve a Cusco.
  - CA4 — en pantalla, la búsqueda sin filtro no devuelve los tours "solo itinerario" ni los internacionales del demo.
- **Desviaciones de la tarea y por qué:** ninguna. La banda dice "y sus lugares" además del nombre.

## Hallazgos fuera de alcance
- `BibliotecaCotizadorController` también lo usa `venta-directa.vue` (tours y paquetes): ahora tampoco recibe
  solo-itinerario ni internacionales del backend. Ya filtraba `solo_itinerario` en el cliente; lo internacional
  es nuevo ahí. Si Venta directa debe poder vender un tour internacional, hay que pasar un parámetro.
- En modo clásico (`modo_tours != 'nuevo'`) el backend de `desdePlantilla` no rechaza `solo_itinerario`; ahora
  la biblioteca igual no los ofrece. No se tocó.
- `npm run type-check` tiene 47 errores preexistentes (`fake-backend.ts` y otros): sin limpiarlos,
  `npm run build` no puede ser criterio de aceptación (CA5 solo se cumple con `build-only`).
