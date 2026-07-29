import type { MenuItemType } from "@/types/menu";

export const MENU_ITEMS: MenuItemType[] = [
  {
    key: "main",
    label: "Main Menu",
    isTitle: true,
  },
  {
    key: "dashboards",
    icon: "iconoir-home-simple",
    label: "Dashboards",
    route: { name: "dashboards.analytics" },
    parentKey: "dashboards",
    permission: "all",
  },
  {
    key: "Adminportal",
    label: "ADMIN PORTAL",
    isTitle: true,
    permissions: ['list_categorie_system', 'edit_categorie_system', 'list_system', 'list_plain', 'edit_plain',
    ]
  },
  {
    key: "system_categories",
    icon: "fas fa-life-ring",
    label: "Categorias Sistemas",
    route: { name: "system_categories.index" },
    parentKey: "system_categories",
    permission: 'list_categorie_system',
  },
  {
    key: "systems",
    label: "Sistemas",
    isTitle: false,
    icon: "fa-brands fa-windows",
    children: [
      {
        key: "register_system",
        label: "Registrar",
        route: { name: "system.register" },
        parentKey: "systems",
        permission: 'register_system',
      },
      {
        key: "list_systems",
        label: "Listar",
        route: { name: "systems.index" },
        parentKey: "systems",
        permission: 'list_system',
      },

    ],
  },

  {
    key: "Accesos",
    label: "ACCESS",
    isTitle: true,
    permissions: ['list_role', 'list_user']
  },
  {
    key: "roles",
    icon: "fas fa-unlock",
    label: "Roles y Permisos",
    route: { name: "access.roles" },
    parentKey: "roles",
    permission: 'list_role',
  },
  {
    key: "usuarios",
    icon: "fas fa-users",
    label: "Usuarios",
    route: { name: "access.users" },// access.users es el nombre de la ruta definida en router/index.ts
    parentKey: "usuarios",
    permission: 'list_user',
  },
  {
    key: "Comercial",
    label: "COMERCIAL",
    isTitle: true,
    permissions: ['list_categorie', 'list_product', 'register_product', 'list_client', 'register_sale', 'list_sale',
      'register_guia_remision', 'list_guia_remision'
    ]
  },
  {
    key: "categories",
    icon: "fas fa-life-ring",
    label: "Categorias",
    route: { name: "categories.index" },
    parentKey: "categories",
    permission: 'list_categorie',
  },
  {
    key: "products",
    label: "Productos",
    isTitle: false,
    icon: "fas fa-qrcode",
    children: [
      {
        key: "register_product",
        label: "Registrar",
        route: { name: "product.register" },
        parentKey: "products",
        permission: 'register_product',
      },
      {
        key: "list_products",
        label: "Listar",
        route: { name: "product.index" },
        parentKey: "products",
        permission: 'list_product',
      },
    ],
  },
  {
    key: "clients",
    icon: "fas fa-user-plus",
    label: "Clientes",
    route: { name: "clients.index" },
    parentKey: "clients",
    permission: 'list_client',
  },
  {
    key: "sales",
    label: "Ventas",
    isTitle: false,
    icon: "fas fa-money-check-alt",
    children: [
 /*      {
        key: "register_sale",
        label: "Registrar",
        route: { name: "sale.register" },
        parentKey: "sales",
        permission: 'register_sale',
      }, */
      {
        key: "list_sales",
        label: "Mis Ventas",
        route: { name: "sale.list" },
        parentKey: "sales",
        permission: 'list_sale',
      },
      {
        key: "list_notas",
        label: "Notas de Crédito/Débito",
        route: { name: "nota.list" },
        parentKey: "sales",
        permission: 'list_nota_electronica',
      },
/*       {
        key: "register_advance",
        label: "Adelantos — Registrar",
        route: { name: "advances.create" },
        parentKey: "sales",
        permission: 'register_advance',
      }, */
      {
        key: "list_advances",
        label: " Emitir Anticipos",
        route: { name: "advances.index" },
        parentKey: "sales",
        permission: 'list_advance',
      },
      {
        key: "credit_receivables",
        label: "Ventas por Cobrar",
        route: { name: "credit_receivables.index" },
        parentKey: "sales",
        permission: 'list_sale',
      },
    ],
  },
  // Módulo Caja — Fase 2 (turno activo) + Fase 5 (historial/dashboard,
  // plan-modulo-caja.md §9, §11). Padre con dos hijos porque cada uno se
  // gatea con un permiso distinto y no son excluyentes (§9: "un usuario
  // puede tener ambos") — un cajero solo-open_session ve solo "Turno
  // Activo", un admin solo-view_all ve solo "Historial y Reportes", y quien
  // tiene ambos ve las dos entradas.
  {
    key: "cash_session",
    icon: "fas fa-cash-register",
    label: "Caja",
    isTitle: false,
    children: [
      {
        key: "cash_session_turno",
        label: "Turno Activo",
        route: { name: "cash.session" },
        parentKey: "cash_session",
        permission: 'cash.open_session',
      },
      {
        key: "cash_session_dashboard",
        label: "Historial y Reportes",
        route: { name: "cash.dashboard" },
        parentKey: "cash_session",
        permission: 'cash.view_all',
      },
    ],
  },
  // Vertical Agencia de Viajes — maestros (Sesión 11a). Solo aparece para
  // usuarios con alguno de los 5 permisos 'agencia.*' — un tenant retail no
  // los tiene asignados a nadie, así que esta sección nunca les aparece.
  {
    key: "agencia_viajes",
    label: "Agencia de Viajes",
    isTitle: false,
    icon: "fas fa-suitcase-rolling",
    children: [
      {
        key: "agencia_proveedores",
        label: "Proveedores",
        route: { name: "agencia.proveedores.index" },
        parentKey: "agencia_viajes",
        permission: 'agencia.proveedores',
      },
      {
        key: "agencia_destinos",
        label: "Destinos y Atractivos",
        route: { name: "agencia.destinos.index" },
        parentKey: "agencia_viajes",
        permission: 'agencia.destinos',
      },
      {
        key: "agencia_temporadas",
        label: "Temporadas",
        route: { name: "agencia.temporadas.index" },
        parentKey: "agencia_viajes",
        permission: 'agencia.temporadas',
      },
      {
        key: "agencia_guias",
        label: "Guías Turísticos",
        route: { name: "agencia.guias.index" },
        parentKey: "agencia_viajes",
        permission: 'agencia.guias',
      },
      {
        key: "agencia_configuracion",
        label: "Configuración",
        route: { name: "agencia.configuracion.index" },
        parentKey: "agencia_viajes",
        permission: 'agencia.configuracion',
      },
    ],
  },
  {
    key: "guias",
    label: "Guia de Remisión",
    isTitle: false,
    icon: "fas fa-file-alt",
    children: [
      {
        key: "register_guia",
        label: "Registrar",
        route: { name: "dashboards.ecommerce" },
        parentKey: "guias",
        permission: 'register_guia_remision',
      },
      {
        key: "list_guia",
        label: "Listar",
        route: { name: "dashboards.ecommerce" },
        parentKey: "guias",
        permission: 'list_guia_remision',
      },
    ],
  },
  {
    key: "configurat",
    icon: "fas fa-wrench",
    label: "Configuraciones",
    isTitle: false,
    children: [
      {
        key: "company",
        label: "Datos de la empresa",
        route: { name: "company.index" },
        parentKey: "configurat",
        permission: 'company',
      },
      // Módulo Caja — Fase 0 (plan-modulo-caja.md §3).
      {
        key: "payment_methods",
        label: "Métodos de Pago",
        route: { name: "payment-methods.index" },
        parentKey: "configurat",
        permission: 'list_payment_method',
      },
      // Módulo de series de comprobantes.
      {
        key: "series_comprobante",
        label: "Series de Comprobantes",
        route: { name: "series-comprobante.index" },
        parentKey: "configurat",
        permission: 'list_serie_comprobante',
      },
      {
        key: "suppliers",
        label: "Proveedores",
        route: { name: "suppliers.index" },
        parentKey: "configurat",
        permission: 'list_supplier',
      },
      {
        key: "cash_concepts",
        label: "Conceptos de Caja",
        route: { name: "cash-concepts.index" },
        parentKey: "configurat",
        permission: 'list_cash_concept',
      },
    ],
  },
  {
    key: "recursos_cliente",
    label: "RECURSOS CLIENTE",
    isTitle: true,
    permissions: ['list_recurso', 'register_recurso']
  },
  {
    key: "recursos",
    label: "Recursos",
    isTitle: false,
    icon: "fas fa-qrcode",
    children: [
      {
        key: "list_recursos",
        label: "Listar",
        route: { name: "recursos.index" },
        parentKey: "recursos",
        permission: 'list_recurso',
      },
      {
        key: "register_recursos",
        label: "Registrar",
        route: { name: "recurso.register" },
        parentKey: "recurso",
        permission: 'register_recurso',
      },
      {
        key: "manual",
        label: "Manual del Sistema",
        route: { name: "recurso.manual" },
        parentKey: "recurso",
        permission: 'register_recurso',
      },

    ],
  },
]