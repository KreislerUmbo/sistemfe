import { createRouter, createWebHistory } from "vue-router";
import { allRoute } from "@/router/routes";
import { useAuthStore } from "@/stores/auth";
import type { User } from "@/types/auth";
import { useClientAuthStore } from '@/stores/clientAuth'
import { useMenuStore } from "@/stores/menu";




const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: allRoute,
});

router.beforeEach((to, from, next) => {
  const title = to.meta.title;
  if (title) {
    document.title = title.toString();
  }
  next();
});

router.beforeEach((routeTo, routeFrom, next) => {
  // Check if auth is required on this route
  // (including nested routes).

  const useAuth = useAuthStore();
  const clientAuth = useClientAuthStore()
  const clientRequired = routeTo.matched.some(
    route => route.meta.clientAuth
  )
  if (clientRequired && !clientAuth.isAuthenticated) {
    return next({
      name: 'portal.login',
      query: { redirectedFrom: routeTo.fullPath }
    })
  }

  const authLogin = routeTo.matched.some((route) => route.meta.authLogin);
  if (authLogin) {
    if (useAuth.isAuthenticated()) {
      return redirectToDashboard();
    }
  }

  const authRequired = routeTo.matched.some((route) => route.meta.authRequired);

  // If auth isn't required for the route, just continue.
  if (!authRequired) return next();

  // If auth is required and the user is logged in...
  if (authRequired && useAuth.isAuthenticated()) {
    // Fase 2b (§6) — cubre el caso de sesión restaurada desde localStorage
    // (F5 en el navegador): saveSession() ya dispara el fetch en el login,
    // pero acá no hay ningún "login" que lo dispare. Fire-and-forget: no
    // bloquea la navegación, el sidebar solo queda vacío el instante en
    // que tarda en resolver.
    useMenuStore().ensureLoaded();

    if (useAuth.isPermitedRoute(routeTo.meta.permission + "")) {
      return next();
    } else {
      return redirectToNoAuthorize();
    }
  }

  // If auth is required and the user is NOT currently logged in,
  // redirect to login.
  redirectToLogin();

  function redirectToLogin() {
    // Pass the original route to the login component
    next({ name: "auth.sign-in", query: { redirectedFrom: routeTo.fullPath } });
  }
  function redirectToDashboard() {
    next({ name: "dashboards.analytics" });
  }
  function redirectToNoAuthorize() {
    next({ name: "error.500" });
  }
});

export default router;
