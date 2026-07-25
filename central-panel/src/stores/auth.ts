import { defineStore } from 'pinia';
import { ref } from 'vue';

export interface CentralUser {
  id: number;
  name: string;
  email: string;
  roles: string[];
}

// Claves de localStorage propias de este proyecto (central_*) — nunca
// comparten storage con admin-start-kit: son orígenes de navegador
// distintos, así que la separación ya es real por defecto, pero el prefijo
// deja explícito que este token es del guard 'central', no del guard 'api'
// de un tenant.
const TOKEN_KEY = 'central_token';
const USER_KEY = 'central_user';

export const useAuthStore = defineStore('central_auth', () => {
  const token = ref<string | null>(localStorage.getItem(TOKEN_KEY));
  const user = ref<CentralUser | null>(
    localStorage.getItem(USER_KEY) ? JSON.parse(localStorage.getItem(USER_KEY) as string) : null,
  );

  const saveSession = (newToken: string, newUser: CentralUser) => {
    token.value = newToken;
    user.value = newUser;
    localStorage.setItem(TOKEN_KEY, newToken);
    localStorage.setItem(USER_KEY, JSON.stringify(newUser));
  };

  const logout = () => {
    token.value = null;
    user.value = null;
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
  };

  const isAuthenticated = () => token.value !== null;

  return { token, user, saveSession, logout, isAuthenticated };
});
