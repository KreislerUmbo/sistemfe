# T-039 — Listas de proveedores, hoteles, mayoristas y guías sin cortar: buscador contra el servidor (H28)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 11, H28 (26-oct-2026) |
| **Rama** | `fix/T-039-listas-sin-cortar` |

## Contexto
El cotizador pide listas completas (`/proveedores` por tipo, `/guias`, `/servicios`) pero el servidor
pagina: 15 por defecto en proveedores, 15 fijo en guías, 200 en servicios. El sobrante desaparece
sin aviso. En agencia-demo ya pasa: 16 hoteles (falta 1 en "usar tarifa registrada" del mayorista) y
41 proveedores (el filtro de proveedor de la biblioteca muestra 15). Con el tiempo empeora.

## Alcance
- Reemplazar los `<select>` con lista entera por un buscador que consulta al servidor al escribir
  (reutilizar `components/AgenciaViajes/ProveedorBuscador.vue`, que ya usa el tablero de salidas, o el de
  `reservas/detalle.vue`), con los primeros resultados al abrirlo:
  - filtro de proveedor de la biblioteca (`proveedoresFiltro`);
  - proveedor mayorista en `OpcionMayoristaForm.vue` (`proveedoresMayoristas`);
  - hotel registrado del mayorista (`proveedoresHotel`);
  - guía en el cotizador (`guiaService.listar({})`) y en el tablero de salidas (`salidas/index.vue`, `guiaService.listar({ page: 1 })`).
- Lo mismo en `paquetes/detalle.vue` (`proveedoresFiltro`, guías).
- Reporte operativo (`reporte-operativo/index.vue`): el select de guías (`guiaService.listar({ page: 1 })`)
  y la biblioteca completa de tarifas que carga al abrir para reasignar proveedor
  (`proveedorService.biblioteca()`), obs. 15.
- `stores/agenciaViajesCatalogos.ts` → `proveedoresActivos`: ver quién lo usa; si es una lista entera,
  pasarlo al buscador o quitarlo.
- `GuiaController::index`: aceptar `search` y `per_page` acotado, como `ProveedorController::index`.
- Al editar algo ya guardado (p. ej. una opción de mayorista), el valor elegido se muestra aunque no
  esté en los primeros resultados.
- Buscar el mismo patrón (lista entera sobre endpoint paginado) en el resto de `agencia-viajes/` y
  anotarlo en "Hallazgos" si aparece fuera de estas pantallas.

## Fuera de alcance
- Cambiar la paginación del listado principal de Proveedores.

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `admin-start-kit/src/components/AgenciaViajes/OpcionMayoristaForm.vue`
- `admin-start-kit/src/views/agencia-viajes/paquetes/detalle.vue`
- `admin-start-kit/src/stores/agenciaViajesCatalogos.ts`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/GuiaController.php`

## Criterios de aceptación
- [ ] **CA1** — Con 16 hoteles, el hotel 16 (orden alfabético) se encuentra escribiendo su nombre en "usar tarifa registrada". → prueba funcional en agencia-demo
- [ ] **CA2** — El filtro de proveedor de la biblioteca encuentra cualquiera de los 41. → prueba funcional
- [ ] **CA3** — Con 20 guías, el guía 20 se encuentra en el cotizador. → test de `GuiaController::index` con `search` + prueba funcional
- [ ] **CA4** — Editar una opción de mayorista cuyo proveedor no está entre los primeros resultados lo muestra elegido. → prueba funcional
- [ ] **CA5** — `type-check`, `build` y suite backend en verde.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: `search` como texto, `per_page` acotado (1-100)
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=Guia
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:** rama `fix/T-039-listas-sin-cortar-v2` (desde `main`, con el avance de Codex `5bc9f7a`
  aplicado y terminado por Claude Opus, 28-oct-2026). La rama vieja `fix/T-039-listas-sin-cortar`
  quedó 26 commits atrás y no se usa.
- **Qué quedó:**
  - `ProveedorBuscador` (ya existía) y `GuiaBuscador` (nuevo): al abrirlos muestran los primeros 15;
    al escribir buscan en el servidor. Un id ya guardado sin nombre se busca por id (CA4). Al salir
    sin elegir vuelve a mostrar el elegido. La lista se ubica con `position: fixed`
    (`composables/agencia-viajes/useListaFlotante.ts`) y se abre hacia arriba al pie de la ventana:
    dentro de una tabla con `.table-responsive` una lista absoluta quedaba cortada (pasaba en salidas).
  - Cotizador: filtro de proveedor de la biblioteca, mayorista (`OpcionMayoristaForm`, ya sin la
    prop `proveedoresMayoristas`), hotel registrado del mayorista y del hotel local, guía. Ya no se
    cargan las listas enteras de mayoristas/hoteles al abrir.
  - Paquetes: filtro de proveedor y guía. Salidas: guía de la salida y del vehículo
    (`SalidaServiciosVehiculos` sin la prop `guias`). Reporte operativo: guía, y reasignar
    proveedor consulta `biblioteca` por `servicio_id` + texto (antes cargaba solo las primeras 30
    tarifas y filtraba en el navegador).
  - `stores/agenciaViajesCatalogos.ts`: `proveedoresActivos` no lo usaba nadie; quitado.
  - `GuiaController::index`: `search` + `per_page` (1-100), test `GuiaBusquedaTest` (de Codex).
- **Verificación:** suite backend 1588 en verde. CA1 (agencia-demo, 16 hoteles: "RIOJA PLAZA HOTEL" no está entre los primeros 15
  y aparece al escribir; Cumbaza carga "Registrada (150.00)"), CA2 ("Hotel Rio Sol" fuera de los 15,
  filtra la biblioteca), CA3 (test; agencia-demo solo tiene 3 guías), CA4 (opción de mayorista 17
  muestra "Dkm Xplore"). También paquetes, salidas y reporte operativo, sin guardar cambios.
  type-check 46 (los preexistentes), build OK, `--filter=Guia` 31 en verde.
- **Desviaciones de la tarea y por qué:** salidas muestra el buscador de guía siempre (antes: texto +
  lápiz que abría el select); con un buscador el clic extra no aportaba.

## Hallazgos fuera de alcance
- `reservas/detalle.vue` (~2292) carga `proveedorService.biblioteca()` (30 tarifas) y
  `guiaService.listar({ page: 1 })` (15 guías) enteras al abrir: mismo corte, otra pantalla.
- `servicioService.listar({ per_page: 200 })` en cotizador, paquetes y el store: corta en 200 servicios
  (lejos hoy).
- Reasignar proveedor del reporte operativo usa su propia lista absoluta dentro de la tabla: puede
  quedar cortada en las últimas filas igual que pasaba en salidas.
