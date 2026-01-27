<template>
  <b-card no-body>
    <b-card-body>
      <div class="position-absolute end-0 me-3">
        <b-badge
          :variant="null"
          class="rounded text-primary bg-transparent border border-primary ms-1 p-1"
        >
          {{ kebabToTitleCase(item.status) }}
        </b-badge>
      </div>
      <div class="text-center border-dashed-bottom pb-3">
        <img
          :src="item.logo"
          alt=""
          height="80"
          class="rounded-circle d-inline-block"
        />
        <h5 class="fw-bold my-2 fs-20">{{ item.title }}</h5>
        <p class="text-dark fs-13 fw-semibold">
          <span class="text-muted">Client : </span>{{ item.client }}
        </p>
        <div
          class="d-flex justify-content-between fw-semibold align-items-center"
        >
          <p class="mb-1 d-inline-flex align-items-center">
            <i class="iconoir-task-list fs-18 text-muted me-1"></i>
            {{ item.tasks }} Tasks
          </p>
          <small class="text-end text-body-emphasis d-block ms-auto"
            >{{ item.completionPercentage }}%</small
          >
        </div>
        <b-progress style="height: 5px">
          <b-progress-bar
            :value="item.completionPercentage"
            class="rounded-pill"
            style="margin-right: 2px"
            variant="secondary"
          />
        </b-progress>
        <b-row class="mt-3 align-items-center">
          <b-col md="6" class="col-auto">
            <div class="text-start">
              <h5 class="fs-18 fw-semibold mb-1">
                {{ currency }}{{ item.budget }}
              </h5>
              <p class="mb-0 text-muted">Total Budget</p>
            </div>
          </b-col>
          <b-col md="6" class="col text-end align-self-center">
            <h6 class="fw-normal text-muted">
              Start :
              <span class="text-dark fw-semibold"> {{ item.startDate }}</span>
            </h6>
            <h6 class="fw-normal text-muted">
              Deadline :
              <span class="text-dark fw-semibold"> {{ item.deadline }}</span>
            </h6>
          </b-col>
        </b-row>

        <p class="text-muted mt-3 mb-0">{{ item.description }}</p>
      </div>
      <div
        class="d-flex justify-content-between fw-semibold align-items-center mt-3"
      >
        <div class="img-group d-flex justify-content-center">
          <template
            v-for="(member, idx) in item.members.slice(0, 3)"
            :key="idx"
          >
            <a
              class="user-avatar position-relative d-inline-block"
              href="#"
              :class="idx && 'ms-n2'"
            >
              <img
                :src="member"
                alt="avatar"
                class="thumb-md shadow-sm rounded-circle"
              />
            </a>
          </template>
          <a
            href=""
            class="user-avatar position-relative d-inline-block ms-1"
            v-if="item.members.length > 3"
          >
            <span
              class="thumb-md shadow-sm justify-content-center d-flex align-items-center bg-info-subtle rounded-circle fw-semibold fs-6"
              >+{{ item.members.length - 3 }}</span
            >
          </a>
        </div>
        <button
          type="button"
          class="btn bg-secondary-subtle text-dark btn-sm px-3"
        >
          Details
        </button>
      </div>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { ProjectType } from "@/views/apps/projects/project/components/types";
import { currency } from "@/helpers/constants";
import { kebabToTitleCase } from "@/helpers/change-casing";

defineProps({
  item: {
    type: Object as PropType<ProjectType>,
    required: true,
  },
});
</script>
