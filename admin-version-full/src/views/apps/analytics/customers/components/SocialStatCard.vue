<template>
  <b-card no-body>
    <b-card-body>
      <b-row class="d-flex justify-content-center border-dashed-bottom pb-3">
        <b-col cols="9">
          <p class="text-dark mb-0 fw-semibold fs-14">{{ item.name }}</p>
          <h3 class="mt-2 mb-0 fw-bold">
            {{ item.clickCount }} <span class="fs-13 text-muted">Click</span>
          </h3>
        </b-col>
        <b-col cols="3" class="align-self-center">
          <div
            class="d-flex justify-content-center align-items-center thumb-xl rounded-circle mx-auto"
            :class="item.variant"
          >
            <i
              class="h1 align-self-center mb-0 text-white"
              :class="item.icon"
            ></i>
          </div>
        </b-col>
      </b-row>
      <b-row class="d-flex justify-content-center mt-3 text-center">
        <b-col cols="6">
          <h5 class="mb-2 fs-18 mb-0 fw-bold">
            {{ item.audience.count }}
            <span class="text-success fw-normal fs-12"
              >{{ item.audience.change }}%</span
            >
          </h5>
          <p class="text-muted mb-0 fw-semibold fs-14">Audiance</p>
        </b-col>
        <b-col cols="6" class="align-self-center">
          <h5 class="mb-2 fs-18 mb-0 fw-bold">
            {{ item.commission.count }}
            <span class="text-danger fw-normal fs-12"
              >{{ item.commission.change }}%</span
            >
          </h5>
          <p class="text-muted mb-0 fw-semibold fs-14">Comission</p>
        </b-col>
      </b-row>
      <div class="text-center mt-3">
        <b-button type="button" variant="outline-primary" class="px-3"
          >Report</b-button
        >
      </div>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { SocialStatType } from "@/views/apps/analytics/customers/components/types";

defineProps({
  item: {
    type: Object as PropType<SocialStatType>,
    required: true,
  },
});
</script>
