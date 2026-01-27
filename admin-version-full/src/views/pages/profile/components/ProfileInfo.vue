<template>
  <b-col cols="12">
    <b-card no-body>
      <b-card-body>
        <b-row>
          <b-col lg="4" class="align-self-center mb-3 mb-lg-0">
            <div class="d-flex align-items-center flex-row flex-wrap">
              <div class="position-relative me-3">
                <img
                  :src="avatar7"
                  alt=""
                  height="120"
                  class="rounded-circle"
                />
                <a
                  href="#"
                  class="thumb-md justify-content-center d-flex align-items-center bg-primary text-white rounded-circle position-absolute end-0 bottom-0 border border-3 border-card-bg"
                >
                  <i class="fas fa-camera"></i>
                </a>
              </div>
              <div class="">
                <h5 class="fw-semibold fs-22 mb-1">Rosa Dodson</h5>
                <p class="mb-0 text-muted fw-medium">UI/UX Designer, USA</p>
              </div>
            </div>
          </b-col>
          <b-col lg="4" class="ms-auto align-self-center">
            <div class="d-flex justify-content-center">
              <div
                class="border-dashed rounded border-theme-color p-2 me-2 flex-grow-1 flex-basis-0"
              >
                <h5 class="fw-semibold fs-22 mb-1">75</h5>
                <p class="text-muted mb-0 fw-medium">Projects</p>
              </div>
              <div
                class="border-dashed rounded border-theme-color p-2 me-2 flex-grow-1 flex-basis-0"
              >
                <h5 class="fw-semibold fs-22 mb-1">68%</h5>
                <p class="text-muted mb-0 fw-medium">Success Rate</p>
              </div>
              <div
                class="border-dashed rounded border-theme-color p-2 me-2 flex-grow-1 flex-basis-0"
              >
                <h5 class="fw-semibold fs-22 mb-1">{{ currency }}8620</h5>
                <p class="text-muted mb-0 fw-medium">Earning</p>
              </div>
            </div>
          </b-col>
          <b-col lg="4" class="align-self-center">
            <b-row class="row-cols-2">
              <b-col class="text-end">
                <ApexChart
                  :chart="completeChart"
                  class="apex-charts"
                  id="complete"
                />
              </b-col>
              <b-col class="align-self-center">
                <b-button type="button" variant="primary" class="d-inline-block"
                  >Follow</b-button
                >{{ " " }}
                <b-button type="button" variant="light" class="d-inline-block"
                  >Hire Me</b-button
                >
              </b-col>
            </b-row>
          </b-col>
        </b-row>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import ApexChart from "@/components/ApexChart.vue";
import { currency } from "@/helpers/constants";
import { completeChart } from "@/views/pages/profile/components/data";
import avatar7 from "@/assets/images/users/avatar-7.jpg";
</script>
