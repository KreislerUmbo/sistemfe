<template>
  <DefaultLayout>
    <b-row class="my-3">
      <b-col cols="12">
        <div>
          <b-card-body>
            <div
              class="d-block d-md-flex justify-content-between align-items-center"
            >
              <div class="d-flex align-self-center mb-2 mb-md-0">
                <div class="img-group d-inline-flex justify-content-center">
                  <a
                    class="user-avatar position-relative d-inline-block"
                    :class="idx && 'ms-n2'"
                    href="#"
                    v-for="(img, idx) in userImg"
                    :key="idx"
                  >
                    <img
                      :src="img"
                      alt="avatar"
                      class="thumb-md shadow-sm rounded-circle"
                    />
                  </a>
                  <a
                    href="#"
                    class="user-avatar position-relative d-inline-block ms-1"
                  >
                    <span
                      class="thumb-md shadow-sm justify-content-center d-flex align-items-center bg-info-subtle rounded-circle fw-semibold fs-6"
                      >+6</span
                    >
                  </a>
                </div>
                <button
                  type="button"
                  class="btn card-bg text-primary shadow-sm ms-2"
                >
                  <i class="fa-solid fa-plus me-1"></i> Invite
                </button>
              </div>
              <div class="align-self-center">
                <form class="row g-2">
                  <div class="col-auto">
                    <label for="inputsearch" class="visually-hidden"
                      >Search</label
                    >
                    <input
                      type="search"
                      class="form-control"
                      id="inputsearch"
                      placeholder="Search"
                    />
                  </div>
                  <div class="col-auto">
                    <b-dropdown
                      variant="primary"
                      menu-class="dropdown-menu-start"
                      toggle-class="btn card-bg text-primary shadow-sm"
                      auto-close="outside"
                    >
                      <template #button-content>
                        <i class="iconoir-filter-alt"></i> Filter
                      </template>
                      <b-dropdown-text class="m-0 p-0">
                        <div class="p-2">
                          <div class="form-check mb-2">
                            <input
                              type="checkbox"
                              class="form-check-input"
                              checked
                              id="filter-all"
                            />
                            <label class="form-check-label" for="filter-all">
                              All
                            </label>
                          </div>
                          <div class="form-check mb-2">
                            <input
                              type="checkbox"
                              class="form-check-input"
                              checked
                              id="filter-one"
                            />
                            <label class="form-check-label" for="filter-one">
                              Design
                            </label>
                          </div>
                          <div class="form-check mb-2">
                            <input
                              type="checkbox"
                              class="form-check-input"
                              checked
                              id="filter-two"
                            />
                            <label class="form-check-label" for="filter-two">
                              UI/UX
                            </label>
                          </div>
                          <div class="form-check">
                            <input
                              type="checkbox"
                              class="form-check-input"
                              checked
                              id="filter-three"
                            />
                            <label class="form-check-label" for="filter-three">
                              Backend
                            </label>
                          </div>
                        </div>
                      </b-dropdown-text>
                    </b-dropdown>
                  </div>

                  <div class="col-auto">
                    <b-button
                      type="button"
                      variant="primary"
                      @click="boardModal = !boardModal"
                    >
                      <i class="fa-solid fa-plus me-1"></i> Add Task
                    </b-button>
                  </div>
                </form>
              </div>
            </div>
          </b-card-body>
        </div>
      </b-col>
    </b-row>

    <KanbanBoard />

    <!-- Board Modal -->
    <b-modal
      v-model="boardModal"
      title="Add New Board"
      title-class="m-0"
      ok-title="Save"
      cancel-title="Close"
      cancel-variant="outline-danger"
    >
      <b-row>
        <label
          for="inputTaskTitle"
          class="col-sm-3 col-form-label text-end fw-medium"
          >Board Title :</label
        >
        <b-col sm="9">
          <b-form-input type="text" id="inputTaskTitle" />
        </b-col>
      </b-row>
    </b-modal>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { ref } from "vue";
import KanbanBoard from "@/views/apps/projects/kanban/components/KanbanBoard.vue";

import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";

const userImg = [avatar1, avatar2, avatar4, avatar5, avatar4, avatar6];

const boardModal = ref(false);
import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
