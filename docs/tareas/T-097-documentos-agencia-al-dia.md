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

## Seguridad y correctitud
- [ ] Todo: no aplica (solo documentos)

## Cómo verificar
```
grep -rn "PEGAR-EN-CLAUDE-CODE\|plan-refactor-mayoristas-tramos" docs CLAUDE.md api-sistema-fe/app
```

---

## Resultado
- **Commits:**
- **Tests:** no aplica
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
