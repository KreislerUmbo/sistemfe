<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Basic Line Chart">
          <ApexChart
            :chart="basicLineChart"
            class="apex-charts"
            id="apex_line1"
          />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Line with Data Labels">
          <ApexChart
            :chart="dataLabelsLineChart"
            class="apex-charts"
            id="apex_line2"
          />
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Basic Area Chart">
          <ApexChart
            :chart="basicAreaChart"
            class="apex-charts"
            id="apex_area1"
          />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Area Chart - Datetime X-Axis">
          <div class="toolbar hstack gap-1">
            <b-button variant="outline-light" size="sm" id="one_month"
              >1M</b-button
            >
            <b-button variant="outline-light" size="sm" id="six_months"
              >6M</b-button
            >
            <b-button variant="outline-light" size="sm" id="one_year" active
              >1Y</b-button
            >
            <b-button variant="outline-light" size="sm" id="ytd">YTD</b-button>
            <b-button variant="outline-light" size="sm" id="all">ALL</b-button>
          </div>
          <ApexChart :chart="areaChart" class="apex-charts" id="apex_area2" />
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Basic Column Chart">
          <ApexChart
            :chart="basicColumnChart"
            class="apex-charts"
            id="apex_column1"
          />
        </UIComponentCard>
      </b-col>

      <b-col md="6" lg="6">
        <UIComponentCard title="Column Chart With Datalabels">
          <ApexChart
            :chart="columnChart"
            class="apex-charts"
            id="apex_column2"
          />
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Basic Bar Chart">
          <ApexChart :chart="basicBarChart" class="apex-charts" id="apex_bar" />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Multiple Y-Axis Chart">
          <ApexChart
            :chart="multipleChart"
            class="apex-charts"
            id="apex_mixed"
          />
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="3D Bubble Chart">
          <ApexChart
            :chart="bubbleChart"
            class="apex-charts"
            id="apex_bubble"
          />
        </UIComponentCard>
      </b-col>

      <b-col md="6" lg="6">
        <UIComponentCard title="Simple Candlestick Chart">
          <ApexChart
            :chart="simpleCandlestickChart"
            class="apex-charts"
            id="apex_candlestick"
          />
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Basic Treemap">
          <ApexChart
            :chart="basicTreemapChart"
            class="apex-charts"
            id="treemap_1"
          />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Treemap Color">
          <ApexChart
            :chart="treemapColorChart"
            class="apex-charts"
            id="treemap_2"
          />
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Polar Area">
          <ApexChart
            :chart="polarAreaChart"
            class="apex-charts"
            id="polar_Area_1"
          />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Monochrome">
          <ApexChart
            :chart="monochromeChart"
            class="apex-charts"
            id="polar_Area_2"
          />
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="4">
        <UIComponentCard title="Simple Pie Chart">
          <ApexChart
            :chart="simplePieChart"
            class="apex-charts"
            id="apex_pie1"
          />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="4">
        <UIComponentCard title="Gradient Donut Chart">
          <ApexChart
            :chart="gradientDonutChart"
            class="apex-charts"
            id="apex_pie2"
          />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="4">
        <UIComponentCard title="Patterned Donut Chart">
          <ApexChart
            :chart="patternedDonutChart"
            class="apex-charts"
            id="apex_pie3"
          />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="4">
        <UIComponentCard title="Basic RadialBar Chart">
          <ApexChart
            :chart="basicRadialBarChart"
            class="apex-charts"
            id="apex_radialbar1"
          />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="4">
        <UIComponentCard title="Multiple RadialBars">
          <ApexChart
            :chart="multipleRadialBarsChart"
            class="apex-charts"
            id="apex_radialbar2"
          />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="4">
        <UIComponentCard title="Stroked Circular Guage">
          <ApexChart
            :chart="strokedCircularGuageChart"
            class="apex-charts"
            id="apex_radialbar3"
          />
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col cols="12">
        <UIComponentCard title="Sparkline inline">
          <b-row>
            <b-col md="4">
              <ApexChart
                :chart="sparkChartChart1"
                class="apex-charts"
                id="spark1"
              />
            </b-col>
            <b-col md="4">
              <ApexChart
                :chart="sparkChartChart2"
                class="apex-charts"
                id="spark2"
              />
            </b-col>
            <b-col md="4">
              <ApexChart
                :chart="sparkChartChart3"
                class="apex-charts"
                id="spark3"
              />
            </b-col>
          </b-row>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
import ApexChart from "@/components/ApexChart.vue";
import {
  basicLineChart,
  dataLabelsLineChart,
  basicAreaChart,
  areaChart,
  basicColumnChart,
  columnChart,
  basicBarChart,
  multipleChart,
  bubbleChart,
  simpleCandlestickChart,
  basicTreemapChart,
  treemapColorChart,
  polarAreaChart,
  monochromeChart,
  simplePieChart,
  gradientDonutChart,
  patternedDonutChart,
  basicRadialBarChart,
  multipleRadialBarsChart,
  strokedCircularGuageChart,
  sparkChartChart1,
  sparkChartChart2,
  sparkChartChart3,
} from "@/views/charts/apex/components/data";
</script>
