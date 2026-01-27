<template>
  <b-card no-body class="shadow-none border mb-3 mb-lg-0">
    <b-card-body>
      <div class="d-flex align-items-center">
        <i class="fs-24 align-self-center me-2" :class="item.icon"></i>
        <div class="flex-grow-1 text-truncate">
          <p class="text-dark mb-0 fw-semibold fs-13">{{ item.title }}</p>
          <h3 class="mt-1 mb-0 fs-18 fw-bold">
            {{ item.prefix }}{{ item.amount }}{{ item.suffix }}
            <span class="fs-11 text-muted fw-normal">{{ item.subtitle }}</span>
          </h3>
        </div>
      </div>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { StateType } from "@/views/apps/ecommerce/customers/components/types";

defineProps({
  item: {
    type: Object as PropType<StateType>,
    required: true,
  },
});
</script>
