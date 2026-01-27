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

  const isAuthenticated = () => user.value != null;

  const getUser = () => user.value;

  const isPermitedRoute = (permission: string) => {
    let USER = user.value;
    if (USER && USER.role?.name != 'Super-Admin') {
      //LISTA DE PERMISOS DEL USUARIO AUTENTICADO
      let permissions = USER.permissions;
      if (permissions?.includes(permission) || permission == 'all') {
        return true;
      } else {
        return false;
      }
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
