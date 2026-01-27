<template>
  <b-col lg="6">
    <UIComponentCard title="By Social Media">
      <b-table-simple responsive class="mb-0">
        <b-thead class="table-light">
          <b-tr>
            <b-th>Source</b-th>
            <b-th class="text-end">Views</b-th>
            <b-th class="text-end">Uniques</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="(item, idx) in socialStats" :key="idx">
            <b-td>{{ item.source }}</b-td>
            <b-td class="text-end">{{ item.views }}k</b-td>
            <b-td class="text-end">{{ item.uniques }}k</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>
    </UIComponentCard>
  </b-col>
</template>
<script setup lang="ts">
import UIComponentCard from "@/components/UIComponentCard.vue";
import { socialStats } from "@/views/apps/analytics/reports/components/data";
</script>
