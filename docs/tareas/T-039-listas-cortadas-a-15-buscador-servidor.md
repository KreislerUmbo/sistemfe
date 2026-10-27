# T-039 — Listas de proveedores, hoteles, mayoristas y guías sin cortar: buscador contra el servidor (H28)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna (hacer antes o junto con T-040, que carga la pestaña de mayorista al abrirla) |
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
  (mismo patrón que ya usan otros buscadores del vertical, p. ej. el de proveedores en
  `reservas/detalle.vue`), con los primeros resultados al abrirlo:
  - filtro de proveedor de la biblioteca (`proveedoresFiltro`);
  - proveedor mayorista en `OpcionMayoristaForm.vue` (`proveedoresMayoristas`);
  - hotel registrado del mayorista (`proveedoresHotel`);
  - guía en el cotizador (`guiaService.listar({})`).
- Lo mismo en `paquetes/detalle.vue` (`proveedoresFiltro`, guías).
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
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
