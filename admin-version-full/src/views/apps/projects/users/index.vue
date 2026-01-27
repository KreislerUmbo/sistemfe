<template>
  <DefaultLayout>
    <b-row>
      <b-col cols="12">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <div class="col">
                <b-card-title>Users Details</b-card-title>
              </div>
              <div class="col-auto">
                <b-button :variant="null" class="bg-primary-subtle text-primary"
                  ><i class="fas fa-plus me-1"></i> Add User</b-button
                >{{ " " }}
                <b-button variant="primary"
                  ><i class="fas fa-plus me-1"></i> Invite User</b-button
                >
              </div>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-table-simple responsive class="mb-0" id="datatable_1">
              <b-thead class="table-light">
                <b-tr>
                  <b-th>Name</b-th>
                  <b-th>ID</b-th>
                  <b-th>Roal</b-th>
                  <b-th>Last activity</b-th>
                  <b-th>Status</b-th>
                  <b-th>Action</b-th>
                </b-tr>
              </b-thead>
              <tbody>
                <b-tr v-for="(item, idx) in usersData" :key="idx">
                  <b-td class="d-flex align-items-center">
                    <div class="d-flex align-items-center">
                      <img
                        :src="item.image"
                        class="me-2 thumb-md align-self-center rounded"
                        alt="..."
                      />
                      <div class="flex-grow-1 text-truncate">
                        <h6 class="m-0">{{ item.name }}</h6>
                        <a href="#" class="fs-12 text-primary">{{
                          item.email
                        }}</a>
                      </div>
                    </div>
                  </b-td>
                  <b-td>{{ item.id }}</b-td>
                  <b-td
                    ><a href="#" class="text-body text-decoration-underline">{{
                      item.role
                    }}</a></b-td
                  >
                  <b-td>{{ item.lastActive }}</b-td>
                  <b-td>
                    <b-badge
                      class="rounded"
                      :variant="null"
                      :class="
                        item.status === 'active'
                          ? 'text-success bg-success-subtle'
                          : 'text-secondary bg-secondary-subtle'
                      "
                    >
                      {{ kebabToTitleCase(item.status) }}
                    </b-badge>
                  </b-td>
                  <b-td class="text-end">
                    <a href="#"
                      ><i class="las la-pen text-secondary fs-18"></i></a
                    >{{ " " }}
                    <a href="#"
                      ><i class="las la-trash-alt text-secondary fs-18"></i
                    ></a>
                  </b-td>
                </b-tr>
              </tbody>
            </b-table-simple>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { onMounted } from "vue";
import { DataTable } from "simple-datatables";
import { kebabToTitleCase } from "@/helpers/change-casing";
import { usersData } from "@/views/apps/projects/users/components/data";

onMounted(() => {
  new DataTable("#datatable_1", {
    searchable: true,
    fixedHeight: false,
    classes: {
      table: "datatable-table mb-0",
    },
  });
});

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
