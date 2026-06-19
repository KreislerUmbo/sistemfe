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
];