<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <StatWidgets />
    </b-row>
    <b-row class="justify-content-center">
      <Files />
    </b-row>
  </DefaultLayout>
</template>

<script setup lang="ts">
import StatWidgets from "@/views/advanced/file-manager/components/StatWidgets.vue";
import Files from "@/views/advanced/file-manager/components/Files.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
