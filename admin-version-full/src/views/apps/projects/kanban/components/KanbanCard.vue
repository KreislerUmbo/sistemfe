<template>
  <b-card no-body class="mb-3">
    <b-card-body>
      <DropDown custom-class="d-inline-block float-end">
        <a
          class="dropdown-toggle arrow-none text-secondary"
          data-bs-toggle="dropdown"
          href="#"
          role="button"
          aria-haspopup="false"
          aria-expanded="false"
        >
          <i class="fa-solid fa-ellipsis fs-18"></i> </a
        >{{ " " }}
        <div
          class="dropdown-menu dropdown-menu-end"
          data-popper-placement="bottom-end"
        >
          <a class="dropdown-item" href="#"
            ><i class="las la-pen fs-16 me-1 align-text-bottom"></i> Edit</a
          >
          <a class="dropdown-item text-danger" href="#">
            <i class="las la-trash fs-16 me-1 align-text-bottom"></i> Delete
          </a>
        </div>
      </DropDown>

      <b-badge
        :variant="null"
        class="rounded fw-normal px-1 mb-1"
        :class="
          subItem.priority === 'Medium'
            ? 'text-info bg-info-subtle'
            : subItem.priority === 'High'
              ? 'text-danger bg-danger-subtle'
              : subItem.priority === 'Low'
                ? 'text-warning bg-warning-subtle'
                : 'text-success bg-success-subtle'
        "
      >
        {{ subItem.priority }}
      </b-badge>
      <h5 class="my-2 font-14">{{ subItem.title }}</h5>

      <template v-if="subItem.description">
        <p class="text-muted mb-3">{{ subItem.description }}</p>
        <template v-if="!subItem.tags">
          <div
            class="d-flex justify-content-between fw-semibold align-items-center"
          >
            <p class="mb-1 d-inline-flex align-items-center">
              <i class="iconoir-task-list fs-18 text-muted me-1"></i>14 Tasks
            </p>
            <small class="text-end text-body-emphasis d-block ms-auto"
              >70%</small
            >
          </div>
          <b-progress style="height: 5px">
            <b-progress-bar
              :value="15"
              class="rounded-pill"
              style="margin-right: 2px"
              variant="secondary"
            />
            <b-progress-bar
              :value="30"
              class="rounded-pill"
              style="margin-right: 2px"
              variant="secondary"
            />
            <b-progress-bar
              :value="20"
              class="rounded-pill"
              style="margin-right: 2px"
              variant="secondary"
            />
          </b-progress>
        </template>
        <div class="hstack gap-1" v-else>
          <template v-for="(tag, idx) in subItem.tags" :key="idx">
            <b-badge
              :variant="null"
              class="rounded fw-normal px-1 mb-1"
              :class="
                tag === 'API'
                  ? 'text-primary bg-primary-subtle'
                  : tag === 'Responsive'
                    ? 'text-danger bg-danger-subtle'
                    : 'text-info bg-info-subtle'
              "
              >{{ tag }}</b-badge
            >
          </template>
        </div>
      </template>
      <p class="p-3 rounded-md" v-if="subItem.image">
        <img :src="subItem.image" alt="" class="img-fluid mx-auto" />
      </p>
      <hr class="hr-dashed" />
      <b-row class="justify-content-center">
        <div class="col-auto align-self-center">
          <ul class="list-inline mb-0">
            <li class="list-item d-inline-block me-2">
              <a href="#" v-if="!subItem.completedTasks">
                <i class="fa-solid fa-list-check text-muted"></i> {{ " " }}
                <span class="text-muted font-weight-bold">0/5 Tasks</span>
              </a>

              <a href="#" v-else>
                <i class="fa-regular fa-file text-muted"></i> {{ " " }}
                <span class="text-muted font-weight-bold">5 Files</span>
              </a>
            </li>
            <li class="list-item d-inline-block">
              <a href="#">
                <i class="fa-regular fa-message text-muted"></i> {{ " " }}
                <span class="text-muted font-weight-bold">3 Comments</span>
              </a>
            </li>
          </ul>
        </div>
        <div class="col align-self-center">
          <div class="img-group d-flex justify-content-center">
            <a class="user-avatar position-relative d-inline-block" href="#">
              <img
                :src="avatar1"
                alt="avatar"
                class="thumb-sm shadow-sm rounded-circle"
              />
            </a>
            <a
              class="user-avatar position-relative d-inline-block ms-n2"
              href="#"
            >
              <img
                :src="avatar2"
                alt="avatar"
                class="thumb-sm shadow-sm rounded-circle"
              />
            </a>
            <a
              class="user-avatar position-relative d-inline-block ms-n2"
              href="#"
            >
              <img
                :src="avatar6"
                alt="avatar"
                class="thumb-sm shadow-sm rounded-circle"
              />
            </a>
          </div>
        </div>
      </b-row>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";

import type { PropType } from "vue";
import type { MovableType } from "@/views/apps/projects/kanban/components/types";
import DropDown from "@/components/DropDown.vue";

defineProps({
  subItem: {
    type: Object as PropType<MovableType>,
    required: true,
  },
});
</script>
