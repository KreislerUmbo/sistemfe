# Cómo trabajamos

> Vigente desde 24-oct-2026. Reemplaza, de a poco, la forma anterior (estado e historial
> repetidos en `CLAUDE.md`, `TODO.md`, planes, hoja de ruta y memoria). Lo anterior no se
> borra: se congela en `docs/archivo/` a medida que se migra cada módulo.

## 1. La regla de fondo: cada dato vive en un solo lugar

Cada tipo de documento tiene **un** rol. Los demás **enlazan**, nunca copian.

| Tipo | Dónde | Contiene | Nunca contiene | Vida |
|---|---|---|---|---|
| **Reglas del módulo** | `docs/modulos/<mod>/reglas.md` | Qué hace el sistema **hoy**, con ID por regla (`R-AGE-04`); modelo de datos | Estado, fechas de avance, historial, "antes era así" | Se edita en su lugar, sin parches |
| **Decisión** | `docs/modulos/<mod>/decisiones/D-AGE-018-<slug>.md` | Por qué se eligió algo, qué se descartó, qué reglas afecta | Cómo se implementó | Inmutable; si cambia, una decisión nueva la reemplaza |
| **Tarea** | `docs/tareas/T-042-<slug>.md` | El contrato para quien la construye: alcance, criterios, tests, seguridad | Diseño nuevo (va antes, en reglas/decisiones) | Se borra al unir a `main` (git la conserva) |
| **Backlog** | `docs/backlog.md` | Una línea por tarea con su estado | Detalle | Vivo — **único lugar con estado** |
| **Historial** | `git log` + mensaje de commit | Qué se hizo y por qué | — | No se escribe a mano |
| **Recorrido** | `docs/aprendizaje/NN-<slug>.md` | Pantalla → controller → servicio → tabla, ejercicios | Reglas repetidas (enlaza a `R-…`) | Uno por tarea cerrada |
| **Transversal** | `docs/transversal/<tema>.md` | Reglas que aplican a todos los módulos (fechas, tenancy, dinero, tributario, seguridad) | Estado | Se edita en su lugar |

**Consecuencias prácticas:**
- "Pendiente", "falta", "en curso", "hecho", "sin desplegar" se escriben **solo** en `backlog.md`.
  En cualquier otro documento: `ver T-042`.
- `CLAUDE.md` tiene contexto y convenciones, no avance de módulos.
- La memoria de Claude guarda preferencias de trabajo y trampas del entorno, no estado.

### IDs

| Qué | Formato | Numeración |
|---|---|---|
| Tarea | `T-001` | Global, correlativa (cruza módulos) |
| Regla | `R-<MOD>-NN` | Por módulo; un ID retirado no se reusa |
| Decisión | `D-<MOD>-NNN` | Por módulo (Agencia conserva su D6-D18 → `D-AGE-006`…) |

Códigos de módulo: `AGE` Agencia · `CRE` Créditos · `VEN` Ventas/SUNAT · `NOT` Notas ·
`CAJ` Caja · `AMO` Amortizaciones · `ADE` Adelantos · `COT` Cotizaciones comerciales ·
`COM` Compras · `PLA` Plataforma (tenancy, superadmin, menú, roles) · `POR` Portal ·
`TRA` Transversal.

## 2. Las etapas

| # | Etapa | Quién | Sale de acá |
|---|---|---|---|
| 1 | **Requerimiento** | Usuario (con sus palabras) | `docs/requerimientos/<slug>.md` o el mensaje directo |
| 2 | **Análisis** | Claude | Casos, preguntas abiertas, reglas afectadas — en el mismo requerimiento |
| 3 | **Diseño** | Claude + usuario | `reglas.md` actualizado + decisiones. **Lo aprueba el usuario** |
| 4 | **Desglose** | Claude | Tareas `T-…` en `docs/tareas/` + líneas en `backlog.md` |
| 5 | **Construcción** | Cualquier agente | Rama + commits, solo lo que dice la tarea |
| 6 | **Verificación** | El mismo agente | Tests por criterio, prueba funcional, checklist de seguridad |
| 7 | **Revisión** | Claude + usuario | Diff revisado contra la tarea; `/code-review` y `/security-review` |
| 8 | **Cierre** | Quien une a `main` | Backlog → `hecha`; tarea borrada; recorrido escrito; reglas corregidas si cambió algo |

**Reglas de la construcción (etapa 5):**
- Leer la tarea completa y lo que enlaza (reglas, decisiones, transversal) antes de tocar código.
- Si algo de la tarea es ambiguo o contradice el código: **parar y preguntar**, no adivinar.
- Si aparece un problema fuera del alcance: anotarlo en "Hallazgos" de la tarea, no
  arreglarlo. Se convierte en una tarea nueva o en una línea de "Por analizar".
- Una tarea = una rama = un conjunto de commits revisables. Nombre de rama:
  `<tipo>/T-042-<slug>` (`feat`, `fix`, `refactor`, `docs`, `test`).
- Mensaje de commit: `tipo(módulo): qué cambia (T-042)` + por qué en el cuerpo. Es el historial.

**Una tarea está bien escrita si** otro agente la puede construir sin preguntar nada, cabe en
un PR que se revisa en una sesión, y cada criterio de aceptación se puede comprobar con un
test o un paso concreto.

## 3. Definición de Hecho

Una tarea pasa a `hecha` solo si:

- [ ] Cada criterio de aceptación tiene su test (o su paso de verificación manual descrito) y pasa.
- [ ] La suite completa del backend está en verde contra PostgreSQL real (no solo SQLite).
- [ ] Frontend: `type-check` y `build` sin errores; Vitest si se tocó lógica con tests.
- [ ] Prueba funcional en la pantalla real cuando la tarea tiene UI (Playwright o a mano).
- [ ] Checklist de seguridad de la tarea revisado (§4).
- [ ] Sin cambios fuera del alcance.
- [ ] Revisión hecha (etapa 7) y observaciones resueltas o convertidas en tareas.
- [ ] `reglas.md` refleja lo que el sistema hace ahora (si la tarea cambió una regla).
- [ ] Recorrido en `docs/aprendizaje/`.

## 4. Checklist de seguridad y correctitud

Cada tarea marca lo que aplica (y "no aplica" lo demás). Detalle de cada punto:
`docs/transversal/seguridad.md` (pendiente de escribir — ver backlog).

- **Permisos:** toda ruta nueva tiene permiso o Policy; nadie otorga lo que no tiene.
- **Tenancy:** ninguna consulta cruza tenant/central sin intención; URLs de archivos con `StorageUrl`.
- **Entrada:** FormRequest o validación explícita; archivos con tipo validado.
- **Concurrencia:** `lockForUpdate()` donde dos usuarios pueden escribir lo mismo (correlativos, saldos, cupos).
- **Dinero:** centavos enteros en código; un solo punto de redondeo.
- **Fechas:** `HoraPeru` para "hoy" y filtros por día; nunca `now()->toDateString()`.
- **Tributario:** sin fallback silencioso; revisar el flujo producto → destino → operación.
- **Salida:** sin `v-html` sin sanitizar; errores sin datos internos.
- **Datos:** migraciones nuevas, nunca editar una existente; `tenants:migrate-verticales`.

## 5. Pasarle una tarea a otro agente

Primer mensaje de la sesión (no copiar documentos, dar rutas):

> Construye la tarea `docs/tareas/T-042-<slug>.md`. Antes lee `docs/proceso.md` y todo lo que
> la tarea enlaza. Trabaja en la rama `feat/T-042-<slug>`. Si algo es ambiguo, pregunta antes
> de codificar. Al terminar, completa la sección "Resultado" de la tarea y no la marques
> como hecha: queda en revisión.

Para revisar (con Claude): "Revisa la rama `feat/T-042-…` contra `docs/tareas/T-042-….md`".

## 6. Migración desde la forma anterior

Se hace de a poco, sin parar el trabajo:

1. Este documento, las plantillas y el backlog.
2. Piloto con un requerimiento real: "Modificar reserva"
   (`docs/requerimientos/agencia-modificar-reserva.md` → T-001 a T-007).
3. `CLAUDE.md` adelgazado: estado e historial → `docs/archivo/`.
4. Documentos actuales congelados en `docs/archivo/`; las reglas vigentes de un módulo se
   extraen a `reglas.md` **cuando una tarea toca ese módulo**, no todas de golpe.
5. Pendientes de `TODO.md`, `CLAUDE.md`, planes y memoria pasados una sola vez al backlog,
   verificando cada uno contra el código y `git log`.
6. Memoria de Claude limpia de estado.
