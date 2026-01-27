<template>
  <div class="kanban-col">
    <div class="my-3">
      <div
        class="d-flex justify-content-between align-items-center border-bottom border-2"
        :class="
          item.title === 'To Do'
            ? 'border-pink'
            : item.title === 'In Progress'
              ? 'border-warning'
              : item.title === 'Review'
                ? 'border-success'
                : 'border-info'
        "
      >
        <div>
          <h6 class="fw-semibold fs-16 text-muted mb-1">{{ item.title }}</h6>
          <h6 class="fs-13 fw-semibold">
            {{ item.issues }} Issues -
            <span class="text-muted">{{ item.points }} Points </span>
          </h6>
        </div>
        <div>
          <a
            class="text-secondary me-1 add-btn cursor-pointer"
            @click="taskModal = !taskModal"
          >
            <i class="fa-solid fa-plus fs-18"></i>
          </a>
          <DropDown custom-class="d-inline-block">
            <a
              class="dropdown-toggle arrow-none text-secondary"
              data-bs-toggle="dropdown"
              href="#"
              role="button"
              aria-haspopup="false"
              aria-expanded="false"
            >
              <i class="fa-solid fa-ellipsis fs-18"></i>
            </a>
            <div
              class="dropdown-menu dropdown-menu-end"
              data-popper-placement="bottom-end"
            >
              <a class="dropdown-item" href="#">
                <i class="las la-pen fs-16 me-1 align-text-bottom"></i> Edit
              </a>
              <a class="dropdown-item text-danger" href="#">
                <i class="las la-trash fs-16 me-1 align-text-bottom"></i> Delete
              </a>
            </div>
          </DropDown>
        </div>
      </div>
    </div>
    <VueDraggableNext group="project-task" id="project-list-left" class="pt-1">
      <template v-for="(subItem, idx) in item.children" :key="idx">
        <KanbanCard :subItem="subItem" />
      </template>
    </VueDraggableNext>
    <a
      href="#"
      class="btn btn-outline-primary w-100"
      @click="taskModal = !taskModal"
    >
      <i class="fa-solid fa-plus me-1"></i> Add New Task
    </a>
  </div>

  <!-- Task Modal -->
  <b-modal
    v-model="taskModal"
    title="Add New Task"
    title-class="m-0"
    ok-title="Save"
    cancel-title="Close"
    cancel-variant="outline-danger"
  >
    <b-row class="mb-3">
      <label
        for="inputTaskTitle1"
        class="col-sm-3 col-form-label text-end fw-medium"
        >Task Title :</label
      >
      <b-col sm="9">
        <b-form-input type="text" id="inputTaskTitle1" />
      </b-col>
    </b-row>
    <b-row class="mb-3">
      <label
        for="inputTaskID"
        class="col-sm-3 col-form-label text-end fw-medium"
        >Task ID :</label
      >
      <b-col sm="9">
        <b-form-input type="text" id="inputTaskID" />
      </b-col>
    </b-row>
    <b-row class="mb-3">
      <label
        for="inputAssigned"
        class="col-sm-3 col-form-label text-end fw-medium"
        >Assigned to:</label
      >
      <b-col sm="9">
        <b-form-input type="text" id="inputAssigned" />
      </b-col>
    </b-row>
    <b-row class="mb-3">
      <label
        for="inputDescription"
        class="col-sm-3 col-form-label text-end fw-medium"
        >Description:</label
      >
      <b-col sm="9">
        <b-form-textarea type="textarea" rows="2" id="inputDescription" />
      </b-col>
    </b-row>
    <b-row>
      <label
        for="inputPriority"
        class="col-sm-3 col-form-label text-end fw-medium"
        >Priority:</label
      >
      <b-col sm="9">
        <b-form-select model-value="1">
          <option value="1">Low</option>
          <option value="2">Medium</option>
          <option value="3">High</option>
        </b-form-select>
      </b-col>
    </b-row>
  </b-modal>
</template>
<script setup lang="ts">
import { ref } from "vue";
import type { PropType } from "vue";
import type { KanbanTaskType } from "@/views/apps/projects/kanban/components/types";
import { VueDraggableNext } from "vue-draggable-next";
import KanbanCard from "@/views/apps/projects/kanban/components/KanbanCard.vue";
import DropDown from "@/components/DropDown.vue";

const taskModal = ref(false);
defineProps({
  item: {
    type: Object as PropType<KanbanTaskType>,
    required: true,
  },
});
</script>
