<template>
  <DefaultLayout>
    <b-row>
      <TasksSummary />
      <StateWidgets />
    </b-row>
    <TaskDetails />
  </DefaultLayout>
</template>
<script setup lang="ts">
import TasksSummary from "@/views/apps/projects/task/components/TasksSummary.vue";
import StateWidgets from "@/views/apps/projects/task/components/StateWidgets.vue";
import TaskDetails from "@/views/apps/projects/task/components/TaskDetails.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
