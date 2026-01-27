//import { permission } from "process";

const setTitle = (title: string) => {
  return title
    ? `${title} | Rizz Vue - Responsive Admin Dashboard Template`
    : "Rizz Vue | Responsive Admin Dashboard Template";
};

const authRoutes = [
  {
    path: "/auth/sign-in",
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
  {
    path: "/",
    name: "dashboards.analytics",
    meta: {
      title: setTitle("Analytics"),
      authRequired: true,
      permission: 'all',
    },
    component: () => import("@/views/dashboards/analytics/index.vue"),
  },
  {
    path: "/dashboards/ecommerce",
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

];


export const allRoute = [
  ...authRoutes,
  ...errorRoutes,
  ...dashboardRoutes,
  ...accessRoutes,
  ...comercialRoutes,
];
