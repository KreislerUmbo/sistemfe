# T-019 — Comparar hoteles: empezar la comparación con 1 sola habitación

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 6 |
| **Rama** | `fix/T-019-comparar-hoteles-1-habitacion` |

## Contexto
Para comparar "matrimonial del Hotel A vs. matrimonial del Hotel B" en Local/Nacional, el picker
del primer hotel exige marcar al menos 2 habitaciones si el hotel tiene más de un tipo cargado.
El vendedor tiene que agregar una de más y borrarla después. El backend no pone mínimo
(`grupo_opcion_id` es `nullable|uuid` en `AlternativaItemController`), y aceptar ya exige
exactamente 1 elegida por grupo.

## Alcance
- `views/agencia-viajes/cotizador/editar.vue`: el picker de Local/Nacional (`pasoDrawer ===
  'matrizHotel'`) pasa `:minimo-grupo="1"` siempre (hoy `grupoAbierto || tarifas.length <= 1 ? 1 : 2`).
- `HabitacionMatrixPicker.vue`: con 1 sola marcada en el **primer** hotel, el botón dice
  **"Empezar comparación"** (hoy "Sumar a la comparación", que solo tiene sentido si ya hay una
  en curso). Sugerencia: una prop o el propio `iniciarEnModoGrupo` para distinguir los dos casos.
- Comprobar que el banner "Comparando 1 hotel(es) para este día" aparece tras crear un grupo de
  1 fila (`grupoHotelAbiertoDiaActivo` debería detectarlo sin cambios) y que el siguiente hotel
  se suma al mismo grupo.
- El picker de **mayorista** no cambia (sigue con mínimo 2: ahí se marcan varios hoteles juntos).

## Fuera de alcance
- Grupos no adyacentes en el lienzo (pendiente conocido de M4).
- Fotos en el PDF (T-020/T-021).

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `admin-start-kit/src/components/AgenciaViajes/HabitacionMatrixPicker.vue`

## Criterios de aceptación
- [x] **CA1** — Hotel A con varios tipos: marcar solo Matrimonial → el botón se habilita y crea un
  grupo de 1 fila (tarjeta "1 opciones de hotel" con "Sin resolver"). → prueba funcional
- [x] **CA2** — Clic en el Hotel B → abre en modo comparación con el aviso; marcar Matrimonial →
  la tarjeta queda con 2 opciones del mismo grupo. → prueba funcional
- [x] **CA3** — Con el grupo **abierto** el PDF sigue bloqueado (`AlternativaPdfService::
  validarGruposResueltos()`, guardrail del 19-sep — no se toca). Marcando una como elegida, el
  PDF sale con la tabla "Opciones de hoteles": los 2 hoteles en la columna Matrimonial y la
  elegida resaltada. → prueba funcional (descargar el PDF en los dos estados)
- [x] **CA4** — Marcar una como elegida y aceptar la cotización funciona; con el grupo sin
  resolver, aceptar sigue bloqueado (422). → prueba funcional + tests existentes del guard en verde
- [x] **CA5** — Picker de mayorista: sigue pidiendo 2. → prueba funcional
- [ ] **CA6** — `type-check` y `build` sin errores.

## Seguridad y correctitud
- [x] Permisos: no aplica
- [x] Aislamiento de tenant: no aplica
- [x] Validación de entrada: no aplica (sin cambios de backend)
- [x] Concurrencia: no aplica
- [x] Dinero: el total "desde" de un grupo de 1 fila es el precio de esa fila (revisar en pantalla)
- [x] Fechas con `HoraPeru`: no aplica
- [x] Tributario: no aplica
- [x] `v-html`: no aplica
- [x] Migraciones: no aplica

## Cómo verificar
```
cd admin-start-kit && npm run type-check && npm run build
```
Prueba en `agencia-demo` (login en memoria `reference_agencia_demo_admin_login`) con dos hoteles
de Alojamiento que tengan matrimonial; revertir los datos de prueba al terminar.

---

## Resultado
- **Commits:** en la rama `fix/T-019-comparar-hoteles-1-habitacion` (`fix(agencia): empezar la comparación de hoteles con 1 sola habitación (T-019)` + este de documentación).
- **Tests:** CA6 — `npm run build` OK. `npm run type-check` falla con 47 errores previos (fake-backend.ts y otros), ninguno en `editar.vue` ni `HabitacionMatrixPicker.vue` (CA6 queda abierto por esos errores previos: ver T-101). Tests del guard de grupos (`SesionM1MatrizHotelesNucleoTest`, `SesionM4ElegirGrupoTest`, `SesionM5OpcionesHotelesPdfTest`, `T024GrupoHotelesIndependienteTest`): 53/53 en verde.
- **Prueba funcional (revisión, 10-nov, agencia-demo, Playwright):** en agencia-demo ningún otro hotel con varios tipos tiene Matrimonial, así que se probó **Doble de Cumbaza (5 tipos) vs. Doble de Hotel Rio Sol (2 tipos)**, que cubre lo mismo. CA1: con 1 marcada el botón dice "Empezar comparación" y crea "1 opciones de hotel · Sin resolver" + banner "Comparando 1 hotel(es)"; total "desde" PEN 300 = 150 × 2 noches (la única fila). CA2: Rio Sol abre en modo comparación con el aviso, "Sumar a la comparación", mismo `grupo_opcion_id`, tarjeta con 2 opciones. CA3: PDF con grupo abierto → 422; con Rio Sol elegida → tabla "Opciones de hoteles" con los 2 en la columna Doble y la elegida resaltada (revisado con Ghostscript). CA4: aceptar con grupo abierto → 422; resuelto → reserva creada solo con el ítem elegido. CA5: picker de mayorista (RDKM-1126-0000004-M2, sin confirmar) deshabilitado con 1, habilitado con 2. Datos de prueba borrados; quedan saltados los correlativos CDKM-1126-0000012 y RDKM-1126-0000012 (no fiscales).
- **Desviaciones de la tarea y por qué:** el rótulo "Empezar comparación" se decide dentro del picker con `iniciarEnModoGrupo` y `minimoGrupo` (sin prop nueva): aparece solo con 1 marcada, mínimo 1 y sin comparación en curso, así mayorista (mínimo 2) y el segundo hotel ("Sumar a la comparación") no cambian. Verificado por lectura que `grupoHotelAbiertoDiaActivo` y `agregarGrupo*HotelLocal` no exigen mínimo, por lo que un grupo de 1 fila se detecta y el siguiente hotel se suma al mismo `grupo_opcion_id`.

## Hallazgos fuera de alcance
- **PDF con "Incluye descuento de temporada aplicado" sin descuento**, con un grupo resuelto de 2 hoteles: `$totalOriginal` suma las dos opciones (300 + 560) y `$total` solo la elegida (560). Es H10, ya cubierto por **T-023 (CA1)**; reproducción anotada ahí.
- Voseo en los 422 de grupos de hotel y "1 opciones de hotel" con una sola opción → backlog, "Por analizar".
