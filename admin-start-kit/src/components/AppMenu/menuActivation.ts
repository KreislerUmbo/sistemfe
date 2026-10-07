import { useMenuStore } from "@/stores/menu";
import type { MenuItemType } from "@/types/menu";
import { useRoute, type RouteRecordName } from "vue-router";

let activeMenuItem = {};

const getMatchingMenuItems = (
  data: MenuItemType[],
  currentRouteName: RouteRecordName | null | undefined,
) => {
  const matchingItems: string[] = [];

  // Marca cada grupo que contenga la ruta actual en CUALQUIER nivel, no solo
  // como hijo directo: el árbol de /me/menu no trae parentKey, así que con
  // Comercial › Ventas › Mis ventas el abuelo "Comercial" quedaba cerrado y
  // ocultaba el ítem activo.
  const traverse = (item: MenuItemType): boolean => {
    const selfMatch = !!item.route?.name && item.route.name === currentRouteName;
    let descendantMatch = false;
    for (const child of item.children ?? []) {
      if (traverse(child)) descendantMatch = true;
    }
    if (descendantMatch) matchingItems.push(item.key);
    return selfMatch || descendantMatch;
  };

  data.forEach(traverse);

  return matchingItems;
};

export const menuItemActive = (
  key: string,
  currentRouteName: RouteRecordName | null | undefined,
) => {
  activeMenuItem = getMatchingMenuItems(useMenuStore().items, currentRouteName);
  return activeMenuItem && Object.values(activeMenuItem).includes(key);
};

// Ruta que el menú considera activa: las pantallas sin entrada propia (ej.
// registrar/editar producto) declaran `meta.menuActivo` con la ruta del
// listado, así ese ítem queda resaltado y su grupo abierto. Llamar solo
// dentro de setup() (usa useRoute()).
export const rutaActivaDelMenu = (): RouteRecordName | null | undefined => {
  const ruta = useRoute();
  return (ruta.meta.menuActivo as RouteRecordName | undefined) ?? ruta.name;
};
