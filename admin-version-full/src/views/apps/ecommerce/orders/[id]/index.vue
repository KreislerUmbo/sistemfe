<template>
  <DefaultLayout>
    <b-row>
      <b-col lg="8">
        <Orders :id="route.params.id" />
        <BoughtDelivery />
      </b-col>
      <b-col lg="4">
        <OrderSummary />
        <OrderInformation />
      </b-col>
    </b-row>
  </DefaultLayout>
</template>

<script setup lang="ts">
import { useRoute } from "vue-router";

import Orders from "@/views/apps/ecommerce/orders/components/Orders.vue";
import BoughtDelivery from "@/views/apps/ecommerce/orders/components/BoughtDelivery.vue";
import OrderSummary from "@/views/apps/ecommerce/orders/components/OrderSummary.vue";
import OrderInformation from "@/views/apps/ecommerce/orders/components/OrderInformation.vue";
const route = useRoute();

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
