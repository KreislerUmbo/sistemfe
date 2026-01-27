<template>
  <b-card no-body>
    <b-card-body>
      <div class="text-center">
        <i class="d-inline-block mt-2 display-4" :class="item.icon"></i>
        <h6 class="pt-3 pb-2 m-0 fs-18 fw-medium">{{ item.plan }}</h6>
        <ul
          class="list-unstyled pricing-content text-center pt-2 border-0 mb-3"
        >
          <li v-for="(feat, idx) in item.features" :key="idx">{{ feat }}</li>
        </ul>
        <hr class="hr-dashed" />
        <div class="pt-2">
          <h2 class="d-inline-block">{{ currency }}{{ item.price }}</h2>
          <small class="font-12 text-muted"> /month</small>
        </div>
        <a href="#" class="btn btn-dark py-2 px-3 mt-2"
          ><span>Get Started</span></a
        >
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
