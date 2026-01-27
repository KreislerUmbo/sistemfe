<template>
  <b-col md="6" lg="6">
    <b-card no-body>
      <b-card-header>
        <b-row class="align-items-center">
          <b-col>
            <b-card-title>Timeline</b-card-title>
          </b-col>
        </b-row>
      </b-card-header>
      <b-card-body class="px-0 pt-0">
        <simplebar
          class="activity p-3 pt-0"
          data-simplebar
          style="height: 500px"
        >
          <div
            class="activity-info"
            v-for="(item, idx) in activities"
            :key="idx"
          >
            <div class="icon-info-activity">
              <i :class="item.icon"></i>
            </div>
            <div class="activity-info-text">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="m-0 w-75">{{ item.title }}</h6>
                <span class="text-muted d-block">{{ item.time }}</span>
              </div>
              <p class="text-muted mt-3">
                {{ item.description }}
                <a href="#" class="text-primary">[more info]</a>
              </p>
              <b-badge
                :variant="null"
                class="badge-soft-secondary"
                v-for="(badge, idx) in item.badges"
                >{{ badge }}</b-badge
              >{{ " " }}
            </div>
          </div>
        </simplebar>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import simplebar from "simplebar-vue";
import { activities } from "@/views/pages/timeline/components/data";
</script>
