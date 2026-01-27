<template>
  <b-card no-body>
    <b-card-header>
      <b-row class="align-items-center">
        <div class="col">
          <b-card-title>Order Information</b-card-title>
        </div>
        <div class="col-auto">
          <a href="#" class="text-secondary"
            ><i class="fas fa-pen me-1"></i> Edit</a
          >
        </div>
      </b-row>
    </b-card-header>
    <b-card-body class="pt-0">
      <div>
        <div class="d-flex justify-content-between mb-2">
          <p class="text-body fw-semibold">
            <i
              class="iconoir-profile-circle text-secondary fs-20 align-middle me-1"
            ></i
            >Username :
          </p>
          <p class="text-body-emphasis fw-semibold">@donFlo</p>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <p class="text-body fw-semibold">
            <i
              class="iconoir-people-tag text-secondary fs-20 align-middle me-1"
            ></i
            >Full Name :
          </p>
          <p class="text-body-emphasis fw-semibold">Don Flowers</p>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <p class="text-body fw-semibold">
            <i class="iconoir-mail text-secondary fs-20 align-middle me-1"></i
            >Email :
          </p>
          <p class="text-body-emphasis fw-semibold">
            DonIFlowers@jourrapide.com
          </p>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <p class="text-body fw-semibold">
            <i
              class="iconoir-dollar-circle text-secondary fs-20 align-middle me-1"
            ></i
            >Total Payment :
          </p>
          <p class="text-body-emphasis fw-semibold">
            <span class="text-primary">{{ currency }}2450</span> ({{
              currency
            }}30 for transportation)
          </p>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <p class="text-body fw-semibold">
            <i
              class="iconoir-calendar text-secondary fs-20 align-middle me-1"
            ></i
            >Order Date :
          </p>
          <p class="text-body-emphasis fw-semibold">31 Dec 2023</p>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <p class="text-body fw-semibold">
            <i
              class="iconoir-calendar text-secondary fs-20 align-middle me-1"
            ></i
            >Delivery Date :
          </p>
          <p class="text-body-emphasis fw-semibold">05 Jan 2024</p>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <p class="text-body fw-semibold">
            <i
              class="iconoir-delivery-truck text-secondary fs-20 align-middle me-1"
            ></i
            >Courier :
          </p>
          <p class="text-body-emphasis fw-semibold">FedEx Corporation</p>
        </div>
        <div class="d-flex justify-content-between">
          <p class="text-body fw-semibold">
            <i
              class="iconoir-map-pin text-secondary fs-20 align-middle me-1"
            ></i
            >Address :
          </p>
          <p class="text-body-emphasis fw-semibold">
            <img
              :src="bahaFlag"
              alt=""
              class="thumb-sm rounded-circle d-inline-block me-1"
            />
            718 Bingamon Branch Road <br />
            Central Valley, NY 10917
          </p>
        </div>
      </div>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import { currency } from "@/helpers/constants";
import bahaFlag from "@/assets/images/flags/baha_flag.jpg";
</script>
