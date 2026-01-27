<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <Timeline1 />
      <Timeline2 />
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import Timeline1 from "@/views/pages/timeline/components/Timeline1.vue";
import Timeline2 from "@/views/pages/timeline/components/Timeline2.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
