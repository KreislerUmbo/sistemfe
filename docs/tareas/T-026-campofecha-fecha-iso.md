# T-026 — `CampoFecha` muestra el día correcto aunque reciba una fecha con hora y zona

| | |
|---|---|
| **Módulo** | TRA (frontend, todos los giros) |
| **Depende de** | ninguna |
| **Reglas** | `CLAUDE.md` → "Regla única de fechas y horas" (fechas de negocio `YYYY-MM-DD`, sin zona) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión del flujo de cotizaciones", H11 |
| **Rama** | `fix/T-026-campofecha-fecha-iso` |

## Contexto
Laravel serializa las columnas con cast `date` como `2026-08-20T00:00:00.000000Z`.
`components/CampoFecha.vue` le pasa ese texto a flatpickr (`defaultDate` y `setDate()` en el
`watch` del modelo). flatpickr trata cualquier texto que termina en "Z" con `new Date(texto)`
(`node_modules/flatpickr/dist/esm/utils/dates.js`), es decir medianoche UTC = **19:00 del día
anterior en Perú**: el campo muestra un día antes. Caso real: editar un destino de la alternativa
en el cotizador (`abrirFormEditarDestinoActivo()` pasa `destino.fecha_inicio` tal cual). La
cabecera de la cotización ya lo esquivaba a mano con `slice(0, 10)`. `CampoFecha` se usa en 25
pantallas: puede haber otros casos iguales.

## Alcance
- En `CampoFecha.vue`, normalizar el valor **de entrada** antes de dárselo a flatpickr: si es un
  texto que empieza con `YYYY-MM-DD` (con o sin hora/zona), usar solo esos 10 caracteres. Aplica a
  `defaultDate` y al `watch` del modelo. La fecha de negocio nunca se convierte de zona: se toma
  el día escrito.
- Que el modelo quede en `YYYY-MM-DD` también cuando llegó con hora (emitir el valor
  normalizado), para que el formulario mande al servidor el mismo formato que si se hubiera elegido
  en el calendario. Cuidar de no disparar un cambio "falso" que marque el formulario como editado
  si eso tiene efecto en alguna pantalla (revisar los `watch` de los padres más usados).
- Función pura en `helpers/fecha.ts` (por ejemplo `soloFechaISO(valor)`) con su test de Vitest:
  `'2026-08-20T00:00:00.000000Z'` → `'2026-08-20'`, `'2026-08-20'` → igual, `''`/`null` → `''`.
- Quitar el `slice(0, 10)` manual de la cabecera del cotizador **solo si** queda redundante y sin
  riesgo; si no, dejarlo y anotarlo.
- Buscar otros lugares que pasen a `CampoFecha` un valor que venga del servidor sin recortar
  (`grep -rn "<CampoFecha" src`) y listarlos en "Resultado" (quedan cubiertos por el arreglo central).

## Fuera de alcance
- `CampoHora` y los instantes con hora (esos sí se convierten a hora de Perú, regla de fechas).
- Cambiar cómo serializa Laravel las fechas.

## Archivos probables
- `admin-start-kit/src/components/CampoFecha.vue`
- `admin-start-kit/src/helpers/fecha.ts` + `fecha.test.ts`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue` (solo si se quita el `slice` manual)

## Criterios de aceptación
- [ ] **CA1** — `soloFechaISO()` cubre los casos de arriba. → Vitest
- [ ] **CA2** — Editar un destino con fecha de inicio 20/08 muestra 20/08 (hoy muestra 19/08). →
  prueba funcional en `agencia-demo` (anotar el "antes" y el "después")
- [ ] **CA3** — Guardar ese destino sin tocar la fecha deja la misma fecha en la base. → prueba funcional
- [ ] **CA4** — Elegir una fecha en el calendario sigue funcionando igual en una pantalla cualquiera
  de otro giro (por ejemplo Ventas o Créditos). → prueba funcional
- [ ] **CA5** — `type-check`, `build` y Vitest sin errores.

## Seguridad y correctitud
- [ ] Permisos: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: no aplica
- [ ] Concurrencia: no aplica
- [ ] Dinero: no aplica
- [ ] Fechas: **es el objetivo** — fecha de negocio sin conversión de zona
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
cd admin-start-kit && npx vitest run src/helpers/fecha.test.ts && npm run type-check && npm run build
```

---

## Resultado
- **Commits:** rama `fix/T-026-campofecha-fecha-iso` (ver `git log`).
- **Tests:** 2 nuevos en Vitest (`soloFechaISO` en `fecha.test.ts`); Vitest 159 en verde;
  `type-check` con los mismos 46 errores previos; `build` correcto.
- **Prueba funcional (agencia-demo, cotización 45, destino temporal 20/08–22/08, borrado al
  terminar):**
  - **Antes:** el formulario de editar destino mostraba 19/08 y 21/08, y el valor interno ya era
    `2026-08-19`: guardar sin tocar nada **corría la fecha un día atrás en la base** (peor que
    solo mostrarla mal).
  - **Después:** muestra 20/08 y 22/08; guardar sin tocar deja 2026-08-20 / 2026-08-22.
  - **CA4** (otro giro): no se probó en pantalla; para un valor `YYYY-MM-DD` (el que llega al
    elegir en el calendario) `normalizarModelo()` no hace nada, así que el comportamiento no
    cambia.
- **Cómo quedó:** `soloFechaISO()` en `helpers/fecha.ts`; `CampoFecha` la usa en `defaultDate`, al
  montar y en el `watch` del modelo, y si el valor traía hora deja el modelo en `YYYY-MM-DD`.
  Revisados los padres con `watch` profundo (`PasajeAereoForm`, `DialogoReprogramar`): solo piden
  una vista previa; normalizar provoca a lo sumo una más.
- **`slice(0, 10)` del cotizador** (`editar.vue`, cabecera): **se dejó**. Con el arreglo es
  redundante para el campo, pero esos valores también se usan fuera de `CampoFecha`.
- **Otras pantallas:** `CampoFecha` se usa en 25 archivos; todas quedan cubiertas por el arreglo
  central, sin cambios en cada una.

## Hallazgos fuera de alcance
- Ninguno.
