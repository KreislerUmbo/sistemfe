<template>
  <DefaultLayout>
    <b-row>
      <StatWidgets />
      <MonthlyAvgIncome />
    </b-row>
    <b-row class="justify-content-center">
      <PopularProducts />
      <Customers />
    </b-row>
    <b-row class="justify-content-center">
      <TopSelling />
      <CategoriesData />
      <RecentsOrder />
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import StatWidgets from "@/views/dashboards/ecommerce/components/StatWidgets.vue";
import MonthlyAvgIncome from "@/views/dashboards/ecommerce/components/MonthlyAvgIncome.vue";
import PopularProducts from "@/views/dashboards/ecommerce/components/PopularProducts.vue";
import Customers from "@/views/dashboards/ecommerce/components/Customers.vue";
import TopSelling from "@/views/dashboards/ecommerce/components/TopSelling.vue";
import CategoriesData from "@/views/dashboards/ecommerce/components/CategoriesData.vue";
import RecentsOrder from "@/views/dashboards/ecommerce/components/RecentsOrder.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
