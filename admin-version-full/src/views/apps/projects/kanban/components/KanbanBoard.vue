<template>
  <b-row>
    <b-col md="12" lg="12">
      <div class="kanban-board">
        <template v-for="(item, idx) in kanbanTasksData" :key="idx">
          <KanbanCol :item="item" />
        </template>
      </div>
    </b-col>
  </b-row>
</template>
<script setup lang="ts">
import { kanbanTasksData } from "@/views/apps/projects/kanban/components/data";
import KanbanCol from "@/views/apps/projects/kanban/components/KanbanCol.vue";
</script>
