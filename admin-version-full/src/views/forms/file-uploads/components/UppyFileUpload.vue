<template>
  <div id="drag-drop-area"></div>
</template>
<script setup lang="ts">
import { onMounted } from "vue";

import Uppy from "@uppy/core";
import Dashboard from "@uppy/dashboard";
import Tus from "@uppy/tus";

onMounted(() => {
  let uppy = new Uppy()
    .use(Dashboard, {
      inline: true,
      target: "#drag-drop-area",
    })
    .use(Tus, { endpoint: "https://tusd.tusdemo.net/files/" });

  uppy.on("complete", (result) => {});
});
</script>
