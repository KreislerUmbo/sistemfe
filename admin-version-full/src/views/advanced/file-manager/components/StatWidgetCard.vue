<template>
  <b-card no-body>
    <b-card-body>
      <DropDown custom-class="float-end">
        <a
          href="#"
          class="text-muted fs-16 dropdown-toggle p-1"
          data-bs-toggle="dropdown"
          aria-haspopup="true"
          aria-expanded="true"
        >
          <i class="fa-solid fa-ellipsis-vertical"></i>
        </a>
        <div
          class="dropdown-menu dropdown-menu-end"
          data-popper-placement="bottom-end"
          style="
            position: absolute;
            inset: 0px 0px auto auto;
            margin: 0px;
            transform: translate(0px, 30px);
          "
        >
          <a class="dropdown-item" href="#">View Detail</a>
          <a class="dropdown-item" href="#">Clear All</a>
          <a class="dropdown-item" href="#">Delete</a>
        </div>
      </DropDown>
      <img
        :src="item.image"
        class="me-2 align-self-center thumb-xl"
        alt="..."
      />
      <h5 class="fw-semibold mt-3 fs-14">{{ item.title }}</h5>
      <div class="d-flex justify-content-between my-2">
        <p class="text-muted mb-0 fs-13 fw-semibold">
          <span class="text-dark">{{ item.files }} </span> Files
        </p>
        <p class="text-muted mb-0 fs-13 fw-semibold">
          <span class="text-dark">{{ item.storage }} </span> GB
        </p>
      </div>
      <div class="d-flex align-items-center">
        <div class="flex-grow-1 text-truncate">
          <div class="d-flex align-items-center">
            <div
              class="progress bg-secondary-subtle w-100"
              style="height: 5px"
              role="progressbar"
              aria-label="Success example"
              :aria-valuenow="item.progress"
              aria-valuemin="0"
              aria-valuemax="100"
            >
              <div
                class="progress-bar bg-secondary"
                :style="`width: ${item.progress}%`"
              ></div>
            </div>
            <small class="flex-shrink-1 ms-1">{{ item.progress }}%</small>
          </div>
        </div>
      </div>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts">
import type { PropType } from "vue";
import type { FolderType } from "@/views/advanced/file-manager/components/types";
import DropDown from "@/components/DropDown.vue";

defineProps({
  item: {
    type: Object as PropType<FolderType>,
    required: true,
  },
});
</script>
