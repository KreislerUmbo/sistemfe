# T-000 — <qué se construye, en una línea>

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-000 / ninguna |
| **Reglas** | R-AGE-00, R-AGE-00 |
| **Decisiones** | D-AGE-000 |
| **Rama** | `feat/T-000-<slug>` |

## Contexto
<!-- 3-5 líneas: qué problema resuelve y para quién. Enlazar, no copiar reglas. -->

## Alcance
- 

## Fuera de alcance
<!-- Lo que alguien podría creer que entra y no entra. -->
- 

## Archivos probables
<!-- Punto de partida, no lista cerrada. -->
- `api-sistema-fe/...`
- `admin-start-kit/src/...`

## Criterios de aceptación
<!-- Cada uno verificable. Indicar el test que lo cubre o el paso manual. -->
- [ ] **CA1** — Dado …, cuando …, entonces … → test: `…Test::test_…`
- [ ] **CA2** — 

## Seguridad y correctitud
<!-- Marcar lo que aplica; escribir "no aplica" en lo demás. Ver docs/proceso.md §4. -->
- [ ] Permisos / Policy en rutas nuevas:
- [ ] Aislamiento de tenant:
- [ ] Validación de entrada / archivos:
- [ ] Concurrencia (`lockForUpdate`):
- [ ] Dinero en centavos:
- [ ] Fechas con `HoraPeru`:
- [ ] Tributario sin fallback silencioso:
- [ ] Sin `v-html` sin sanitizar:
- [ ] Migraciones nuevas + `tenants:migrate-verticales`:

## Cómo verificar
<!-- Comandos, tenant de prueba, pantalla y datos para la prueba funcional. -->
```
php artisan test --filter=...
```

---

## Resultado
<!-- Lo completa quien construye, al terminar. -->
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
<!-- Problemas vistos y NO arreglados. Se convierten en tareas o en "Por analizar". -->
- 
