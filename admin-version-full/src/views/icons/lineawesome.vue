<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col cols="12">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title class="mb-1">Line Awesome Icons</b-card-title>
                <p class="text-muted mb-0">
                  Use
                  <code class="fs-14"
                    >&lt;i class="las la-braille"&gt;&lt;/i&gt;</code
                  >
                </p>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-row class="icon-demo-content">
              <b-col
                sm="6"
                md="4"
                lg="3"
                v-for="(icon, idx) in lineAwesomeIcon"
                :key="idx"
              >
                <i :class="icon"></i>
                {{ icon }}
              </b-col>
            </b-row>
            <div class="p-3 border border-primary bg-primary-subtle rounded">
              <b-row>
                <div class="col">
                  <h5 class="text-primary fs-18 mb-1 fw-semibold">
                    Line Awesome Icons
                  </h5>
                  <p class="text-muted mb-0 fs-14">
                    Swap Font Awesome for modern line icons in one line of code.
                  </p>
                  <p class="text-muted mb-0 fs-14">
                    Line Awesome is a free alternative for Font Awesome 5.11.2.
                    It consists of ~1380 flat icons that offer complete coverage
                    of the main Font Awesome icon set.
                  </p>
                </div>
                <div class="col-auto align-self-center">
                  <a
                    href="https://icons8.com/line-awesome"
                    target="_blank"
                    type="button"
                    class="btn btn-primary"
                  >
                    More Icons
                  </a>
                </div>
              </b-row>
            </div>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { lineAwesomeIcon } from "@/views/icons/data";
import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
