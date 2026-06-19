<template>
  <b-col md="6" lg="4">
    <b-card no-body>
      <b-card-header>
        <b-row class="align-items-center">
          <div class="col">
            <b-card-title>Customers</b-card-title>
          </div>
          <div class="col-auto">
            <div class="img-group d-flex">
              <a class="user-avatar position-relative d-inline-block" href="#">
                <img
                  :src="avatar1"
                  alt="avatar"
                  class="thumb-md shadow-sm rounded-circle"
                />
              </a>
              <a
                class="user-avatar position-relative d-inline-block ms-n2"
                href="#"
              >
                <img
                  :src="avatar2"
                  alt="avatar"
                  class="thumb-md shadow-sm rounded-circle"
                />
              </a>
              <a
                class="user-avatar position-relative d-inline-block ms-n2"
                href="#"
              >
                <img
                  :src="avatar4"
                  alt="avatar"
                  class="thumb-md shadow-sm rounded-circle"
                />
              </a>
              <a
                class="user-avatar position-relative d-inline-block ms-n2"
                href="#"
              >
                <img
                  :src="avatar3"
                  alt="avatar"
                  class="thumb-md shadow-sm rounded-circle"
                />
              </a>
              <a
                href=""
                class="user-avatar position-relative d-inline-block ms-1"
              >
                <span
                  class="thumb-md shadow-sm justify-content-center d-flex align-items-center bg-info-subtle rounded-circle fw-semibold fs-6"
                  >+6</span
                >
              </a>
            </div>
          </div>
        </b-row>
      </b-card-header>
      <b-card-body class="pt-0">
        <ApexChart :chart="customersChart" class="apex-charts" id="customers" />
        <div class="bg-light py-3 px-2 mb-0 mt-3 text-center rounded">
          <h6 class="mb-0">
            <i class="icofont-calendar fs-5 me-1"></i> 01 January 2024 to 31
            December 2024
          </h6>
        </div>
        <p class="text-secondary text-truncate mb-0 rounded mt-2 text-center">
          To review the new order by Jems.
          <a href="#" class="text-primary text-decoration-underline">detail</a>
        </p>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import ApexChart from "@/components/ApexChart.vue";
import { customersChart } from "@/views/dashboards/ecommerce/components/data";

import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar3 from "@/assets/images/users/avatar-3.jpg";
</script>
