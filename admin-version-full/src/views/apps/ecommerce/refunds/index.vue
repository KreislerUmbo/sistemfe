<template>
  <DefaultLayout>
    <b-row>
      <b-col lg="8">
        <RefundRequest />
      </b-col>
      <b-col lg="4">
        <OrderSummary />
        <Amount />
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import RefundRequest from "@/views/apps/ecommerce/refunds/components/RefundRequest.vue";
import OrderSummary from "@/views/apps/ecommerce/refunds/components/OrderSummary.vue";
import Amount from "@/views/apps/ecommerce/refunds/components/Amount.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
