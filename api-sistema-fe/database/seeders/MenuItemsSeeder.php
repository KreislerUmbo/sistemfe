<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Central\MenuItem;
use Illuminate\Database\Seeder;

/**
 * Fase 1a (plan-modulo-menus-y-roles.md §3.1/§7, Parte 1) — siembra el
 * catálogo REAL de navegación, migrado desde el inventario hardcodeado de
 * `admin-start-kit/src/assets/data/menu-items.ts` (ver
 * docs/planning/claude/auditoria-menu-admin-start-kit.md §2, fuente de
 * verdad de este seeder — no se inventó ningún ítem/ruta).
 *
 * Alcance de esta fase (brief explícito): solo giro `agencia_viajes` +
 * ítems universales (giro=NULL, que agencia_viajes también necesita para
 * tener un menú completo — "Configuraciones", "COMERCIAL", etc. no son
 * exclusivos de retail, todos los giros comparten esas tablas 'core'). El
 * catálogo `retail` propio (Fase 3 del plan) no se formaliza acá porque
 * retail sigue en `role_id` legacy, sin roles Spatie reales todavía.
 *
 * Decisiones tomadas al migrar la estructura (documentadas en el resumen
 * final de la sesión, no solo acá):
 * - **Corrección 11-sep-2026 (hallazgo real, reportado por el usuario en vivo)**: la
 *   afirmación original de este comentario ("Caja y Configuraciones eran ítems planos con
 *   '›' en el label, nunca un grupo real") era incorrecta — el `menu-items.ts` viejo (visto
 *   en `git show 7336253~1:...`) ya los tenía como grupos reales con `children`, igual que
 *   "Agencia de Viajes". El bug real de esta migración fue otro: 3 sub-grupos que SÍ existían
 *   un nivel más adentro ("Productos"/"Ventas" dentro de "Comercial", "Sistemas" dentro de
 *   "Admin Portal") se aplanaron a hijos directos de su grupo padre, y el ícono del sub-grupo
 *   perdido quedó pegado sin sentido en uno de esos hijos sueltos en vez de descartarse o
 *   promoverse — el usuario lo notó de inmediato ("se perdieron los iconos, productos no lo
 *   veo como nombre"). Corregido acá: se restauran los 3 sub-grupos con su ícono real, y los
 *   4 grupos que ya eran de primer nivel en el viejo (`caja`/`agencia`/`configuraciones`/
 *   `recursos_cliente`) recuperan el ícono que tenían ahí y que se había perdido igual.
 * - Los headers (`tipo=grupo`) llevan `permiso_requerido=NULL` a propósito:
 *   la poda de MenuResolver (paso 5, "grupos vacíos no se pintan") ya
 *   resuelve el mismo efecto que el viejo `permissions` (plural, OR) del
 *   frontend, sin necesitar una lista de permisos en el propio grupo.
 * - "Guía de Remisión" (2 ítems) NO se sembró — su ruta real apunta a
 *   `dashboards.ecommerce` (bug ya documentado en la auditoría, el módulo
 *   de Guía de Remisión SUNAT no existe todavía). Perpetuar un destino
 *   roto en la infraestructura nueva es peor que dejarlo afuera — avisado
 *   en el resumen final, no se inventó una ruta nueva para reemplazarlo.
 * - `cash.history` (ruta real, sin ítem de menú hoy, permiso compuesto
 *   `cash.open_session|cash.view_all`) tampoco se sembró: `permiso_requerido`
 *   es un solo string, no soporta el OR que esa ruta necesita — mismo gap
 *   que ya señalaba la auditoría, no se resuelve acá.
 *
 * Idempotente: `updateOrCreate` por `codigo`.
 */
class MenuItemsSeeder extends Seeder
{
    /**
     * Módulo Créditos (plan §4): ítems retail/SUNAT compartidos (giro=NULL) que un
     * tenant de giro 'creditos' no usa. Excluir un grupo oculta también sus hijos.
     */
    private const SIN_CREDITOS = ['creditos'];

    public function run(): void
    {
        // giro=null → visible para cualquier giro (MenuResolver: "giro IS
        // NULL OR giro = $tenant->giro").
        $this->item('dashboard', null, null, 'enlace', 'Inicio', 'iconoir-home-simple', 'dashboards.analytics', null, 1, self::SIN_CREDITOS);
        // Fase 4d: en el giro créditos el inicio es el Panel (reemplaza al Dashboard genérico de la plantilla).
        $this->item('creditos_panel', null, 'creditos', 'enlace', 'Panel', 'iconoir-home-simple', 'creditos.panel', 'creditos.ver', 1);

        // Portal web edita el catálogo central: solo el tenant dueño de la plataforma
        // (config/plataforma.php). Ocultar el grupo oculta también sus hijos.
        $adminPortal = $this->item('admin_portal', null, null, 'grupo', 'Portal web', 'iconoir-app-window', null, null, 2, soloPlataforma: true);
        $this->item('admin_portal.categorias_sistemas', $adminPortal, null, 'enlace', 'Categorías de sistemas', 'iconoir-label', 'system_categories.index', 'list_categorie_system', 1);
        $this->item('admin_portal.sistemas', $adminPortal, null, 'enlace', 'Sistemas', 'iconoir-multiple-pages-empty', 'systems.index', 'list_system', 2);

        $access = $this->item('access', null, null, 'grupo', 'Accesos', 'iconoir-lock', null, null, 3);
        $this->item('access.roles', $access, null, 'enlace', 'Roles y permisos', 'iconoir-shield-check', 'access.roles', 'list_role', 1);
        $this->item('access.usuarios', $access, null, 'enlace', 'Usuarios', 'iconoir-group', 'access.users', 'list_user', 2);

        $comercial = $this->item('comercial', null, null, 'grupo', 'Comercial', 'iconoir-shop', null, null, 4);
        $this->item('comercial.categorias', $comercial, null, 'enlace', 'Categorías', 'iconoir-label', 'categories.index', 'list_categorie', 1, self::SIN_CREDITOS);
        $this->item('comercial.productos', $comercial, null, 'enlace', 'Productos', 'iconoir-box-iso', 'product.index', 'list_product', 2, self::SIN_CREDITOS);
        $this->item('comercial.clientes', $comercial, null, 'enlace', 'Clientes', 'iconoir-user', 'clients.index', 'list_client', 3);
        $ventas = $this->item('comercial.ventas', $comercial, null, 'grupo', 'Ventas', 'iconoir-cart', null, null, 4, self::SIN_CREDITOS);
        $this->item('comercial.ventas_mis_ventas', $ventas, null, 'enlace', 'Mis ventas', null, 'sale.list', 'list_sale', 1);
        $this->item('comercial.ventas_nc_nd', $ventas, null, 'enlace', 'Notas de crédito/débito', null, 'nota.list', 'list_nota_electronica', 2);
        $this->item('comercial.ventas_anticipos', $ventas, null, 'enlace', 'Emitir anticipos', null, 'advances.index', 'list_advance', 3);
        // Reusa list_sale — mismo criterio que el menú viejo, sin permiso propio.
        $this->item('comercial.ventas_por_cobrar', $ventas, null, 'enlace', 'Ventas por cobrar', null, 'credit_receivables.index', 'list_sale', 4);
        $this->item('comercial.ventas_cotiz_comerciales', $ventas, null, 'enlace', 'Cotizaciones comerciales', null, 'commercial-quotes.index', 'list_commercial_quote', 5);

        // "Caja"/"Agencia de Viajes"/"Configuraciones"/"Recursos Cliente" ya eran grupos
        // reales de primer nivel en el frontend viejo (ver corrección del comentario de
        // arriba) — su ícono va acá, no en ninguno de sus hijos.
        $caja = $this->item('caja', null, null, 'grupo', 'Caja', 'iconoir-cash', null, null, 5);
        $this->item('caja.turno_activo', $caja, null, 'enlace', 'Turno activo', null, 'cash.session', 'cash.open_session', 1);
        $this->item('caja.historial', $caja, null, 'enlace', 'Historial y reportes', null, 'cash.dashboard', 'cash.view_all', 2);

        $agencia = $this->item('agencia', null, 'agencia_viajes', 'grupo', 'Agencia de viajes', 'iconoir-airplane', null, null, 6);
        $this->item('agencia.cotizador', $agencia, 'agencia_viajes', 'enlace', 'Cotizador', null, 'agencia.cotizador.index', 'agencia.cotizaciones', 1);
        $this->item('agencia.reservas', $agencia, 'agencia_viajes', 'enlace', 'Reservas', null, 'agencia.reservas.index', 'agencia.reservas', 2);
        $this->item('agencia.reporte_operativo', $agencia, 'agencia_viajes', 'enlace', 'Reporte operativo', null, 'agencia.reporteOperativo.index', 'agencia.reservas', 3);
        $this->item('agencia.salidas_operativas', $agencia, 'agencia_viajes', 'enlace', 'Salidas operativas', null, 'agencia.salidas.index', 'agencia.reservas', 4);
        $this->item('agencia.venta_directa', $agencia, 'agencia_viajes', 'enlace', 'Venta directa', null, 'agencia.ventaDirecta', 'agencia.reservas', 5);
        $this->item('agencia.proveedores', $agencia, 'agencia_viajes', 'enlace', 'Proveedores', null, 'agencia.proveedores.index', 'agencia.proveedores', 6);
        $this->item('agencia.destinos', $agencia, 'agencia_viajes', 'enlace', 'Destinos y atractivos', null, 'agencia.destinos.index', 'agencia.destinos', 7);
        $this->item('agencia.temporadas', $agencia, 'agencia_viajes', 'enlace', 'Temporadas', null, 'agencia.temporadas.index', 'agencia.temporadas', 8);
        $this->item('agencia.guias', $agencia, 'agencia_viajes', 'enlace', 'Guías turísticos', null, 'agencia.guias.index', 'agencia.guias', 9);
        $this->item('agencia.paquetes', $agencia, 'agencia_viajes', 'enlace', 'Paquetes y tours', null, 'agencia.paquetes.index', 'agencia.paquetes', 10);
        $this->item('agencia.configuracion', $agencia, 'agencia_viajes', 'enlace', 'Config. de la Agencia', null, 'agencia.configuracion.index', 'agencia.configuracion', 11);
        $this->item('agencia.configuracion_codigos', $agencia, 'agencia_viajes', 'enlace', 'Códigos y numeración', null, 'agencia.configuracion.codigos', 'agencia.configuracion', 12);

        // Módulo Créditos (04-frontend "Además"): solo para el giro 'creditos'.
        $creditos = $this->item('creditos', null, 'creditos', 'grupo', 'Créditos', 'iconoir-hand-cash', null, null, 6);
        $this->item('creditos.cobranza', $creditos, 'creditos', 'enlace', 'Cobranza del día', null, 'creditos.cobranza', 'creditos.cobrar', 1);
        $this->item('creditos.listado', $creditos, 'creditos', 'enlace', 'Listado de Créditos', null, 'creditos.index', 'creditos.ver', 2);
        $this->item('creditos.nuevo', $creditos, 'creditos', 'enlace', 'Nuevo crédito', null, 'creditos.nuevo', 'creditos.crear', 3);
        $this->item('creditos.migrar', $creditos, 'creditos', 'enlace', 'Registrar crédito antiguo', null, 'creditos.migrar', 'creditos.migrar', 4);
        $this->item('creditos.configuracion', $creditos, 'creditos', 'enlace', 'Configuración de créditos', null, 'creditos.configuracion', 'creditos.configurar', 8);
        // Fase 4d: la agenda la usa también el cobrador; los reportes, quien tiene creditos.reportes.
        $this->item('creditos.agenda', $creditos, 'creditos', 'enlace', 'Agenda de cobranza', null, 'creditos.agenda', 'creditos.cobrar', 6);
        $this->item('creditos.reportes', $creditos, 'creditos', 'enlace', 'Reportes', null, 'creditos.reportes', 'creditos.reportes', 7);

        $config = $this->item('configuraciones', null, null, 'grupo', 'Configuración', 'iconoir-settings', null, null, 7);
        $this->item('configuraciones.empresa', $config, null, 'enlace', 'Datos de la empresa', null, 'company.index', 'company', 1);
        $this->item('configuraciones.sucursales', $config, null, 'enlace', 'Sucursales', null, 'branches.index', 'list_branch', 2);
        $this->item('configuraciones.cajas', $config, null, 'enlace', 'Cajas', null, 'cash-registers.index', 'list_cash_register', 3);
        $this->item('configuraciones.metodos_pago', $config, null, 'enlace', 'Métodos de pago', null, 'payment-methods.index', 'list_payment_method', 4);
        $this->item('configuraciones.series', $config, null, 'enlace', 'Series de comprobantes', null, 'series-comprobante.index', 'list_serie_comprobante', 5, self::SIN_CREDITOS);
        $this->item('configuraciones.proveedores', $config, null, 'enlace', 'Proveedores de gastos', null, 'suppliers.index', 'list_supplier', 6);
        $this->item('configuraciones.conceptos_caja', $config, null, 'enlace', 'Conceptos de caja', null, 'cash-concepts.index', 'list_cash_concept', 7);

        $recursos = $this->item('recursos_cliente', null, null, 'grupo', 'Capacitación', 'iconoir-book-stack', null, null, 8);
        // Administrar el contenido es de la plataforma; verlo ("Manuales y videos") es de todos.
        $this->item('recursos_cliente.listar', $recursos, null, 'enlace', 'Administrar recursos', null, 'recursos.index', 'list_recurso', 1, soloPlataforma: true);
        // Reusa register_recurso — mismo criterio que el menú viejo, sin permiso propio.
        $this->item('recursos_cliente.manual', $recursos, null, 'enlace', 'Manuales y videos', null, 'recurso.manual', 'register_recurso', 3);

        // 07-oct-2026: "Registrar" ya no tiene entrada propia en el menú — el listado tiene su
        // botón (visible solo con el permiso de registrar), y Productos/Sistemas pasan a ser
        // enlaces directos al listado. Se desactivan en vez de borrarse: updateOrCreate no
        // quita filas, y así un tenant ya sembrado deja de verlas al re-correr este seeder.
        MenuItem::whereIn('codigo', [
            'admin_portal.sistemas_registrar',
            'admin_portal.sistemas_listar',
            'comercial.productos_registrar',
            'comercial.productos_listar',
            'recursos_cliente.registrar',
        ])->update(['activo' => false]);
    }

    private function item(
        string $codigo,
        ?int $parentId,
        ?string $giro,
        string $tipo,
        string $label,
        ?string $icono,
        ?string $ruta,
        ?string $permisoRequerido,
        int $orden,
        ?array $girosExcluidos = null,
        bool $soloPlataforma = false
    ): int {
        $menuItem = MenuItem::updateOrCreate(
            ['codigo' => $codigo],
            [
                'parent_id' => $parentId,
                'giro' => $giro,
                'giros_excluidos' => $girosExcluidos,
                'solo_plataforma' => $soloPlataforma,
                'tipo' => $tipo,
                'label' => $label,
                'icono' => $icono,
                'ruta' => $ruta,
                'permiso_requerido' => $permisoRequerido,
                'orden' => $orden,
                'activo' => true,
            ]
        );

        return $menuItem->id;
    }
}
