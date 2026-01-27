<template>
  <b-col md="12" lg="8">
    <b-row class="g-3">
      <b-col md="6" lg="4">
        <b-card no-body class="mb-3 mb-lg-0">
          <b-card-body>
            <b-row class="align-items-center">
              <b-col class="text-center">
                <span class="fs-24 text-danger fw-normal">38</span>
                <h6 class="text-uppercase fs-12 text-muted mt-2 m-0">
                  Ongoing
                </h6>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="4">
        <b-card no-body class="mb-3 mb-lg-0">
          <b-card-body>
            <b-row class="align-items-center">
              <b-col class="text-center">
                <span class="fs-24 text-warning fw-normal">41</span>
                <h6 class="text-uppercase fs-12 text-muted mt-2 m-0">
                  Process
                </h6>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="4">
        <b-card no-body class="mb-3 mb-lg-0">
          <b-card-body>
            <b-row class="align-items-center">
              <b-col class="text-center">
                <span class="fs-24 text-success fw-normal">40.8%</span>
                <h6 class="text-uppercase fs-12 text-muted mt-2 m-0">
                  Completed
                </h6>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
    <div class="p-3 border-info border-dashed bg-info-subtle mt-3 rounded">
      <b-row class="d-flex justify-content-center">
        <div class="col">
          <div class=" ">
            <a href="#" class="fw-bold me-1">You've almost reached your goal</a>
            75% of your goals are completed just complate 25% of remaining goals
            to achieve your target.
          </div>
          <b-row class="mt-3 g-2">
            <b-col md="12" lg="6">
              <div class="">
                <p class="text-dark mb-2 fw-semibold fs-13">All Members</p>
                <div class="img-group d-flex">
                  <a
                    class="user-avatar position-relative d-inline-block"
                    href="#"
                  >
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
                      :src="avatar5"
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
                      :src="avatar6"
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
                      >+80</span
                    >
                  </a>
                </div>
              </div>
            </b-col>
            <b-col md="12" lg="6" class="align-self-center">
              <b-badge
                :variant="null"
                class="rounded text-primary bg-transparent border border-primary mb-2 p-1"
                >Senior team leader interview</b-badge
              >
              <p class="text-dark fw-semibold fs-13">15 Aug 2024, AM-10:15</p>
            </b-col>
          </b-row>
        </div>
        <div class="col-auto align-self-center">
          <b-button type="button" variant="primary" class="px-3 btn-sm mt-2"
            >View All</b-button
          >
        </div>
      </b-row>
    </div>
  </b-col>
</template>
<script setup lang="ts">
import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";
</script>
