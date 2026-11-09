# T-016 — Buscador de aerolíneas, corrección de nombres guardados y aerolínea por defecto en el vuelo de agencia

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-015 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 3 |
| **Rama** | `feat/T-016-buscador-aerolineas` |

## Contexto
Con el catálogo listo (T-015), los campos de texto libre pasan a ser un buscador, se corrigen los
nombres ya guardados y, en Pasajeros, el vuelo vendido por la agencia trae cargada la aerolínea
cotizada.

**Lo que dejó T-015 (09-nov-2026), tenerlo en cuenta:**
- Comparar nombres siempre con `Aerolinea::normalizar()` (minúsculas, sin tildes ni espacios
  sobrantes): el comando de corrección debe usar esa misma función, no un `lower()` en SQL.
- `GET`/`POST aerolineas` exigen `cotizaciones.editar|reservas.editar` y dan 404 fuera del giro
  Agencia. Si el buscador va en una pantalla de solo lectura, ampliar el permiso del `GET`.
- `POST` devuelve 201 al crear y 200 con la existente (o la vigente, si estaba unida). Si la
  existente o su destino están **desactivados**, igual la devuelve: el campo quedaría con un nombre
  que no sale en el buscador. Decidir si `AerolineaSelect` lo avisa o si el `POST` debe responder
  distinto en ese caso.
- La búsqueda ordena por nombre, sin priorizar las que empiezan con lo escrito.

## Alcance
- Componente `AerolineaSelect.vue` (sugiere mientras se escribe, con debounce; si no existe ofrece
  "Agregar «X»" con `POST aerolineas`; guarda el **nombre** del catálogo). Estilo de los
  buscadores ya usados en Agencia (ver memoria `feedback_estilo_ui_como_agencia_y_ventas`).
- Reemplazar el campo de **nombre de aerolínea** en:
  `PasajeAereoForm.vue` (`cotizacion_pasaje_aereo.aerolinea`), `OpcionMayoristaForm.vue`
  (`opcion_mayorista.vuelo_aerolinea`), `paquetes/form.vue` y `paquetes/detalle.vue`
  (`paquetes_plantilla.vuelo_aerolinea`), `reservas/detalle.vue`
  (`reserva_item_vuelo_pasajero.vuelo_aerolinea_confirmada`), y donde más aparezca
  (`grep -rn aerolinea admin-start-kit/src`).
- **Ojo:** en `reservas/detalle.vue` los campos `vuelo_aerolinea_ida`/`_vuelta` del vuelo propio
  tienen placeholder "Ej. LA2203": **parecen guardar el número de vuelo, no la aerolínea**.
  Verificar con datos reales antes de tocarlos; si es así, no se reemplazan ni se corrigen, y se
  anota en "Hallazgos".
- Vuelo de agencia en Pasajeros: si `vuelo_aerolinea_confirmada` está vacía, el formulario
  **carga como valor** la aerolínea del pasaje cotizado (hoy solo es placeholder); se puede cambiar.
  Lo que se guarda es lo que queda en el campo.
- Comando `agencia-viajes:normalizar-aerolineas --simular` (por tenant de Agencia): por cada
  columna de nombre de aerolínea, si el valor normalizado coincide con una del catálogo, reemplazar
  por el nombre del catálogo; si coincide con una **unida**, por la de destino. Registro en tabla
  (`ajustes_aerolinea`: tabla, id, columna, antes, después), reversible con `--revertir`.
  Valores que no coinciden con nada: se listan, no se tocan.
- Unir en el panel central (T-015) muestra el aviso "corre `agencia-viajes:normalizar-aerolineas`
  para corregir lo ya guardado" (o lo dispara en segundo plano, si es simple; decidir y anotar).

## Fuera de alcance
- Convertir las columnas a FK (no hay FK entre bases: siguen siendo texto).
- Reportes por aerolínea.

## Archivos probables
- `admin-start-kit/src/components/AgenciaViajes/AerolineaSelect.vue` (nuevo)
- Los formularios listados arriba
- `api-sistema-fe/app/Console/Commands/NormalizarAerolineas.php`
- migración `tenant/verticals/agencia-viajes/` para la tabla de registro

## Criterios de aceptación
- [ ] **CA1** — En el cotizador, escribir "lat" sugiere LATAM y guarda "LATAM". → prueba funcional
- [ ] **CA2** — Escribir una que no existe ofrece agregarla y queda en el catálogo. → prueba funcional
- [ ] **CA3** — Vuelo de agencia sin aerolínea confirmada: el campo viene con la cotizada; al
  guardar sin tocar, queda guardada. → prueba funcional + test del endpoint si hace falta
- [ ] **CA4** — `--simular` en `agencia-demo` lista "latam"/"Latam" → "LATAM" sin escribir; sin
  `--simular` corrige y registra; `--revertir` deja todo como estaba. → test
- [ ] **CA5** — Valor que no coincide con el catálogo no se toca y se lista. → test
- [ ] **CA6** — `type-check` y `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos: usa las rutas de T-015
- [ ] Aislamiento de tenant: el comando corre por tenant y lee el catálogo central
- [ ] Validación de entrada: no aplica (el backend de cada formulario ya valida largo)
- [ ] Concurrencia: no aplica
- [ ] Dinero: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no (sugerencias como texto)
- [ ] Migraciones: tabla de registro en `verticals/agencia-viajes/`; `tenants:migrate-verticales`

## Cómo verificar
```
php artisan agencia-viajes:normalizar-aerolineas --simular
php artisan test   # suite completa, contra PostgreSQL
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:** ver `git log feat/T-016-buscador-aerolineas`.
- **Tests:** `NormalizarAerolineasTest` (CA4, CA5, unidas, revertir sin pisar ediciones),
  `AerolineaCatalogoTest` (orden por prefijo, permiso ampliado). `--simular` real en `agencia-demo`
  lista Latam/latam → LATAM (cotizaciones, opciones de mayorista, vuelo confirmado) sin escribir.
  `type-check`: 47 errores, los mismos que antes de la tarea (fake-backend, etc.); `build` OK.
- **Desviaciones de la tarea y por qué:**
  - El comando recibe el `tenant` como argumento (igual que `limpiar-operacion`), no recorre todos.
  - `GET`/`POST aerolineas` aceptan además `agencia.paquetes`: Paquetes no usa `cotizaciones.editar`.
  - Unir en el panel central: se eligió **solo el aviso** con el nombre del comando (no segundo plano).
  - La búsqueda ahora prioriza las que empiezan con lo escrito.
  - No se avisa en `AerolineaSelect` si la existente está desactivada (sigue sin decidirse; el
    nombre se guarda igual).
  - Prueba funcional en pantalla: hecha en la revisión (ver abajo).
  - `components.d.ts` se regeneró solo (registra el componente nuevo).
  - Suite completa: 2111 pasan, 2 fallan; `VentaDirectaLineasTest` falló en la corrida completa y pasa aislado (con y sin estos cambios). El otro fallo no quedó registrado (salida recortada): repetir la suite en la revisión.
- **Revisión (09-nov-2026):**
  - Suite repetida: 2112 pasan, 1 falla. `VentaDirectaLineasTest` pasó (intermitente). El fallo
    era `RutasAgenciaPermisosTest`: esperaba el permiso viejo de `aerolineas`; se agregó
    `agencia.paquetes` a la regla.
  - Pantalla (agencia-demo): CA2 y CA3 OK. CA1 fallaba: el navegador mandaba "LATAM" y se guardaba
    "Latam", porque `validarPasajeAereo()` pasaba la aerolínea por `capitalizarNombrePropio()`
    (29-ago). Ahora usa `AerolineaController::formatearNombre()` (formato solo si viene todo en
    minúsculas). Test nuevo `test_pasaje_aereo_respeta_mayusculas_del_catalogo`; el de T-075 esperaba
    "Latam" y ahora "LATAM". Repetido en pantalla: guarda "LATAM". Carpeta `tests/Feature/AgenciaViajes`: 1180 pasan.
  - Observaciones de UX (no bloquean): salir del campo sin elegir descarta lo escrito; "Agregar «X»"
    aparece aunque haya sugerencias (riesgo de nombres a medias en el catálogo común).

## Hallazgos fuera de alcance
- `reserva_pasajeros.vuelo_aerolinea_ida/_vuelta` ya no existen (la migración
  `2026_11_04_100000` los pasó a texto de vuelo propio): no había nada que reemplazar ni corregir.
- `agencia-demo` tiene 16 pasajes cotizados con "Latam": el comando los corrige al aplicarlo.
