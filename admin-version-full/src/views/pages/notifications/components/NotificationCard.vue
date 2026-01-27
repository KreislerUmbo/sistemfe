<template>
  <b-card-body class="mb-3">
    <h5 class="text-body m-0 d-inline-block">{{ notification.date }}</h5>
    {{ " " }}
    <span
      class="text-pink bg-pink-subtle py-0 px-1 rounded fw-medium d-inline-block ms-1"
    >
      {{ notification.count }}
    </span>
  </b-card-body>
  <b-card no-body v-for="(item, idx) in notification.events" :key="idx">
    <b-card-body>
      <b-row>
        <b-col md="10">
          <a href="#" class="">
            <div class="d-flex align-items-center">
              <div class="flex-shrink-0">
                <img
                  :src="item.avatar"
                  alt=""
                  class="thumb-lg rounded-circle"
                />
              </div>
              <div class="flex-grow-1 ms-2 text-truncate">
                <h6 class="my-1 fw-medium text-dark fs-14">
                  {{ item.title
                  }}<small class="text-muted ps-2">{{ item.time }}</small>
                </h6>
                <p class="text-muted mb-0 text-wrap">
                  {{ item.description }}
                </p>
              </div>
            </div>
          </a>
        </b-col>
        <b-col md="2" class="text-end align-self-center mt-sm-2 mt-lg-0">
          <b-button type="button" size="sm" variant="primary" class="px-2"
            >View</b-button
          >{{ " " }}
          <b-button
            type="button"
            size="sm"
            :variant="null"
            class="bg-secondary-subtle text-secondary"
          >
            <i class="fas fa-trash"></i>
          </b-button>
        </b-col>
      </b-row>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { NotificationType } from "@/views/pages/notifications/components/types";

defineProps({
  notification: {
    type: Object as PropType<NotificationType>,
    required: true,
  },
});
</script>
