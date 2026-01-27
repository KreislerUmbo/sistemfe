<template>
  <b-col md="6" lg="4">
    <b-card no-body>
      <b-card-header>
        <b-row class="align-items-center">
          <div class="col">
            <b-card-title>Categories Data</b-card-title>
          </div>
          <div class="col-auto">
            <div class="text-center">
              <h6 class="text-uppercase text-muted mt-1 m-0">
                <span class="fs-16 fw-semibold">24+</span> Categories
              </h6>
            </div>
          </div>
        </b-row>
      </b-card-header>
      <b-card-body class="pt-0">
        <ApexChart
          :chart="categoriesChart"
          class="apex-charts mt-n2"
          id="customers"
        />
        <div class="text-center">
          <b-button type="button" variant="primary" class="mx-auto"
            >More Detail
            <i class="fa-solid fa-arrow-right-long"></i>
          </b-button>
        </div>
      </b-card-body>
    </b-card>
  </b-col>
</template>

<script setup lang="ts">
import ApexChart from "@/components/ApexChart.vue";
import { categoriesChart } from "@/views/dashboards/ecommerce/components/data";
</script>
