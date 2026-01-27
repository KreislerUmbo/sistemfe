<template>
  <DefaultLayout>
    <b-row>
      <b-col md="6" lg="4" v-for="(item, idx) in teamsData" :key="idx">
        <TeamCard :item="item" />
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { teamsData } from "@/views/apps/projects/team/components/data";
import TeamCard from "@/views/apps/projects/team/components/TeamCard.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
