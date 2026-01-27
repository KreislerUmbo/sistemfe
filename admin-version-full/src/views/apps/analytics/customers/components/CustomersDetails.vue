<template>
  <b-row>
    <b-col cols="12">
      <UIComponentCard title="Customers Details">
        <b-table-simple responsive class="mb-0">
          <b-thead class="table-light">
            <b-tr>
              <b-th>Name</b-th>
              <b-th>Ext.</b-th>
              <b-th>City</b-th>
              <b-th>Start Date</b-th>
              <b-th>Completion</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="(item, idx) in customerTableData" :key="idx">
              <b-td class="d-flex align-items-center">
                <img
                  :src="item.avatar"
                  alt=""
                  class="thumb-md rounded-circle me-1"
                />
                {{ item.name }}
              </b-td>
              <b-td>{{ item.ext }}</b-td>
              <b-td>{{ item.city }}</b-td>
              <b-td>{{ item.startDate }}</b-td>
              <b-td>{{ item.completion }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </UIComponentCard>
    </b-col>
  </b-row>
</template>
<script setup lang="ts">
import UIComponentCard from "@/components/UIComponentCard.vue";
import { customerTableData } from "@/views/apps/analytics/customers/components/data";
</script>
