<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <Listree />
      <ListreeCustomIcon />
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { ref, onMounted } from "vue";
import Listree from "@/views/pages/treeview/components/Listree.vue";
import ListreeCustomIcon from "@/views/pages/treeview/components/ListreeCustomIcon.vue";
import listree from "listree";

const listreeInstance = ref();

onMounted(() => {
  listreeInstance.value = new listree();

  document
    .getElementsByClassName("listree")[0]
    .querySelectorAll(".listree-submenu-heading")
    .forEach(function (element, ind) {
      if (ind == 1 || ind == 0) {
        element.classList.remove("collapsed");
        element.classList.add("expanded");
      }
    });

  document
    .getElementsByClassName("listree")[1]
    .querySelectorAll(".listree-submenu-heading")
    .forEach(function (element, ind) {
      if (ind == 1 || ind == 0) {
        element.classList.remove("collapsed");
        element.classList.add("expanded");
      }
    });
});

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
