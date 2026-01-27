<template>
  <b-col lg="6">
    <b-card no-body class="card-h-100">
      <b-card-header>
        <b-row class="align-items-center">
          <b-col>
            <b-card-title>Total Visits</b-card-title>
          </b-col>
        </b-row>
      </b-card-header>
      <b-card-body class="pt-0">
        <b-table-simple responsive class="mb-0">
          <b-thead class="table-light">
            <b-tr>
              <b-th class="border-top-0">Channel</b-th>
              <b-th class="border-top-0">Sessions</b-th>
              <b-th class="border-top-0">Prev.Period</b-th>
              <b-th class="border-top-0">% Change</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="(item, idx) in visitsList" :key="idx">
              <b-td
                ><a href="#" class="text-primary">{{ item.channel }}</a></b-td
              >
              <b-td
                >{{ item.sessions.count
                }}<small class="text-muted"
                  >({{ item.sessions.percentage }}%)</small
                ></b-td
              >
              <b-td
                >{{ item.prevPeriod.count
                }}<small class="text-muted"
                  >({{ item.prevPeriod.percentage }}%)</small
                ></b-td
              >
              <b-td
                >{{ item.change.percentage }}%
                <i
                  :class="`fas fa-caret-${item.change.trend} text-success font-16`"
                ></i
              ></b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
        <p class="m-0 fs-12 fst-italic ps-2 text-muted">
          Last data updated - 13min ago
          <a href="#!" class="link-danger ms-1"
            ><i class="align-middle iconoir-refresh"></i
          ></a>
        </p>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import { visitsList } from "@/views/dashboards/analytics/components/data";
</script>
