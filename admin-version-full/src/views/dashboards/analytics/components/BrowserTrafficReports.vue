<template>
  <b-col lg="6">
    <b-card no-body class="card-h-100">
      <b-card-header>
        <b-row class="align-items-center">
          <b-col>
            <b-card-title>Browser Used & Traffic Reports</b-card-title>
          </b-col>
        </b-row>
      </b-card-header>
      <b-card-body class="pt-0">
        <b-table-simple responsive class="mb-0 browser_users">
          <b-thead class="table-light">
            <b-tr>
              <b-th class="border-top-0">Browser</b-th>
              <b-th class="border-top-0">Sessions</b-th>
              <b-th class="border-top-0">Bounce Rate</b-th>
              <b-th class="border-top-0">Transactions</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="(item, idx) in browserData" :key="idx">
              <b-td
                ><img :src="item.image" alt="img" height="24" class="me-2" />{{
                  item.browser
                }}</b-td
              >
              <b-td>{{ item.sessions }}</b-td>
              <b-td>{{ item.bounceRate }}</b-td>
              <b-td>{{ item.transactions }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import { browserData } from "@/views/dashboards/analytics/components/data";
</script>
