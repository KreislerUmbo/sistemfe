<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Basic">
          <b-toast :model-value="true" :noCloseButton="true">
            <template #title>
              <img :src="logoSm" alt="" height="20" class="me-1" />
              <h5 class="me-auto my-0">Rizz</h5>
              <small>11 mins ago</small>
              <button
                type="button"
                class="btn-close"
                data-bs-dismiss="toast"
                aria-label="Close"
              ></button>
            </template>
            Hello, world! This is a toast message.
          </b-toast>
        </UIComponentCard>

        <UIComponentCard title="Stacking">
          <div class="bg-light rounded p-3">
            <div class="toast-container position-relative">
              <b-toast :model-value="true" :noCloseButton="true">
                <template #title>
                  <img :src="logoSm" alt="" height="20" class="me-1" />
                  <h5 class="me-auto my-0">Rizz</h5>
                  <small>11 mins ago</small>
                  <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="toast"
                    aria-label="Close"
                  ></button>
                </template>
                Hello, world! This is a toast message.
              </b-toast>

              <b-toast :model-value="true" :noCloseButton="true">
                <template #title>
                  <img :src="logoSm" alt="" height="20" class="me-1" />
                  <h5 class="me-auto my-0">Rizz</h5>
                  <small>11 mins ago</small>
                  <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="toast"
                    aria-label="Close"
                  ></button>
                </template>
                Hello, world! This is a toast message.
              </b-toast>
            </div>
          </div>
        </UIComponentCard>

        <UIComponentCard title="Toast Placement">
          <form>
            <b-form-group class="mb-3">
              <b-form-select
                v-model="selected"
                :options="options"
                class="mt-1"
                id="selectToastPlacement"
              />
            </b-form-group>
          </form>
          <div
            aria-live="polite"
            aria-atomic="true"
            class="position-relative bd-example-toasts"
            style="height: 260px; background-color: rgba(235, 240, 247, 0.1)"
          >
            <div
              class="toast-container position-absolute p-3"
              id="toastPlacement"
              :class="selected"
            >
              <b-toast :model-value="true" :noCloseButton="true">
                <template #title>
                  <img :src="logoSm" alt="" height="20" class="me-1" />
                  <h5 class="me-auto my-0">Rizz</h5>
                  <small>11 mins ago</small>
                  <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="toast"
                    aria-label="Close"
                  ></button>
                </template>
                Hello, world! This is a toast message.
              </b-toast>
            </div>
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Translucent">
          <div class="bg-light rounded p-3">
            <b-toast
              :model-value="true"
              role="alert"
              aria-live="assertive"
              aria-atomic="true"
              :noCloseButton="true"
            >
              <template #title>
                <img :src="logoSm" alt="" height="20" class="me-1" />
                <h5 class="me-auto my-0">Rizz</h5>
                <small>11 mins ago</small>
                <button
                  type="button"
                  class="btn-close"
                  data-bs-dismiss="toast"
                  aria-label="Close"
                ></button>
              </template>
              Hello, world! This is a toast message.
            </b-toast>
          </div>
        </UIComponentCard>

        <UIComponentCard title="Custom Content">
          <div class="bg-light rounded p-3">
            <div
              class="toast d-flex align-items-center mb-2"
              role="alert"
              aria-live="assertive"
              aria-atomic="true"
            >
              <div class="toast-body">
                Hello, world! This is a toast message.
              </div>
              <button
                type="button"
                class="btn-close ms-auto me-2"
                data-bs-dismiss="toast"
                aria-label="Close"
              ></button>
            </div>

            <b-toast class="mb-2" :model-value="true" :noCloseButton="true">
              Hello, world! This is a toast message.
              <div class="mt-2 pt-2 border-top">
                <b-button type="button" size="sm" variant="primary"
                  >Take action</b-button
                >
                {{ " " }}
                <b-button type="button" size="sm" variant="secondary"
                  >Close</b-button
                >
              </div>
            </b-toast>

            <div
              class="toast d-flex align-items-center text-white bg-primary border-0"
              role="alert"
              aria-live="assertive"
              aria-atomic="true"
            >
              <div class="toast-body">
                Hello, world! This is a toast message.
              </div>
              <button
                type="button"
                class="btn-close btn-close-white ms-auto me-2"
                data-bs-dismiss="toast"
                aria-label="Close"
              ></button>
            </div>
          </div>
        </UIComponentCard>

        <UIComponentCard title="Custom Toast">
          <b-toast :model-value="true" :noCloseButton="true">
            <template #title>
              <button
                type="button"
                class="btn-close ms-auto"
                data-bs-dismiss="toast"
                aria-label="Close"
              ></button>
            </template>
            <div class="text-center">
              <img
                :src="avatar5"
                alt=""
                class="d-block mx-auto rounded-circle thumb-xl"
              />
              <h5 class="fw-bold mt-2 mb-1">Charles Smith</h5>
              <p class="text-muted mb-0">UI/UX front end developer</p>
              <div class="mt-3 mb-2">
                <b-button
                  type="button"
                  :variant="null"
                  class="btn-outline-primary btn-icon-circle btn-icon-circle-sm"
                >
                  <i class="fab fa-facebook-f"></i>
                </b-button>
                {{ " " }}
                <b-button
                  type="button"
                  :variant="null"
                  class="btn-outline-info btn-icon-circle btn-icon-circle-sm"
                >
                  <i class="fab fa-twitter"></i>
                </b-button>
                {{ " " }}
                <b-button
                  type="button"
                  :variant="null"
                  class="btn-outline-pink btn-icon-circle btn-icon-circle-sm"
                >
                  <i class="fab fa-dribbble"></i>
                </b-button>
              </div>
            </div>
          </b-toast>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>

<script setup lang="ts">
import { ref } from "vue";
const selected = ref(null);
const options = [
  {
    value: null,
    text: "Select a position...",
  },
  {
    value: "top-0 start-0",
    text: "Top left",
  },
  {
    value: "top-0 start-50 translate-middle-x",
    text: "Top center",
  },
  {
    value: "top-0 end-0",
    text: "Top right",
  },
  {
    value: "top-50 start-0 translate-middle-y",
    text: "Middle left",
  },
  {
    value: "top-50 start-50 translate-middle",
    text: "Middle center",
  },
  {
    value: "top-50 end-0 translate-middle-y",
    text: "Middle right",
  },
  {
    value: "bottom-0 start-0",
    text: "Bottom left",
  },
  {
    value: "bottom-0 start-50 translate-middle-x",
    text: "Bottom center",
  },
  {
    value: "bottom-0 end-0",
    text: "Bottom right",
  },
];

import logoSm from "@/assets/images/logo-sm.png";
import avatar5 from "@/assets/images/users/avatar-5.jpg";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
