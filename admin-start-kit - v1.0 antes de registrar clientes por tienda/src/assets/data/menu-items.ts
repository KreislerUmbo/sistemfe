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
    key: "Accesos",
    label: "ACCESS",
    isTitle: true,
    permissions: ['list_role','list_user']
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
    permissions: ['list_categorie','list_product','register_product','list_client','register_sale','list_sale',
      'register_guia_remision','list_guia_remision'
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
      {
        key: "register_sale",
        label: "Registrar",
        route: { name: "sale.register" },
        parentKey: "sales",
        permission: 'register_sale',
      },
      {
        key: "list_sales",
        label: "Listar",
        route: { name: "sale.list" },
        parentKey: "sales",
        permission: 'list_sale',
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
    route: { name: "company.index" },
    parentKey: "configurat",
    permission: 'company',
  },
]