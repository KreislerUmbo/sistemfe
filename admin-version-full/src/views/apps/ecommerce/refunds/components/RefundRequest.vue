<template>
  <b-card no-body>
    <b-card-header>
      <b-row class="align-items-center">
        <div class="col">
          <b-card-title>Refund Request</b-card-title>
          <p class="mb-0 text-muted mt-1">Orders #234755</p>
        </div>
        <div class="col-auto">
          <router-link to="/apps/ecommerce/orders/3001" class="text-secondary"
            ><i class="fa-solid fa-circle-info me-1"></i>
            Order Detail
          </router-link>
        </div>
      </b-row>
    </b-card-header>
    <b-card-body class="pt-0">
      <b-table-simple responsive class="mb-0">
        <b-thead class="table-light">
          <b-tr>
            <b-th>Item</b-th>
            <b-th class="text-end">Price</b-th>
            <b-th class="text-end">Quantity</b-th>
            <b-th class="text-end">Total</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="(item, idx) in orderList" :key="idx">
            <b-td>
              <img :src="item.product.image" alt="" height="40" />{{ " " }}
              <p class="d-inline-block align-middle mb-0">
                <span
                  class="d-block align-middle mb-0 product-name text-body"
                  >{{ item.product.name }}</span
                >
                <span class="text-muted font-13">{{
                  item.product.description
                }}</span>
              </p>
            </b-td>
            <b-td class="text-end">{{ currency }}{{ item.price }}</b-td>
            <b-td class="text-end">{{ item.quantity }}</b-td>
            <b-td class="text-end">{{ currency }}{{ item.total }}</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import { currency } from "@/helpers/constants";
import { orderList } from "@/views/apps/ecommerce/refunds/components/data";
</script>
