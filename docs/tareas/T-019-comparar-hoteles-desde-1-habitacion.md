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
- [ ] **CA1** — Hotel A con varios tipos: marcar solo Matrimonial → el botón se habilita y crea un
  grupo de 1 fila (tarjeta "1 opciones de hotel" con "Sin resolver"). → prueba funcional
- [ ] **CA2** — Clic en el Hotel B → abre en modo comparación con el aviso; marcar Matrimonial →
  la tarjeta queda con 2 opciones del mismo grupo. → prueba funcional
- [ ] **CA3** — PDF con el grupo abierto: tabla "Opciones de hoteles" con los 2 hoteles en la
  columna Matrimonial. → prueba funcional (descargar el PDF)
- [ ] **CA4** — Marcar una como elegida y aceptar la cotización funciona; con el grupo sin
  resolver, aceptar sigue bloqueado (422). → prueba funcional + tests existentes del guard en verde
- [ ] **CA5** — Picker de mayorista: sigue pidiendo 2. → prueba funcional
- [ ] **CA6** — `type-check` y `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: no aplica (sin cambios de backend)
- [ ] Concurrencia: no aplica
- [ ] Dinero: el total "desde" de un grupo de 1 fila es el precio de esa fila (revisar en pantalla)
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
cd admin-start-kit && npm run type-check && npm run build
```
Prueba en `agencia-demo` (login en memoria `reference_agencia_demo_admin_login`) con dos hoteles
de Alojamiento que tengan matrimonial; revertir los datos de prueba al terminar.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
