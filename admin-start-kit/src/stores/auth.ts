import { defineStore } from "pinia";
import router from "@/router";
// import { useSessionStorage } from "@vueuse/core";
import type { User } from "@/types/auth";
import { ref } from "vue";

export const useAuthStore = defineStore("auth_store", () => {
  const user = ref(localStorage.getItem("user") ? JSON.parse(localStorage.getItem("user") || '') : null);//useSessionStorage<string | null>("RIZZ_VUE_USER", null);

  const saveSession = (newUser: User) => {
    localStorage.setItem("token", newUser.token || '');
    localStorage.setItem("user", JSON.stringify(newUser));
    user.value = newUser;//JSON.stringify(newUser);
  };

  const removeSession = () => {
    user.value = null;
    localStorage.removeItem("token");
    localStorage.removeItem("user");
    setTimeout(() => {
      router.push("/auth/sign-in");
    }, 25);
  };

  const isAuthenticated = () => user.value != null;//esta parte lo que hace es verificar si el usuario esta autenticado

  const getUser = () => user.value;

  // "a|b" = OR (tiene cualquiera de los dos) — mismo criterio que el
  // middleware permission: de Spatie en el backend (Módulo Caja — Fase 5,
  // necesario para gatear cash.history/cash.dashboard a
  // "cash.open_session O cash.view_all"). Un solo permiso sin "|" se
  // comporta exactamente igual que antes.
  const isPermitedRoute = (permission: string) => {
    let USER = user.value;
    if (USER && USER.role?.name != 'Super-Admin') {
      //LISTA DE PERMISOS DEL USUARIO AUTENTICADO
      let permissions = USER.permissions;
      if (permission == 'all') {
        return true;
      }
      let opciones = permission.split('|');
      return opciones.some((p) => permissions?.includes(p));
    }
    return true;
  }

  return {
    user,
    saveSession,
    removeSession,
    isAuthenticated,
    isPermitedRoute,
    getUser,
  };
});
