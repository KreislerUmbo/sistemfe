<template>
  <b-card no-body>
    <b-card-body class="border-dashed-bottom pb-3">
      <b-row class="d-flex justify-content-between">
        <div class="col-auto">
          <div
            class="d-flex justify-content-center align-items-center thumb-xl border border-secondary rounded-circle"
          >
            <i
              class="h1 align-self-center mb-0 text-secondary"
              :class="item.icon"
            />
          </div>
          <h5 class="mt-2 mb-0 fs-14">{{ item.title }}</h5>
        </div>
        <b-col class="align-self-center">
          <ApexChart
            :chart="statWidgetsChart"
            :width="120"
            class="apex-charts float-end"
          />
        </b-col>
      </b-row>
    </b-card-body>
    <b-card-body>
      <b-row class="d-flex justify-content-center">
        <b-col cols="12" md="6">
          <h2 class="fs-22 mt-0 mb-1 fw-bold">{{ item.stat }}</h2>
          <p class="mb-0 text-truncate text-muted">
            <span class="text-success"
              ><i class="mdi mdi-trending-up"></i> {{ item.change }}% </span
            >{{ item.subTitle }}
          </p>
        </b-col>
        <b-col
          cols="12"
          md="6"
          class="align-self-center text-start text-md-end"
        >
          <b-button
            type="button"
            :variant="item.buttonVariant"
            size="sm"
            class="px-2 mt-2 mt-md-0"
          >
            View Report
          </b-button>
        </b-col>
      </b-row>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { StatType } from "@/views/dashboards/ecommerce/components/types";
import { statWidgetsChart } from "@/views/dashboards/ecommerce/components/data";

import ApexChart from "@/components/ApexChart.vue";

defineProps({
  item: {
    type: Object as PropType<StatType>,
    required: true,
  },
});
</script>
