<template>
  <li :class="className">
    <a
      class="nav-link"
      :class="{ active: menuItemActive(item.key, currentRouteName) }"
      @click="visible = !visible"
      data-bs-toggle="collapse"
      role="button"
      :aria-expanded="toggle() ? 'true' : 'false'"
      :aria-controls="item.key"
    >
      <i class="menu-icon" :class="item.icon" v-if="item.icon" />
      <span> {{ item.label }} </span>

      <b-badge
        :variant="null"
        class="rounded text-success bg-success-subtle ms-1"
        v-if="item.badge"
      >
        {{ item.badge.text }}
      </b-badge>
    </a>
    <b-collapse :id="item.key" :visible="toggle()">
      <ul :class="subMenuClassName">
        <template v-for="(link, idx) in item.children || []" :key="idx">
          <MenuItemWithChildren
            v-if="link.children"
            :item="link"
            className="nav-item"
            subMenuClassName="nav flex-column"
          />
          <MenuItem
            v-else
            :item="link"
            className="nav-item"
            linkClassName="nav-link"
          />
        </template>
      </ul>
    </b-collapse>
  </li>
</template>

<script setup lang="ts">
import { ref, watch } from "vue";
import MenuItem from "@/components/AppMenu/MenuItem.vue";
import { menuItemActive } from "@/components/AppMenu/menuActivation";
import type { SubMenus } from "@/types/menu";
const props = defineProps<SubMenus>();

const visible = ref(true);

import router from "@/router";
const currentRouteName = router.currentRoute.value.name;

const toggle = () => {
  return visible.value || menuItemActive(props.item.key, currentRouteName);
};

watch(
  () => currentRouteName,
  () => {
    visible.value = false;

    document.body.scrollTop = 0; // For Safari
    document.documentElement.scrollTop = 0; // For Chrome, Firefox, IE and Opera
  },
  { immediate: true, deep: true },
);
</script>
