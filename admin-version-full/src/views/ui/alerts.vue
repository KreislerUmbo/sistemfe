<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Default Alerts">
          <b-alert
            :model-value="true"
            variant="success"
            class="shadow-sm border-theme-white-2"
          >
            <div
              class="d-inline-flex justify-content-center align-items-center thumb-xs bg-success rounded-circle mx-auto me-1"
            >
              <i class="fas fa-check align-self-center mb-0 text-white"></i>
            </div>
            {{ " " }} <strong>Well done!</strong> You successfully read this
            important alert message.
          </b-alert>
          <b-alert
            :model-value="true"
            variant="danger"
            class="shadow-sm border-theme-white-2"
          >
            <div
              class="d-inline-flex justify-content-center align-items-center thumb-xs bg-danger rounded-circle mx-auto me-1"
            >
              <i class="fas fa-xmark align-self-center mb-0 text-white"></i>
            </div>
            {{ " " }} <strong>Oh snap!</strong> Change a few things up and try
            submitting again.
          </b-alert>
          <b-alert
            :model-value="true"
            variant="warning"
            class="shadow-sm border-theme-white-2"
          >
            <div
              class="d-inline-flex justify-content-center align-items-center thumb-xs bg-warning rounded-circle mx-auto me-1"
            >
              <i
                class="fas fa-exclamation align-self-center mb-0 text-white"
              ></i>
            </div>
            {{ " " }} <strong>Well done!</strong> An example warning alert with
            an icon.
          </b-alert>
          <b-alert
            :model-value="true"
            :variant="null"
            class="alert-purple shadow-sm border-theme-white-2 mb-0"
          >
            <div
              class="d-inline-flex justify-content-center align-items-center thumb-xs bg-purple rounded-circle mx-auto me-1"
            >
              <i class="fas fa-info align-self-center mb-0 text-white"></i>
            </div>
            {{ " " }} A simple primary alert with
            <a href="#" class="alert-link">an example link</a>. Give it a click
            if you like.
          </b-alert>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Outline Alerts">
          <b-alert
            :model-value="true"
            :variant="null"
            class="border-2 border-success text-success"
          >
            <strong>Well done!</strong> You successfully read this important
            alert message.
          </b-alert>
          <b-alert
            :model-value="true"
            :variant="null"
            class="border-2 border-danger text-danger"
          >
            <strong>Oh snap!</strong> Change a few things up and try submitting
            again.
          </b-alert>
          <b-alert
            :model-value="true"
            :variant="null"
            class="border-2 border-warning text-warning"
          >
            <strong>Well done!</strong> You successfully read this important
            alert message.
          </b-alert>
          <b-alert
            :model-value="true"
            :variant="null"
            class="border-2 border-info mb-0 text-info"
          >
            <strong>Oh snap!</strong> Change a few things up and try submitting
            again. 🎉
          </b-alert>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Dismissible Alerts">
          <b-alert
            :model-value="true"
            variant="success"
            dismissible
            class="shadow-sm border-theme-white-2 rounded-pill"
          >
            <div
              class="d-inline-flex justify-content-center align-items-center thumb-xs bg-success rounded-circle mx-auto me-1"
            >
              <i class="fas fa-check align-self-center mb-0 text-white"></i>
            </div>
            {{ " " }} <strong>Well done!</strong> You successfully read this
            important alert message.
            <button
              type="button"
              class="btn-close"
              data-bs-dismiss="alert"
              aria-label="Close"
            ></button>
          </b-alert>

          <b-alert
            :model-value="true"
            variant="danger"
            dismissible
            class="shadow-sm border-theme-white-2 mb-0"
          >
            <div
              class="d-inline-flex justify-content-center align-items-center thumb-xs bg-danger rounded-circle mx-auto me-1"
            >
              <i class="fas fa-xmark align-self-center mb-0 text-white"></i>
            </div>
            {{ " " }} <strong>Oh snap!</strong> Change a few things up and try
            submitting again.
            <button
              type="button"
              class="btn-close"
              data-bs-dismiss="alert"
              aria-label="Close"
            ></button>
          </b-alert>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Dismissible Outline Alerts">
          <b-alert
            :model-value="true"
            :variant="null"
            dismissible
            class="border-2 border-success text-success rounded-pill"
          >
            <div
              class="d-inline-flex justify-content-center align-items-center thumb-xs bg-success rounded-circle mx-auto me-1"
            >
              <i class="fas fa-check align-self-center mb-0 text-white"></i>
            </div>
            {{ " " }} <strong>Well done!</strong> You successfully read this
            important alert message.
            <button
              type="button"
              class="btn-close"
              data-bs-dismiss="alert"
              aria-label="Close"
            ></button>
          </b-alert>

          <b-alert
            :model-value="true"
            :variant="null"
            dismissible
            class="border-2 border-danger text-danger mb-0"
          >
            <div
              class="d-inline-flex justify-content-center align-items-center thumb-xs bg-danger rounded-circle mx-auto me-1"
            >
              <i class="fas fa-xmark align-self-center mb-0 text-white"></i>
            </div>
            {{ " " }} <strong>Oh snap!</strong> Change a few things up and try
            submitting again.
            <button
              type="button"
              class="btn-close"
              data-bs-dismiss="alert"
              aria-label="Close"
            ></button>
          </b-alert>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Custom Icon Alerts">
          <b-alert
            :model-value="true"
            variant="danger"
            dismissible
            class="border-start border-2 border-danger mb-0"
          >
            <div class="d-flex align-items-center gap-2">
              <i
                class="fas fa-skull-crossbones align-self-center fs-30 text-danger"
              ></i>
              <div class="flex-grow-1 ms-2 text-truncate">
                <h5 class="mb-1 fw-bold mt-0">Primary</h5>
                <p class="mb-0">
                  Oh snap! Change a few things up and try submitting again.
                </p>
                <button
                  type="button"
                  class="btn-close"
                  data-bs-dismiss="alert"
                  aria-label="Close"
                ></button>
              </div>
            </div>
          </b-alert>
        </UIComponentCard>
      </b-col>

      <b-col md="6" lg="6">
        <UIComponentCard title="Additional Content">
          <b-alert :model-value="true" variant="info" class="mb-0 border-2">
            <h4 class="alert-heading font-18">Well done!</h4>
            <p>
              Aww yeah, you successfully read this important alert message. This
              example text is going to run a bit longer so that you can see how
              spacing within an alert works with this kind of content.
            </p>
            <p class="mb-0">
              Whenever you need to, be sure to use margin utilities to keep
              things nice and tidy.
            </p>
          </b-alert>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
