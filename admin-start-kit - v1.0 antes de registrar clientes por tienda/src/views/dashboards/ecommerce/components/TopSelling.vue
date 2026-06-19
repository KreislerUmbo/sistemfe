<template>
  <b-col md="6" lg="4">
    <b-card no-body>
      <b-card-header>
        <b-row class="align-items-center">
          <div class="col">
            <b-card-title>Top Selling by Country</b-card-title>
          </div>
          <div class="col-auto">
            <b-dropdown variant="light" menu-class="dropdown-menu-end">
              <template #button-content>
                <i class="icofont-calendar fs-5 me-1"></i> This Month<i
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
        <b-table-simple responsive class="mb-0">
          <b-tbody>
            <b-tr v-for="(item, idx) in topSelling" :key="idx">
              <b-td class="px-0">
                <div class="d-flex align-items-center">
                  <img
                    :src="item.flag"
                    class="me-2 align-self-center thumb-md rounded-circle"
                    alt="..."
                  />
                  <div class="flex-grow-1 text-truncate">
                    <h6 class="m-0 text-truncate">{{ item.name }}</h6>
                    <div class="d-flex align-items-center">
                      <div
                        class="progress bg-primary-subtle w-100"
                        style="height: 5px"
                        role="progressbar"
                        aria-label="Success example"
                        :aria-valuenow="item.progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                      >
                        <div
                          class="progress-bar bg-primary"
                          :style="`width: ${item.progress}%`"
                        ></div>
                      </div>
                      <small class="flex-shrink-1 ms-1"
                        >{{ item.progress }}%</small
                      >
                    </div>
                  </div>
                </div>
              </b-td>
              <b-td class="px-0 text-end"
                ><span class="text-body ps-2 align-self-center text-end"
                  >{{ currency }}{{ item.sales }}</span
                >
              </b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import { currency } from "@/helpers/constants";
import { topSelling } from "@/views/dashboards/ecommerce/components/data";
</script>
