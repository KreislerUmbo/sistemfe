<template>
  <b-col md="4">
    <b-card no-body>
      <b-card-header>
        <b-row class="align-items-center">
          <div class="col">
            <b-card-title>Personal Information</b-card-title>
          </div>
          <div class="col-auto">
            <a
              href="#"
              class="float-end text-muted d-inline-flex text-decoration-underline"
            >
              <i class="iconoir-edit-pencil fs-18 me-1"></i>Edit
            </a>
          </div>
        </b-row>
      </b-card-header>
      <b-card-body class="pt-0">
        <p class="text-muted fw-medium mb-3">
          It is a long established fact that a reader will be distracted by the
          readable content of a page when looking at its layout.
        </p>
        <div class="mb-3">
          <b-badge :variant="null" class="border text-gray-700 fs-12 fw-medium"
            >Javascript</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" class="border text-gray-700 fs-12 fw-medium"
            >Python</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" class="border text-gray-700 fs-12 fw-medium"
            >Angular</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" class="border text-gray-700 fs-12 fw-medium"
            >Reactjs</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" class="border text-gray-700 fs-12 fw-medium"
            >Flutter</b-badge
          >
        </div>
        <ul class="list-unstyled mb-0">
          <li class="">
            <i
              class="las la-birthday-cake me-2 text-secondary fs-22 align-middle"
            ></i>
            <b> Birth Date </b> : 06 June 1989
          </li>
          <li class="mt-2">
            <i
              class="las la-briefcase me-2 text-secondary fs-22 align-middle"
            ></i>
            <b> Position </b> : Full Stack Developer
          </li>
          <li class="mt-2">
            <i
              class="las la-university me-2 text-secondary fs-22 align-middle"
            ></i>
            <b> Education </b> : Stanford Univercity
          </li>
          <li class="mt-2">
            <i
              class="las la-language me-2 text-secondary fs-22 align-middle"
            ></i>
            <b> Languages </b> : English, French, Spanish
          </li>
          <li class="mt-2">
            <i class="las la-phone me-2 text-secondary fs-22 align-middle"></i>
            <b> Phone </b> : +91 23456 78910
          </li>
          <li class="mt-2">
            <i
              class="las la-envelope text-secondary fs-22 align-middle me-2"
            ></i>
            <b> Email </b> : mannat.theme@gmail.com
          </li>
        </ul>
        <b-row class="justify-content-center mt-4">
          <div class="col-auto text-end border-end">
            <span
              class="thumb-md justify-content-center d-flex align-items-center bg-blue text-white rounded-circle ms-auto mb-1"
            >
              <i class="fab fa-facebook-f"></i>
            </span>
            <p class="mb-0 fw-semibold">Facebook</p>
            <h4 class="m-0 fw-bold">
              25k <span class="text-muted fs-12 fw-normal">Followers</span>
            </h4>
          </div>
          <div class="col-auto">
            <span
              class="thumb-md justify-content-center d-flex align-items-center bg-black text-white rounded-circle mb-1"
            >
              <i class="fab fa-x-twitter"></i>
            </span>
            <p class="mb-0 fw-semibold">Twitter</p>
            <h4 class="m-0 fw-bold">
              58k <span class="text-muted fs-12 fw-normal">Followers</span>
            </h4>
          </div>
        </b-row>
      </b-card-body>
    </b-card>
  </b-col>
</template>
