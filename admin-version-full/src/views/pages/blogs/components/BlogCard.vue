<template>
  <b-card no-body>
    <b-card-body>
      <div :class="item.id > 3 && 'text-center'">
        <img :src="item.image" alt="" class="img-fluid rounded" />
        <div class="mt-3">
          <b-badge
            :variant="null"
            class="bg-purple-subtle text-purple px-2 py-1 fw-semibold"
          >
            {{ item.category }}</b-badge
          >
          |
          <p class="mb-0 text-muted fs-12 d-inline-block">{{ item.date }}</p>
        </div>
        <a
          href=""
          class="d-block fs-18 fw-medium text-body text-truncate"
          :class="item.id > 3 ? 'my-3' : 'my-2'"
        >
          {{ item.title }}
        </a>
        <p class="text-muted">{{ item.content }}</p>
        <hr class="hr-dashed" />
        <div class="d-flex justify-content-between">
          <div class="d-flex align-items-center">
            <div class="flex-shrink-0">
              <img
                :src="item.author.avatar"
                alt=""
                class="thumb-md rounded-circle"
              />
            </div>
            <div class="flex-grow-1 ms-2 text-truncate text-start">
              <h6 class="m-0 text-dark">{{ item.author.name }}</h6>
              <p class="mb-0 text-muted">by <a href="">admin</a></p>
            </div>
          </div>
          <div class="align-self-center">
            <a href="#" class="btn btn-sm btn-primary"
              >Read more <i class="fas fa-long-arrow-alt-right"></i
            ></a>
          </div>
        </div>
      </div>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { BlogType } from "@/views/pages/blogs/components/types";

defineProps({
  item: {
    type: Object as PropType<BlogType>,
    required: true,
  },
});
</script>
