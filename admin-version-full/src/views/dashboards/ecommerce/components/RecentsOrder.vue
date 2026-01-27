<template>
  <b-col md="6" lg="4">
    <b-card no-body>
      <b-card-header>
        <b-row class="align-items-center">
          <div class="col">
            <b-card-title>Recents Order</b-card-title>
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
            <b-tr v-for="(item, idx) in orderList" :key="idx">
              <b-td class="px-0">
                <div class="d-flex align-items-center">
                  <img
                    :src="item.avatar"
                    height="36"
                    class="me-2 align-self-center rounded"
                    alt="..."
                  />
                  <div class="flex-grow-1 text-truncate">
                    <h6 class="m-0 text-truncate">{{ item.name }}</h6>
                    <a
                      href="#"
                      class="font-12 text-muted text-decoration-underline"
                      >{{ item.id }}</a
                    >
                  </div>
                </div>
              </b-td>
              <b-td class="px-0 text-end"
                ><span class="text-primary ps-2 align-self-center text-end"
                  >{{ currency }}{{ item.amount }}</span
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
import { orderList } from "@/views/dashboards/ecommerce/components/data";
</script>
