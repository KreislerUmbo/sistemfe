# T-097 — Documentos de Agencia al día: un solo lugar para el estado y planes viejos archivados

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | `docs/proceso.md` §1 (el estado vive solo en `backlog.md`) |
| **Decisiones** | — |
| **Rama** | `docs/T-097-documentos-agencia` |

## Contexto
La auditoría del 09-nov-2026 encontró que `00-mapa-de-modulos.md` manda a buscar el estado en
`plan-hoja-de-ruta-ejecucion.md`, que todavía tiene marcas de avance con cabecera del 01-sep:
contradice el proceso. Hay además planes que ya no aplican mezclados con los vigentes.

## Alcance
1. `00-mapa-de-modulos.md`: la línea «Estado vigente de cada fila» pasa a enlazar `docs/backlog.md`.
2. `plan-hoja-de-ruta-ejecucion.md`: quitar las marcas de estado (`[x]`, «pendiente», «cerrada»,
   «sin pushear»…) o, si ya no sirve como hoja de ruta, moverlo a `docs/archivo/` y dejar en su
   lugar el enlace al backlog. Antes de quitar algo, verificar que lo que está «pendiente» ahí está
   en el backlog; si falta, sumarlo a «Por analizar».
3. Mover a `docs/archivo/agencia-de-viajes/`: los 4 `PEGAR-EN-CLAUDE-CODE-*.md` (prompts de un solo
   uso) y `plan-refactor-mayoristas-tramos.md` (superado).
4. `auditoria-arquitectonica-agencia-viajes.md` §1: el problema 3 (descuento global sobre una
   alternativa aceptada) está resuelto (T-030); anotarlo con el commit, sin reescribir el resto.
5. Actualizar los enlaces que apunten a los archivos movidos (`grep -rn` en `docs/`, `CLAUDE.md` y comentarios del código).
6. **Tareas hechas que no se borraron** (hallazgo de T-099): `docs/tareas/T-024-…`, `T-028-…` y
   `T-078-…` están en «Hechas» del backlog pero su archivo sigue, y piden correr comandos que T-099
   retiró. Antes de borrar, verificar que su fila de «Hechas» resume lo esencial y que sus
   «Hallazgos» están en el backlog; si falta algo, sumarlo a «Por analizar». Revisar si hay otras
   tareas hechas con archivo (comparar `ls docs/tareas/` con la sección «Hechas»).

## Fuera de alcance
- Reescribir los planes vigentes.
- Comentarios del código (T-094/T-095/T-096).

## Dónde tocar
- `docs/planning/agencia-de-viajes/00-mapa-de-modulos.md` (cabecera)
- `docs/planning/agencia-de-viajes/plan-hoja-de-ruta-ejecucion.md`
- `docs/planning/agencia-de-viajes/PEGAR-EN-CLAUDE-CODE-*.md`, `plan-refactor-mayoristas-tramos.md`
- `docs/planning/agencia-de-viajes/auditoria-arquitectonica-agencia-viajes.md` §1

## Criterios de aceptación
- [ ] **CA1** — `grep -rniE "pendiente|en curso|\[x\]" docs/planning/agencia-de-viajes/plan-hoja-de-ruta-ejecucion.md` no devuelve marcas de estado (o el archivo está en `docs/archivo/`).
- [ ] **CA2** — Ningún enlace de `docs/` ni de `CLAUDE.md` apunta a un archivo movido.
- [ ] **CA3** — Todo pendiente que estaba solo en la hoja de ruta quedó en el backlog.
- [ ] **CA4** — `docs/tareas/` solo tiene archivos de tareas que no están en «Hechas».

## Seguridad y correctitud
- [ ] Todo: no aplica (solo documentos)

## Cómo verificar
```
grep -rn "PEGAR-EN-CLAUDE-CODE\|plan-refactor-mayoristas-tramos" docs CLAUDE.md api-sistema-fe/app
```

---

## Resultado
- **Commits:** ver `git log docs/T-097-documentos-agencia`
- **Tests:** no aplica
- **Desviaciones de la tarea y por qué:**
  - `plan-hoja-de-ruta-ejecucion.md` se movió entero a `docs/archivo/agencia-de-viajes/` (segunda opción del alcance 2) con un aviso arriba: todo su contenido son marcas de avance e historial, y no quedaba nada que conservar como hoja de ruta. Sus pendientes ya estaban en el backlog (11f/11g → Compras; 12g, endoso, guía cotizado → «Por analizar»; F2 → tributario; F4 etapa 2 → T-007 hecha); solo faltaban las fases 4 y 5 de la fila 13, que se sumaron a esa misma línea. Se actualizaron ~60 menciones en `docs/` (planes, INDICE, requerimientos, recorridos, `CLAUDE-hasta-2026-10-29.md`) con una sustitución de ruta.
  - Problema 3 de la auditoría (§1): se anotó el commit `3d78656` (sesión 12a, 01-sep), no T-030 como decía la tarea: el guard del descuento global lo puso 12a; T-030 (`ffbdd4a`) cerró el cambio de estado y de mayorista. Las dos se mencionan.
  - Se borraron 13 archivos de tareas ya en «Hechas» (no solo los 3 de la lista): T-009, 010, 011, 014, 024, 027, 028, 033, 039, 050, 051, 078, 079. Sus hallazgos que faltaban en el backlog se sumaron a «Por analizar» (4 filas: guías, `PrecioPorPasajeroTest`, enviar cotización vs `view`, HMR de Vite). A las filas de T-024 y T-028 se les anotó que su comando fue retirado en T-099.
  - `docs/archivo/CLAUDE-hasta-2026-10-29.md` también cambió (solo la ruta del archivo movido).
  - `00-mapa-de-modulos.md`: la línea de estado enlaza `docs/backlog.md` y la hoja de ruta figura como archivo.
  - No se tocaron `docs/aprendizaje/18-…` ni `docs/manuales/` (del usuario, sin seguir).

## Hallazgos fuera de alcance
- Los comentarios del código aún nombran los archivos movidos (`PEGAR-EN-CLAUDE-CODE-*` en 14 archivos de `api-sistema-fe/app`, `admin-start-kit/src` y `central-panel/src`; `plan-hoja-de-ruta-ejecucion.md` en 3 de `AgenciaViajes`). El «Cómo verificar» de esta tarea los seguirá listando; quedan para T-094/T-095/T-096 (fuera de alcance).
- `sincronizacion.md` sigue describiendo cómo mantener al día la hoja de ruta, que ya es archivo; conviene archivarlo o reescribirlo junto con el resto de `docs/planning/agencia-de-viajes/`.
- (Revisión) Los dos hallazgos anteriores se pasaron a «Por analizar» del backlog: T-096 ya está hecha y T-094/T-095 no cubren esos archivos. También en la revisión: la fila de `INDICE.md` seguía llamando a la hoja archivada «fuente de verdad del estado»; se reescribió como archivo.
