<template>
  <div class="border-dashed border-theme-color rounded">
    <b-card no-body class="bg-light">
      <b-card-body class="border-dashed-bottom border-theme-color">
        <b-button
          type="button"
          variant="outline-primary"
          size="sm"
          class="px-2 d-inline-flex align-items-center float-end"
        >
          <i class="iconoir-chat-bubble fs-14 me-1"></i>
          Message
        </b-button>
        <div class="d-flex align-items-center">
          <div class="flex-shrink-0">
            <img
              :src="item.img"
              alt=""
              class="rounded-circle thumb-xxl mx-auto d-inline-block"
            />
          </div>
          <div class="flex-grow-1 ms-2 text-truncate">
            <h5 class="m-0 fw-bold">{{ item.name }}</h5>
            <p class="text-muted mb-0">{{ item.handle }}</p>
          </div>
        </div>
        <b-row class="mt-3 align-items-center">
          <b-col cols="12">
            <div class="text-muted mb-2 d-flex align-items-center">
              <i class="iconoir-mail-out fs-20 me-1"></i>
              <span class="text-body fw-semibold">Email :</span>
              <a href="#" class="text-primary text-decoration-underline">{{
                item.email
              }}</a>
            </div>
            <div class="text-body d-flex align-items-center">
              <i class="iconoir-phone fs-20 me-1 text-muted"></i>
              <span class="text-body fw-semibold">Phone :</span>
              {{ item.phone }}
            </div>
          </b-col>
        </b-row>
      </b-card-body>
    </b-card>
    <VueDraggableNext group="task" id="project-list-start" class="p-3 pt-0">
      <template v-for="(subItem, idx) in item.children" :key="idx">
        <MovableCard :item="subItem" />
      </template>
    </VueDraggableNext>
  </div>
</template>
<script setup lang="ts">
import { VueDraggableNext } from "vue-draggable-next";
import MovableCard from "@/views/advanced/dragula/components/MovableCard.vue";

import type { PropType } from "vue";
import type { ProjectType } from "@/views/advanced/dragula/components/types";

defineProps({
  item: {
    type: Object as PropType<ProjectType>,
    required: true,
  },
});
</script>
