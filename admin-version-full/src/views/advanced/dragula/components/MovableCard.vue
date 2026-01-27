<template>
  <b-card no-body>
    <b-card-body>
      <div class="position-absolute end-0 me-3">
        <b-badge
          :variant="null"
          class="rounded bg-transparent border"
          :class="
            item.status === 'pending'
              ? 'text-warning border-warning'
              : item.status === 'completed'
                ? 'text-danger border-danger'
                : 'text-success border-primary'
          "
        >
          {{ kebabToTitleCase(item.status) }}
        </b-badge>
      </div>
      <div
        class="d-flex align-items-center"
        :class="item.members && 'mb-3 pb-2 border-dashed-bottom'"
      >
        <div class="flex-shrink-0">
          <img
            :src="item.logo"
            alt=""
            height="50"
            class="rounded-circle d-inline-block"
          />
        </div>
        <div class="flex-grow-1 ms-2 text-truncate">
          <h5 class="fw-bold mb-1 fs-15">{{ item.title }}</h5>
          <p class="text-dark mb-0 fs-13 fw-semibold">
            <span class="text-muted">Client : </span>
            {{ item.clientName }}
          </p>
        </div>
      </div>
      <template v-if="item.members">
        <div
          class="d-flex justify-content-between fw-semibold align-items-center my-3"
        >
          <div class="img-group d-flex justify-content-center">
            <template v-for="(img, idx) in item.members.slice(0, 3)" :key="idx">
              <a
                class="user-avatar position-relative d-inline-block"
                :class="idx && 'ms-n2'"
                href="#"
              >
                <img
                  :src="img"
                  alt="avatar"
                  class="thumb-md shadow-sm rounded-circle"
                />
              </a>
            </template>
            <a
              href="#"
              class="user-avatar position-relative d-inline-block ms-1"
            >
              <span
                class="thumb-md shadow-sm justify-content-center d-flex align-items-center bg-info-subtle rounded-circle fw-semibold fs-6"
              >
                +{{ item.members.length - 3 }}
              </span>
            </a>
          </div>
          <b-button type="button" variant="primary" size="sm" class="px-3"
            >Details</b-button
          >
        </div>
        <div>
          <div
            class="d-flex justify-content-between fw-semibold align-items-center"
          >
            <p class="mb-1 d-inline-flex align-items-center">
              <i class="iconoir-task-list fs-18 text-muted me-1"></i>
              {{ item.tasks }} Tasks
            </p>
            <small class="text-end text-body-emphasis d-block ms-auto"
              >{{ item.progress }}%</small
            >
          </div>
          <b-progress style="height: 5px">
            <b-progress-bar
              :value="item.progress"
              class="rounded-pill"
              style="margin-right: 2px"
              variant="secondary"
            />
          </b-progress>
          <b-row class="mt-3 align-items-center">
            <b-col md="5" class="col-auto">
              <div class="text-start">
                <h5 class="fs-16 fw-semibold mb-0">
                  {{ currency }}{{ item.budget }}
                </h5>
                <p class="mb-0 text-muted">Total Budget</p>
              </div>
            </b-col>
            <b-col md="7" class="col text-end align-self-center">
              <h6 class="fw-normal text-muted fs-12 mb-1">
                Start :
                <span class="text-dark fw-semibold">
                  {{ item.startDate }}
                </span>
              </h6>
              <h6 class="fw-normal text-muted mb-0 fs-12">
                Deadline :
                <span class="text-dark fw-semibold">
                  {{ item.deadlineDate }}
                </span>
              </h6>
            </b-col>
          </b-row>
        </div>
      </template>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { MovableType } from "@/views/advanced/dragula/components/types";

import { kebabToTitleCase } from "@/helpers/change-casing";
import { currency } from "@/helpers/constants";

defineProps({
  item: {
    type: Object as PropType<MovableType>,
    required: true,
  },
});
</script>
