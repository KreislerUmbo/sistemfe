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
