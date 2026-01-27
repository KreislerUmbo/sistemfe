<template>
  <b-col md="6" lg="8">
    <b-card no-body>
      <b-card-header>
        <b-row class="align-items-center">
          <div class="col">
            <b-card-title>Popular Products</b-card-title>
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
        <b-table-simple responsive class="mb-0">
          <b-thead class="table-light">
            <b-tr>
              <b-th class="border-top-0">Product</b-th>
              <b-th class="border-top-0">Price</b-th>
              <b-th class="border-top-0">Sell</b-th>
              <b-th class="border-top-0">Status</b-th>
              <b-th class="border-top-0">Action</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="(item, idx) in productList" :key="idx">
              <b-td>
                <div class="d-flex align-items-center">
                  <img
                    :src="item.image"
                    height="40"
                    class="me-3 align-self-center rounded"
                    alt="..."
                  />
                  <div class="flex-grow-1 text-truncate">
                    <h6 class="m-0">{{ item.name }}</h6>
                    <a href="#" class="fs-12 text-primary">ID: {{ item.id }}</a>
                  </div>
                </div>
              </b-td>
              <b-td
                >{{ currency }}{{ item.price }}
                <del class="text-muted fs-10"
                  >{{ currency }}{{ item.originalPrice }}</del
                ></b-td
              >
              <b-td
                >{{ item.stockQuantity }}
                <small class="text-muted"
                  >({{ item.soldQuantity }})</small
                ></b-td
              >
              <b-td>
                <b-badge
                  :variant="null"
                  class="px-2"
                  :class="
                    item.status === 'in-stock'
                      ? 'bg-primary-subtle text-primary'
                      : 'bg-danger-subtle text-danger'
                  "
                >
                  {{ kebabToTitleCase(item.status) }}
                </b-badge>
              </b-td>
              <b-td>
                <a href="#"><i class="las la-pen text-secondary fs-18"></i></a
                >{{ " " }}
                <a href="#"
                  ><i class="las la-trash-alt text-secondary fs-18"></i
                ></a>
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
import { kebabToTitleCase } from "@/helpers/change-casing";
import { productList } from "@/views/dashboards/ecommerce/components/data";
</script>
