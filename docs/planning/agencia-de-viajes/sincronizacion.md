---
name: sincronizacion
description: Protocolo para mantener sincronizados el repositorio git (donde trabaja Claude Code), el Proyecto de claude.ai (esta base de conocimiento) y las sesiones de planificación (Cowork/chat). Leer cuando haya dudas sobre qué versión de un documento es la vigente o cómo actualizar el Proyecto tras una sesión de Claude Code.
---

# Sincronización: Git ↔ Proyecto de claude.ai ↔ Sesiones de planificación

> Creado 18-ago-2026 a partir de una duda real del usuario: las sesiones
> de planificación (acá) y Claude Code (en el repo) se estaban
> desincronizando porque no existía ningún mecanismo que empujara los
> cambios de un lado al otro. Repo local: `C:\xampp\htdocs\sistemfe`
> (Windows, dispositivo "umbosystem") — `admin-start-kit` (frontend
> panel/POS), `api-sistema-fe` (backend Laravel), `central-panel`
> (frontend del panel superadmin), `docs/planning/` (los documentos que
> este Proyecto espeja) + `CLAUDE.md`/`TODO.md` en la raíz del repo.

## 1. Fuente de verdad

El **repositorio git** (`docs/planning/`) es la fuente de verdad para
todos los documentos de planificación. Claude Code lee y escribe ahí.

El **Proyecto de claude.ai** (esta base de conocimiento, ver
`claude/INDICE.md`) es un **espejo parcial**, no una segunda fuente. Se
actualiza en un paso deliberado, no automáticamente, y **no** incluye
`CLAUDE.md`/`TODO.md` (ver punto 6).

**Regla práctica:** si un documento del Proyecto y el mismo archivo en
el repo dicen cosas distintas, gana el repo. Si hay duda, preguntar al
usuario si hubo cambios recientes en Claude Code que todavía no se
reflejaron acá.

## 2. Cuándo sincronizar (el gatillo)

Sincronizar el Proyecto **solo cuando se cierra una fila de
`plan-hoja-de-ruta-ejecucion.md`** (merge a `main`) y esa sesión tocó
alguno de los documentos de planificación — no en cada commit, no en
cada sesión de Claude Code. La mayoría de los commits son código, no
tocan los `.md` de planificación.

## 3. Cómo sincronizar (bajo costo de tokens)

Con el puente de dispositivo (carpeta del repo conectada — pedir acceso
a `C:\xampp\htdocs\sistemfe` con `device_request_folder_access` si no
está conectada todavía en la sesión):

1. `device_list_dir` (recursivo sobre `docs/planning/`) para ver qué
   cambió, o `device_stage_files` directo si ya se sabe qué archivo.
2. `Read` el archivo staged para confirmar el contenido.
3. `project_write` al mismo `path` en el Proyecto, reemplazando la
   versión anterior. Para llevar algo del Proyecto AL repo (dirección
   inversa): `Write` el contenido a un archivo local, `SendUserFile`,
   luego `device_commit_files` a la ruta real dentro de
   `docs/planning/`.

Sin puente de dispositivo conectado: el usuario pega el contenido del
archivo actualizado o lo sube como adjunto, y se hace `project_write`
igual — el costo extra es solo copiar/pegar un archivo, no todos.

## 4. Disciplina de lectura (ahorro de tokens en toda sesión nueva)

Cualquier sesión nueva — de Claude Code o de planificación — debe:

1. Leer primero `claude/INDICE.md` (mapa de qué documento cubre qué).
2. Ir directo al documento y sección específica que necesita, con
   `project_search` o lectura puntual — nunca releer todos los
   documentos completos "por si acaso".
3. Para el vertical Agencia de Viajes, el punto de entrada operativo es
   `plan-hoja-de-ruta-ejecucion.md` §0 — dice exactamente qué sección de
   qué documento leer para la siguiente sesión pendiente.

## 5. Mantenimiento del propio protocolo

Cuando `plan-hoja-de-ruta-ejecucion.md` vuelva a acumular mucho
historial cerrado, archivar el tramo viejo en `historial-archivo.md`
(mismo patrón usado el 18-ago-2026) y dejar en el documento principal
solo la tabla de estado + últimas 2-3 entradas.

## 6. Por qué `CLAUDE.md`/`TODO.md` quedan fuera del espejo (decisión 18-ago-2026)

Estos dos archivos, en la raíz del repo, son bitácoras de alta
frecuencia — Claude Code los edita en casi cada sesión (a diferencia de
los documentos de `docs/planning/`, que solo cambian cuando se cierra
una fila de la hoja de ruta). Mirrorearlos al Proyecto tendría dos
problemas reales: (1) copiarlos completos exige retipear ~1450 y ~1030
líneas respectivamente cada vez que se resincronizan — alto costo de
tokens y riesgo de transcripción; (2) quedarían desactualizados de
nuevo casi de inmediato, defeando el propósito de sincronizar solo
cuando aporta.

**Decisión explícita del usuario:** quedan solo en el repo. Cualquier
sesión que necesite su contenido debe leerlos directo ahí (vía el
puente de dispositivo) o pedirle al usuario el fragmento puntual que
hace falta — nunca asumir que están reflejados en este Proyecto.

## 7. Pendiente conocido, no resuelto — nombres de archivo inconsistentes

Al reconciliar el 18-ago-2026 se encontraron archivos con doble
extensión `.md.md` en el repo (`guia-despliegue-produccion-ovh.md.md`,
`PEGAR-EN-CLAUDE-CODE-giro-selector.md.md`) que en el Proyecto están
guardados con el nombre correcto (`.md` simple). Tampoco se unificó el
nombre de `gap-selector-giro-tenant.md` (Proyecto) vs.
`gap-editar-tenant-giro-password.md` (repo, mismo contenido) ni de
`sesion-giro-selector-panel.md` (Proyecto) vs.
`PEGAR-EN-CLAUDE-CODE-giro-selector.md` (repo, mismo contenido). No se
corrigió en esta sesión — decidir con el usuario cuál nombre es el
bueno antes de tocar el repo.

## 8. Historial de actualizaciones

| Fecha | Cambio |
|---|---|
| 18-ago-2026 | Primera versión del protocolo, a partir de la duda del usuario sobre desincronización entre Claude Code/git y el Proyecto de claude.ai. |
| 18-ago-2026 | Reconciliación real vía puente de dispositivo (`C:\xampp\htdocs\sistemfe`): 4 documentos desalineados sincronizados en ambas direcciones. Agregada la sección 6 (decisión de no mirrorear `CLAUDE.md`/`TODO.md`) y la sección 7 (nombres de archivo inconsistentes, pendiente). |