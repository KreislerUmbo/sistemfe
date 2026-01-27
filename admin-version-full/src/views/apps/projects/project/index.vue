<template>
  <DefaultLayout>
    <b-row>
      <b-col md="6" lg="4" v-for="(item, idx) in projectsData" :key="idx">
        <ProjectCard :item="item" />
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { projectsData } from "@/views/apps/projects/project/components/data";
import ProjectCard from "@/views/apps/projects/project/components/ProjectCard.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
