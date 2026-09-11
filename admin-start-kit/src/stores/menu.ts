import { defineStore } from "pinia";
import { ref } from "vue";
import httpClient from "@/helpers/http-client";
import type { MenuItemType } from "@/types/menu";

// Fase 2b (plan-modulo-menus-y-roles.md §6) — hidrata el sidebar desde
// GET /me/menu en vez del `MENU_ITEMS` estático viejo
// (assets/data/menu-items.ts). El backend (MenuResolver) ya filtra por
// permisos reales del usuario y arma el árbol — acá solo se mapea la forma
// {codigo, label, icono, ruta, hijos} al MenuItemType que el AppMenu ya
// sabe renderizar, sin volver a filtrar por permiso (ya viene filtrado).
type MenuNodoApi = {
  codigo: string;
  label: string;
  icono: string | null;
  ruta: string | null;
  hijos: MenuNodoApi[];
};

const mapearNodo = (nodo: MenuNodoApi): MenuItemType => ({
  key: nodo.codigo,
  label: nodo.label,
  icon: nodo.icono ?? undefined,
  route: nodo.ruta ? { name: nodo.ruta } : undefined,
  children: nodo.hijos.length > 0 ? nodo.hijos.map(mapearNodo) : undefined,
});

export const useMenuStore = defineStore("menu_store", () => {
  const items = ref<MenuItemType[]>([]);
  const loaded = ref(false);
  let enVuelo: Promise<void> | null = null;

  const fetch = (): Promise<void> => {
    if (enVuelo) return enVuelo;

    enVuelo = httpClient
      .get("me/menu")
      .then((res) => {
        const menu: MenuNodoApi[] = res.data?.menu ?? [];
        items.value = menu.map(mapearNodo);
        loaded.value = true;
      })
      .catch(() => {
        // Sidebar vacío es preferible a uno roto — no bloquea la sesión
        // si /me/menu falla (ej. red caída, tenant sin catálogo sembrado).
        items.value = [];
      })
      .finally(() => {
        enVuelo = null;
      });

    return enVuelo;
  };

  // Idempotente: si ya se cargó (o hay una carga en curso), no repite la
  // petición — pensado para llamarse en cada navegación autenticada sin
  // costo extra una vez cargado.
  const ensureLoaded = (): Promise<void> => {
    if (loaded.value) return Promise.resolve();
    return fetch();
  };

  // §6 punto 1 del plan: "se vuelve a pedir si cambia el usuario activo o
  // su rol" — logout limpia el estado para que el próximo login (mismo u
  // otro usuario) dispare un fetch real, no sirva el árbol del usuario
  // anterior desde memoria.
  const clear = () => {
    items.value = [];
    loaded.value = false;
    enVuelo = null;
  };

  return { items, loaded, fetch, ensureLoaded, clear };
});
