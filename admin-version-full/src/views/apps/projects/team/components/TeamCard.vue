<template>
  <b-card no-body>
    <b-card-body>
      <div class="text-center border-dashed-bottom pb-3">
        <img
          :src="item.logo"
          alt=""
          height="80"
          class="rounded-circle d-inline-block"
        />
        <h5 class="m-0 fw-bold mt-2 fs-20">{{ item.team }}</h5>
        <p class="text-muted mb-0">@karen</p>

        <div class="img-group d-flex justify-content-center mt-3">
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
          <a href="" class="user-avatar position-relative d-inline-block ms-1">
            <span
              class="thumb-md shadow-sm justify-content-center d-flex align-items-center bg-info-subtle rounded-circle fw-semibold fs-6"
            >
              +{{ item.members.length - 3 }}
            </span>
          </a>
        </div>
        <p class="text-muted mt-3 mb-0">{{ item.description }}</p>
      </div>
      <b-row class="mt-3 align-items-center">
        <b-col md="6" class="col-auto">
          <div class="d-flex align-items-center">
            <img
              :src="item.leader.avatar"
              class="me-2 align-self-center thumb-lg rounded"
              alt="..."
            />
            <div class="flex-grow-1 text-truncate">
              <h6 class="m-0 text-truncate fs-14 fw-bold">
                {{ item.leader.name }}
              </h6>
              <p class="font-12 mb-0 text-muted">{{ item.leader.role }}</p>
            </div>
          </div>
        </b-col>
        <b-col md="6" class="col text-end align-self-center">
          <small class="text-end">{{ item.percentage }}%</small>
          <b-progress style="height: 5px">
            <b-progress-bar
              :value="item.percentage"
              class="rounded-pill"
              style="margin-right: 2px"
              variant="secondary"
            />
          </b-progress>
        </b-col>
      </b-row>
      <div class="mt-3 text-center">
        <router-link
          to="/apps/chat"
          class="btn btn-outline-primary px-2 d-inline-flex align-items-center"
          ><i class="iconoir-chat-bubble fs-14 me-1"></i>Message</router-link
        >
      </div>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { TeamType } from "@/views/apps/projects/team/components/types";

defineProps({
  item: {
    type: Object as PropType<TeamType>,
    required: true,
  },
});
</script>
