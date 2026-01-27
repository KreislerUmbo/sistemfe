<template>
  <DefaultLayout>
    <b-row>
      <b-col md="6" lg="4" v-for="(item, idx) in clients" :key="idx">
        <ClientCard :item="item" />
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { clients } from "@/views/apps/projects/clients/components/data";
import ClientCard from "@/views/apps/projects/clients/components/ClientCard.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
