<template>
  <DefaultLayout>
    <b-row class="row justify-content-center">
      <b-col md="4" lg="4" v-for="(item, idx) in projectData" :key="idx">
        <ProjectCard :item="item" />
      </b-col>
    </b-row>
  </DefaultLayout>
</template>

<script setup lang="ts">
import { projectData } from "@/views/advanced/dragula/components/data";
import ProjectCard from "@/views/advanced/dragula/components/ProjectCard.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
