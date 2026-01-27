<template>
  <DefaultLayout>
    <b-row>
      <b-col md="12" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <div class="col">
                <b-card-title>Card title & Dropdown</b-card-title>
              </div>
              <div class="col-auto">
                <b-dropdown variant="light" menu-class="dropdown-menu-end">
                  <template #button-content>
                    <i class="icofont-calendar fs-5 me-1"></i> This Year<i
                      class="las la-angle-down ms-1"
                    ></i>
                  </template>
                  <b-dropdown-item>Today</b-dropdown-item>
                  <b-dropdown-item>Last Week</b-dropdown-item>
                  <b-dropdown-item>Last Month</b-dropdown-item>
                  <b-dropdown-item>This Year</b-dropdown-item>
                </b-dropdown>
              </div>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0"> </b-card-body>
        </b-card>
      </b-col>
      <b-col md="12" lg="6">
        <UIComponentCard title="Card Title" />
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
