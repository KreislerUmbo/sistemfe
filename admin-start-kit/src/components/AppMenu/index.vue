<template>
  <ul class="navbar-nav mb-auto w-100">
    <template v-for="(item, idx) in menuItems || []" :key="idx">
      <li
        v-if="item.isTitle"
        class="menu-label"
        :class="!idx ? 'pt-0 mt-0' : 'mt-2'"
      >
        <small class="label-border" v-if="idx">
          <div class="border_left hidden-xs"></div>
          <div class="border_right"></div>
        </small>
        <span>{{ item.label }}</span>
      </li>

      <template v-else>
        <MenuItemWithChildren
          v-if="item.children"
          :item="item"
          className="nav-item"
          linkClassName="nav-link"
          subMenuClassName="nav flex-column"
        />

        <MenuItem
          v-else
          :item="item"
          linkClassName="nav-link"
          className="nav-item"
        />
      </template>
    </template>
  </ul>
</template>

<script setup lang="ts">
import type { MenuItemType } from "@/types/menu";
import MenuItemWithChildren from "@/components/AppMenu/MenuItemWithChildren.vue";
import MenuItem from "@/components/AppMenu/MenuItem.vue";

type AppMenuProps = {
  menuItems: Array<MenuItemType>;
};

defineProps<AppMenuProps>();
</script>
