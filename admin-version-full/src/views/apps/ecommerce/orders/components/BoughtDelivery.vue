<template>
  <b-card no-body>
    <b-card-header>
      <b-row class="align-items-center">
        <div class="col">
          <b-card-title>Bought - Awaiting Delivery</b-card-title>
        </div>
        <div class="col-auto">
          <a href="" class="text-secondary"
            ><i class="fas fa-download me-1"></i> Download Invoice</a
          >
        </div>
      </b-row>
    </b-card-header>
    <b-card-body class="pt-0">
      <div class="position-relative m-4">
        <div
          class="progress"
          role="progressbar"
          aria-label="Progress"
          aria-valuenow="50"
          aria-valuemin="0"
          aria-valuemax="100"
          style="height: 1px"
        >
          <div class="progress-bar" style="width: 50%"></div>
        </div>
        <div
          class="position-absolute top-0 start-0 translate-middle bg-primary text-white rounded-pill thumb-md"
        >
          <i class="iconoir-home"></i>
        </div>
        <div
          class="position-absolute top-0 start-50 translate-middle bg-primary-subtle text-primary rounded-pill thumb-md"
        >
          <i class="iconoir-delivery-truck"></i>
        </div>
        <div
          class="position-absolute top-0 start-100 translate-middle bg-light text-dark rounded-pill thumb-md"
        >
          <i class="iconoir-map-pin"></i>
        </div>
      </div>
      <b-row class="row-cols-3">
        <b-col class="text-start">
          <h6 class="mb-1">Order Created</h6>
          <p class="mb-0 text-muted fs-12 fw-medium">15 Feb 2024, 11:30 AM</p>
        </b-col>
        <b-col class="text-center">
          <h6 class="mb-1">On Delivery</h6>
          <p class="mb-0 text-muted fs-12 fw-medium">18 Feb 2024, 05:15 PM</p>
        </b-col>
        <b-col class="text-end">
          <h6 class="mb-1">Order Delivered</h6>
          <p class="mb-0 text-muted fs-12 fw-medium">20 Feb 2024, 01:00 PM</p>
        </b-col>
      </b-row>
      <div
        class="bg-primary-subtle p-2 border-dashed border-primary rounded mt-3"
      >
        <span class="text-primary fw-semibold">Note :</span
        ><span class="text-primary fw-normal">
          Ship all the ordered item together by monday and i send you an email
          please check. Thanks!</span
        >
      </div>
    </b-card-body>
  </b-card>
</template>
