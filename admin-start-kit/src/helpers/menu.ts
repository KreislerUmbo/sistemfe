import type { MenuItemType } from "@/types/menu";
import { useMenuStore } from "@/stores/menu";

// Fase 2b (plan-modulo-menus-y-roles.md §6) — el árbol ya viene filtrado
// por permiso desde el backend (GET /me/menu, MenuResolver) — acá ya no se
// vuelve a filtrar con isPermitedRoute() como antes (eso filtraba el
// MENU_ITEMS estático viejo, ver git history de este archivo). El store
// devuelve [] mientras no haya cargado o si el usuario no tiene ningún
// ítem visible.
export const getMenuItems = (): MenuItemType[] => {
  return useMenuStore().items;
};

export const findAllParent = (
  menuItems: MenuItemType[],
  menuItem: MenuItemType,
): string[] => {
  let parents: string[] = [];
  const parent = findMenuItem(menuItems, menuItem.parentKey);
  if (parent) {
    parents.push(parent.key);
    if (parent.parentKey) {
      parents = [...parents, ...findAllParent(menuItems, parent)];
    }
  }
  return parents;
};

export const getMenuItemFromURL = (
  items: MenuItemType | MenuItemType[],
  url: string,
): MenuItemType | undefined => {
  if (items instanceof Array) {
    for (const item of items) {
      const foundItem = getMenuItemFromURL(item, url);
      if (foundItem) {
        return foundItem;
      }
    }
  } else {
    if (items.url == url) return items;
    if (items.children != null) {
      for (const item of items.children) {
        if (item.url == url) return item;
      }
    }
  }
};

export const findMenuItem = (
  menuItems: MenuItemType[] | undefined,
  menuItemKey: MenuItemType["key"] | undefined,
): MenuItemType | null => {
  if (menuItems && menuItemKey) {
    for (const item of menuItems) {
      if (item.key === menuItemKey) {
        return item;
      }
      const found = findMenuItem(item.children, menuItemKey);
      if (found) return found;
    }
  }
  return null;
};
