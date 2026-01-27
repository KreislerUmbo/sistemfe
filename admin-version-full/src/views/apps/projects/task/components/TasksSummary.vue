<template>
  <b-col md="12" lg="4">
    <div class="mb-3">
      <b-card-body class="pt-0 text-center">
        <ApexChart :chart="taskChart" class="apex-charts" id="task" />
        <b-card-title class="my-2">Tasks Summary</b-card-title>
        <b-button type="button" variant="outline-primary" class="px-3 mt-2"
          >View Tasks</b-button
        >
      </b-card-body>
    </div>
  </b-col>
</template>
<script setup lang="ts">
import ApexChart from "@/components/ApexChart.vue";
import { taskChart } from "@/views/apps/projects/task/components/data";
</script>
