<template>
  <b-card no-body>
    <b-card-body>
      <div class="text-center">
        <b-badge
          :variant="null"
          class="bg-pink-subtle text-pink mt-0 py-1 px-2 mx-auto"
          v-if="item.isPopular"
          >Popular</b-badge
        >
        <h6 class="pt-3 pb-2 m-0 fs-18 fw-medium">{{ item.plan }}</h6>
        <p class="text-muted pt-2 mb-0">
          {{ item.description }}
        </p>
        <div class="pt-3">
          <h2 class="d-inline-block">{{ currency }}{{ item.price }}</h2>
          <small class="font-12 text-muted"> /month</small>
        </div>
        <hr class="hr-dashed" />
        <ul class="list-unstyled pricing-content text-start pt-3 border-0 mb-0">
          <li v-for="(feat, idx) in item.features" :key="idx">{{ feat }}</li>
        </ul>
        <a
          href="#"
          class="btn py-2 px-5 mt-3 w-100"
          :class="item.isPopular ? 'btn-primary' : 'btn-dark'"
        >
          <span> Get Started </span>
        </a>
      </div>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { PricingDataType } from "@/views/pages/pricing/components/types";
import { currency } from "@/helpers/constants";

defineProps({
  item: {
    type: Object as PropType<PricingDataType>,
    required: true,
  },
});
</script>
