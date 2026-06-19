import { MENU_ITEMS } from "@/assets/data/menu-items";
import type { MenuItemType } from "@/types/menu";
import { useAuthStore } from "@/stores/auth";
const useAuth = useAuthStore();

export const getMenuItems = () => {
  // NOTE - You can fetch from server and return here as well
  let FILTER_MENU_ITEMS: MenuItemType[] = [];

  MENU_ITEMS.forEach((MENU) => {
    if (MENU.route && MENU.permission) {
      let IS_PERMITED = useAuth.isPermitedRoute(MENU.permission);
      if (IS_PERMITED) {
        FILTER_MENU_ITEMS.push(MENU);
      }
    } else if (MENU.children) {
      let SUB_MENUS: MenuItemType[] = [];
      MENU.children.forEach((children) => {
        let IS_PERMITED = children.permission ? useAuth.isPermitedRoute(children.permission) : false;
        if (IS_PERMITED) {
          SUB_MENUS.push(children);
        }
      })
      if (SUB_MENUS.length > 0) {
        MENU.children = SUB_MENUS;
        FILTER_MENU_ITEMS.push(MENU);
      }
    } else {
      if (MENU.permissions) {
        let headingF = MENU.permissions.filter((permission) => {
          let IS_PERMITED = useAuth.isPermitedRoute(permission);
          if (IS_PERMITED) {
            return true;
          }
          return false;
        })
        if (headingF.length > 0) {
          FILTER_MENU_ITEMS.push(MENU);
        }
      } else {
        FILTER_MENU_ITEMS.push(MENU);
      }
    }
  })
  return FILTER_MENU_ITEMS;
  //return MENU_ITEMS;
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
