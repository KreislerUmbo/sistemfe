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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
