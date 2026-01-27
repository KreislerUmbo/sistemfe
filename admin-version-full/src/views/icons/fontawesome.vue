<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col cols="12">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title class="mb-1">Font Awesome</b-card-title>
                <p class="text-muted mb-0">
                  Use
                  <code class="fs-14"
                    >&lt;i class="fas fa-accusoftt"&gt;&lt;/i&gt;</code
                  >
                </p>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <section>
              <div class="icon-demo-content row">
                <b-col
                  sm="6"
                  md="4"
                  xl="3"
                  v-for="(icon, idx) in fontAwesomeIcon"
                  :key="idx"
                >
                  <i :class="icon"></i>
                  {{ icon }}
                </b-col>
              </div>
            </section>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { fontAwesomeIcon } from "@/views/icons/data";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
