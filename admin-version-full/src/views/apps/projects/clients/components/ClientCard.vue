<template>
  <b-card no-body>
    <b-card-body class="p-4 color-bg rounded text-center">
      <h4 class="text-white opacity-75 fs-16 mb-0">{{ item.theme }}</h4>
    </b-card-body>
    <div class="position-relative d-none d-xxl-block">
      <div class="shape overflow-hidden text-card-bg">
        <svg
          viewBox="0 0 2880 48"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            d="M0 48H1437.5H2880V0H2160C1442.5 52 720 0 720 0H0V48Z"
            fill="currentColor"
          ></path>
        </svg>
      </div>
    </div>
    <b-card-body class="mt-n5">
      <div class="position-relative">
        <img :src="item.user.avatar" alt="" class="rounded-circle thumb-xxl" />
        <div class="position-absolute top-0">
          <img
            :src="item.user.flag"
            alt=""
            class="rounded-circle thumb-sm border border-3 border-dark"
          />
        </div>
      </div>
      <b-row class="mt-3 align-items-center">
        <b-col md="6" class="col-auto">
          <div class="">
            <h5 class="m-0 fw-bold">{{ item.user.name }}</h5>
            <p class="text-muted mb-0">{{ item.user.handle }}</p>
          </div>
        </b-col>
        <b-col md="6" class="col text-end">
          <div class="text-muted mb-2 d-flex align-items-center">
            <span class="text-body fw-semibold">Pre.Project :</span>
            {{ item.preProject }}
          </div>
        </b-col>
      </b-row>
      <p class="text-muted fs-14 my-3">{{ item.description }}</p>
      <div class="text-muted mb-2 d-flex align-items-center">
        <i class="iconoir-mail-out fs-20 me-1"></i>
        <span class="text-body fw-semibold">Email :</span>
        <a href="#" class="text-primary text-decoration-underline">{{
          item.contact.email
        }}</a>
      </div>
      <div class="text-body d-flex align-items-center">
        <i class="iconoir-phone fs-20 me-1 text-muted"></i>
        <span class="text-body fw-semibold">Phone :</span>
        {{ item.contact.phone }}
      </div>
      <div class="mt-3">
        <router-link
          to="/pages/profile"
          class="btn btn-sm btn-primary px-2 d-inline-flex align-items-center"
        >
          <i class="iconoir-user fs-14 me-1"></i>View Profile </router-link
        >{{ " " }}
        <router-link
          to="/apps/chat"
          class="btn btn-sm btn-outline-primary px-2 d-inline-flex align-items-center"
        >
          <i class="iconoir-chat-bubble fs-14 me-1"></i>Message
        </router-link>
      </div>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { ClientType } from "@/views/apps/projects/clients/components/types";

defineProps({
  item: {
    type: Object as PropType<ClientType>,
    required: true,
  },
});
</script>
