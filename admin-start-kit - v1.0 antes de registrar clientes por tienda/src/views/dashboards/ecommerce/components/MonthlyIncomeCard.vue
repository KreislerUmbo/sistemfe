<template>
  <b-card no-body class="shadow-none border mb-3 mb-lg-0">
    <b-card-body>
      <b-row class="align-items-center">
        <b-col class="text-center">
          <span class="fs-18 fw-semibold">{{ item.stat }}</span>
          <h6 class="text-uppercase text-muted mt-2 m-0">{{ item.title }}</h6>
        </b-col>
      </b-row>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { StatType } from "@/views/dashboards/ecommerce/components/types";

defineProps({
  item: {
    type: Object as PropType<StatType>,
    required: true,
  },
});
</script>
