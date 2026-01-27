<template>
  <b-card no-body>
    <b-card-header>
      <b-row class="align-items-center">
        <div class="col">
          <b-card-title>Order Summary</b-card-title>
        </div>
        <div class="col-auto">
          <b-badge
            :variant="null"
            class="text-success bg-success-subtle fs-12 p-1"
            >Payment Completed</b-badge
          >
        </div>
      </b-row>
    </b-card-header>
    <b-card-body class="pt-0">
      <div>
        <div class="d-flex justify-content-between">
          <p class="text-body fw-semibold">Items subtotal :</p>
          <p class="text-body-emphasis fw-semibold">{{ currency }}1060</p>
        </div>
        <div class="d-flex justify-content-between">
          <p class="text-body fw-semibold">Discount :</p>
          <p class="text-danger fw-semibold">-{{ currency }}80</p>
        </div>
        <div class="d-flex justify-content-between">
          <p class="text-body fw-semibold">Tax :</p>
          <p class="text-body-emphasis fw-semibold">{{ currency }}180.70</p>
        </div>
        <div class="d-flex justify-content-between">
          <p class="text-body fw-semibold">Subtotal :</p>
          <p class="text-body-emphasis fw-semibold">{{ currency }}1160.70</p>
        </div>
        <div class="d-flex justify-content-between">
          <p class="text-body fw-semibold mb-0">Shipping Cost :</p>
          <p class="text-body-emphasis fw-semibold mb-0">{{ currency }}20</p>
        </div>
      </div>
      <hr class="hr-dashed" />
      <div class="d-flex justify-content-between">
        <h4 class="mb-0">Total :</h4>
        <h4 class="mb-0">{{ currency }}1180.70</h4>
      </div>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import { currency } from "@/helpers/constants";
</script>
