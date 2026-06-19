<template>
  <b-col md="6" lg="8">
    <b-card no-body>
      <b-card-header>
        <b-row class="align-items-center">
          <div class="col">
            <b-card-title>Organic Traffic In World</b-card-title>
          </div>

          <div class="col-auto">
            <b-dropdown variant="light" menu-class="dropdown-menu-end">
              <template #button-content>
                <i class="icofont-calendar fs-5 me-1"></i> Today<i
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
        <b-row>
          <b-col lg="8">
            <JsVectorMap
              id="map_2"
              :height="320"
              :options="worldMapLinesOptions"
            />
          </b-col>
          <b-col lg="4" class="align-self-center">
            <div
              class="d-flex align-items-center my-3"
              v-for="(item, idx) in organicTraffic"
              :key="idx"
            >
              <img
                :src="item.countryFlag"
                class="thumb-sm align-self-center rounded-circle"
                alt="..."
              />
              <div class="flex-grow-1 ms-2">
                <h5 class="mb-1">{{ item.count }}</h5>
                <p class="text-muted mb-0">
                  {{ item.country }}. Last Month
                  <span class="text-success"
                    >{{ item.percentage }}% <i class="mdi mdi-arrow-up"></i
                  ></span>
                </p>
              </div>
            </div>
          </b-col>
        </b-row>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import JsVectorMap from "@/components/JsVectorMap.vue";
import {
  organicTraffic,
  worldMapLinesOptions,
} from "@/views/dashboards/analytics/components/data";
</script>
