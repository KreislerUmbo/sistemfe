# T-063 — Menú de Agencia por tareas del negocio (Ventas, Viajes, Finanzas, Catálogos, Administración)

| | |
|---|---|
| **Módulo** | PLA + AGE |
| **Depende de** | ninguna (las entradas del CRM se agregan cuando existan T-051 y T-062) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 17 (aprobada 27-oct-2026) |
| **Rama** | `feat/T-063-menu-agencia` |

## Contexto
El menú de un tenant de Agencia mezcla el grupo "Comercial" de retail (con su propio "Ventas",
Productos, Categorías, Cotizaciones comerciales) y un grupo "Agencia de viajes" con 12 opciones en
una sola lista. Se reordena por lo que hace cada persona; retail y Créditos no cambian.

## Alcance
- Datos de `menu_items` (central), con una migración/seeder idempotente:
  - Grupos nuevos con `giro='agencia_viajes'`: VENTAS, VIAJES, FINANZAS, CATÁLOGOS,
    ADMINISTRACIÓN, AYUDA, y sus hijos según el árbol de la obs. 17. Los hijos que son pantallas
    comunes (Clientes, Mis ventas, Notas, Ventas por cobrar, Anticipos, Caja, Empresa, Sucursales,
    Cajas, Métodos de pago, Conceptos de caja, Series, Usuarios, Roles, Manuales) son ítems nuevos
    con `giro='agencia_viajes'`, la **misma ruta y el mismo permiso** que el original.
  - Los originales (Comercial y sus hijos, Caja, Configuración, Accesos, Capacitación, Portal web,
    grupo "Agencia de viajes") reciben `agencia_viajes` en `giros_excluidos`. Los ítems propios de
    Agencia se mueven a su grupo nuevo (cambio de `parent_id`, `orden` y `label`).
  - Antes de escribir: confirmar cómo arma el árbol `MenuController`/servicio de menú (filtro por
    `giro` y `giros_excluidos`, ocultar un grupo sin hijos visibles, `solo_plataforma`) y si hay un
    seeder del menú que deba quedar igual (fuente de verdad del menú: editar ahí, no solo en datos).
- **Proveedores de gastos** (`suppliers.index`) va en ADMINISTRACIÓN → Configuración de caja, no
  se oculta: Caja lo usa como contraparte de egresos. Se retira cuando exista la tabla única de
  proveedores (Compras, `plan-tours-venta-simple-costo-real.md` §3.7c).
- Seguimiento y Reportes comerciales: se agregan aquí si sus rutas ya existen; si no, lo hacen
  T-051 y T-062 en el grupo VENTAS.
- Frontend:
  - Ocultar el botón "Nueva Venta" de `views/sale/index.vue` cuando el giro es agencia.
  - Títulos de pantallas propias de Agencia iguales al menú: "Cotizador" → "Cotizaciones"
    (listado), "Paquetes y tours" → "Tours y paquetes", "Destinos y atractivos" → "Destinos",
    "Guías turísticos" → "Guías".
  - Las rutas de Productos/Categorías/Cotizaciones comerciales siguen existiendo (sin entrada de
    menú); no se bloquean por giro en esta tarea.
- Limpiar la caché del menú (dura 24 h) en el despliegue: anotar el comando en "Resultado".

## Fuera de alcance
- Pantallas nuevas con pestañas para los submenús combinados.
- Cambiar el menú de retail o créditos.
- Compras (entrará en FINANZAS cuando exista).

## Archivos probables
- `api-sistema-fe/database/seeders/` (seeder del menú) o migración central de datos
- servicio/controller del menú (lectura, para confirmar filtros)
- `admin-start-kit/src/views/sale/index.vue`, títulos en `views/agencia-viajes/*`

## Criterios de aceptación
- [ ] **CA1** — Usuario administrador de agencia-demo → ve exactamente el árbol de la obs. 17. → test del armado del menú + prueba funcional
- [ ] **CA2** — Un rol de vendedor (sin permisos de caja ni administración) → ve VENTAS y lo que sus permisos permitan; grupos vacíos no aparecen. → test
- [ ] **CA3** — Tenant retail (umbo) y créditos (creditos-demo) → menú idéntico al de antes. → test (comparar árbol antes/después)
- [ ] **CA4** — Seeder idempotente: correrlo dos veces no duplica ítems. → test
- [ ] **CA5** — "Nueva Venta" no aparece en Mis ventas de agencia; sí en retail. → prueba funcional
- [ ] **CA6** — Suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: los ítems nuevos copian `permiso_requerido` del original; ninguna ruta cambia su middleware
- [ ] Aislamiento de tenant: menú central por giro, sin datos de tenant
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: migración central (no de tenant); caché de menú limpiada

## Cómo verificar
```
php artisan test --filter=Menu
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:** `a0c5640` (foto del menú de retail y créditos, tomada antes de cambiar nada) y `72a48b0` (el menú de Agencia). Rama `feat/T-063-menu-agencia`.
- **Cómo quedó:** se editó `MenuItemsSeeder` (fuente de verdad del menú; no hay migración). Los grupos de retail reciben `agencia_viajes` en `giros_excluidos` (Portal web, Accesos, Comercial, Caja, Configuración, Capacitación y el grupo viejo «Agencia de viajes»). Los ítems propios de Agencia conservan su código y se mueven; las pantallas comunes son ítems nuevos `ag_*` con la misma ruta y permiso. Se agregó `menu.tieneCodigo()` en `stores/menu.ts` para que «Mis ventas» sepa que es Agencia.
- **Producción:** el comando es `php artisan db:seed --class=MenuItemsSeeder --force`, que `deploy.sh` ya corre en el paso 7, seguido de `php artisan cache:clear` y `php artisan tenants:run cache:clear` (también en ese paso: la caché del menú dura 24 h). Si el paso 7 avisa que falló, correrlos a mano. **Solo cambia dakamu** (agencia). market (retail) y credishirley (créditos) quedan con el mismo árbol (CA3).
- **Tests:**
  - Nuevos, en `tests/Feature/MenuGirosTest.php` (7): retail, retail como tenant plataforma y créditos contra la foto previa (CA3); árbol de la obs. 17 con Super-Admin (CA1); vendedor de agencia sin grupos vacíos (CA2); agencia sin grupos de retail aunque sea tenant plataforma; seeder idempotente (CA4).
  - Suite completa: 1806 pasan y falla 1, el intermitente conocido (`Fila13Fase1cFacturacionTest::descuento del combo…`), en dos corridas. `type-check`: 46 errores antes y 46 después (ninguno nuevo).
  - Prueba funcional con Playwright en agencia-demo: el administrador (Super-Admin) ve el árbol completo de la obs. 17 y Mis ventas no muestra «Nueva Venta» (CA5, lado agencia). Un vendedor temporal (rol «Vendedor de agencia», ya borrado) ve VENTAS (Cotizaciones, Venta directa), VIAJES y FINANZAS ▸ Caja ▸ Turno activo.
  - **No verificado en el navegador:** que «Nueva Venta» siga apareciendo en retail (CA5, lado retail). No tengo credenciales de umbo; el `v-if` solo se oculta con el ítem `ag_finanzas.mis_ventas`, que retail no tiene.
- **Desviaciones de la tarea y por qué:**
  - **Seguimiento y Reportes comerciales no se agregaron:** sus rutas no existen (T-051 y T-062 los suman en VENTAS).
  - **«Cotizador» → «Cotizaciones»:** el listado ya se llamaba así; no hubo cambio. Sí cambiaron Destinos, Guías y Tours y paquetes (título de ruta, encabezado y el «Volver a…» de conversión).
  - **Un test viejo se tocó:** `CreditosDatosTest::test_menu_real_del_giro_creditos…` afirmaba que Agencia seguía viendo `comercial.ventas_mis_ventas` y `configuraciones.series`; ahora comprueba `ag_finanzas.mis_ventas` y `ag_admin.series_comprobantes`.
  - El árbol se probó con Super-Admin y con el vendedor del seeder. El rol «Administrador de agencia» del seeder no trae `list_client`, `list_advance` ni `register_recurso`, así que ese rol no vería Clientes, Anticipos ni Manuales (ver hallazgos).

## Hallazgos fuera de alcance
- El rol «Administrador de agencia» (`AgenciaViajesRolesSeeder`) no tiene `list_client`, `list_advance` ni `register_recurso`: con el menú nuevo no vería Clientes, Anticipos ni Manuales y videos. Antes tampoco veía Clientes (mismo permiso). El administrador de agencia-demo es Super-Admin, por eso la prueba no lo detectó. Revisar al desplegar a dakamu qué rol usa su dueño.
- La pantalla «Anticipos» se llama «Adelantos de clientes» en su encabezado y «Adelantos» en el título de ruta; en retail el menú dice «Emitir anticipos». El menú de Agencia sigue la obs. 17 («Anticipos»). Es una pantalla común, no se renombró.
- Las rutas de Productos, Categorías y Cotizaciones comerciales siguen abiertas por URL en Agencia (la tarea lo deja así).
