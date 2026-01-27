<template>
  <b-accordion-item
    class="task-accordion rounded border-dashed border-theme-color p-2"
    :class="item.id === 3 ? '' : 'mb-3'"
    body-class="bg-body border-dashed-top p-3 p-3"
    button-class="p-0 m-0"
    :visible="item.id === 1"
  >
    <template #title>
      <b-table-simple responsive class="mb-0 text-nowrap w-100">
        <b-tbody>
          <b-tr>
            <b-td>
              <div class="d-flex align-items-center">
                <i class="iconoir-calendar fs-2 me-2"></i>
                <div class="flex-grow-1 text-truncate">
                  <h6 class="m-0 mb-1 fw-semibold">{{ item.title }}</h6>
                  <div class="fw-semibold">
                    <span>{{ item.startDate }}</span>
                    -
                    <span class="text-danger"> {{ item.endDate }}</span>
                  </div>
                </div>
              </div>
            </b-td>
            <b-td class="text-start">
              <h6 class="m-0 mb-1 fw-semibold">Task</h6>
              <span class="fs-13 fw-semibold">{{ item.task }}</span>
            </b-td>
            <b-td class="text-start">
              <h6 class="m-0 mb-1 fw-semibold">Priority</h6>
              <span
                :class="
                  item.priority === 'High'
                    ? 'text-danger'
                    : item.priority === 'Low'
                      ? 'text-warning'
                      : 'text-info'
                "
              >
                <i class="fa-solid fa-stop fs-10"></i> {{ item.priority }}
              </span>
            </b-td>

            <b-td class="text-start">
              <h6 class="m-0 mb-1 fw-semibold">Team</h6>
              <span class="text-secondary">{{ item.team }}</span>
            </b-td>

            <b-td class="text-end">
              <h6 class="m-0 mb-1 fw-semibold">Status</h6>
              <b-badge
                :variant="null"
                class="rounded bg-transparent border p-1"
                :class="
                  item.status === 'done'
                    ? 'text-success border-success'
                    : item.status === 'pending'
                      ? 'text-secondary border-secondary'
                      : 'text-blue border-blue'
                "
              >
                {{ kebabToTitleCase(item.status) }}
              </b-badge>
            </b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>
    </template>

    <h6 class="fw-semibold">Task Info</h6>
    <p class="mt-2 mb-3">
      {{ item.description }}
    </p>
    <b-row class="row-cols-auto g-3">
      <b-col md="3">
        <div class="d-flex align-items-center">
          <img
            :src="item.client.avatar"
            class="thumb-md align-self-center rounded-circle"
            alt="..."
          />
          <div class="flex-grow-1 ms-2">
            <h6 class="m-0 mb-1 fw-semibold">{{ item.client.name }}</h6>
            <p class="text-muted mb-0">{{ item.client.role }}</p>
          </div>
        </div>
      </b-col>
      <b-col md="3">
        <h6 class="m-0 mb-1 fw-semibold">Project type</h6>
        <p class="text-muted mb-0">{{ item.projectType }}</p>
      </b-col>
      <b-col md="2" class="text-end align-self-center">
        <div class="d-flex justify-content-between">
          <h6 class="m-0 mb-1 fw-semibold">Progress</h6>
          <small class="text-end">{{ item.progress }}%</small>
        </div>
        <b-progress style="height: 5px">
          <b-progress-bar
            :value="item.progress"
            class="rounded-pill"
            style="margin-right: 2px"
            variant="secondary"
          />
        </b-progress>
      </b-col>
      <b-col md="2">
        <h6 class="m-0 mb-1 fw-semibold">Total Report</h6>
        <p class="text-muted mb-0">{{ item.totalReport }}</p>
      </b-col>
      <b-col md="2" class="ms-auto">
        <h6 class="m-0 mb-1 fw-semibold">Assigned To</h6>
        <p class="text-muted mb-0">{{ item.assignedTo }}</p>
      </b-col>
    </b-row>
  </b-accordion-item>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { TaskType } from "@/views/apps/projects/task/components/types";

import { kebabToTitleCase } from "@/helpers/change-casing";

defineProps({
  item: {
    type: Object as PropType<TaskType>,
    required: true,
  },
});
</script>
<style>
.task-accordion .accordion-button::after {
  display: none;
}

.task-accordion .accordion-button:not(.collapsed) {
  background-color: transparent !important;
}
</style>
