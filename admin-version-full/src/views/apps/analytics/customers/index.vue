<template>
  <DefaultLayout>
    <b-row>
      <CustomersData />
      <CustomersGrowth />
    </b-row>
    <SocialStats />
    <CustomersDetails />
  </DefaultLayout>
</template>
<script setup lang="ts">
import CustomersData from "@/views/apps/analytics/customers/components/CustomersData.vue";
import CustomersGrowth from "@/views/apps/analytics/customers/components/CustomersGrowth.vue";
import SocialStats from "@/views/apps/analytics/customers/components/SocialStats.vue";
import CustomersDetails from "@/views/apps/analytics/customers/components/CustomersDetails.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
