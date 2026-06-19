<template>
  <b-col md="12" lg="8">
    <b-card no-body>
      <b-card-header>
        <b-row class="align-items-center">
          <div class="col">
            <b-card-title>Monthly Avg. Income</b-card-title>
          </div>
          <div class="col-auto">
            <b-dropdown variant="light" menu-class="dropdown-menu-end">
              <template #button-content>
                <i class="icofont-calendar fs-5 me-1"></i> This Year<i
                  class="las la-angle-down ms-1"
                ></i>
              </template>
              <b-dropdown-item>Today</b-dropdown-item>
              <b-dropdown-item>Last Week</b-dropdown-item>
              <b-dropdown-item>Last Month</b-dropdown-item>
              <b-dropdown-item>This Year</b-dropdown-item>
            </b-dropdown>
          </div>
        </b-row>
      </b-card-header>
      <b-card-body class="pt-0">
        <ApexChart
          :chart="monthlyIncomeChart"
          class="apex-charts"
          id="monthly_income"
        />
        <b-row>
          <b-col
            md="6"
            lg="3"
            v-for="(item, idx) in monthlyIncomeData"
            :key="idx"
          >
            <MonthlyIncomeCard :item="item" />
          </b-col>
        </b-row>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import ApexChart from "@/components/ApexChart.vue";
import {
  monthlyIncomeChart,
  monthlyIncomeData,
} from "@/views/dashboards/ecommerce/components/data";
import MonthlyIncomeCard from "@/views/dashboards/ecommerce/components/MonthlyIncomeCard.vue";
</script>
