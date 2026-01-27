<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="3" v-for="(item, idx) in pricingData" :key="idx">
        <PricingCard :item="item" />
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="3" v-for="(item, idx) in pricingIconData" :key="idx">
        <PricingIconCard :item="item" />
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import {
  pricingIconData,
  pricingData,
} from "@/views/pages/pricing/components/data";
import PricingCard from "@/views/pages/pricing/components/PricingCard.vue";
import PricingIconCard from "@/views/pages/pricing/components/PricingIconCard.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
