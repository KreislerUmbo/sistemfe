<template>
  <DefaultLayout>
    <b-row>
      <Profile :id="route.params.id" />
      <StatsWidgets />
    </b-row>
    <Orders />
  </DefaultLayout>
</template>

<script setup lang="ts">
import { useRoute } from "vue-router";

import Profile from "@/views/apps/ecommerce/customers/components/Profile.vue";
import StatsWidgets from "@/views/apps/ecommerce/customers/components/StatsWidgets.vue";
import Orders from "@/views/apps/ecommerce/customers/components/Orders.vue";
import DefaultLayout from "@/layouts/DefaultLayout.vue";

const route = useRoute();
</script>
