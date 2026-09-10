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
 * - Grupos "Caja" y "Configuraciones" eran items PLANOS con "›" en el label
 *   en el frontend viejo (nunca un grupo real) — acá sí se arman como grupo
 *   real con hijos, porque el brief pide "estructura jerárquica real
 *   (grupos/hijos), no una lista plana".
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
    public function run(): void
    {
        // giro=null → visible para cualquier giro (MenuResolver: "giro IS
        // NULL OR giro = $tenant->giro").
        $this->item('dashboard', null, null, 'enlace', 'Dashboards', 'iconoir-home-simple', 'dashboards.analytics', null, 1);

        $adminPortal = $this->item('admin_portal', null, null, 'grupo', 'Admin Portal', null, null, null, 2);
        $this->item('admin_portal.categorias_sistemas', $adminPortal, null, 'enlace', 'Categorias Sistemas', 'fas fa-life-ring', 'system_categories.index', 'list_categorie_system', 1);
        $this->item('admin_portal.sistemas_registrar', $adminPortal, null, 'enlace', 'Registrar', null, 'system.register', 'register_system', 2);
        $this->item('admin_portal.sistemas_listar', $adminPortal, null, 'enlace', 'Listar', 'fa-brands fa-windows', 'systems.index', 'list_system', 3);

        $access = $this->item('access', null, null, 'grupo', 'Access', null, null, null, 3);
        $this->item('access.roles', $access, null, 'enlace', 'Roles y Permisos', 'fas fa-unlock', 'access.roles', 'list_role', 1);
        $this->item('access.usuarios', $access, null, 'enlace', 'Usuarios', 'fas fa-users', 'access.users', 'list_user', 2);

        $comercial = $this->item('comercial', null, null, 'grupo', 'Comercial', null, null, null, 4);
        $this->item('comercial.categorias', $comercial, null, 'enlace', 'Categorias', 'fas fa-life-ring', 'categories.index', 'list_categorie', 1);
        $this->item('comercial.productos_registrar', $comercial, null, 'enlace', 'Registrar', null, 'product.register', 'register_product', 2);
        $this->item('comercial.productos_listar', $comercial, null, 'enlace', 'Listar', 'fas fa-qrcode', 'product.index', 'list_product', 3);
        $this->item('comercial.clientes', $comercial, null, 'enlace', 'Clientes', 'fas fa-user-plus', 'clients.index', 'list_client', 4);
        $this->item('comercial.ventas_mis_ventas', $comercial, null, 'enlace', 'Mis Ventas', 'fas fa-money-check-alt', 'sale.list', 'list_sale', 5);
        $this->item('comercial.ventas_nc_nd', $comercial, null, 'enlace', 'Notas de Credito/Debito', null, 'nota.list', 'list_nota_electronica', 6);
        $this->item('comercial.ventas_anticipos', $comercial, null, 'enlace', 'Emitir Anticipos', null, 'advances.index', 'list_advance', 7);
        // Reusa list_sale — mismo criterio que el menú viejo, sin permiso propio.
        $this->item('comercial.ventas_por_cobrar', $comercial, null, 'enlace', 'Ventas por Cobrar', null, 'credit_receivables.index', 'list_sale', 8);
        $this->item('comercial.ventas_cotiz_comerciales', $comercial, null, 'enlace', 'Cotizaciones Comerciales', null, 'commercial-quotes.index', 'list_commercial_quote', 9);

        // Grupo real nuevo — antes era 2 ítems planos con "Caja ›" en el label.
        $caja = $this->item('caja', null, null, 'grupo', 'Caja', null, null, null, 5);
        $this->item('caja.turno_activo', $caja, null, 'enlace', 'Turno Activo', 'fas fa-cash-register', 'cash.session', 'cash.open_session', 1);
        $this->item('caja.historial', $caja, null, 'enlace', 'Historial y Reportes', null, 'cash.dashboard', 'cash.view_all', 2);

        $agencia = $this->item('agencia', null, 'agencia_viajes', 'grupo', 'Agencia de Viajes', null, null, null, 6);
        $this->item('agencia.cotizador', $agencia, 'agencia_viajes', 'enlace', 'Cotizador', 'fas fa-suitcase-rolling', 'agencia.cotizador.index', 'agencia.cotizaciones', 1);
        $this->item('agencia.reservas', $agencia, 'agencia_viajes', 'enlace', 'Reservas', null, 'agencia.reservas.index', 'agencia.reservas', 2);
        $this->item('agencia.reporte_operativo', $agencia, 'agencia_viajes', 'enlace', 'Reporte Operativo', null, 'agencia.reporteOperativo.index', 'agencia.reservas', 3);
        $this->item('agencia.salidas_operativas', $agencia, 'agencia_viajes', 'enlace', 'Salidas Operativas', null, 'agencia.salidas.index', 'agencia.reservas', 4);
        $this->item('agencia.venta_directa', $agencia, 'agencia_viajes', 'enlace', 'Venta Directa', null, 'agencia.ventaDirecta', 'agencia.reservas', 5);
        $this->item('agencia.proveedores', $agencia, 'agencia_viajes', 'enlace', 'Proveedores', null, 'agencia.proveedores.index', 'agencia.proveedores', 6);
        $this->item('agencia.destinos', $agencia, 'agencia_viajes', 'enlace', 'Destinos y Atractivos', null, 'agencia.destinos.index', 'agencia.destinos', 7);
        $this->item('agencia.temporadas', $agencia, 'agencia_viajes', 'enlace', 'Temporadas', null, 'agencia.temporadas.index', 'agencia.temporadas', 8);
        $this->item('agencia.guias', $agencia, 'agencia_viajes', 'enlace', 'Guias Turisticos', null, 'agencia.guias.index', 'agencia.guias', 9);
        $this->item('agencia.paquetes', $agencia, 'agencia_viajes', 'enlace', 'Paquetes / Tours', null, 'agencia.paquetes.index', 'agencia.paquetes', 10);
        $this->item('agencia.configuracion', $agencia, 'agencia_viajes', 'enlace', 'Configuracion', null, 'agencia.configuracion.index', 'agencia.configuracion', 11);
        $this->item('agencia.configuracion_codigos', $agencia, 'agencia_viajes', 'enlace', 'Codigos y numeracion', null, 'agencia.configuracion.codigos', 'agencia.configuracion', 12);

        // Grupo real nuevo — antes eran 7 ítems planos con "Configuraciones ›" en el label.
        $config = $this->item('configuraciones', null, null, 'grupo', 'Configuraciones', null, null, null, 7);
        $this->item('configuraciones.empresa', $config, null, 'enlace', 'Datos de la empresa', 'fas fa-wrench', 'company.index', 'company', 1);
        $this->item('configuraciones.sucursales', $config, null, 'enlace', 'Sucursales', null, 'branches.index', 'list_branch', 2);
        $this->item('configuraciones.cajas', $config, null, 'enlace', 'Cajas', null, 'cash-registers.index', 'list_cash_register', 3);
        $this->item('configuraciones.metodos_pago', $config, null, 'enlace', 'Metodos de Pago', null, 'payment-methods.index', 'list_payment_method', 4);
        $this->item('configuraciones.series', $config, null, 'enlace', 'Series de Comprobantes', null, 'series-comprobante.index', 'list_serie_comprobante', 5);
        $this->item('configuraciones.proveedores', $config, null, 'enlace', 'Proveedores', null, 'suppliers.index', 'list_supplier', 6);
        $this->item('configuraciones.conceptos_caja', $config, null, 'enlace', 'Conceptos de Caja', null, 'cash-concepts.index', 'list_cash_concept', 7);

        $recursos = $this->item('recursos_cliente', null, null, 'grupo', 'Recursos Cliente', null, null, null, 8);
        $this->item('recursos_cliente.listar', $recursos, null, 'enlace', 'Listar', 'fas fa-qrcode', 'recursos.index', 'list_recurso', 1);
        $this->item('recursos_cliente.registrar', $recursos, null, 'enlace', 'Registrar', null, 'recurso.register', 'register_recurso', 2);
        // Reusa register_recurso — mismo criterio que el menú viejo, sin permiso propio.
        $this->item('recursos_cliente.manual', $recursos, null, 'enlace', 'Manual del Sistema', null, 'recurso.manual', 'register_recurso', 3);
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
        int $orden
    ): int {
        $menuItem = MenuItem::updateOrCreate(
            ['codigo' => $codigo],
            [
                'parent_id' => $parentId,
                'giro' => $giro,
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
