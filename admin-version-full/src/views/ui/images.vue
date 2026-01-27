<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="12">
        <UIComponentCard title="Responsive Imagess">
          <img :src="colorBg2" alt="" class="img-fluid rounded" />
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="4">
        <b-card no-body>
          <b-card-body>
            <div class="d-flex align-items-center">
              <div class="flex-shrink-0">
                <img :src="avatar1" alt="" class="img-thumbnail" />
              </div>
              <div class="flex-grow-1 ms-3 text-truncate">
                <h4 class="mb-1 fw-semibold">Kathryn Money</h4>
                <p class="text-muted mb-3 font-13">UI & UX Designer, Japan</p>
                <b-button type="button" size="sm" variant="primary"
                  >Message</b-button
                >
              </div>
            </div>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="4">
        <b-card no-body>
          <b-card-body>
            <div class="d-flex align-items-center">
              <div class="flex-shrink-0">
                <img :src="avatar10" alt="" class="rounded" />
              </div>
              <div class="flex-grow-1 ms-3 text-truncate">
                <h4 class="mb-1 fw-semibold">Anthony Stover</h4>
                <p class="text-muted mb-3 font-13">Frontend Developer, USA</p>
                <b-button type="button" size="sm" variant="primary"
                  >Message</b-button
                >
              </div>
            </div>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="4">
        <b-card no-body>
          <b-card-body>
            <div class="d-flex align-items-center">
              <div class="flex-shrink-0">
                <img :src="avatar8" alt="" class="rounded-circle" />
              </div>
              <div class="flex-grow-1 ms-3 text-truncate">
                <h4 class="mb-1 fw-semibold">Catherine Orman</h4>
                <p class="text-muted mb-3 font-13">Backend Developer, Canada</p>
                <b-button type="button" size="sm" variant="primary"
                  >Message</b-button
                >
              </div>
            </div>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Aligning Images (float)">
          <img :src="avatar4" alt="" class="rounded float-start" />
          <img :src="avatar7" alt="" class="rounded float-end" />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Aligning Images">
          <img :src="avatar6" alt="" class="rounded d-block mx-auto" />
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import colorBg2 from "@/assets/images/extra/color-bg-2.jpg";

import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";
import avatar7 from "@/assets/images/users/avatar-7.jpg";
import avatar8 from "@/assets/images/users/avatar-8.jpg";
import avatar10 from "@/assets/images/users/avatar-10.jpg";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
