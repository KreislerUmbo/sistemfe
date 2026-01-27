<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="8">
        <UIComponentCard title="Line Chart">
          <ChartJs
            id="lineChart"
            :config="lineChartConfig"
            width="300"
            height="300"
          />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="4">
        <UIComponentCard title="Donut Chart">
          <ChartJs
            id="doughnut"
            :config="doughnutChartConfig"
            width="300"
            height="300"
          />
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="8">
        <UIComponentCard title="Bar Chart">
          <ChartJs id="bar" :config="barChartConfig" width="300" height="300" />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="4">
        <UIComponentCard title="Polar Chart">
          <ChartJs
            id="polarArea"
            :config="polarAreaChartConfig"
            width="300"
            height="300"
          />
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Pie Chart">
          <ChartJs id="pie" :config="pieChartConfig" width="300" height="300" />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Radar Chart">
          <ChartJs
            id="radar"
            :config="radarChartConfig"
            width="300"
            height="300"
          />
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import ChartJs from "@/components/ChartJs.vue";
import {
  lineChartConfig,
  barChartConfig,
  doughnutChartConfig,
  polarAreaChartConfig,
  pieChartConfig,
  radarChartConfig,
} from "@/views/charts/chartjs/components/data";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
