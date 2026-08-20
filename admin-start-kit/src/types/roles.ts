export type Role = {
    id: string,
    name: string,
    created_at: string,
    permissions: Array<any>,
    permissions_pluck: Array<any>,
}

export type Roles = {
    total: number,
    paginate: number,
    roles: Role[],
}

export type RolePermiso = {
    name: string,
    permiso: string,
}

export type RolesResponse = {
    code: number,
    message?: string,
    role?: Role,
}



export const PERMISOS = [
    {
        'name': 'Dashboard',
        'permisos': [
            {
                name: 'Graficos',
                permiso: 'dashboard',
            },
        ]
    },
    {
        'name': 'Roles',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'register_role',
            },
            {
                name: 'Listado',
                permiso: 'list_role',
            },
            {
                name: 'Editar',
                permiso: 'edit_role',
            },
            {
                name: 'Eliminar',
                permiso: 'delete_role',
            }
        ]
    },
    {
        'name': 'Usuarios',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'register_user',
            },
            {
                name: 'Listado',
                permiso: 'list_user',
            },
            {
                name: 'Editar',
                permiso: 'edit_user',
            },
            {
                name: 'Eliminar',
                permiso: 'delete_user',
            },
        ]
    },
    {
        'name': 'Categorias',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'register_categorie',
            },
            {
                name: 'Listado',
                permiso: 'list_categorie',
            },
            {
                name: 'Editar',
                permiso: 'edit_categorie',
            },
            {
                name: 'Eliminar',
                permiso: 'delete_categorie',
            },
        ]
    },
    {
        'name': 'Productos',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'register_product',
            },
            {
                name: 'Listado',
                permiso: 'list_product',
            },
            {
                name: 'Editar',
                permiso: 'edit_product',
            },
            {
                name: 'Eliminar',
                permiso: 'delete_product',
            },
        ]
    },
    {
        'name': 'Clientes',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'register_client',
            },
            {
                name: 'Listado',
                permiso: 'list_client',
            },
            {
                name: 'Editar',
                permiso: 'edit_client',
            },
            {
                name: 'Eliminar',
                permiso: 'delete_client',
            },
        ]
    },
    {
        'name': 'Venta',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'register_sale',
            },
            {
                name: 'Listado',
                permiso: 'list_sale',
            },
            {
                name: 'Editar',
                permiso: 'edit_sale',
            },
            {
                name: 'Eliminar',
                permiso: 'delete_sale',
            },
        ]
    },
    {
        'name': 'Nota Eletrónica',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'nota_electronica',
            },
            {
                name: 'Listado',
                permiso: 'list_nota_electronica',
            },
        ]
    },
    {
        'name': 'Guia de Remisión',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'register_guia_remision',
            },
            {
                name: 'Listado',
                permiso: 'list_guia_remision',
            },
        ]
    },
    {
        // CRUD real agregado 2026-08-17 (antes solo listado, sin permisos
        // propios) — mismo criterio que Métodos de Pago (catálogo simple).
        'name': 'Sucursales',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'register_branch',
            },
            {
                name: 'Listado',
                permiso: 'list_branch',
            },
            {
                name: 'Editar',
                permiso: 'edit_branch',
            },
            {
                name: 'Eliminar',
                permiso: 'delete_branch',
            },
        ]
    },
    {
        // Módulo Caja — Fase 0 (plan-modulo-caja.md §3).
        'name': 'Métodos de Pago',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'register_payment_method',
            },
            {
                name: 'Listado',
                permiso: 'list_payment_method',
            },
            {
                name: 'Editar',
                permiso: 'edit_payment_method',
            },
            {
                name: 'Eliminar',
                permiso: 'delete_payment_method',
            },
        ]
    },
    {
        'name': 'Proveedores',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'register_supplier',
            },
            {
                name: 'Listado',
                permiso: 'list_supplier',
            },
            {
                name: 'Editar',
                permiso: 'edit_supplier',
            },
            {
                name: 'Eliminar',
                permiso: 'delete_supplier',
            },
        ]
    },
    {
        'name': 'Conceptos de Caja',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'register_cash_concept',
            },
            {
                name: 'Listado',
                permiso: 'list_cash_concept',
            },
            {
                name: 'Editar',
                permiso: 'edit_cash_concept',
            },
            {
                name: 'Eliminar',
                permiso: 'delete_cash_concept',
            },
        ]
    },
    {
        // Módulo Caja — Fase 2 (plan-modulo-caja.md §9).
        'name': 'Caja (Apertura/Cierre)',
        'permisos': [
            {
                name: 'Abrir/cerrar turno propio',
                permiso: 'cash.open_session',
            },
            {
                name: 'Ver todas las cajas/sedes (reportes, fase futura)',
                permiso: 'cash.view_all',
            },
            {
                name: 'Cerrar sesión de otro cajero',
                permiso: 'cash.close_others_session',
            },
            {
                // Módulo Caja — Fase 4 (plan-modulo-caja.md §6).
                name: 'Aprobar/rechazar egresos pendientes',
                permiso: 'cash.approve_expenses',
            },
        ]
    },
    {
        // Módulo Amortizaciones — permisos de anulación creados en el
        // backend (migraciones tenant 2026_07_15_12/13/14) pero nunca
        // agregados a este catálogo — sin esto no eran asignables desde
        // la UI de Roles.
        'name': 'Créditos (Amortizaciones)',
        'permisos': [
            {
                name: 'Anular cuota',
                permiso: 'anular-cuota-credito',
            },
            {
                name: 'Anular pago',
                permiso: 'anular-pago-credito',
            },
            {
                name: 'Liquidar devolución',
                permiso: 'liquidar-devolucion-credito',
            },
            {
                name: 'Reemplazar comprobante',
                permiso: 'reemplazar-comprobante-credito',
            },
        ]
    },
    {
        // Módulo de series de comprobantes — CRUD de catálogo, mismo criterio
        // que Métodos de Pago (sin permiso de eliminar: no hay borrado real,
        // solo desactivar).
        'name': 'Series de Comprobantes',
        'permisos': [
            {
                name: 'Registrar',
                permiso: 'register_serie_comprobante',
            },
            {
                name: 'Listado',
                permiso: 'list_serie_comprobante',
            },
            {
                name: 'Editar',
                permiso: 'edit_serie_comprobante',
            },
        ]
    },
    {
        // Módulo de series de comprobantes — Paso 3.5. can_switch_branch:
        // permiso independiente de rol (un cajero de confianza que cubre más
        // de una sucursal), mismo criterio que cash.close_others_session.
        // emitir_*: qué tipos de documento puede emitir cada usuario desde
        // register.vue/edit.vue (validado también en SaleController::store(),
        // no solo en el frontend).
        'name': 'Emisión de Comprobantes',
        'permisos': [
            {
                name: 'Cambiar de sucursal al emitir (multi-sede)',
                permiso: 'can_switch_branch',
            },
            {
                name: 'Emitir Factura',
                permiso: 'emitir_factura',
            },
            {
                name: 'Emitir Boleta',
                permiso: 'emitir_boleta',
            },
            {
                name: 'Emitir Nota de Venta',
                permiso: 'emitir_nota_venta',
            },
        ]
    },
    {
        // Vertical Agencia de Viajes — maestros (Sesión 11a). Agregado al
        // catálogo desde el día 1 esta vez — Caja (Fase 5) dejó documentado
        // que crear el permiso en el backend sin sumarlo acá lo deja
        // inasignable desde la UI de Roles pese a existir de verdad.
        'name': 'Agencia de Viajes (Maestros)',
        'permisos': [
            {
                name: 'Proveedores (y sus tarifas)',
                permiso: 'agencia.proveedores',
            },
            {
                name: 'Destinos y Atractivos',
                permiso: 'agencia.destinos',
            },
            {
                name: 'Temporadas',
                permiso: 'agencia.temporadas',
            },
            {
                name: 'Guías Turísticos',
                permiso: 'agencia.guias',
            },
            {
                name: 'Paquetes / Tours de plantilla',
                permiso: 'agencia.paquetes',
            },
            {
                name: 'Configuración de la agencia',
                permiso: 'agencia.configuracion',
            },
        ]
    },
    {
        // Cotizador — Sesión 11b. Permiso PROPIO, distinto de los 5 de
        // arriba (Agencia de Viajes — Maestros): cotizar es una operación
        // de venta diaria de cualquier vendedor, no gestión de catálogo.
        'name': 'Agencia de Viajes (Cotizador)',
        'permisos': [
            {
                name: 'Cotizaciones, alternativas e ítems',
                permiso: 'agencia.cotizaciones',
            },
        ]
    },
    {
        // Reserva y pasajeros — Sesión 11c. Permiso PROPIO, distinto de
        // 'agencia.cotizaciones': es un paso posterior (control operativo de
        // pasajeros/ítems ya cerrados), no armado de precio.
        'name': 'Agencia de Viajes (Reservas)',
        'permisos': [
            {
                name: 'Reservas, pasajeros y venta directa',
                permiso: 'agencia.reservas',
            },
        ]
    },
];