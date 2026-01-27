<template>
  <b-col md="6" lg="4">
    <b-card no-body>
      <b-card-body>
        <b-row class="align-items-center">
          <div class="col">
            <p class="text-dark mb-0 fw-semibold fs-14">New Visitors</p>
            <h2 class="mt-0 mb-0 fw-bold">1,282</h2>
          </div>
          <div class="col-auto align-self-center">
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
                href="#"
                class="user-avatar position-relative d-inline-block ms-1"
              >
                <span
                  class="thumb-md shadow-sm justify-content-center d-flex align-items-center bg-info-subtle rounded-circle fw-semibold fs-6"
                  >+6</span
                >
              </a>
            </div>
            <small class="text-muted">Logined Visitors</small>
          </div>
        </b-row>
        <ApexChart
          :chart="visitorsChart"
          class="apex-charts mb-2"
          id="visitors_report"
        />

        <b-button type="button" variant="primary" class="w-100 btn-lg fs-14">
          More Detail <i class="fa-solid fa-arrow-right-long"></i>
        </b-button>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import ApexChart from "@/components/ApexChart.vue";
import { visitorsChart } from "@/views/dashboards/analytics/components/data";

import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar3 from "@/assets/images/users/avatar-3.jpg";
</script>
