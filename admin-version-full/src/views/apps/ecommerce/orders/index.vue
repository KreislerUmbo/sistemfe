<template>
  <DefaultLayout>
    <b-row>
      <b-col lg="12">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <div class="col">
                <b-card-title>Orders</b-card-title>
              </div>
              <div class="col-auto">
                <b-button variant="primary"
                  ><i class="fas fa-plus me-1"></i> Add Order</b-button
                >
              </div>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-table-simple responsive class="mb-0">
              <b-thead class="table-light">
                <b-tr>
                  <b-th>ID</b-th>
                  <b-th>Product</b-th>
                  <b-th>Date</b-th>
                  <b-th>Payment</b-th>
                  <b-th>Status</b-th>
                  <b-th>Price</b-th>
                  <th class="text-end">Action</th>
                </b-tr>
              </b-thead>
              <b-tbody>
                <b-tr v-for="(item, idx) in ordersData" :key="idx">
                  <b-td>
                    <router-link :to="`/apps/ecommerce/orders/${item.orderId}`"
                      >#{{ item.orderId }}</router-link
                    >
                  </b-td>
                  <b-td>
                    <p class="d-inline-block align-middle mb-0">
                      <span
                        class="d-block align-middle mb-0 product-name text-body"
                        >{{ item.productName }}</span
                      >
                      <span class="text-muted font-13">{{
                        item.productDetails
                      }}</span>
                    </p>
                  </b-td>
                  <b-td>{{ item.orderDate }}</b-td>
                  <b-td>{{ item.paymentMethod }}</b-td>
                  <b-td v-if="item.status === 'completed'">
                    <b-badge
                      :variant="null"
                      class="bg-success-subtle text-success"
                    >
                      <i class="fas fa-check me-1"></i>
                      {{ kebabToTitleCase(item.status) }}
                    </b-badge>
                  </b-td>

                  <b-td v-else-if="item.status === 'cancel'">
                    <b-badge
                      :variant="null"
                      class="bg-danger-subtle text-danger"
                    >
                      <i class="fas fa-xmark me-1"></i>
                      {{ kebabToTitleCase(item.status) }}
                    </b-badge>
                  </b-td>

                  <b-td v-else>
                    <b-badge
                      :variant="null"
                      class="bg-secondary-subtle text-secondary"
                    >
                      <i class="fas fa-clock me-1"></i>
                      {{ kebabToTitleCase(item.status) }}
                    </b-badge>
                  </b-td>

                  <b-td>{{ currency }}{{ item.amount }}</b-td>
                  <b-td class="text-end">
                    <a href="#"
                      ><i class="las la-pen text-secondary fs-18"></i></a
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
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { currency } from "@/helpers/constants";
import { kebabToTitleCase } from "@/helpers/change-casing";
import { ordersData } from "@/views/apps/ecommerce/orders/components/data";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
