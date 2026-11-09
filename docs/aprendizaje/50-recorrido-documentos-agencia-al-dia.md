# Recorrido: dónde vive cada cosa en los documentos de Agencia

09-nov-2026, tarea **T-097**. Salió de la auditoría del 09-nov: el mapa del vertical mandaba a
buscar el estado en una hoja de ruta que ya no se mantenía.

---

## 1. El problema

`docs/proceso.md` §1 dice que el estado ("pendiente", "hecho", "en curso") vive **solo** en
`docs/backlog.md`. Pero Agencia nació antes de esa regla (24-oct-2026), cuando el estado se
llevaba en `plan-hoja-de-ruta-ejecucion.md` con casillas `[x]`. Dos documentos de entrada seguían
apuntando ahí, y esa hoja tenía marcas congeladas desde oct-2026: quien la leía veía un estado
viejo como si fuera el actual.

## 2. Dónde está cada cosa ahora

| Busco… | Voy a… |
|---|---|
| Qué falta, qué está en curso, qué se hizo | `docs/backlog.md` |
| Qué hace cada módulo de Agencia y en qué archivos vive | `docs/planning/agencia-de-viajes/00-mapa-de-modulos.md` |
| Requisitos de diseño vigentes | los `plan-*.md` que el mapa marca "vigente" |
| Historia de las sesiones viejas (0 a F5, 12a-12h, M1-M5) | `docs/archivo/agencia-de-viajes/plan-hoja-de-ruta-ejecucion.md` |
| Prompts ya ejecutados y el plan de tramos superado | `docs/archivo/agencia-de-viajes/` |
| Qué se hizo y por qué, con detalle | `git log` |

## 3. Archivos

| Archivo | Qué pasó |
|---|---|
| `plan-hoja-de-ruta-ejecucion.md` | Movido entero a `docs/archivo/agencia-de-viajes/`, con un aviso arriba. Todo su contenido era avance e historial |
| 4 `PEGAR-EN-CLAUDE-CODE-*.md`, `plan-refactor-mayoristas-tramos.md` | Movidos a `docs/archivo/agencia-de-viajes/` |
| `00-mapa-de-modulos.md`, `docs/planning/INDICE.md` | La línea de estado enlaza al backlog; la hoja figura como archivo |
| `auditoria-arquitectonica-agencia-viajes.md` §1 | Problema 3 (descuento global en alternativa aceptada) anotado como resuelto |
| ~60 menciones en `docs/` | Ruta vieja → ruta en `docs/archivo/` |
| 13 archivos de `docs/tareas/` | Borrados: eran tareas ya hechas (el cierre manda borrarlas) |

## 4. El recorrido

### 4.1 Antes de archivar, rescatar lo pendiente

Archivar no es borrar información, pero sí la saca del camino de quien planifica. Por eso, antes
de mover la hoja de ruta se buscó cada "pendiente" que tenía y se comprobó que estuviera en el
backlog. Faltaban las fases 4 y 5 de la fila 13: se sumaron a la línea de "Pendientes de diseño".

Lo mismo con las tareas borradas: antes de borrar cada `docs/tareas/T-…`, se comparó su sección
"Hallazgos" con el backlog. Los que faltaban pasaron a "Por analizar".

### 4.2 Mover sin romper enlaces

Un archivo movido rompe todo lo que lo cita por su ruta. La búsqueda:

```bash
grep -rn "planning/agencia-de-viajes/plan-hoja-de-ruta-ejecucion" docs CLAUDE.md
```

y después una sustitución de ruta en cada resultado. `git mv` (o un movimiento que git detecta
como renombre) conserva la historia del archivo: `git log --follow` la sigue encontrando.

### 4.3 La trampa: cambiar la ruta no cambia lo que la frase dice

La sustitución arregló los enlaces, pero `INDICE.md` seguía diciendo de la hoja: «**fuente de
verdad del estado** … consultar siempre antes de abrir una sesión nueva». El enlace era correcto;
la frase no. Se vio recién en la revisión.

Lección: cuando se archiva un documento que "mandaba", además de las rutas hay que buscar las
frases que le dan autoridad y reescribirlas:

```bash
grep -rniE "fuente de verdad|punto de entrada|consultar siempre" docs/
```

## 5. Cómo se verificó

- La hoja de ruta ya no está en `docs/planning/` (criterio 1).
- Ningún enlace de `docs/` ni de `CLAUDE.md` apunta a la ruta vieja de un archivo movido (criterio 2).
- Cada archivo de `docs/tareas/` corresponde a una tarea que no está en "Hechas" (criterio 4).

## 6. Lo que queda fuera

- Comentarios del código que citan documentos movidos o borrados, y `sincronizacion.md` (que
  explica cómo mantener la hoja archivada): "Por analizar" del backlog.
