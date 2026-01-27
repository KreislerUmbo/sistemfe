<template>
  <b-row>
    <b-col cols="12">
      <UIComponentCard title="Tasks & Details">
        <b-accordion class="card-bg" id="task-1">
          <template v-for="(item, idx) in tasksDetails" :key="idx">
            <TaskDetailsCard :item="item" />
          </template>
        </b-accordion>
      </UIComponentCard>
    </b-col>
  </b-row>
</template>
<script setup lang="ts">
import { tasksDetails } from "@/views/apps/projects/task/components/data";
import UIComponentCard from "@/components/UIComponentCard.vue";
import TaskDetailsCard from "@/views/apps/projects/task/components/TaskDetailsCard.vue";
</script>
