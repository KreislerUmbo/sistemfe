//import { permission } from "process";
import PortalLayout from '@/layouts/PortalLayout.vue'

const setTitle = (title: string) => {
  return title
    ? `${title} | Rizz Vue - Responsive Admin Dashboard Template`
    : "Rizz Vue | Responsive Admin Dashboard Template";
};




/*ANTES
const authRoutes = [
  {
    path: "/auth/sign-in",
    name: "auth.sign-in",
    meta: {
      title: setTitle("Sign In"),
      authLogin: true,
    },
    component: () => import("@/views/auth/login.vue"),
  },*/
// DESPUÉS
const authRoutes = [
  {
    path: "/auth/sign-in",       // mantiene la URL vieja por compatibilidad
    redirect: "/admin/login",    // redirige al nuevo path
  },
  {
    path: "/admin/login",
    name: "auth.sign-in",
    meta: {
      title: setTitle("Sign In"),
      authLogin: true,
    },
    component: () => import("@/views/auth/login.vue"),
  },

  // {
  //   path: "/auth/register",
  //   name: "auth.register",
  //   meta: {
  //     title: setTitle("Register"),
  //   },
  //   component: () => import("@/views/auth/register.vue"),
  // },
  // {
  //   path: "/auth/reset-pass",
  //   name: "auth.reset-pass",
  //   meta: {
  //     title: setTitle("Reset Password"),
  //   },
  //   component: () => import("@/views/auth/reset-pass.vue"),
  // },
  // {
  //   path: "/auth/lock-screen",
  //   name: "auth.lock-screen",
  //   meta: {
  //     title: setTitle("Lock Screen"),
  //   },
  //   component: () => import("@/views/auth/lock-screen.vue"),
  // },
  // {
  //   path: "/auth/maintenance",
  //   name: "auth.maintenance",
  //   meta: {
  //     title: setTitle("Maintenance"),
  //   },
  //   component: () => import("@/views/auth/maintenance.vue"),
  // },
];

const errorRoutes = [
  {
    path: "/auth/error-404",
    name: "error.404",
    meta: {
      title: setTitle("Error 404"),
    },
    component: () => import("@/views/auth/error-404.vue"),
  },
  {
    path: "/auth/error-500",
    name: "error.500",
    meta: {
      title: setTitle("Error 500"),
    },
    component: () => import("@/views/auth/error-500.vue"),
  },
  {
    path: "/:catchAll(.*)",
    redirect: "/auth/error-404",
  },
];

const dashboardRoutes = [
  /*{
    path: "/",
    name: "dashboards.analytics",
    meta: {
      title: setTitle("Analytics"),
      authRequired: true,
      permission: 'all',
    },
    component: () => import("@/views/dashboards/analytics/index.vue"),
  },
  */
  // DESPUÉS
  {
    path: "/admin",
    name: "dashboards.analytics",
    meta: {
      title: setTitle("Analytics"),
      authRequired: true,
      permission: 'all',
    },
    component: () => import("@/views/dashboards/analytics/index.vue"),
  },
  {
    path: "/admin/dashboards/ecommerce",
    name: "dashboards.ecommerce",
    meta: {
      title: setTitle("Ecommerce"),
      authRequired: true,
      permission: 'all',
    },
    component: () => import("@/views/dashboards/ecommerce/index.vue"),
  },
];

const accessRoutes = [
  {
    path: "/roles-permisos",
    name: "access.roles",
    meta: {
      title: setTitle("Roles y Permisos"),
      authRequired: true,
      permission: 'all',
    },
    component: () => import("@/views/roles/index.vue"),
  },
  {
    path: "/users",
    name: "access.users",
    meta: {
      title: setTitle("Usuarios"),
      authRequired: true,
      permission: 'all',
    },
    component: () => import("@/views/users/index.vue"),
  }
];

const adminPortalRoutes = [
  {
    path: "/system_categories",
    name: "system_categories.index",
    meta: {
      title: setTitle("Categorias Sistemas"),
      authRequired: true,
      permission: 'list_categorie_system',
    },
    component: () => import("@/views/portal/admin/categories/index.vue"),
  },
  {
    path: "/categorie_systems/edit/:id",
    name: "categorie_systems.edit",
    meta: {
      title: setTitle("Editar Categoria Sistemas"),
      authRequired: true,
      permission: 'edit_categorie_system',
    },
    component: () => import("@/views/portal/admin/categories/edit.vue"),
  },
  {
    path: "/systems",
    name: "systems.index",
    meta: {
      title: setTitle("Listar Sistemas"),
      authRequired: true,
      permission: 'list_system',
    },
    component: () => import("@/views/portal/admin/systems/index.vue"),
  },
  {
    path: "/system/register",
    name: "system.register",
    meta: {
      title: setTitle("Registrar Sistema"),
      authRequired: true,
      permission: 'register_system',
    },
    component: () => import("@/views/portal/admin/systems/register.vue"),
  },

];


const comercialRoutes = [
  {
    path: "/categories",
    name: "categories.index",
    meta: {
      title: setTitle("Categorias"),
      authRequired: true,
      permission: 'list_categorie',
    },
    component: () => import("@/views/categories/index.vue"),
  },
  // Módulo Caja — Fase 0 (plan-modulo-caja.md §3).
  {
    path: "/payment-methods",
    name: "payment-methods.index",
    meta: {
      title: setTitle("Métodos de Pago"),
      authRequired: true,
      permission: 'list_payment_method',
    },
    component: () => import("@/views/cash/payment-methods.vue"),
  },
  {
    path: "/suppliers",
    name: "suppliers.index",
    meta: {
      title: setTitle("Proveedores"),
      authRequired: true,
      permission: 'list_supplier',
    },
    component: () => import("@/views/cash/suppliers.vue"),
  },
  {
    path: "/cash-concepts",
    name: "cash-concepts.index",
    meta: {
      title: setTitle("Conceptos de Caja"),
      authRequired: true,
      permission: 'list_cash_concept',
    },
    component: () => import("@/views/cash/cash-concepts.vue"),
  },
  // Módulo de series de comprobantes.
  {
    path: "/series-comprobante",
    name: "series-comprobante.index",
    meta: {
      title: setTitle("Series de Comprobantes"),
      authRequired: true,
      permission: 'list_serie_comprobante',
    },
    component: () => import("@/views/series-comprobante/index.vue"),
  },
  // Módulo Caja — Fase 2 (plan-modulo-caja.md §9).
  {
    path: "/caja",
    name: "cash.session",
    meta: {
      title: setTitle("Caja"),
      authRequired: true,
      permission: 'cash.open_session',
    },
    component: () => import("@/views/cash/session.vue"),
  },
  // Módulo Caja — Fase 5 (plan-modulo-caja.md §9, §11: reportes).
  // cash.open_session|cash.view_all: cualquiera con acceso al módulo puede
  // ver su propio historial (o todo, con cash.view_all) — ver
  // isPermitedRoute() en stores/auth.ts (soporte "|" agregado esta fase).
  {
    path: "/caja/historial",
    name: "cash.history",
    meta: {
      title: setTitle("Historial de Caja"),
      authRequired: true,
      permission: 'cash.open_session|cash.view_all',
    },
    component: () => import("@/views/cash/history.vue"),
  },
  // Dashboard admin — binario, exclusivo de cash.view_all (mismo criterio
  // que el backend, ver CashSessionController::dashboard()).
  {
    path: "/caja/dashboard",
    name: "cash.dashboard",
    meta: {
      title: setTitle("Dashboard de Caja"),
      authRequired: true,
      permission: 'cash.view_all',
    },
    component: () => import("@/views/cash/dashboard.vue"),
  },
  {
    path: "/company",
    name: "company.index",
    meta: {
      title: setTitle("Datos de la empresa"),
      authRequired: true,
      permission: 'all',
    },
    component: () => import("@/views/company/index.vue"),
  },
  {
    path: "/product/register",
    name: "product.register",
    meta: {
      title: setTitle("Registrar Producto"),
      authRequired: true,
      permission: 'register_product',
    },
    component: () => import("@/views/product/register.vue"),
  },
  {
    path: "/product/index",
    name: "product.index",
    meta: {
      title: setTitle("Listar Producto"),
      authRequired: true,
      permission: 'list_product',
    },
    component: () => import("@/views/product/index.vue"),
  },
  {
    path: "/product/edit/:id",
    name: "product.edit",
    meta: {
      title: setTitle("Editar Producto"),
      authRequired: true,
      permission: 'edit_product',
    },
    component: () => import("@/views/product/edit.vue"),
  },
  {
    path: "/clients",
    name: "clients.index",
    meta: {
      title: setTitle("Clientes"),
      authRequired: true,
      permission: 'list_client',
    },
    component: () => import("@/views/clients/index.vue"),
  },
  {
    path: "/sale/register",
    name: "sale.register",
    meta: {
      title: setTitle("Registrar Venta"),
      authRequired: true,
      permission: 'register_sale',
    },
    component: () => import("@/views/sale/register.vue"),
  },
  {
    path: "/sale/list",
    name: "sale.list",
    meta: {
      title: setTitle("Listar Ventas"),
      authRequired: true,
      permission: 'list_sale',
    },
    component: () => import("@/views/sale/index.vue"),
  },
  {
    path: "/sale/edit/:id",
    name: "sale.edit",
    meta: {
      title: setTitle("Editar Ventas"),
      authRequired: true,
      permission: 'edit_sale',
    },
    component: () => import("@/views/sale/edit.vue"),
  },
  {
    path: "/sale/nota/:id",
    name: "sale.nota",
    meta: {
      title: setTitle("Emitir Nota de Crédito/Débito"),
      authRequired: true,
      permission: 'nota_electronica',
    },
    component: () => import("@/views/sale/nota-create.vue"),
  },
  {
    path: "/nota/list",
    name: "nota.list",
    meta: {
      title: setTitle("Notas de Crédito/Débito"),
      authRequired: true,
      permission: 'list_nota_electronica',
    },
    component: () => import("@/views/sale/nota-list.vue"),
  },
  {
    path: "/nota/create",
    name: "notas.create",
    meta: {
      title: setTitle("Nueva Nota de Crédito/Débito"),
      authRequired: true,
      permission: 'nota_electronica',
    },
    component: () => import("@/views/sale/nota-create.vue"),
  },
  {
    path: "/advances/list",
    name: "advances.index",
    meta: {
      title: setTitle("Adelantos"),
      authRequired: true,
      permission: 'list_advance',
    },
    component: () => import("@/views/advances/index.vue"),
  },
  {
    path: "/advances/create",
    name: "advances.create",
    meta: {
      title: setTitle("Nuevo Adelanto"),
      authRequired: true,
      permission: 'register_advance',
    },
    component: () => import("@/views/advances/create.vue"),
  },
  {
    path: "/advances/:id",
    name: "advances.show",
    meta: {
      title: setTitle("Detalle de Adelanto"),
      authRequired: true,
      permission: 'list_advance',
    },
    component: () => import("@/views/advances/show.vue"),
  },
  {
    path: "/cuentas-por-cobrar",
    name: "credit_receivables.index",
    meta: {
      title: setTitle("Cuentas por Cobrar"),
      authRequired: true,
      permission: 'list_sale',
    },
    component: () => import("@/views/credit/index.vue"),
  },
  {
    path: "/cuentas-por-cobrar/cliente/:id",
    name: "credit_receivables.client",
    meta: {
      title: setTitle("Estado de Cuenta"),
      authRequired: true,
      permission: 'list_sale',
    },
    component: () => import("@/views/credit/client-detail.vue"),
  },
  {
    path: "/recursos/index",
    name: "recursos.index",
    meta: {
      title: setTitle("Listar Recursos"),
      authRequired: true,
      permission: 'list_recurso',
    },
    component: () => import("@/views/recursos/index.vue"),
  },
  {
    path: "/recursos/register",
    name: "recurso.register",
    meta: {
      title: setTitle("Registrar Recurso"),
      authRequired: true,
      permission: 'register_recurso',
    },
    component: () => import("@/views/recursos/register.vue"),
  },
  {
    path: "/recursos/manual",
    name: "recurso.manual",
    meta: {
      title: setTitle("Manual de Sistema"),
      authRequired: true,
      permission: 'register_recurso',
    },
    component: () => import("@/views/recursos/manual.vue"),
  },

  // Agencia de viajes — maestros (Sesión 11a)
  {
    path: "/agencia-viajes/proveedores",
    name: "agencia.proveedores.index",
    meta: {
      title: setTitle("Proveedores"),
      authRequired: true,
      permission: 'agencia.proveedores',
    },
    component: () => import("@/views/agencia-viajes/proveedores/index.vue"),
  },
  {
    path: "/agencia-viajes/proveedores/nuevo",
    name: "agencia.proveedores.create",
    meta: {
      title: setTitle("Nuevo Proveedor"),
      authRequired: true,
      permission: 'agencia.proveedores',
    },
    component: () => import("@/views/agencia-viajes/proveedores/form.vue"),
  },
  {
    path: "/agencia-viajes/proveedores/:id/editar",
    name: "agencia.proveedores.edit",
    meta: {
      title: setTitle("Editar Proveedor"),
      authRequired: true,
      permission: 'agencia.proveedores',
    },
    component: () => import("@/views/agencia-viajes/proveedores/form.vue"),
  },
  {
    path: "/agencia-viajes/proveedores/:id",
    name: "agencia.proveedores.show",
    meta: {
      title: setTitle("Detalle de Proveedor"),
      authRequired: true,
      permission: 'agencia.proveedores',
    },
    component: () => import("@/views/agencia-viajes/proveedores/detalle.vue"),
  },
  {
    path: "/agencia-viajes/destinos",
    name: "agencia.destinos.index",
    meta: {
      title: setTitle("Destinos y Atractivos"),
      authRequired: true,
      permission: 'agencia.destinos',
    },
    component: () => import("@/views/agencia-viajes/destinos/index.vue"),
  },
  {
    path: "/agencia-viajes/temporadas",
    name: "agencia.temporadas.index",
    meta: {
      title: setTitle("Temporadas"),
      authRequired: true,
      permission: 'agencia.temporadas',
    },
    component: () => import("@/views/agencia-viajes/temporadas/index.vue"),
  },
  {
    path: "/agencia-viajes/guias",
    name: "agencia.guias.index",
    meta: {
      title: setTitle("Guías Turísticos"),
      authRequired: true,
      permission: 'agencia.guias',
    },
    component: () => import("@/views/agencia-viajes/guias/index.vue"),
  },
  {
    path: "/agencia-viajes/guias/:id",
    name: "agencia.guias.show",
    meta: {
      title: setTitle("Tarifas de Guía"),
      authRequired: true,
      permission: 'agencia.guias',
    },
    component: () => import("@/views/agencia-viajes/guias/detalle.vue"),
  },
  {
    path: "/agencia-viajes/configuracion",
    name: "agencia.configuracion.index",
    meta: {
      title: setTitle("Configuración de la Agencia"),
      authRequired: true,
      permission: 'agencia.configuracion',
    },
    component: () => import("@/views/agencia-viajes/configuracion/index.vue"),
  },
  // Cotizador (Sesión 11b) — permiso propio 'agencia.cotizaciones',
  // distinto de los 5 de 11a (ver TODO.md).
  {
    path: "/agencia-viajes/cotizador",
    name: "agencia.cotizador.index",
    meta: {
      title: setTitle("Cotizaciones"),
      authRequired: true,
      permission: 'agencia.cotizaciones',
    },
    component: () => import("@/views/agencia-viajes/cotizador/index.vue"),
  },
  {
    path: "/agencia-viajes/cotizador/nueva",
    name: "agencia.cotizador.nueva",
    meta: {
      title: setTitle("Nueva Cotización"),
      authRequired: true,
      permission: 'agencia.cotizaciones',
    },
    component: () => import("@/views/agencia-viajes/cotizador/nueva.vue"),
  },
  {
    path: "/agencia-viajes/cotizador/:id",
    name: "agencia.cotizador.editar",
    meta: {
      title: setTitle("Editar Cotización"),
      authRequired: true,
      permission: 'agencia.cotizaciones',
    },
    component: () => import("@/views/agencia-viajes/cotizador/editar.vue"),
  },

];


// NUEVO — agregar antes del export
// routes.ts

// ... resto de imports

const portalRoutes = [
  {
    path: '/',
    component: PortalLayout,   // ← layout principal
    meta: { title: setTitle('TechTore') },
    children: [
      {
        path: '',              // ruta raíz (/) → HomePortal
        name: 'portal.home',
        meta: { title: setTitle('Inicio') },
        component: () => import('@/views/portal/HomePortal.vue'),
      },
      {
        path: 'ofertas',
        name: 'portal.ofertas',
        meta: { title: setTitle('Ofertas') },
        component: () => import('@/views/portal/Ofertas.vue'),
      },
      {
        path: 'catalogo',//esta ruta es para el catalogo de productos
        name: 'portal.catalogo',
        meta: { title: setTitle('Catalogo') },
        component: () => import('@/views/portal/Catalogo.vue'),
      },
      {
        path: 'productos/:id',//esta ruta es para el detalle de un producto
        name: 'portal.producto',
        meta: { title: setTitle('Producto') },
        component: () => import('@/views/portal/Producto.vue'),
      },
      {
        path: 'sistemas', //esta ruta es para el catalogo de sistemas
        name: 'portal.sistemas',
        meta: { title: setTitle('Sistemas Empresariales') },
        component: () => import('@/views/portal/Sistemas.vue'),
      },
      {
        path: 'serviciotecnico', //esta ruta es para el catalogo de sistemas
        name: 'portal.serviciotecnico',
        meta: { title: setTitle('Servicio Tecnico') },
        component: () => import('@/views/portal/ServicioTecnico.vue'),
      },
      {
        path: 'nosotros',
        name: 'portal.nosotros',
        meta: { title: setTitle('Nosotros') },
        component: () => import('@/views/portal/Nosotros.vue'),
      },
      {
        path: 'contactos',
        name: 'portal.contactos',
        meta: { title: setTitle('Contacto') },
        component: () => import('@/views/portal/Contactos.vue'),
      },
      {
        path: '/sistemas/detalle/:id',
        name: 'sistemas.detalle',
        meta: { title: setTitle('Sistema Detalle') },
        component: () => import('@/views/portal/SistemaDetalle.vue'),
      },
      {
        path: 'carrito',
        name: 'portal.carrito',
        meta: { title: setTitle('Carrito') },
        component: () => import('@/views/portal/Carrito.vue'),
      },
      /*       {
              path: 'micuenta',
              name: 'portal.micuenta.',
              meta: { title: setTitle('Mi cuenta') },
              component: () => import('@/views/portal/MiCuenta.vue'),
            }, */
      {
        path: 'favoritos',
        name: 'portal.favoritos',
        meta: { title: setTitle('Favoritos') },
        component: () => import('@/views/portal/Favoritos.vue'),
      },
      {
        path: 'checkout',
        name: 'portal.checkout',
        meta: { title: setTitle('Checkout') },
        component: () => import('@/views/portal/Checkout.vue'),
      },
      {
        path: 'login',//esta ruta es para el login del portal para los clientes
        name: 'portal.login',
        meta: { title: setTitle('Iniciar sesión') },
        component: () => import('@/views/portal/ClientLogin.vue')
      },
      {
        path: 'registro',//esta ruta es para registrar cliente nuevo por el portal cuando no tiene cuenta o no es invitado
        name: 'portal.register',
        meta: { title: setTitle('Registro') },
        component: () => import('@/views/portal/ClientRegister.vue')
      },
      {
        path: 'micuenta',
        name: 'portal.micuenta',
        meta: { title: setTitle('Mi cuenta'), clientAuth: true },
        component: () => import('@/views/portal/MiCuenta.vue'),
        children: [
          {
            path: 'panel',
            name: 'portal.micuenta.panel',
            meta: { title: setTitle('Dashboard'), clientAuth: true },
            component: () => import('@/views/portal/MiCuentaDashboard.vue'),
          },
          {
            path: 'perfil',
            name: 'portal.micuenta.perfil',
            meta: { title: setTitle('Mi perfil'), clientAuth: true },
            component: () => import('@/views/portal/profile/Profile.vue'),
          },
          {
            path: 'pedidos',
            name: 'portal.micuenta.pedidos',
            meta: { title: setTitle('Mis pedidos'), clientAuth: true },
            component: () => import('@/views/portal/PedidosCliente.vue'),
          },
          {
            path: 'comprobantes',
            name: 'portal.micuenta.comprobantes',
            meta: { title: setTitle('Mis Comprobantes'), clientAuth: true },
            component: () => import('@/views/portal/ComprobantesCliente.vue'),
          },
          {
            path: 'direcciones',
            name: 'portal.micuenta.datos',
            meta: { title: setTitle('Mis direcciones'), clientAuth: true },
            // component: () => import('@/views/portal/MisDatos.vue'), 
          },
          {
            path: 'seguridad',
            name: 'portal.micuenta.security',
            meta: { title: setTitle('Seguridad'), clientAuth: true },
            component: () => import('@/views/portal/profile/Security.vue'),
          },

        ]
      },
    ],
  },

]

export const allRoute = [
  ...portalRoutes,    // <-- portal primero
  ...adminPortalRoutes,
  ...authRoutes,
  ...errorRoutes,
  ...dashboardRoutes,
  ...accessRoutes,
  ...comercialRoutes,
];
