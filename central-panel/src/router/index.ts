import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { public: true },
    },
    {
      path: '/',
      name: 'dashboard',
      component: () => import('@/views/DashboardView.vue'),
    },
    {
      path: '/tenants',
      name: 'tenants',
      component: () => import('@/views/TenantListView.vue'),
    },
    {
      path: '/tenants/:id',
      name: 'tenant-detail',
      component: () => import('@/views/TenantDetailView.vue'),
    },
    {
      path: '/audit-logs',
      name: 'audit-logs',
      component: () => import('@/views/AuditLogsView.vue'),
    },
    {
      path: '/planes',
      name: 'plans',
      component: () => import('@/views/PlansView.vue'),
    },
  ],
});

// Guard mínimo: cualquier ruta sin meta.public exige sesión central activa.
// Simétrico al interceptor 401 de httpClient.ts (ese cubre "el token dejó de
// ser válido a mitad de sesión"; este cubre "nunca hubo token").
router.beforeEach((to) => {
  const auth = useAuthStore();

  if (!to.meta.public && !auth.isAuthenticated()) {
    return { name: 'login' };
  }

  if (to.name === 'login' && auth.isAuthenticated()) {
    return { name: 'dashboard' };
  }
});

export default router;
