<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col cols="12">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title class="mb-1">Iconoir Icons</b-card-title>
                <p class="text-muted mb-0">
                  Use
                  <code class="fs-14"
                    >&lt;i class="iconoir-cycling"&gt;&lt;/i&gt;</code
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
                class="d-grid"
                v-for="(icon, idx) in iconoir"
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
                    Iconoir Icons
                  </h5>
                  <p class="text-muted mb-0 fs-14">
                    A high-quality selection of free icons. Your new alternative
                    to Noun Project, Flaticon, and all Figma resources.
                    Available in SVG, Font, React, React Native, Flutter, Figma
                    and Framer.
                  </p>
                </div>
                <div class="col-auto align-self-center">
                  <a
                    href="https://iconoir.com/"
                    target="_blank"
                    type="button"
                    class="btn btn-primary"
                    >More Icons</a
                  >
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
import { iconoir } from "@/views/icons/data";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
