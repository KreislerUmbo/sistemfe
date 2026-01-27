import { createRouter, createWebHistory } from "vue-router";
import { allRoute } from "@/router/routes";
import { useAuthStore } from "@/stores/auth";
import type { User } from "@/types/auth";

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

  const authLogin = routeTo.matched.some((route) => route.meta.authLogin);
  if(authLogin){
    if (useAuth.isAuthenticated()) {
      return  redirectToDashboard();
    }
  }

  const authRequired = routeTo.matched.some((route) => route.meta.authRequired);

  // If auth isn't required for the route, just continue.
  if (!authRequired) return next();

  // If auth is required and the user is logged in...
  if (authRequired && useAuth.isAuthenticated()) {
    if(useAuth.isPermitedRoute(routeTo.meta.permission+"")){
      return next();
    }else{
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
    next({ name: "dashboards.analytics"});
  }
  function redirectToNoAuthorize() {
    next({ name: "error.500"});
  }
});

export default router;
